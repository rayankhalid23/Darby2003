<?php

namespace App\Services\Shared;

use App\Models\Driver\Driver;
use App\Models\Parent\ParentModel;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\FinancialLedger;
use App\Models\Shared\MasterEscrowVault;
use App\Models\Shared\RechargeRequest;
use App\Models\Shared\Trip;
use App\Models\Shared\TripDispute;
use App\Models\Shared\TripEscrowHold;
use App\Models\Shared\TripStudentAttendance;
use App\Models\Shared\WithdrawalRequest;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FinancialLedgerService
{
    protected ?NotificationService $notificationService;

    public function __construct(?NotificationService $notificationService = null)
    {
        $this->notificationService = $notificationService ?? app(NotificationService::class);
    }

    // شروط ومحددات النظام المالي
    public const FIXED_SERVICE_FEE = 100; // 1 دينار (100 قرش) رسوم خدمة ثابتة
    public const MAX_PARENT_BALANCE = 500000; // 5,000 دينار (500,000 قرش) الحد الأعلى لرصيد محفظة ولي الأمر
    /**
     * الحد الأدنى لسحب السائق بالقروش.
     *
     * ⚠️ كان هذا الثابت يقول 5,000 قرش (50 د.ل) بينما الحد المطبَّق فعلياً في
     * WithdrawalService و WithdrawalRequest كان 5 د.ل مكتوباً رقماً صريحاً في
     * الموضعين. وحّدنا القيمة على السلوك المطبَّق في الإنتاج (5 د.ل) حتى لا
     * يرتفع الحد الأدنى عشرة أضعاف على السائقين بلا قرار. لتغيير السياسة يكفي
     * تعديل هذا الرقم — فهو الآن المصدر الوحيد للحد في كل المسارات.
     */
    public const MIN_WITHDRAWAL_AMOUNT = 500; // 5 دنانير
    public const ESCROW_HOLD_HOURS = 24; // 24 ساعة فترة السماح للنزاع والتحويل للرصيد المتاح

    /**
     * ⚠️ لا تُثبّت نسبة العمولة كثابت هنا. المصدر الوحيد هو جدول pricing_settings عبر
     * PricingSetting::commissionRateFraction()، وإلا اختلفت العمولة بين هذه الخدمة
     * وبقية النظام (كانت 12٪ هنا و8٪ في كل مكان آخر) ولم تتطابق التقارير أبداً.
     */
    protected function commissionRate(): float
    {
        return \App\Models\Shared\PricingSetting::commissionRateFraction();
    }

    /**
     * 🔑 المصدر الوحيد لاسم حساب المحفظة في دفتر الأستاذ.
     *
     * ⚠️ كانت المفاتيح تُبنى يدوياً في كل خدمة، فمرة بـ ParentModel::id ومرة
     * بـ User::id لنفس ولي الأمر (وكذلك السائق). النتيجة أن حساب المستخدم
     * الواحد كان موزّعاً على مفتاحين، فيستحيل إعادة بناء كشف حساب صحيح.
     * المعيار المعتمد: مُعرّف مالك المحفظة نفسه (ParentModel::id / Driver::id)
     * لأنه ما تُربط به محفظة bavix فعلياً عبر holder_id.
     */
    public static function parentAccount(ParentModel|\App\Models\User|int $parent): string
    {
        $id = is_int($parent) ? $parent : $parent->id;

        return "parent_wallet_{$id}";
    }

    public static function driverAccount(Driver|int $driver): string
    {
        $id = $driver instanceof Driver ? $driver->id : $driver;

        return "driver_wallet_{$id}";
    }

    /**
     * حلّ مُعرّف ولي الأمر القادم من الجداول المالية القديمة.
     *
     * ⚠️ الجداول ليست متسقة تاريخياً: `trip_escrow_holds` و `invoices` تخزّن
     * User::id بينما `platform_finances` تخزّن ParentModel::id. النمط القديم
     * `find($x) ?? where('user_id', $x)` كان يعيد ولي أمر خاطئاً بصمت متى تصادف
     * وجود الرقم في الجدولين. هنا تُجرَّب الدلالة الأرجح أولاً ويُسجَّل تحذير
     * صريح عند اللجوء للاحتياطية حتى تُرصد البيانات القديمة وتُصحَّح.
     */
    public function resolveParent(?int $id, bool $preferUserId = true): ?ParentModel
    {
        if (!$id) {
            return null;
        }

        $primary  = $preferUserId
            ? ParentModel::where('user_id', $id)->first()
            : ParentModel::find($id);

        if ($primary) {
            return $primary;
        }

        $fallback = $preferUserId
            ? ParentModel::find($id)
            : ParentModel::where('user_id', $id)->first();

        if ($fallback) {
            Log::warning(
                "تعذّر حلّ ولي الأمر بالدلالة المتوقعة للمُعرّف {$id}؛ استُخدمت الدلالة الاحتياطية. "
                . "يرجى تصحيح بيانات هذا السجل."
            );
        }

        return $fallback;
    }

    /**
     * 1️⃣ تسجيل حركة مالية غير قابلة للمسح في السجل المالي (Immutable Double-Entry Ledger)
     */
    public function recordLedgerEntry(
        string $sourceAccount,
        string $destinationAccount,
        int $amountCents,
        string $type,
        int $balanceBefore = 0,
        int $balanceAfter = 0,
        ?string $referenceNumber = null,
        ?array $metadata = null
    ): FinancialLedger {
        return FinancialLedger::create([
            'reference_number'    => $referenceNumber,
            'source_account'      => $sourceAccount,
            'destination_account' => $destinationAccount,
            'amount'              => $amountCents,
            'balance_before'      => $balanceBefore,
            'balance_after'       => $balanceAfter,
            'type'                => $type,
            'status'              => 'completed',
            'metadata'            => $metadata,
        ]);
    }

    /**
     * ب. فحص السلامة المالية اليومي (Daily Solvency Check)
     *
     * ⚠️ المعادلة السابقة كانت تقارن مجموع أحواض الخزينة بمجموع أرصدة المحافظ
     * مضافاً إليها إيراد المنصة. هذه المقارنة لا يمكن أن تتحقق في أي نظام سليم:
     * المال المشحون والجالس في محفظة ولي الأمر ليس له حوض يقابله، فكان مسار
     * الشحن يزيد حوض الأمانات ليُوهم التوازن، ثم يزيده الحجز مرة ثانية بنفس
     * المال. النتيجة أن `Log::emergency` كان يُطلق في كل نظام سليم فيُتجاهل.
     *
     * البديل هنا ثلاثة تحققات مستقلة، كل واحد منها قابل للإثبات ويشير إلى
     * موضع الخلل بدل رقم فرق مجرّد:
     *
     *   1. مرآة السائقين : driver_available_pool = مجموع أرصدة محافظ السائقين
     *   2. غطاء الأمانات : parents_escrow_pool = ما تبقى غير مُسوّى من الاشتراكات
     *                       + الحجوزات المفتوحة للرحلات اليومية
     *   3. سلامة الأحواض : لا حوض بقيمة سالبة
     */
    public function checkDailySolvency(): array
    {
        $vault = MasterEscrowVault::getVault();

        // مجاميع مباشرة من قاعدة البيانات — النسخة السابقة كانت تحمّل كل سجلات
        // أولياء الأمور والسائقين ثم تقرأ رصيد كل واحد باستعلام منفصل (N+1).
        $totalParentWallets = $this->sumWalletBalances(ParentModel::class);
        $totalDriverWallets = $this->sumWalletBalances(Driver::class);

        // 1️⃣ مرآة محافظ السائقين
        $driverMirrorDiff = $vault->driver_available_pool - $totalDriverWallets;

        // 2️⃣ غطاء حوض الأمانات
        $unsettledSubscriptions = (int) round(
            (float) \App\Models\Shared\PlatformFinance::whereIn('status', [
                \App\Models\Shared\PlatformFinance::STATUS_HELD,
                \App\Models\Shared\PlatformFinance::STATUS_DISPUTED,
            ])->selectRaw('COALESCE(SUM(total_amount - settled_amount - refunded_amount), 0) AS remaining')
              ->value('remaining') * 100
        );

        $openTripHolds = (int) TripEscrowHold::whereIn('hold_status', ['held', 'captured_pending', 'disputed'])
            ->sum('amount');

        $expectedEscrow = $unsettledSubscriptions + $openTripHolds;
        $escrowDiff     = $vault->parents_escrow_pool - $expectedEscrow;

        // 3️⃣ لا حوض سالب
        $pools = [
            'parents_escrow_pool'     => (int) $vault->parents_escrow_pool,
            'driver_pending_pool'     => (int) $vault->driver_pending_pool,
            'driver_available_pool'   => (int) $vault->driver_available_pool,
            'pending_withdrawal_pool' => (int) ($vault->pending_withdrawal_pool ?? 0),
            'platform_revenue_pool'   => (int) $vault->platform_revenue_pool,
            'penalty_pool'            => (int) $vault->penalty_pool,
        ];
        $negativePools = array_keys(array_filter($pools, fn($v) => $v < 0));

        $checks = [
            'driver_wallet_mirror' => [
                'passed'          => $driverMirrorDiff === 0,
                'expected_dinar'  => round($totalDriverWallets / 100, 2),
                'actual_dinar'    => round($vault->driver_available_pool / 100, 2),
                'difference_cents' => $driverMirrorDiff,
                'description'     => 'حوض أرصدة السائقين المتاحة يجب أن يساوي مجموع أرصدة محافظهم.',
            ],
            'escrow_backing' => [
                'passed'          => $escrowDiff === 0,
                'expected_dinar'  => round($expectedEscrow / 100, 2),
                'actual_dinar'    => round($vault->parents_escrow_pool / 100, 2),
                'difference_cents' => $escrowDiff,
                'description'     => 'حوض الأمانات يجب أن يساوي المتبقي غير المُسوّى من الاشتراكات مضافاً إليه حجوزات الرحلات المفتوحة.',
            ],
            'no_negative_pools' => [
                'passed'         => empty($negativePools),
                'negative_pools' => $negativePools,
                'description'    => 'لا يجوز أن يحمل أي حوض في الخزينة قيمة سالبة.',
            ],
        ];

        $isSolvent = collect($checks)->every(fn($c) => $c['passed'] === true);

        if (!$isSolvent) {
            $failed = collect($checks)->filter(fn($c) => !$c['passed'])->keys()->implode('، ');
            Log::emergency("🚨 خلل في السلامة المالية للنظام. الفحوص التي فشلت: {$failed}");
        }

        return [
            'is_solvent'             => $isSolvent,
            'checks'                 => $checks,
            'parents_escrow_pool'    => round($pools['parents_escrow_pool'] / 100, 2),
            'driver_pending_pool'    => round($pools['driver_pending_pool'] / 100, 2),
            'driver_available_pool'  => round($pools['driver_available_pool'] / 100, 2),
            'pending_withdrawal_pool' => round($pools['pending_withdrawal_pool'] / 100, 2),
            'platform_revenue_pool'  => round($pools['platform_revenue_pool'] / 100, 2),
            'penalty_pool'           => round($pools['penalty_pool'] / 100, 2),
            'parent_wallets_dinar'   => round($totalParentWallets / 100, 2),
            'driver_wallets_dinar'   => round($totalDriverWallets / 100, 2),
            'total_custody_dinar'    => round(array_sum($pools) / 100, 2),
        ];
    }

    /**
     * مجموع أرصدة محافظ نوع معيّن من المالكين باستعلام واحد على جدول wallets.
     */
    protected function sumWalletBalances(string $holderClass): int
    {
        return (int) DB::table('wallets')
            ->where('holder_type', $holderClass)
            ->whereNull('deleted_at')
            ->sum(DB::raw('CAST(balance AS SIGNED)'));
    }

    /**
     * 2️⃣ طلب رحلة يومية وتجميد المبلغ (Hold)
     */
    public function holdTripAmount(Trip $trip, int $parentUserId, float $amountDinar): TripEscrowHold
    {
        return DB::transaction(function () use ($trip, $parentUserId, $amountDinar) {
            $amountCents = (int) round($amountDinar * 100);
            $parent = $this->resolveParent($parentUserId);

            if (!$parent) {
                throw ValidationException::withMessages([
                    'parent' => ['تعذر العثور على حساب ولي الأمر لحجز مبلغ الرحلة.'],
                ]);
            }

            if (($parent->balance) < $amountCents) {
                throw ValidationException::withMessages([
                    'balance' => ['رصيد محفظتك غير كافٍ لحجز هذه الرحلة. يرجى شحن المحفظة.'],
                ]);
            }

            // حجز واحد لكل رحلة: إعادة الإرسال أو النقر المزدوج تُعيد الحجز القائم
            // بدل خصم المبلغ من المحفظة مرة ثانية.
            $existing = TripEscrowHold::where('trip_id', $trip->id)
                ->where('parent_id', $parentUserId)
                ->whereIn('hold_status', ['held', 'captured_pending', 'disputed'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $balBefore = $parent->balance;
            $parent->withdraw($amountCents);
            $balAfter = $parent->balance;

            $vault = MasterEscrowVault::getVault();
            $vault->increment('parents_escrow_pool', $amountCents);

            $hold = TripEscrowHold::create([
                'trip_id'     => $trip->id,
                'parent_id'   => $parentUserId,
                'driver_id'   => $trip->driver_id,
                'amount'      => $amountCents,
                'hold_status' => 'held',
                'held_at'     => now(),
            ]);

            $this->recordLedgerEntry(
                self::parentAccount($parent),
                "parents_escrow_pool",
                $amountCents,
                'trip_hold',
                $balBefore,
                $balAfter,
                "TRIP-HOLD-{$trip->id}",
                ['trip_id' => $trip->id]
            );

            return $hold;
        });
    }

    /**
     * تنفيذ الرحلة وتحويل الرصيد للمعلق (Hold -> Capture -> Pending 24h)
     */
    public function captureTripOnCompletion(Trip $trip): TripEscrowHold
    {
        return DB::transaction(function () use ($trip) {
            $hold = TripEscrowHold::where('trip_id', $trip->id)
                ->where('hold_status', 'held')
                ->firstOrFail();

            $vault = MasterEscrowVault::getVault();
            $vault->decrement('parents_escrow_pool', $hold->amount);
            $vault->increment('driver_pending_pool', $hold->amount);

            $hold->update([
                'hold_status'  => 'captured_pending',
                'captured_at'  => now(),
                'available_at' => now()->addHours(self::ESCROW_HOLD_HOURS),
            ]);

            $this->recordLedgerEntry(
                "parents_escrow_pool",
                "driver_pending_pool",
                $hold->amount,
                'trip_capture',
                0,
                $hold->amount,
                "TRIP-CAPTURE-{$trip->id}",
                ['trip_id' => $trip->id]
            );

            return $hold;
        });
    }

    /**
     * تحويل الأرباح المعلقة إلى رصيد متاح للسائق تلقائياً بعد مرور 24 ساعة (Escrow Release)
     */
    public function releasePendingTripEscrows(): int
    {
        $eligibleHolds = TripEscrowHold::where('hold_status', 'captured_pending')
            ->where('available_at', '<=', now())
            ->get();

        $releasedCount = 0;

        foreach ($eligibleHolds as $hold) {
            DB::transaction(function () use ($hold, &$releasedCount) {
                // القفل يمنع تحرير نفس الحجز مرتين لو تداخل تشغيلان للمهمة المجدولة.
                $hold = TripEscrowHold::where('id', $hold->id)
                    ->where('hold_status', 'captured_pending')
                    ->lockForUpdate()
                    ->first();

                if (!$hold) {
                    return;
                }

                $vault = MasterEscrowVault::getVault();

                $totalCents    = $hold->amount;
                $commission    = (int) round($totalCents * $this->commissionRate());
                $netDriverPay  = $totalCents - $commission;

                $vault->decrement('driver_pending_pool', $totalCents);
                $vault->increment('platform_revenue_pool', $commission);
                $vault->increment('driver_available_pool', $netDriverPay);

                $driver = Driver::find($hold->driver_id);
                if ($driver) {
                    $driver->deposit($netDriverPay);
                }

                $hold->update(['hold_status' => 'released_available']);

                $this->recordLedgerEntry(
                    "driver_pending_pool",
                    self::driverAccount($hold->driver_id),
                    $netDriverPay,
                    'driver_payout',
                    0,
                    $netDriverPay,
                    "PAYOUT-HOLD-{$hold->id}",
                    ['commission_cents' => $commission]
                );

                $this->recordLedgerEntry(
                    "driver_pending_pool",
                    "platform_revenue_pool",
                    $commission,
                    'platform_commission',
                    0,
                    $commission,
                    "COMMISSION-HOLD-{$hold->id}"
                );

                $releasedCount++;
            });
        }

        return $releasedCount;
    }

    /**
     * جدول إلغاء الرحلات اليومية وسياسة الغرامات (Cancellation Matrix)
     */
    public function processTripCancellation(Trip $trip, string $cancelledBy, ?Carbon $tripScheduledAt = null): array
    {
        return DB::transaction(function () use ($trip, $cancelledBy, $tripScheduledAt) {
            $hold = TripEscrowHold::where('trip_id', $trip->id)
                ->where('hold_status', 'held')
                ->first();

            $amountCents = $hold?->amount ?? 0;
            $scheduledAt = $tripScheduledAt ?? Carbon::parse($trip->scheduled_start_time ?? $trip->scheduled_at ?? now());
            $minutesBefore = now()->diffInMinutes($scheduledAt, false);

            $parentRefundCents = 0;
            $driverPayCents    = 0;
            $platformFeeCents  = 0;
            $driverPenaltyCents= 0;

            if ($cancelledBy === 'parent') {
                if ($minutesBefore > 120) { // قبل وقت الرحلة بـ > 2 ساعة
                    $parentRefundCents = $amountCents;
                } elseif ($minutesBefore < 30) { // قبل وقت الرحلة بـ < 30 دقيقة
                    $parentRefundCents = (int) round($amountCents * 0.50); // خصم 50% كغرامة
                    $driverPayCents    = (int) round($amountCents * 0.40); // 40% للسائق
                    $platformFeeCents  = (int) round($amountCents * 0.10); // 10% منصة
                } else { // بين 30 دقيقة وساعتين
                    $parentRefundCents = (int) round($amountCents * 0.80);
                    $driverPayCents    = (int) round($amountCents * 0.20);
                }
            } elseif ($cancelledBy === 'no_show') { // عدم خروج الطفل عند وصول السائق
                $parentRefundCents = 0; // خصم 100%
                $platformFeeCents  = (int) round($amountCents * $this->commissionRate());
                $driverPayCents    = $amountCents - $platformFeeCents;
            } elseif ($cancelledBy === 'driver') { // إلغاء من السائق في أي وقت
                $parentRefundCents = $amountCents; // استرجاع 100% لولي الأمر
                $driverPenaltyCents= (int) round($amountCents * 0.20); // غرامة 20% على السائق
            }

            $vault = MasterEscrowVault::getVault();

            if ($amountCents > 0 && $hold) {
                // ⚠️ التوزيع يجب أن يستنفد المبلغ المحجوز بالكامل ولا يتجاوزه. الكسر
                // المتبقي من التقريب يذهب لولي الأمر لأنه صاحب المال الأصلي.
                $distributed = $parentRefundCents + $driverPayCents + $platformFeeCents;
                if ($distributed !== $amountCents) {
                    $parentRefundCents += ($amountCents - $distributed);
                    $parentRefundCents = max(0, $parentRefundCents);
                }

                $vault->decrement('parents_escrow_pool', $amountCents);

                if ($parentRefundCents > 0) {
                    $parent = $this->resolveParent($hold->parent_id);
                    if ($parent) {
                        $parent->deposit($parentRefundCents);
                        $this->recordLedgerEntry(
                            'parents_escrow_pool',
                            self::parentAccount($parent),
                            $parentRefundCents,
                            'trip_cancellation_refund',
                            0,
                            (int) $parent->balance,
                            "TRIP-CANCEL-REFUND-{$trip->id}",
                            ['trip_id' => $trip->id, 'cancelled_by' => $cancelledBy]
                        );
                    }
                }

                if ($driverPayCents > 0) {
                    $driver = Driver::find($hold->driver_id);
                    if ($driver) {
                        $driver->deposit($driverPayCents);
                        // المرآة إلزامية: أي إيداع في محفظة سائق يقابله ارتفاع في
                        // driver_available_pool، وإلا انكسر فحص السلامة المالية.
                        $vault->increment('driver_available_pool', $driverPayCents);
                        $this->recordLedgerEntry(
                            'parents_escrow_pool',
                            self::driverAccount($driver),
                            $driverPayCents,
                            'trip_cancellation_driver_pay',
                            0,
                            (int) $driver->balance,
                            "TRIP-CANCEL-DRIVER-{$trip->id}",
                            ['trip_id' => $trip->id, 'cancelled_by' => $cancelledBy]
                        );
                    }
                }

                if ($platformFeeCents > 0) {
                    $vault->increment('platform_revenue_pool', $platformFeeCents);
                    $this->recordLedgerEntry(
                        'parents_escrow_pool',
                        'platform_revenue_pool',
                        $platformFeeCents,
                        'platform_commission',
                        0,
                        $platformFeeCents,
                        "TRIP-CANCEL-COMMISSION-{$trip->id}"
                    );
                }

                // الغرامة حركة منفصلة تماماً عن توزيع مبلغ الرحلة: تخرج من محفظة
                // السائق إلى حوض الغرامات، ولا تُقتطع من أمانة ولي الأمر.
                if ($driverPenaltyCents > 0) {
                    $driver = Driver::find($hold->driver_id);
                    if ($driver && $driver->balance >= $driverPenaltyCents) {
                        $driver->withdraw($driverPenaltyCents);
                        $vault->decrement('driver_available_pool', $driverPenaltyCents);
                        $vault->increment('penalty_pool', $driverPenaltyCents);
                        $this->recordLedgerEntry(
                            self::driverAccount($driver),
                            'penalty_pool',
                            $driverPenaltyCents,
                            'driver_penalty',
                            0,
                            (int) $driver->balance,
                            "TRIP-CANCEL-PENALTY-{$trip->id}",
                            ['trip_id' => $trip->id]
                        );
                    } else {
                        // رصيد السائق لا يغطي الغرامة — تُسجَّل كغير محصّلة بدل
                        // تضخيم حوض الغرامات بمال لم يخرج من أي محفظة.
                        $driverPenaltyCents = 0;
                        Log::warning("تعذّر تحصيل غرامة إلغاء الرحلة ID {$trip->id}: رصيد السائق غير كافٍ.");
                    }
                }

                $hold->update(['hold_status' => 'refunded']);
            }

            return [
                'cancelled_by'         => $cancelledBy,
                'parent_refund_dinar'  => round($parentRefundCents / 100, 2),
                'driver_pay_dinar'     => round($driverPayCents / 100, 2),
                'platform_fee_dinar'   => round($platformFeeCents / 100, 2),
                'driver_penalty_dinar' => round($driverPenaltyCents / 100, 2),
            ];
        });
    }

    /**
     * 3️⃣ تسوية العقد الشهري الإغلاق والمقاصة النهائية (Monthly Subscription Settlement)
     */
    /**
     * 3️⃣ كشف المقاصة النهائية للاشتراك الشهري (تقرير للقراءة فقط)
     *
     * ⚠️ هذه الدالة لا تحرّك قرشاً واحداً — الصرف الفعلي يتم تناسبياً عند إنهاء
     * كل رحلة في TripLifecycleService::settlePlatformFinancesForCompletedTrip().
     * وكانت رغم ذلك تنفّذ `update(['is_settled' => true])` على طلبات تغيير الموقع،
     * فتُعلّم الرسوم كأنها حُصِّلت بينما لم يُحوَّل منها شيء لأحد. أُزيلت تلك
     * الكتابة: رسوم تغيير الموقع تُحصَّل الآن لحظة موافقة السائق في
     * LocationChangeService، وهذه الدالة تقرأ الأرقام ولا تعدّلها.
     */
    public function settleMonthlySubscription(SubscriptionRequest $subscription): array
    {
        return DB::transaction(function () use ($subscription) {
            $totalContractPrice = (float) ($subscription->total_amount_after_discount ?? $subscription->total_price ?? 0);
            $plannedTripsCount  = max((int) ($subscription->days_count ?? 20), 1);
            $perTripCost        = $totalContractPrice / $plannedTripsCount;

            $trips = Trip::whereHas('route', fn($q) => $q->where('subscription_request_id', $subscription->id))->get();

            $completedCount    = $trips->where('status', 'completed')->count();
            $parentAbsentCount = TripStudentAttendance::whereIn('trip_id', $trips->pluck('id'))
                ->where('attendance_status', 'absent')
                ->count();

            $driverAbsentCount = $trips->where('driver_attendance', false)->count();
            $holidaysCount     = $trips->where('status', 'holiday')->count();

            // احتساب عدد مرات تغيير الموقع المعتمدة ورسومها
            $activeSubIds = \App\Models\Shared\ActiveSubscription::where('subscription_request_id', $subscription->id)->pluck('id');
            $approvedChanges = \App\Models\Shared\LocationChangeRequest::whereIn('active_subscription_id', $activeSubIds)
                ->where('status', \App\Models\Shared\LocationChangeRequest::STATUS_APPROVED)
                ->get();
            $locationChangesCount = $approvedChanges->count();
            $locationChangesFees  = (float) $approvedChanges->sum('fee_amount');

            $billableTrips = $completedCount + $parentAbsentCount;
            $finalAmount   = round(($billableTrips * $perTripCost) + $locationChangesFees, 2);
            $refundAmount  = max(0, round($totalContractPrice - round($billableTrips * $perTripCost, 2), 2));

            return [
                'contract_number'        => "REQ-{$subscription->id}",
                'total_contract_price'   => $totalContractPrice,
                'planned_trips'          => $plannedTripsCount,
                'per_trip_cost'          => round($perTripCost, 2),
                'completed_trips'        => $completedCount,
                'parent_absent_trips'    => $parentAbsentCount,
                'driver_absent_trips'    => $driverAbsentCount,
                'holidays_trips'         => $holidaysCount,
                'location_changes_count' => $locationChangesCount,
                'location_changes_fees'  => $locationChangesFees,
                'final_settled_amount'   => $finalAmount,
                'rollover_refund_credit' => $refundAmount,
            ];
        });
    }

    public function settleMonthlyContract($subscription): array
    {
        return $this->settleMonthlySubscription($subscription);
    }

    /**
     * الإلغاء المبكر للاشتراك في منتصف الشهر (Mid-Month Termination)
     *
     * ⚠️ كانت هذه الدالة مساراً ثانياً كاملاً للإلغاء: تحسب المتبقي بنفسها ثم
     * تودعه في محفظة ولي الأمر **دون أن تخصمه من حوض الأمانات**، فكل استدعاء
     * لها كان يضيف مبلغ الاسترجاع إلى أموال النظام من العدم. كما كانت تتجاهل
     * ما صُرف فعلياً للسائق عن الرحلات المنفّذة (settled_amount).
     *
     * الآن تفوّض الحركة المالية بالكامل إلى المسار الوحيد المعتمد للاسترجاع
     * في SubscriptionRequestService::refundHeldFundsOnCancellation()، الذي يحترم
     * المصروف فعلياً ويحرّك الأحواض ويسجّل القيود. وتبقى هذه الدالة مسؤولة عن
     * إغلاق حالة الاشتراك واحتساب رسم الإلغاء المبكر فقط.
     */
    public function terminateSubscriptionMidMonth(SubscriptionRequest $subscription, string $terminatedBy, bool $isArbitraryByParent = false): array
    {
        return DB::transaction(function () use ($subscription, $terminatedBy, $isArbitraryByParent) {
            $preview = $this->previewSubscriptionTermination($subscription, $terminatedBy, $isArbitraryByParent);

            $refundResult = app(\App\Services\Shared\SubscriptionRequestService::class)
                ->refundHeldFundsOnCancellation(
                    $subscription->id,
                    $isArbitraryByParent ? 'parent' : $terminatedBy,
                );

            $subscription->update(['status' => 'cancelled']);

            \App\Models\Shared\ActiveSubscription::where('subscription_request_id', $subscription->id)
                ->whereNotIn('status', ['cancelled', 'completed'])
                ->update(['status' => 'cancelled']);

            return array_merge($preview, [
                'refunded_to_parent' => $refundResult['refund_amount'] ?? 0.0,
                'driver_net_pay'     => $refundResult['driver_net_pay'] ?? 0.0,
                'platform_fee'       => $refundResult['platform_fee'] ?? 0.0,
                'settlement_status'  => $refundResult['status'] ?? 'no_held_funds',
            ]);
        });
    }

    public function terminateContractMidMonth($subscription, string $terminatedBy, bool $isArbitraryByParent = false): array
    {
        return $this->terminateSubscriptionMidMonth($subscription, $terminatedBy, $isArbitraryByParent);
    }

    /**
     * 4️⃣ فتح نزاع مالي وتجميد الحركة (24h Dispute Window)
     */
    public function openDispute(int $tripId, int $parentUserId, string $reason): TripDispute
    {
        $hold = TripEscrowHold::where('trip_id', $tripId)->firstOrFail();

        if ($hold->created_at->diffInHours(now()) > self::ESCROW_HOLD_HOURS) {
            throw ValidationException::withMessages([
                'dispute' => ['انتهت المهلة المحددة لتقديم النزاع (24 ساعة من الرحلة).'],
            ]);
        }

        $hold->update(['hold_status' => 'disputed', 'disputed_at' => now()]);

        return TripDispute::create([
            'trip_id'   => $tripId,
            'parent_id' => $parentUserId,
            'driver_id' => $hold->driver_id,
            'reason'    => $reason,
            'status'    => 'open',
        ]);
    }

    /**
     * حل النزاع المالي بواسطة الأدمن (Admin Resolution)
     */
    public function resolveDispute(int $disputeId, int $adminId, string $resolution, ?string $notes = null): TripDispute
    {
        return DB::transaction(function () use ($disputeId, $adminId, $resolution, $notes) {
            $dispute = TripDispute::findOrFail($disputeId);
            $hold = TripEscrowHold::where('trip_id', $dispute->trip_id)->firstOrFail();

            // ⚠️ الحوض الذي يخرج منه المال يعتمد على المرحلة التي تجمّد فيها الحجز:
            // النزاع قبل تنفيذ الرحلة يجمّد المال في أمانات أولياء الأمور، وبعد
            // تنفيذها يكون قد انتقل إلى حوض المستحقات المعلّقة. النسخة السابقة لم
            // تخصم من أي حوض إطلاقاً في كلا الفرعين، فكان كل قرار نزاع يضيف مبلغه
            // إلى إجمالي أموال النظام من العدم.
            $vault = MasterEscrowVault::getVault();
            $sourcePool = $hold->captured_at ? 'driver_pending_pool' : 'parents_escrow_pool';

            if ($resolution === 'resolve_parent_refunded') {
                $parent = $this->resolveParent($dispute->parent_id);
                if ($parent) {
                    $vault->decrement($sourcePool, $hold->amount);
                    $parent->deposit($hold->amount);

                    $this->recordLedgerEntry(
                        $sourcePool,
                        self::parentAccount($parent),
                        $hold->amount,
                        'dispute_refund',
                        0,
                        (int) $parent->balance,
                        "DISPUTE-REFUND-{$dispute->id}",
                        ['dispute_id' => $dispute->id, 'trip_id' => $dispute->trip_id]
                    );
                }
                $hold->update(['hold_status' => 'refunded']);
                $dispute->update([
                    'status'           => 'resolved_parent_refunded',
                    'resolution_notes' => $notes ?? 'تم إرجاع المبلغ لولي الأمر وتغريم السائق.',
                    'resolved_by'      => $adminId,
                    'resolved_at'      => now(),
                ]);
            } else {
                $commission   = (int) round($hold->amount * $this->commissionRate());
                $netDriverPay = $hold->amount - $commission;

                $vault->decrement($sourcePool, $hold->amount);
                $vault->increment('platform_revenue_pool', $commission);
                $vault->increment('driver_available_pool', $netDriverPay);

                $driver = Driver::find($dispute->driver_id);
                if ($driver) {
                    $driver->deposit($netDriverPay);

                    $this->recordLedgerEntry(
                        $sourcePool,
                        self::driverAccount($driver),
                        $netDriverPay,
                        'driver_payout',
                        0,
                        (int) $driver->balance,
                        "DISPUTE-PAYOUT-{$dispute->id}",
                        ['dispute_id' => $dispute->id, 'commission_cents' => $commission]
                    );
                }

                $this->recordLedgerEntry(
                    $sourcePool,
                    'platform_revenue_pool',
                    $commission,
                    'platform_commission',
                    0,
                    $commission,
                    "DISPUTE-COMMISSION-{$dispute->id}"
                );

                $hold->update(['hold_status' => 'released_available']);
                $dispute->update([
                    'status'           => 'resolved_driver_paid',
                    'resolution_notes' => $notes ?? 'تم رفض الشكوى وتحويل الأرباح للسائق.',
                    'resolved_by'      => $adminId,
                    'resolved_at'      => now(),
                ]);
            }

            $freshDispute = $dispute->fresh(['parent.user', 'driver.user']);

            // 🔔 إرسال إشعار لولي الأمر والسائق بحل النزاع المالي
            try {
                $parentUser = $freshDispute->parent?->user ?? User::find($dispute->parent_id);
                $driverUser = $freshDispute->driver?->user ?? Driver::find($dispute->driver_id)?->user;

                if ($parentUser && $this->notificationService) {
                    $parentMsg = $resolution === 'resolve_parent_refunded'
                        ? 'تمت مراجعة النزاع المالي بقرار الإدارة وإعادة المبلغ إلى محفظتك بنجاح.'
                        : 'تمت مراجعة النزاع المالي من قبل الإدارة واعتماد مستحقات الرحلة.';
                    
                    $this->notificationService->sendToUser($parentUser, 'dispute_resolved', [
                        'title'       => '⚖️ نتيجة مراجعة النزاع المالي',
                        'message'     => $parentMsg,
                        'entity_type' => 'dispute',
                        'entity_id'   => (string) $dispute->id,
                        'screen'      => 'DISPUTE_DETAILS',
                    ]);
                }

                if ($driverUser && $this->notificationService) {
                    $driverMsg = $resolution === 'resolve_driver_paid'
                        ? 'تمت مراجعة النزاع المالي بقرار الإدارة وتحويل أرباح الرحلة إلى محفظتك.'
                        : 'تمت مراجعة النزاع المالي من قبل الإدارة وإعادة المبلغ لولي الأمر.';
                    
                    $this->notificationService->sendToUser($driverUser, 'dispute_resolved', [
                        'title'       => '⚖️ نتيجة مراجعة النزاع المالي',
                        'message'     => $driverMsg,
                        'entity_type' => 'dispute',
                        'entity_id'   => (string) $dispute->id,
                        'screen'      => 'DISPUTE_DETAILS',
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning("فشل إرسال إشعار حل النزاع المالي: " . $e->getMessage());
            }

            return $freshDispute;
        });
    }

    /**
     * معاينة حسابات الإلغاء المبكر للاشتراك دون تعديل البيانات
     */
    public function previewSubscriptionTermination(SubscriptionRequest $subscription, string $terminatedBy, bool $isArbitraryByParent = false): array
    {
        $totalPrice  = (float) ($subscription->total_amount_after_discount ?? $subscription->total_price ?? 0);
        $totalTrips  = max((int) ($subscription->days_count ?? 20), 1);
        $perTripCost = $totalPrice / $totalTrips;

        $tripsCompleted = Trip::whereHas('route', fn($q) => $q->where('subscription_request_id', $subscription->id))
            ->where('status', 'completed')
            ->count();

        $executedCost = round($tripsCompleted * $perTripCost, 2);
        $remaining    = max(0, round($totalPrice - $executedCost, 2));

        $cancellationFee = 0;
        if ($isArbitraryByParent && $remaining > 0) {
            $cancellationFee = round($remaining * 0.10, 2);
        }

        $refundToParent = max(0, round($remaining - $cancellationFee, 2));

        return [
            'contract_id'        => $subscription->id,
            'contract_number'    => "REQ-{$subscription->id}",
            'total_price'        => $totalPrice,
            'executed_cost'      => $executedCost,
            'remaining_balance'  => $remaining,
            'cancellation_fee'   => $cancellationFee,
            'refunded_to_parent' => $refundToParent,
        ];
    }

    public function previewContractTermination($subscription, string $terminatedBy, bool $isArbitraryByParent = false): array
    {
        return $this->previewSubscriptionTermination($subscription, $terminatedBy, $isArbitraryByParent);
    }

    /**
     * معاينة مصفوفة الغرامات لإلغاء رحلة دون تعديل البيانات
     */
    public function previewTripCancellation(Trip $trip, string $cancelledBy): array
    {
        $route = $trip->route;
        $subscription = $route?->subscriptionRequest;
        $tripPriceDinar = (float) ($subscription?->total_price ? ($subscription->total_price / max($subscription->days_count, 1)) : ($subscription?->trip_price ?? 25.00));

        $parentRefundDinar = 0;
        $driverPayDinar = 0;
        $platformFeeDinar = 0;
        $driverPenaltyDinar = 0;

        if ($cancelledBy === 'parent') {
            $parentRefundDinar = $tripPriceDinar;
        } elseif ($cancelledBy === 'no_show') {
            $platformFeeDinar = round($tripPriceDinar * $this->commissionRate(), 2);
            $driverPayDinar = round($tripPriceDinar - $platformFeeDinar, 2);
        } elseif ($cancelledBy === 'driver') {
            $parentRefundDinar = $tripPriceDinar;
            $driverPenaltyDinar = round($tripPriceDinar * 0.20, 2);
        }

        return [
            'trip_id'               => $trip->id,
            'cancelled_by'          => $cancelledBy,
            'trip_price_dinar'      => round($tripPriceDinar, 2),
            'parent_refund_dinar'   => round($parentRefundDinar, 2),
            'driver_pay_dinar'      => round($driverPayDinar, 2),
            'platform_amount_dinar' => round($platformFeeDinar, 2),
            'penalty_dinar'         => round($driverPenaltyDinar, 2),
        ];
    }
}
