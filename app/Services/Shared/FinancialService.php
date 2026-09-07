<?php

namespace App\Services\Shared;

use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Invoice;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStudentAttendance;
use App\Models\Parent\ParentModel;
use App\Models\Driver\Driver;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FinancialService
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * توليد فاتورة مبدئية لطلب اشتراك
     */
    public function generateProformaInvoice(SubscriptionRequest|ActiveSubscription $sub): Invoice
    {
        $subscriptionRequest = $sub instanceof ActiveSubscription ? $sub->subscriptionRequest : $sub;
        if (!$subscriptionRequest) {
            throw new \Exception('تعذر العثور على طلب الاشتراك لتوليد الفاتورة.');
        }

        $subscriptionRequest->loadMissing(['parent.user', 'driver.user']);

        // فاتورة مبدئية واحدة لكل طلب: إعادة الاستدعاء تُعيد القائمة بدل إنشاء
        // نسخة ثانية تنافسها على نفس الاشتراك.
        $existing = Invoice::where('subscription_request_id', $subscriptionRequest->id)
            ->where('type', 'proforma')
            ->latest('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        // ⚠️ أربعة أحرف من uniqid() ليست ضماناً ضد التصادم على عمود unique،
        // وفشلها يظهر للمستخدم كخطأ قاعدة بيانات. رقم تسلسلي مشتق من مُعرّف
        // الطلب نفسه فريد بحكم البناء.
        $sequence      = Invoice::where('subscription_request_id', $subscriptionRequest->id)->count() + 1;
        $invoiceNumber = sprintf('INV-REQ-%d-%03d', $subscriptionRequest->id, $sequence);

        $parentUserId = $subscriptionRequest->parent?->user_id ?? $subscriptionRequest->parent_id;
        $driverId     = $subscriptionRequest->driver_id;

        if (!$parentUserId) {
            throw new \Exception('تعذر تحديد معرّف ولي الأمر لتوليد الفاتورة.');
        }

        if (!$driverId) {
            throw new \Exception('تعذر تحديد معرّف السائق لتوليد الفاتورة.');
        }

        $totalTrips = $this->calculateTotalTrips($subscriptionRequest);
        $totalPrice = (float) ($subscriptionRequest->total_amount_after_discount ?? $subscriptionRequest->total_price ?? 0);

        return Invoice::create([
            'subscription_request_id' => $subscriptionRequest->id,
            'parent_id'               => $parentUserId,
            'driver_id'               => $driverId,
            'invoice_number'          => $invoiceNumber,
            'amount'                  => $totalPrice,
            'type'                    => 'proforma',
            'status'                  => 'pending',
            'due_date'                => $subscriptionRequest->end_date ?? now()->addDays(30),
            'subscription_type'       => $subscriptionRequest->subscription_type ?? 'multi_day',
            'total_trips'             => $totalTrips,
            'completed_trips'         => 0,
            'driver_absences'         => 0,
            'student_absences'        => 0,
        ]);
    }

    /**
     * إصدار الفاتورة النهائية للاشتراك (مستند محاسبي — لا يحرّك أي مال).
     *
     * ⚠️ كانت هذه الدالة مساراً مالياً ثانياً كاملاً: تنفّذ
     * `$parent->transfer($driver, $netAmountCents)` مباشرة من محفظة إلى محفظة،
     * بلا مرور بحوض الأمانات وبلا اقتطاع أي عمولة للمنصة. ولو عملت إلى جانب
     * المسار الفعلي (PlatformFinance + الخزينة) لخُصم من ولي الأمر مرتين ولحصل
     * السائق على 100٪ بدل 92٪. لم تكن معطّلة بقرار، بل بالصدفة: لا أحد يستدعي
     * generateProformaInvoice() في مسار حيّ، وأمر subscriptions:settle غير مجدول.
     *
     * الآن: الصرف الفعلي يتم تناسبياً عند إنهاء كل رحلة في
     * TripLifecycleService::settlePlatformFinancesForCompletedTrip()، وهذه الدالة
     * تقرأ ما صُرف فعلاً من سجل PlatformFinance وتحوّله إلى فاتورة نهائية للعرض
     * والأرشفة فقط.
     */
    public function settleSubscription(SubscriptionRequest|ActiveSubscription $sub): Invoice
    {
        $subscriptionRequest = $sub instanceof ActiveSubscription ? $sub->subscriptionRequest : $sub;
        if (!$subscriptionRequest) {
            throw new \Exception('بيانات الاشتراك غير مكتملة.');
        }

        return DB::transaction(function () use ($subscriptionRequest) {
            $subscriptionRequest->loadMissing(['parent.user', 'driver.user']);

            $parent = $subscriptionRequest->parent;
            $driver = $subscriptionRequest->driver;

            if (!$parent || !$driver) {
                throw new \Exception('بيانات ولي الأمر أو السائق غير مكتملة.');
            }

            $proforma = Invoice::where('subscription_request_id', $subscriptionRequest->id)
                ->where('type', 'proforma')
                ->where('status', 'pending')
                ->latest()
                ->first();

            if (!$proforma) {
                $proforma = $this->generateProformaInvoice($subscriptionRequest);
            }

            $trips = Trip::whereHas('route', function ($q) use ($subscriptionRequest) {
                $q->where('subscription_request_id', $subscriptionRequest->id);
            })->where('driver_id', $subscriptionRequest->driver_id)->get();

            $completedTrips = $trips->where('status', 'completed');
            $completedCount = $completedTrips->count();

            $driverAbsences = $completedTrips->where('driver_attendance', false)->count();
            $studentAbsences = 0;

            $tripIds = $completedTrips->pluck('id');
            if ($tripIds->isNotEmpty()) {
                $studentAbsences = TripStudentAttendance::whereIn('trip_id', $tripIds)
                    ->whereIn('attendance_status', ['absent', 'late'])
                    ->count();
            }

            // المبلغ المفوتر = ما صُرف فعلاً من الأمانة، مقروءاً من السجل المالي
            // للاشتراك. لا إعادة حساب هنا: أي معادلة مستقلة ستنحرف عاجلاً أو آجلاً
            // عمّا نفّذه محرّك التسوية.
            $finance = \App\Models\Shared\PlatformFinance::where('subscription_request_id', $subscriptionRequest->id)
                ->latest('id')
                ->first();

            $settledAmount  = (float) ($finance->settled_amount ?? 0);
            $refundedAmount = (float) ($finance->refunded_amount ?? 0);
            $isClosed       = $finance
                && in_array($finance->status, [
                    \App\Models\Shared\PlatformFinance::STATUS_COMPLETED,
                    \App\Models\Shared\PlatformFinance::STATUS_REFUNDED,
                    \App\Models\Shared\PlatformFinance::STATUS_PARTIALLY_REFUNDED,
                ], true);

            $proforma->update([
                'status'            => $isClosed ? 'paid' : 'pending',
                'type'              => $isClosed ? 'final' : 'proforma',
                'completed_trips'   => $completedCount,
                'driver_absences'   => $driverAbsences,
                'student_absences'  => $studentAbsences,
                'calculated_amount' => $settledAmount,
                'paid_at'           => $isClosed ? ($finance->settled_at ?? now()) : null,
                'details'           => [
                    'settled_amount'      => $settledAmount,
                    'refunded_amount'     => $refundedAmount,
                    'settled_trips_count' => (int) ($finance->settled_trips_count ?? 0),
                    'expected_trips'      => (int) ($finance->expected_trips_count ?? 0),
                    'finance_status'      => $finance->status ?? null,
                ],
            ]);

            if ($isClosed && isset($parent->user)) {
                $this->notificationService->sendToUser($parent->user, 'settlement_paid', [
                    'title'      => 'الفاتورة النهائية للاشتراك',
                    'message'    => "صدرت الفاتورة النهائية للاشتراك رقم #{$subscriptionRequest->id} بقيمة {$settledAmount} د.ل.",
                    'amount'     => $settledAmount,
                    'invoice_id' => (string) $proforma->id,
                ]);
            }

            return $proforma->fresh();
        });
    }

    public function sendPreSettlementWarning(SubscriptionRequest|ActiveSubscription $sub): void
    {
        $subscriptionRequest = $sub instanceof ActiveSubscription ? $sub->subscriptionRequest : $sub;
        if (!$subscriptionRequest) return;

        $parent = $subscriptionRequest->parent;
        if (!$parent || !isset($parent->user)) return;

        $invoice = Invoice::where('subscription_request_id', $subscriptionRequest->id)
            ->where('type', 'proforma')
            ->where('status', 'pending')
            ->first();

        if (!$invoice) {
            $invoice = $this->generateProformaInvoice($subscriptionRequest);
        }

        $this->notificationService->sendToUser($parent->user, 'settlement_warning', [
            'title'      => 'تذكير بتسوية الاشتراك',
            'message'    => "سيتم تسوية الاشتراك رقم #{$subscriptionRequest->id} بقيمة {$invoice->amount} د.ل بعد 3 أيام. يرجى شحن محفظتك.",
            'action_url' => '/parent/wallet',
            'amount'     => $invoice->amount,
            'invoice_id' => (string) $invoice->id,
        ]);
    }

    public function getParentInvoices(int $parentUserId, array $filters = [])
    {
        $query = Invoice::with(['subscriptionRequest', 'driver.user'])
            ->where('parent_id', $parentUserId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->latest()->paginate(15);
    }

    public function getDriverInvoices(int $driverId, array $filters = [])
    {
        $query = Invoice::with(['subscriptionRequest', 'parent'])
            ->where('driver_id', $driverId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->latest()->paginate(15);
    }

    public function getAllInvoices(array $filters = [])
    {
        $query = Invoice::with(['subscriptionRequest', 'parent', 'driver.user']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['driver_id'])) {
            $query->where('driver_id', $filters['driver_id']);
        }

        return $query->latest()->paginate(15);
    }

    public function getInvoiceById(int $id): Invoice
    {
        return Invoice::with(['subscriptionRequest', 'parent', 'driver.user'])->findOrFail($id);
    }

    /**
     * حساب إجمالي الرحلات بشكل آمن
     */
    private function calculateTotalTrips(SubscriptionRequest $req): int
    {
        $daysCount = $req->days_count ?? 1;

        if ($req->subscription_type === 'single_day') {
            return max((int)$daysCount, 1);
        }

        if ($req->start_date && $req->end_date) {
            try {
                $start = Carbon::parse($req->start_date);
                $end   = Carbon::parse($req->end_date);

                // استثناء الجمعة (5) والسبت (6)
                $workingDays = 0;
                while ($start->lte($end)) {
                    if (!in_array($start->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY])) {
                        $workingDays++;
                    }
                    $start->addDay();
                }
                return max($workingDays, 1);
            } catch (\Exception $e) {
                return max((int)$daysCount, 1);
            }
        }

        return max((int)$daysCount, 1);
    }
}