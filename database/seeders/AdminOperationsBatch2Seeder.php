<?php

namespace Database\Seeders;

use App\Models\Admin\AdminAlert;
use App\Models\Driver\Vehicle;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * AdminOperationsBatch2Seeder - دفعة ثانية إضافية بحتة (Additive-only) مكمّلة
 * لـ AdminOperationsSeeder، بدون أي حذف أو تفريغ أو تعديل لأي سجل موجود.
 *
 * تغطي:
 *   - رحلات نشطة (in_progress) على مسارات حقيقية قائمة فعلاً + بث مواقع حية
 *   - طلبات إنشاء حساب جديدة من سائقين (تسجيل بانتظار مراجعة الأدمن)
 *   - طلبات تعديل بيانات/مركبة من سائقين معتمَدين (driver_profile_changes)
 *   - 20 حالة AI مختلفة لسلوك السائقين (أنواع ومستويات خطورة متنوعة)
 *   - 40 شكوى مختلفة، كل واحدة مربوطة بسائق/ولي أمر/رحلة حقيقيين موجودين فعلاً
 *
 *   php artisan db:seed --class=AdminOperationsBatch2Seeder
 */
class AdminOperationsBatch2Seeder extends Seeder
{
    private array $staff = [];

    public function run(): void
    {
        $this->command?->info('🛠️  AdminOperationsBatch2Seeder: دفعة إضافية ثانية (بدون أي حذف/تفريغ)...');

        if (User::where('email', 'driverreg1@darby.ly')->exists()) {
            $this->command?->warn('⚠️  يبدو أن هذه الدفعة شُغِّلت من قبل (driverreg1@darby.ly موجود). تم التخطي.');
            return;
        }

        $this->loadStaff();

        DB::transaction(function () {
            $this->createActiveTrips();
            $this->createDriverRegistrationRequests();
            $this->createDriverProfileChangeRequests();
            $this->createTwentyAiAlerts();
            $this->createFortyComplaints();
        });

        $this->printReport();
    }

    private function loadStaff(): void
    {
        foreach ([
            'super_admin'           => 'admin@darby.ly',
            'operations_supervisor' => 'operations@darby.ly',
            'fleet_supervisor'      => 'fleet@darby.ly',
            'support_supervisor'    => 'support@darby.ly',
            'finance_officer'       => 'finance@darby.ly',
        ] as $key => $email) {
            $this->staff[$key] = User::where('email', $email)->value('id');
        }
    }

    // =====================================================================
    // 1) رحلات نشطة (in_progress) على مسارات حقيقية قائمة + بث مواقع حية
    // =====================================================================
    private function createActiveTrips(): void
    {
        $this->command?->info('1️⃣  رحلات نشطة (in_progress) على مسارات حقيقية...');

        $today = now()->format('Y-m-d');

        // مسارات ضمن اشتراكات نشطة فعلياً وليس لها أي رحلة "جارية" اليوم بعد
        $candidates = DB::table('active_subscriptions')
            ->join('routes', 'routes.id', '=', 'active_subscriptions.route_id')
            ->join('request_children', 'request_children.id', '=', 'active_subscriptions.request_child_id')
            ->where('active_subscriptions.status', 'active')
            ->whereNotIn('routes.id', function ($q) use ($today) {
                $q->select('route_id')->from('trips')->where('trip_date', $today)->where('status', 'in_progress');
            })
            ->select(
                'routes.id as route_id',
                'routes.driver_id',
                'routes.shift_slot',
                'routes.route_type',
                'active_subscriptions.id as sub_id',
                'active_subscriptions.subscription_request_id',
                'request_children.child_id',
                'active_subscriptions.pickup_lat',
                'active_subscriptions.pickup_lng',
                'active_subscriptions.dropoff_lat',
                'active_subscriptions.dropoff_lng'
            )
            ->orderBy('routes.id')
            ->get()
            ->unique('route_id')
            ->take(6);

        $created = 0;

        foreach ($candidates as $c) {
            $startedMinutesAgo = rand(6, 35);
            $startedAt = now()->subMinutes($startedMinutesAgo);

            $tripId = DB::table('trips')->insertGetId([
                'driver_id'             => $c->driver_id,
                'route_id'              => $c->route_id,
                'trip_type'             => $c->route_type,
                'shift_slot'            => $c->shift_slot,
                'status'                => 'in_progress',
                'scheduled_at'          => $startedAt->copy()->subMinutes(5),
                'started_at'            => $startedAt,
                'scheduled_start_time'  => $startedAt->copy()->subMinutes(5)->format('H:i:s'),
                'actual_start_time'     => $startedAt->format('H:i:s'),
                'start_lat'             => $c->pickup_lat,
                'start_lng'             => $c->pickup_lng,
                'trip_date'             => $today,
                'created_at'            => $startedAt->copy()->subMinutes(5),
                'updated_at'            => $startedAt,
            ]);

            // بث نقاط تتبع حية بين نقطة الانطلاق ونقطة الوصول (استيفاء خطي مبسّط)
            $steps = rand(6, 9);
            for ($i = 1; $i <= $steps; $i++) {
                $ratio = $i / ($steps + 1);
                $lat = (float) $c->pickup_lat + (((float) $c->dropoff_lat - (float) $c->pickup_lat) * $ratio);
                $lng = (float) $c->pickup_lng + (((float) $c->dropoff_lng - (float) $c->pickup_lng) * $ratio);

                DB::table('trip_tracking')->insert([
                    'trip_id'     => $tripId,
                    'latitude'    => round($lat + (mt_rand(-15, 15) / 100000), 7),
                    'longitude'   => round($lng + (mt_rand(-15, 15) / 100000), 7),
                    'speed'       => round(mt_rand(150, 4500) / 100, 2),
                    'accuracy'    => round(mt_rand(300, 1200) / 100, 2),
                    'recorded_at' => $startedAt->copy()->addMinutes((int) round($i * ($startedMinutesAgo / ($steps + 1)))),
                ]);
            }

            // حدث صعود الطفل الأول على هذا المسار
            DB::table('trip_events')->insert([
                'trip_id'         => $tripId,
                'child_id'        => $c->child_id,
                'subscription_id' => $c->sub_id,
                'action_type'     => 'picked_up',
                'trip_type'       => $c->route_type === 'Morning' ? 'ذهاب' : 'عودة',
                'location_lat'    => $c->pickup_lat,
                'location_lng'    => $c->pickup_lng,
                'scanned_at'      => $startedAt->copy()->addMinutes(1),
                'trip_cost'       => 0,
                'created_at'      => $startedAt->copy()->addMinutes(1),
                'updated_at'      => $startedAt->copy()->addMinutes(1),
            ]);

            $created++;
        }

        $this->command?->line("   ✔ {$created} رحلة نشطة (in_progress) جديدة ببث مواقع حية");
    }

    // =====================================================================
    // 2) طلبات إنشاء حساب جديدة من سائقين (تسجيل بانتظار المراجعة)
    // =====================================================================
    private function createDriverRegistrationRequests(): void
    {
        $this->command?->info('2️⃣  طلبات إنشاء حساب جديدة من سائقين (بانتظار المراجعة)...');

        $driverRoleId = DB::table('roles')->where('name', 'driver')->value('id');
        $admins = User::whereIn('role_id', [1, 2])->get();

        $applicants = [
            [
                'full_name' => 'نوري الصادق مفتاح القذافي',
                'email'     => 'driverreg1@darby.ly',
                'phone'     => '0910000020',
                'gender'    => 'male',
                'national_id' => '119900000101',
                'license_number' => 'LY-TR-99010',
                'plate' => 'ط ط 4001',
                'brand' => 'Kia', 'model' => 'Bongo', 'year' => 2018, 'color' => 'أبيض', 'type' => 'Van', 'capacity' => 14,
            ],
            [
                'full_name' => 'أمينة محمد سالم الورفلي',
                'email'     => 'driverreg2@darby.ly',
                'phone'     => '0910000021',
                'gender'    => 'female',
                'national_id' => '119900000102',
                'license_number' => 'LY-TR-99011',
                'plate' => 'ط ط 4002',
                'brand' => 'Hyundai', 'model' => 'H100', 'year' => 2017, 'color' => 'رمادي', 'type' => 'Van', 'capacity' => 12,
            ],
            [
                'full_name' => 'يونس عبدالسلام فرج المسماري',
                'email'     => 'driverreg3@darby.ly',
                'phone'     => '0910000022',
                'gender'    => 'male',
                'national_id' => '119900000103',
                'license_number' => 'LY-TR-99012',
                'plate' => 'ط ط 4003',
                'brand' => 'Toyota', 'model' => 'Coaster', 'year' => 2016, 'color' => 'أبيض', 'type' => 'Bus', 'capacity' => 25,
            ],
            [
                'full_name' => 'هدى الطاهر عمر الشريف',
                'email'     => 'driverreg4@darby.ly',
                'phone'     => '0910000023',
                'gender'    => 'female',
                'national_id' => '119900000104',
                'license_number' => 'LY-TR-99013',
                'plate' => 'ط ط 4004',
                'brand' => 'Kia', 'model' => 'Pregio', 'year' => 2019, 'color' => 'أزرق', 'type' => 'Van', 'capacity' => 13,
            ],
        ];

        foreach ($applicants as $i => $a) {
            $createdAt = now()->subHours(rand(2, 72));

            // created_at/updated_at ليست ضمن fillable في نموذج User، لذا نضبطها
            // صراحة بعد الإنشاء بدل تمريرها لـ create() حيث ستُهمَل بصمت.
            $user = User::create([
                'full_name'    => $a['full_name'],
                'email'        => $a['email'],
                'phone_number' => $a['phone'],
                'password'     => Hash::make('Password123!'),
                'role_id'      => $driverRoleId,
                'is_active'    => false, // معلّق حتى موافقة الأدمن
                'is_trusted'   => false,
                'gender'       => $a['gender'],
            ]);
            $user->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

            $driver = DB::table('drivers')->insertGetId([
                'user_id'           => $user->id,
                'national_id'       => $a['national_id'],
                'license_number'    => $a['license_number'],
                'license_expiry'    => now()->addYears(2)->format('Y-m-d'),
                'license_image_url' => 'https://cdn.darby.ly/demo/licenses/pending_' . ($i + 1) . '.jpg',
                'status'            => 'Pending',
                'shift'             => 'both',
                'created_at'        => $createdAt,
                'updated_at'        => $createdAt,
            ]);

            $vehicle = Vehicle::create([
                'driver_id'         => $driver,
                'plate_number'      => $a['plate'],
                'brand'             => $a['brand'],
                'model'             => $a['model'],
                'year'              => $a['year'],
                'color'             => $a['color'],
                'type'              => $a['type'],
                'capacity_manual'   => $a['capacity'],
                'has_ac'            => true,
                'status'            => 'Active',
                'vehicle_image_url' => 'https://cdn.darby.ly/demo/vehicles/pending_' . ($i + 1) . '.jpg',
            ]);
            $vehicle->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

            foreach ([
                'LOGBOOK'          => now()->addYears(3),
                'INSURANCE'        => now()->addMonths(11),
                'INSPECTION'       => now()->addMonths(10),
                'OPERATING_PERMIT' => now()->addMonths(9),
            ] as $docType => $expiry) {
                DB::table('vehicle_documents')->insert([
                    'vehicle_id'  => $vehicle->id,
                    'doc_type'    => $docType,
                    'file_url'    => 'https://cdn.darby.ly/demo/docs/pending_' . ($i + 1) . '_' . strtolower($docType) . '.pdf',
                    'expiry_date' => $expiry->format('Y-m-d'),
                    'is_verified' => false,
                    'state'       => 'pending',
                    'created_at'  => $createdAt,
                    'updated_at'  => $createdAt,
                ]);
            }

            DB::table('driver_approvals')->insert([
                'driver_id'    => $driver,
                'request_type' => 'Registration',
                'status'       => 'Pending',
                'new_values'   => json_encode([
                    'vehicle_id'     => $vehicle->id,
                    'license_number' => $a['license_number'],
                    'plate_number'   => $a['plate'],
                ], JSON_UNESCAPED_UNICODE),
                'created_at'   => $createdAt,
                'updated_at'   => $createdAt,
            ]);

            foreach ($admins as $adminUser) {
                DB::table('notifications')->insert([
                    'id'              => (string) Str::uuid(),
                    'type'            => 'App\\Notifications\\NewDriverRegistered',
                    'notifiable_type' => User::class,
                    'notifiable_id'   => $adminUser->id,
                    'data'            => json_encode([
                        'title' => 'تسجيل سائق جديد 🚐',
                        'body'  => "قام السائق ({$a['full_name']}) بإكمال بياناته وبانتظار المراجعة.",
                    ], JSON_UNESCAPED_UNICODE),
                    'read_at'         => null,
                    'created_at'      => $createdAt,
                    'updated_at'      => $createdAt,
                ]);
            }
        }

        $this->command?->line('   ✔ ' . count($applicants) . ' طلبات تسجيل سائق جديد بانتظار المراجعة');
    }

    // =====================================================================
    // 3) طلبات تعديل بيانات/مركبة من سائقين معتمَدين (driver_profile_changes)
    // =====================================================================
    private function createDriverProfileChangeRequests(): void
    {
        $this->command?->info('3️⃣  طلبات تعديل بيانات ومركبات من سائقين معتمَدين...');

        if (!Schema::hasTable('driver_profile_changes')) {
            $this->command?->warn('   ⚠️  جدول driver_profile_changes غير موجود - تم تخطي هذا القسم.');
            return;
        }

        $driver = fn (int $id) => DB::table('drivers')->join('users', 'users.id', '=', 'drivers.user_id')
            ->where('drivers.id', $id)->select('drivers.id', 'users.full_name', 'users.phone_number', 'users.alternative_phone')->first();

        $vehicleOf = fn (int $driverId) => DB::table('vehicles')->where('driver_id', $driverId)->first();

        $requests = [];

        // أ) تعديل رقم هاتف بديل - سائق #908
        if ($d = $driver(908)) {
            $requests[] = [
                'driver_id'  => $d->id,
                'old_values' => ['alternative_phone' => $d->alternative_phone],
                'new_values' => ['alternative_phone' => '0928' . rand(100000, 999999)],
                'status'     => 'Pending',
                'created_at' => now()->subHours(20),
            ];
        }

        // ب) تحديث رخصة قيادة منتهية قريباً - سائق #909
        if ($d = $driver(909)) {
            $requests[] = [
                'driver_id'  => $d->id,
                'old_values' => ['license_number' => 'LY-TR-OLD-909', 'license_expiry' => now()->subDays(10)->format('Y-m-d')],
                'new_values' => ['license_number' => 'LY-TR-RENEW-909', 'license_expiry' => now()->addYears(3)->format('Y-m-d')],
                'status'     => 'Pending',
                'created_at' => now()->subHours(15),
            ];
        }

        // ج) تعديل لون وسعة المركبة - سائق #911
        if (($d = $driver(911)) && ($v = $vehicleOf(911))) {
            $requests[] = [
                'driver_id'  => $d->id,
                'old_values' => ['vehicle_id' => $v->id, 'color' => $v->color, 'capacity_manual' => $v->capacity_manual],
                'new_values' => ['vehicle_id' => $v->id, 'color' => 'أسود', 'capacity_manual' => $v->capacity_manual + 2],
                'status'     => 'Pending',
                'created_at' => now()->subHours(10),
            ];
        }

        // د) تحديث صورة رخصة القيادة فقط - سائق #914
        if ($d = $driver(914)) {
            $requests[] = [
                'driver_id'  => $d->id,
                'old_values' => ['license_image_url' => 'https://cdn.darby.ly/demo/licenses/driver914_old.jpg'],
                'new_values' => ['license_image_url' => 'https://cdn.darby.ly/demo/licenses/driver914_new.jpg'],
                'status'     => 'Pending',
                'created_at' => now()->subHours(6),
            ];
        }

        // هـ) تعديل اسم كامل (تصحيح إملائي) - سائق #915 - تمت الموافقة عليه سابقاً (تاريخي)
        if ($d = $driver(915)) {
            $requests[] = [
                'driver_id'      => $d->id,
                'old_values'     => ['full_name' => 'كمال بشير عاشور الفقهي'],
                'new_values'     => ['full_name' => 'كمال البشير عاشور الفقهي'],
                'status'         => 'Approved',
                'action_by'      => $this->staff['support_supervisor'],
                'action_at'      => now()->subDays(3),
                'created_at'     => now()->subDays(4),
            ];
        }

        // و) طلب تحديث مركبة مرفوض (لوحة غير مطابقة للفحص) - سائق #916
        if (($d = $driver(916)) && ($v = $vehicleOf(916))) {
            $requests[] = [
                'driver_id'        => $d->id,
                'old_values'       => ['vehicle_id' => $v->id, 'plate_number' => $v->plate_number],
                'new_values'       => ['vehicle_id' => $v->id, 'plate_number' => 'ط ط 4099'],
                'status'           => 'Rejected',
                'rejection_reason' => 'رقم اللوحة الجديد غير مطابق لشهادة الفحص الفني المرفقة.',
                'action_by'        => $this->staff['fleet_supervisor'],
                'action_at'        => now()->subDays(1),
                'created_at'       => now()->subDays(2),
            ];
        }

        foreach ($requests as $r) {
            DB::table('driver_profile_changes')->insert([
                'driver_id'         => $r['driver_id'],
                'old_values'        => json_encode($r['old_values'], JSON_UNESCAPED_UNICODE),
                'new_values'        => json_encode($r['new_values'], JSON_UNESCAPED_UNICODE),
                'status'            => $r['status'],
                'rejection_reason'  => $r['rejection_reason'] ?? null,
                'action_by'         => $r['action_by'] ?? null,
                'action_at'         => $r['action_at'] ?? null,
                'created_at'        => $r['created_at'],
                'updated_at'        => $r['action_at'] ?? $r['created_at'],
            ]);
        }

        $this->command?->line('   ✔ ' . count($requests) . ' طلبات تعديل بيانات/مركبة (معلّقة + معتمَدة + مرفوضة)');
    }

    // =====================================================================
    // 4) عشرون (20) حالة AI مختلفة لسلوك السائقين
    // =====================================================================
    private function createTwentyAiAlerts(): void
    {
        $this->command?->info('4️⃣  20 حالة AI مختلفة لسلوك السائقين...');

        $used = DB::table('admin_alerts')->pluck('driver_id')->filter()->all();
        $freeDrivers = DB::table('drivers')
            ->where('status', 'Approved')
            ->whereNotIn('id', $used)
            ->orderBy('id')
            ->pluck('id')
            ->values()
            ->all();

        $defs = [
            ['ai_warning', 'LOW', 1, false, 'ملاحظة بسيطة حول نظافة المركبة', 'MINOR_NOTE: بلاغ واحد عن وجود أوراق متناثرة داخل المقصورة.', 'MINOR_NOTE: حادثة معزولة غير متكررة ولا تمس السلامة.'],
            ['ai_warning', 'MEDIUM', 2, false, 'تأخر متكرر عن موعد الاستلام', 'MODERATE_PATTERN: 3 بلاغات تأخر خلال أسبوعين من أولياء أمور مختلفين.', 'MODERATE_PATTERN: نمط متكرر يستدعي تنبيهاً لكن دون خطر سلامة مباشر.'],
            ['ai_moderate_violation', 'MEDIUM', 2, true, 'استخدام سماعات أذن أثناء القيادة', 'MODERATE_VIOLATION: رصد بلاغ موثق باستخدام سماعات أثناء القيادة.', 'MODERATE_VIOLATION: يقلل من انتباه السائق لكنه دون حادثة فعلية.'],
            ['ai_formal_warning', 'HIGH', 3, false, 'تجاوز إشارة حمراء قرب المدرسة', 'ESCALATED_PATTERN: بلاغان موثقان بتجاوز إشارة حمراء خلال شهر.', 'ESCALATED_PATTERN: مخالفة مرورية خطيرة متكررة قرب منطقة مدرسية مزدحمة.'],
            ['ai_admin_review_required', 'HIGH', 2, false, 'إشارات متضاربة حول سلوك القيادة', 'CONFLICTING_SIGNALS: تقييمات إيجابية غالبة مقابل بلاغ سلبي واحد حاد.', 'CONFLICTING_SIGNALS: تناقض واضح يستدعي مراجعة بشرية قبل أي قرار آلي.'],
            ['ai_critical', 'CRITICAL', 3, true, 'رائحة كحول مزعومة - تم التحقق ونفيها', 'CRITICAL_ALLEGATION: بلاغ خطير عن رائحة كحول، تمت المراجعة الميدانية الفورية.', 'CRITICAL_ALLEGATION: فحص ميداني فوري من مشرف العمليات نفى الواقعة، أُغلق البلاغ.'],
            ['ai_warning', 'LOW', 1, true, 'شكوى موسيقى صاخبة داخل المركبة', 'MINOR_NOTE: بلاغ واحد عن مستوى صوت مرتفع للموسيقى.', 'MINOR_NOTE: تم تنبيه السائق شفهياً وخُفض المستوى فوراً.'],
            ['ai_moderate_violation', 'MEDIUM', 2, false, 'عدم التأكد من ربط أحزمة الأمان', 'MODERATE_VIOLATION: بلاغان عن عدم تأكد السائق من ربط الأحزمة قبل الانطلاق.', 'MODERATE_VIOLATION: إجراء سلامة أساسي مُهمَل بشكل متكرر.'],
            ['ai_formal_warning', 'HIGH', 3, true, 'تحميل عدد ركاب يفوق السعة المصرح بها', 'ESCALATED_PATTERN: بلاغان موثقان بتجاوز السعة القصوى للمركبة.', 'ESCALATED_PATTERN: خطر مباشر على سلامة الأطفال عند الازدحام داخل المقصورة.'],
            ['ai_critical', 'CRITICAL', 4, false, 'ترك طفل بمفرده داخل المركبة لدقائق', 'CRITICAL_SAFETY_VIOLATION: بلاغ موثق بنسيان طفل داخل المركبة بعد الوصول.', 'CRITICAL_SAFETY_VIOLATION: انتهاك سلامة جسيم يستوجب إجراءً تأديبياً فورياً.'],
            ['ai_admin_review_required', 'MEDIUM', 2, false, 'بلاغ غامض حول مسار غير معتاد', 'CONFLICTING_SIGNALS: بلاغ عن انحراف عن المسار المعتاد دون توضيح السبب.', 'CONFLICTING_SIGNALS: قد يكون تفادي ازدحام مروري مشروعاً - يتطلب تأكيداً من السائق.'],
            ['ai_decision', 'HIGH', 2, false, 'نمط شكاوى متصاعد خلال 10 أيام', 'AI_TREND_ALERT: النظام رصد ارتفاعاً في معدل الشكاوى بنسبة تفوق المعتاد لنفس السائق.', 'AI_TREND_ALERT: تحليل اتجاه تلقائي دوري - يستدعي متابعة استباقية دون اتخاذ إجراء فوري.'],
            ['ai_warning', 'MEDIUM', 2, true, 'قيادة متسرعة عند نقطة الانتظار', 'MODERATE_PATTERN: بلاغ عن توقف مفاجئ ومتسرع أخاف الأطفال أثناء الصعود.', 'MODERATE_PATTERN: تم التواصل مع السائق وتوجيهه لتخفيف السرعة عند التوقف.'],
            ['ai_moderate_violation', 'MEDIUM', 2, false, 'التدخين بالقرب من المركبة أثناء انتظار الأطفال', 'MODERATE_VIOLATION: بلاغان عن تدخين السائق بجانب باب المركبة المفتوح.', 'MODERATE_VIOLATION: يعرض الأطفال لدخان سلبي أثناء الصعود مباشرة.'],
            ['ai_formal_warning', 'HIGH', 3, false, 'مغادرة نقطة الانتظار قبل وصول الطفل', 'ESCALATED_PATTERN: بلاغان موثقان بمغادرة السائق قبل وصول الطفل بدقائق.', 'ESCALATED_PATTERN: يعرّض الطفل لانتظار غير آمن بمفرده في الشارع.'],
            ['ai_critical', 'CRITICAL', 3, true, 'مشادة جسدية مزعومة مع ولي أمر - لم تثبت', 'CRITICAL_ALLEGATION: بلاغ عن مشادة جسدية مع ولي أمر عند نقطة الاستلام.', 'CRITICAL_ALLEGATION: التحقيق الميداني لم يثبت اعتداءً جسدياً، سوء تفاهم كلامي فقط.'],
            ['ai_admin_review_required', 'HIGH', 2, false, 'اشتباه في تلاعب بمسار GPS', 'CONFLICTING_SIGNALS: انقطاعات غير معتادة في بيانات التتبع تثير الشك.', 'CONFLICTING_SIGNALS: قد يكون خللاً تقنياً بالجهاز وليس تلاعباً متعمداً - يتطلب فحصاً فنياً.'],
            ['ai_warning', 'LOW', 1, false, 'ملاحظة حول المظهر واللباس', 'MINOR_NOTE: بلاغ عن عدم التزام السائق بالزي المتفق عليه مع المنصة.', 'MINOR_NOTE: ملاحظة تنظيمية بسيطة لا تمس السلامة التشغيلية.'],
            ['ai_moderate_violation', 'MEDIUM', 2, true, 'تجاهل تحذير وقود منخفض لفترة طويلة', 'MODERATE_VIOLATION: تكرار تجاهل تحذيرات لوحة القيادة قبل الرحلة.', 'MODERATE_VIOLATION: قد يؤدي لتعطل المركبة أثناء نقل الأطفال - تم توجيه السائق.'],
            ['ai_formal_warning', 'HIGH', 3, false, 'توصيل طفل في نقطة مختلفة عن العنوان المسجل', 'ESCALATED_PATTERN: بلاغان بتوصيل طفل في عنوان غير مطابق للنظام دون تأكيد ولي الأمر.', 'ESCALATED_PATTERN: خطأ تسليم متكرر يمسّ سلامة الطفل مباشرة.'],
        ];

        $count = min(20, count($defs), count($freeDrivers));
        $created = 0;

        for ($i = 0; $i < $count; $i++) {
            [$alertType, $riskLevel, $severity, $isResolved, $title, $message, $reasoning] = $defs[$i];
            $driverId = $freeDrivers[$i];
            $createdAt = now()->subDays(rand(0, 20))->subHours(rand(0, 23));

            AdminAlert::create([
                'driver_id'         => $driverId,
                'alert_type'        => $alertType,
                'risk_level'        => $riskLevel,
                'title'             => $title,
                'message'           => $message,
                'severity'          => $severity,
                'reasoning'         => $reasoning,
                'admin_message'     => $isResolved ? 'تمت المراجعة واتخاذ الإجراء التنظيمي المناسب مع السائق.' : null,
                'actions_taken'     => $isResolved ? ['SEND_ADMIN_ALERT'] : [],
                'ai_metrics'        => [
                    'unique_parents_count'            => rand(1, 5),
                    'total_reviews_analyzed'          => rand(1, 6),
                    'operational_strikes_count'       => rand(1, 3),
                    'ignored_external_factors_count'  => rand(0, 1),
                ],
                'evaluated_reviews' => [
                    ['date' => $createdAt->copy()->subDays(rand(1, 10))->format('Y-m-d'), 'text' => $title, 'parent_id' => 'P_' . Str::padLeft((string) rand(100, 999), 3, '0')],
                ],
                'is_resolved' => $isResolved,
                'is_read'     => $isResolved,
                'created_at'  => $createdAt,
                'updated_at'  => $isResolved ? $createdAt->copy()->addHours(rand(1, 20)) : $createdAt,
            ]);

            $created++;
        }

        $this->command?->line("   ✔ {$created} حالة AI جديدة مختلفة (سائقين مختلفين تماماً عن الدفعة السابقة)");
    }

    // =====================================================================
    // 5) أربعون (40) شكوى مختلفة مربوطة بسائق/ولي أمر/رحلة حقيقيين
    // =====================================================================
    private function createFortyComplaints(): void
    {
        $this->command?->info('5️⃣  40 شكوى مختلفة مربوطة ببيانات واقعية...');

        // أزواج (سائق، ولي أمر) الحقيقية التي لها رحلات مكتملة فعلاً + عدد الشكاوى المطلوب من كل زوج
        $pairCounts = [
            [1, 7, 3], [2, 8, 3], [720, 1633, 2], [908, 2016, 3], [917, 2016, 3],
            [909, 2017, 3], [921, 2017, 3], [916, 2018, 3], [915, 2018, 3],
            [914, 2019, 3], [923, 2019, 3], [911, 2017, 3], [927, 2019, 2], [922, 2017, 3],
        ];

        $slots = [];
        foreach ($pairCounts as [$driverId, $parentId, $n]) {
            $tripIds = DB::table('trips')
                ->join('routes', 'routes.id', '=', 'trips.route_id')
                ->join('requests', 'requests.id', '=', 'routes.subscription_request_id')
                ->where('trips.driver_id', $driverId)
                ->where('requests.parent_id', $parentId)
                ->where('trips.status', 'completed')
                ->orderBy('trips.id')
                ->pluck('trips.id')
                ->all();

            if (empty($tripIds)) {
                continue;
            }

            $picked = [];
            $total = count($tripIds);
            for ($k = 0; $k < $n; $k++) {
                $idx = (int) floor($k * ($total - 1) / max(1, $n - 1));
                $picked[] = $tripIds[$idx] ?? $tripIds[$total - 1];
            }
            $picked = array_values(array_unique($picked));
            // إن أدى تفادي التكرار لنقص العدد، نكمل من بقية الرحلات المتاحة
            foreach ($tripIds as $tid) {
                if (count($picked) >= $n) {
                    break;
                }
                if (!in_array($tid, $picked, true)) {
                    $picked[] = $tid;
                }
            }

            foreach (array_slice($picked, 0, $n) as $tripId) {
                $slots[] = ['driver_id' => $driverId, 'parent_id' => $parentId, 'trip_id' => $tripId];
            }
        }

        // 40 موضوع شكوى مختلف تماماً: [الوصف, الحالة, الإجراء, ai_action, ai_severity, ai_confidence]
        $templates = [
            ['استخدام السائق لهاتفه المحمول بشكل متكرر أثناء القيادة والأطفال بالمركبة.', 'resolved', 'warning_issued', 'notify_driver', 2, 0.8200],
            ['قيادة بسرعة زائدة عند المطبات الاصطناعية القريبة من المدرسة.', 'resolved', 'temporary_suspension', 'suspend_driver', 4, 0.9100],
            ['تأخر أكثر من 20 دقيقة عن موعد الاستلام الصباحي دون أي إشعار مسبق.', 'resolved', 'warning_issued', 'notify_driver', 2, 0.7800],
            ['تأخر السائق عن موعد التسليم المسائي وابني انتظر بمفرده أمام المدرسة.', 'pending', 'none', null, null, null],
            ['رائحة دخان سجائر قوية جداً داخل المركبة رغم وجود أطفال مرضى بالربو.', 'resolved', 'temporary_suspension', 'suspend_driver', 3, 0.8600],
            ['السائق يرفع صوته ويصرخ على الأطفال عند أي تأخير بسيط في النزول.', 'resolved', 'warning_issued', 'notify_driver', 2, 0.7500],
            ['لاحظت أن السائق لا يتأكد من ربط حزام الأمان لابني قبل الانطلاق.', 'pending', 'none', null, null, null],
            ['المركبة كانت محمّلة بعدد أطفال أكبر من العدد المسموح به في المقاعد.', 'resolved', 'temporary_suspension', 'suspend_driver', 4, 0.8900],
            ['توقف السائق بشكل مفاجئ ومتكرر أثناء الطريق مما أخاف الأطفال.', 'dismissed', 'none', 'log_only', 1, 0.5500],
            ['السائق يقود عكس الاتجاه في شارع ضيق بالقرب من بوابة المدرسة.', 'resolved', 'warning_issued', 'notify_driver', 3, 0.8000],
            ['تم توصيل ابني إلى نقطة مختلفة تماماً عن العنوان المسجل بالتطبيق.', 'resolved', 'temporary_suspension', 'suspend_driver', 4, 0.9300],
            ['السائق تجاوز إشارة ضوئية حمراء وطفلتي كانت بالمقعد الخلفي.', 'resolved', 'temporary_suspension', 'suspend_driver', 5, 0.9500],
            ['حدثت مشادة كلامية بين السائق وبيني أمام الأطفال عند نقطة الاستلام.', 'resolved', 'warning_issued', 'notify_driver', 2, 0.7000],
            ['السائق يدخن داخل المركبة أثناء نقل الأطفال يومياً تقريباً.', 'resolved', 'temporary_suspension', 'suspend_driver', 3, 0.8700],
            ['يشغّل السائق الموسيقى بصوت مرتفع جداً منذ فترة ويصعب سماع الأطفال.', 'dismissed', 'none', 'log_only', 1, 0.5000],
            ['المركبة غير نظيفة من الداخل منذ فترة طويلة وبها رائحة غير مستحبة.', 'dismissed', 'none', 'log_only', 1, 0.4800],
            ['مكيف الهواء بالمركبة معطل منذ أسبوعين رغم الحر الشديد.', 'resolved', 'warning_issued', 'notify_driver', 2, 0.6900],
            ['شاهدت السائق يستخدم المركبة لقضاء أغراض شخصية أثناء وقت الرحلة المدرسية.', 'pending', 'none', null, null, null],
            ['السائق يغادر نقطة الانتظار قبل وصول ابني بدقيقتين تقريباً كل يوم.', 'resolved', 'warning_issued', 'notify_driver', 2, 0.7600],
            ['السائق يختصر المسار المتفق عليه بطريق فرعي غير آمن لتوفير الوقت.', 'pending', 'none', null, null, null],
            ['تعامل بخشونة مع طفلي ذي الاحتياجات الخاصة أثناء مساعدته على الصعود.', 'resolved', 'temporary_suspension', 'suspend_driver', 4, 0.9000],
            ['نسي السائق ابني داخل المركبة لعدة دقائق بعد الوصول للمدرسة.', 'resolved', 'temporary_suspension', 'suspend_driver', 5, 0.9600],
            ['المركبة بها صوت غريب بالمحرك ويبدو أن بها عطلاً ميكانيكياً واضحاً.', 'resolved', 'warning_issued', 'notify_driver', 3, 0.8100],
            ['لا توجد طفاية حريق أو حقيبة إسعافات أولية ظاهرة داخل المركبة.', 'dismissed', 'none', 'log_only', 1, 0.5200],
            ['طلب مني السائق مبلغاً إضافياً نقدياً خارج نطاق التطبيق مقابل الخدمة.', 'resolved', 'temporary_suspension', 'suspend_driver', 4, 0.8800],
            ['تلفظ السائق بألفاظ غير لائقة أثناء حديثه مع سائق آخر أمام الأطفال.', 'resolved', 'warning_issued', 'notify_driver', 2, 0.7300],
            ['لا يرد السائق على اتصالاتي في الحالات الطارئة المتعلقة بابني.', 'pending', 'none', null, null, null],
            ['يجبر السائق طفلين على الجلوس بمقعد واحد لزيادة عدد الركاب.', 'resolved', 'temporary_suspension', 'suspend_driver', 4, 0.8500],
            ['يقود السائق بتهور أثناء الأمطار دون أي تخفيف ملحوظ للسرعة.', 'resolved', 'warning_issued', 'notify_driver', 3, 0.7900],
            ['يترك السائق باب المركبة الجانبي مفتوحاً جزئياً أثناء السير أحياناً.', 'resolved', 'temporary_suspension', 'suspend_driver', 4, 0.8400],
            ['ألاحظ تمييزاً واضحاً في المعاملة بين الأطفال داخل المركبة.', 'dismissed', 'none', 'log_only', 1, 0.5300],
            ['رفض السائق اصطحاب ابني يوماً بسبب خلاف سابق بيننا دون تنسيق مع الإدارة.', 'pending', 'none', null, null, null],
            ['وصول متكرر متأخر للمدرسة تسبب في تعرض ابني للعقاب المدرسي.', 'resolved', 'warning_issued', 'notify_driver', 2, 0.7400],
            ['لست متأكداً أن السائق يتحقق من نزول كل الأطفال في مدارسهم الصحيحة.', 'pending', 'none', null, null, null],
            ['يقود السائق بسرعة داخل حرم المدرسة نفسه أثناء نزول الأطفال.', 'resolved', 'temporary_suspension', 'suspend_driver', 4, 0.9000],
            ['يستخدم السائق سماعات أذن طوال وقت القيادة مما يشتت انتباهه.', 'resolved', 'warning_issued', 'notify_driver', 2, 0.7200],
            ['يتجاهل السائق تحذير انخفاض البنزين على اللوحة منذ فترة طويلة.', 'dismissed', 'none', 'log_only', 1, 0.5100],
            ['نشر السائق صورة للأطفال داخل المركبة على وسائل التواصل دون إذن مسبق.', 'resolved', 'temporary_suspension', 'suspend_driver', 4, 0.8700],
            ['تم تبديل المركبة الناقلة دون أي إشعار مسبق لي كولي أمر.', 'pending', 'none', null, null, null],
            ['لا يلتزم السائق بالزي أو المظهر اللائق المتفق عليه مع المنصة.', 'dismissed', 'none', 'log_only', 1, 0.4700],
        ];

        $rows = [];
        $count = min(40, count($slots), count($templates));
        $resolvers = [$this->staff['fleet_supervisor'], $this->staff['support_supervisor']];

        for ($i = 0; $i < $count; $i++) {
            $slot = $slots[$i];
            [$desc, $status, $actionTaken, $aiAction, $aiSeverity, $aiConfidence] = $templates[$i];

            $createdAt = now()->subDays(rand(0, 25))->subHours(rand(0, 23));
            $isDecided = in_array($status, ['resolved', 'dismissed'], true);
            $resolvedAt = $isDecided ? $createdAt->copy()->addHours(rand(2, 48)) : null;
            $resolvedBy = $isDecided ? $resolvers[$i % 2] : null;

            $resolutionNote = null;
            if ($status === 'resolved') {
                $resolutionNote = $actionTaken === 'temporary_suspension'
                    ? 'تم التحقق من الواقعة وتقرر إيقاف السائق مؤقتاً لحين استكمال المراجعة وجلسة التوعية.'
                    : 'تم التواصل مع السائق وتوجيه إنذار رسمي بخصوص الملاحظة المُبلَّغ عنها.';
            } elseif ($status === 'dismissed') {
                $resolutionNote = 'رُوجعت الشكوى ولم يثبت ما يستدعي إجراءً تأديبياً، تم الاكتفاء بالتوضيح لولي الأمر.';
            }

            $rows[] = [
                'submitted_by'        => $slot['parent_id'],
                'against_type'        => 'DRIVER',
                'against_id'          => $slot['driver_id'],
                'driver_id'           => $slot['driver_id'],
                'trip_id'             => $slot['trip_id'],
                'description'         => $desc,
                'status'              => $status,
                'resolved_by'         => $resolvedBy,
                'resolution_note'     => $resolutionNote,
                'action_taken'        => $actionTaken,
                'action_details'      => $actionTaken === 'temporary_suspension' ? 'إيقاف مؤقت + جلسة توعية إلزامية.' : ($actionTaken === 'warning_issued' ? 'إنذار كتابي موثق في ملف السائق.' : null),
                'ai_action'           => $aiAction,
                'ai_confidence'       => $aiConfidence,
                'ai_severity'         => $aiSeverity,
                'ai_analysis_message' => $aiAction ? "تصنيف تلقائي: {$aiAction} بثقة " . ($aiConfidence !== null ? number_format($aiConfidence * 100, 0) : '?') . '%.' : null,
                'resolved_at'         => $resolvedAt,
                'created_at'          => $createdAt,
                'updated_at'          => $resolvedAt ?? $createdAt,
            ];
        }

        foreach (array_chunk($rows, 10) as $chunk) {
            DB::table('complaints')->insert($chunk);
        }

        $this->command?->line("   ✔ " . count($rows) . ' شكوى جديدة مختلفة، كل واحدة مربوطة برحلة/سائق/ولي أمر حقيقيين');
    }

    private function printReport(): void
    {
        $this->command?->newLine();
        $this->command?->info('╔══════════════════════════════════════════════════════════════════╗');
        $this->command?->info('║        ✅ AdminOperationsBatch2Seeder اكتمل (إضافة فقط)           ║');
        $this->command?->info('╚══════════════════════════════════════════════════════════════════╝');
        $this->command?->newLine();
        $this->command?->line('📊 <fg=cyan>تمت إضافة (بدون حذف أي شيء):</>');
        $this->command?->line('   • رحلات نشطة (in_progress) ببث مواقع حية على مسارات حقيقية قائمة');
        $this->command?->line('   • 4 طلبات إنشاء حساب سائق جديد بانتظار المراجعة');
        $this->command?->line('   • طلبات تعديل بيانات/مركبة من سائقين معتمَدين (driver_profile_changes)');
        $this->command?->line('   • 20 حالة AI مختلفة (أنواع ومستويات خطورة متنوعة، سائقون مختلفون)');
        $this->command?->line('   • 40 شكوى مختلفة، كل واحدة مربوطة بسائق/ولي أمر/رحلة حقيقيين فعلاً');
        $this->command?->newLine();
    }
}
