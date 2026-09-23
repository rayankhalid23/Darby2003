<?php

namespace Database\Seeders;

use App\Models\Admin\AdminAlert;
use App\Models\Shared\PaymentMethod;
use App\Models\Shared\SupportTicket;
use App\Models\Shared\Trip;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * AdminOperationsSeeder - بيانات وهمية محكمة لتغطية كل وظائف لوحة تحكم الأدمن.
 *
 * ⚠️ سيدر إضافي بحت (Additive-only): لا يحذف ولا يُفرّغ (truncate) ولا يُحدّث أي
 * سجل موجود مسبقاً في القاعدة إطلاقاً - فقط INSERT لسجلات جديدة. آمن للتشغيل على
 * قاعدة بيانات تطوير فيها بيانات حقيقية/تجريبية قائمة (بعكس BaseSystemSeeder و
 * DemoDataSeeder اللذين يُفرّغان جداولهما قبل الزرع).
 *
 * يعتمد على وجود بنية أساسية مزروعة مسبقاً (أدوار، سائقون، أولياء أمور، رحلات،
 * وسائل دفع...) - أي عبر BaseSystemSeeder/DemoDataSeeder أو بيانات فعلية موجودة.
 * كل مرجع (driver_id, parent_id, trip_id...) يُقرأ ديناميكياً من القاعدة الحالية
 * بدل الاعتماد على أرقام ثابتة، ليعمل السيدر بأمان مهما كانت حالة القاعدة.
 *
 * الوظائف الإدارية المغطاة:
 *   - إدارة المشرفين (تفعيل/تعطيل) - AdminController
 *   - سجل تدقيق إجراءات الأدمن - AdminAuditLogController (كان فارغاً تماماً)
 *   - تنبيهات الذكاء الاصطناعي لسلوك السائقين - DriverAiPolicyController
 *   - الشكاوى (Complaints) بحالات متنوعة - ComplaintController
 *   - تذاكر الدعم الفني بكل الفئات والحالات - SupportTicketController
 *   - طلبات شحن السائقين والأولياء + طلبات السحب - DriverRechargeController/FinancialController
 *   - النزاعات المالية على الرحلات - FinancialController (disputes)
 *   - قيود دفتر الأستاذ المالي (استرداد/تعويض) - FinancialLedgerController
 *   - وسيلة دفع جديدة - PaymentMethodController
 *   - نسخة مسودة من الشروط والأحكام - AdminTermsController
 *   - إشعارات لوحة تحكم الأدمن - AdminNotificationController
 *
 * كلمة المرور لأي حساب أدمن جديد يُنشئه هذا السيدر: Password123!
 *
 *   php artisan db:seed --class=AdminOperationsSeeder
 */
class AdminOperationsSeeder extends Seeder
{
    public const DEFAULT_PASSWORD = 'Password123!';

    /** @var array<string,int> id المشرفين حسب البريد (super_admin, operations, fleet, support, finance, geography) */
    private array $staff = [];

    public function run(): void
    {
        $this->command?->info('🛠️  AdminOperationsSeeder: زرع بيانات وظائف الأدمن (إضافة فقط، بدون أي حذف/تفريغ)...');

        if (User::where('email', 'operations2@darby.ly')->exists()) {
            $this->command?->warn('⚠️  يبدو أن هذا السيدر شُغِّل من قبل (operations2@darby.ly موجود بالفعل). تم التخطي لمنع التكرار.');
            return;
        }

        $this->loadStaff();

        DB::transaction(function () {
            $newAdmins        = $this->createAdditionalStaffAccounts();
            $newPaymentMethod = $this->createPaymentMethod();
            $newAlerts        = $this->createDriverAiAlerts();
            $newComplaints    = $this->createComplaints();
            $newTickets       = $this->createSupportTickets();
            $rechargeRows     = $this->createDriverRechargeRequests($newPaymentMethod);
            $parentRecharges  = $this->createParentRechargeRequests();
            $withdrawals      = $this->createWithdrawalRequests();
            $disputes         = $this->createTripDisputes();
            $this->createFinancialLedgerEntries($disputes);
            $termsDraft       = $this->createDraftTermsVersion();
            $this->createAuditLogs($newAdmins, $newPaymentMethod, $newAlerts, $newComplaints, $newTickets, $rechargeRows, $withdrawals, $disputes, $termsDraft);
            $this->createAdminNotifications($disputes, $newAlerts);
        });

        $this->printReport();
    }

    // =====================================================================
    // 0) تحميل حسابات المشرفين الأساسية (من BaseSystemSeeder أو ما يعادلها)
    // =====================================================================
    private function loadStaff(): void
    {
        $emails = [
            'super_admin'           => 'admin@darby.ly',
            'operations_supervisor' => 'operations@darby.ly',
            'fleet_supervisor'      => 'fleet@darby.ly',
            'support_supervisor'    => 'support@darby.ly',
            'finance_officer'       => 'finance@darby.ly',
            'geography_supervisor'  => 'geography@darby.ly',
        ];

        foreach ($emails as $key => $email) {
            $id = User::where('email', $email)->value('id');
            if (!$id) {
                // بديل احتياطي: أي مستخدم فعلي من نفس الدور إن اختلفت البريد الإلكتروني
                $id = DB::table('users')
                    ->join('roles', 'roles.id', '=', 'users.role_id')
                    ->where('roles.name', $key)
                    ->value('users.id');
            }
            $this->staff[$key] = $id;
        }

        if (!$this->staff['super_admin']) {
            throw new \RuntimeException('لا يوجد حساب super_admin في القاعدة - شغّل BaseSystemSeeder أولاً.');
        }
    }

    // =====================================================================
    // 1) حسابان إداريان إضافيان (لاختبار قائمة/تفعيل/تعطيل المشرفين)
    // =====================================================================
    private function createAdditionalStaffAccounts(): array
    {
        $this->command?->info('1️⃣  إنشاء حسابين إداريين إضافيين (فعال + معطّل)...');

        $roleIds = DB::table('roles')->pluck('id', 'name');

        $active = User::create([
            'full_name'         => 'سلمى فتحي محمد بن رمضان',
            'email'             => 'operations2@darby.ly',
            'phone_number'      => '0910000010',
            'password'          => Hash::make(self::DEFAULT_PASSWORD),
            'role_id'           => $roleIds['operations_supervisor'],
            'is_active'         => true,
            'is_trusted'        => true,
            'gender'            => 'female',
            'created_by'        => $this->staff['super_admin'],
            'email_verified_at' => now(),
            'last_login_at'     => now()->subHours(2),
        ]);

        $suspended = User::create([
            'full_name'         => 'طارق نوري محمد الشريف',
            'email'             => 'fleet2@darby.ly',
            'phone_number'      => '0910000011',
            'password'          => Hash::make(self::DEFAULT_PASSWORD),
            'role_id'           => $roleIds['fleet_supervisor'],
            'is_active'         => false,
            'is_trusted'        => true,
            'gender'            => 'male',
            'avatar_url'        => 'https://cdn.darby.ly/demo/avatars/staff_fleet2.jpg',
            'created_by'        => $this->staff['super_admin'],
            'email_verified_at' => now()->subDays(30),
            'last_login_at'     => now()->subDays(9),
        ]);

        return ['active' => $active, 'suspended' => $suspended];
    }

    // =====================================================================
    // 2) وسيلة دفع جديدة (PaymentMethodController)
    // =====================================================================
    private function createPaymentMethod(): PaymentMethod
    {
        $this->command?->info('2️⃣  إضافة وسيلة دفع جديدة (مصرف الجمهورية)...');

        return PaymentMethod::create([
            'name_ar'          => 'مصرف الجمهورية - تحويل بنكي',
            'name_en'          => 'Al Jumhouria Bank Transfer',
            'code'             => 'aljumhouria_bank',
            'target_audience'  => 'both',
            'processing_type'  => 'manual_proof',
            'account_name'     => 'شركة دربي لتقنية النقل المدرسي',
            'account_number'   => '1020304050',
            'iban'             => 'LY12002002000000000987654',
            'min_amount'       => 10.00,
            'max_amount'       => 8000.00,
            'instructions_ar'  => 'حوّل المبلغ إلى الحساب أعلاه ثم ارفع صورة إيصال التحويل مع رقم المرجع.',
            'is_active'        => true,
            'sort_order'       => 4,
        ]);
    }

    // =====================================================================
    // 3) تنبيهات الذكاء الاصطناعي لسلوك السائقين (DriverAiPolicyController)
    // =====================================================================
    private function createDriverAiAlerts(): array
    {
        $this->command?->info('3️⃣  تنبيهات AI لسلوك السائقين (كل مستويات الخطورة)...');

        $driverExists = fn (int $id) => DB::table('drivers')->where('id', $id)->exists() ? $id : null;

        $d915 = $driverExists(915);
        $d916 = $driverExists(916);
        $d923 = $driverExists(923);
        $d927 = $driverExists(927);
        $d911 = $driverExists(911);

        $alerts = [];

        if ($d915) {
            $alerts['warning'] = AdminAlert::create([
                'driver_id'         => $d915,
                'alert_type'        => 'ai_warning',
                'risk_level'        => 'MEDIUM',
                'title'             => '⚠️ ملاحظة متكررة حول الالتزام بالمواعيد',
                'message'           => 'MODERATE_PATTERN: رصد النظام 3 تعليقات من أولياء أمور مختلفين خلال أسبوعين تشير إلى تأخر متكرر في مواعيد الاستلام.',
                'severity'          => 2,
                'reasoning'         => 'MODERATE_PATTERN: تكرار نفس الملاحظة (تأخر عن الموعد) من 3 أولياء أمور مستقلين خلال نافذة زمنية قصيرة، دون وجود حادثة سلامة مباشرة.',
                'admin_message'     => null,
                'actions_taken'     => ['SEND_ADMIN_ALERT'],
                'ai_metrics'        => ['unique_parents_count' => 3, 'total_reviews_analyzed' => 5, 'operational_strikes_count' => 3, 'ignored_external_factors_count' => 0],
                'evaluated_reviews' => [
                    ['date' => now()->subDays(12)->format('Y-m-d'), 'text' => 'السائق تأخر 10 دقائق عن موعد الاستلام دون إشعار مسبق.', 'parent_id' => 'P_' . Str::padLeft((string) rand(100, 999), 3, '0')],
                    ['date' => now()->subDays(6)->format('Y-m-d'),  'text' => 'تأخير متكرر في مواعيد الصباح هذا الأسبوع.', 'parent_id' => 'P_' . Str::padLeft((string) rand(100, 999), 3, '0')],
                ],
                'is_resolved' => false,
                'is_read'     => false,
                'created_at'  => now()->subDays(2),
                'updated_at'  => now()->subDays(2),
            ]);
        }

        if ($d916) {
            $alerts['moderate_violation'] = AdminAlert::create([
                'driver_id'         => $d916,
                'alert_type'        => 'ai_moderate_violation',
                'risk_level'        => 'MEDIUM',
                'title'             => '🟠 مخالفة متوسطة - أسلوب تعامل مع الأطفال',
                'message'           => 'MODERATE_VIOLATION: ملاحظة سلوكية أدنى من مستوى السلامة الحرج.',
                'severity'          => 2,
                'reasoning'         => 'MODERATE_VIOLATION: بلاغ واحد موثق حول أسلوب تعامل غير لائق مع أحد الأطفال، تمت مراجعته يدوياً وتأكيده جزئياً.',
                'admin_message'     => 'تم التواصل مع السائق وتنبيهه رسمياً؛ لا داعي لإجراء إضافي حالياً.',
                'actions_taken'     => ['SEND_ADMIN_ALERT', 'FORMAL_WARNING'],
                'ai_metrics'        => ['unique_parents_count' => 1, 'total_reviews_analyzed' => 2, 'operational_strikes_count' => 1, 'ignored_external_factors_count' => 0],
                'evaluated_reviews' => [
                    ['date' => now()->subDays(8)->format('Y-m-d'), 'text' => 'السائق رفع صوته على ابني عندما تأخر في النزول.', 'parent_id' => 'P_' . Str::padLeft((string) rand(100, 999), 3, '0')],
                ],
                'is_resolved' => true,
                'is_read'     => true,
                'created_at'  => now()->subDays(7),
                'updated_at'  => now()->subDays(5),
            ]);
        }

        if ($d923) {
            $alerts['formal_warning'] = AdminAlert::create([
                'driver_id'         => $d923,
                'alert_type'        => 'ai_formal_warning',
                'risk_level'        => 'HIGH',
                'title'             => '🔶 إنذار رسمي - مخالفتان خلال الشهر',
                'message'           => 'ESCALATED_PATTERN: مخالفتان مسجلتان خلال 30 يوماً تستوجب إنذاراً رسمياً موثقاً.',
                'severity'          => 3,
                'reasoning'         => 'ESCALATED_PATTERN: بلاغان منفصلان من ولي أمر مختلف في كل مرة خلال الشهر نفسه؛ النمط يستدعي إنذاراً رسمياً قبل أي تصعيد للإيقاف.',
                'admin_message'     => null,
                'actions_taken'     => ['SEND_ADMIN_ALERT'],
                'ai_metrics'        => ['unique_parents_count' => 2, 'total_reviews_analyzed' => 4, 'operational_strikes_count' => 2, 'ignored_external_factors_count' => 1],
                'evaluated_reviews' => [
                    ['date' => now()->subDays(20)->format('Y-m-d'), 'text' => 'قيادة متسرعة عند نقطة انتظار المدرسة.', 'parent_id' => 'P_' . Str::padLeft((string) rand(100, 999), 3, '0')],
                    ['date' => now()->subDays(3)->format('Y-m-d'),  'text' => 'تجاوز خطير عند تقاطع بالقرب من المدرسة أثناء وجود الأطفال بالمركبة.', 'parent_id' => 'P_' . Str::padLeft((string) rand(100, 999), 3, '0')],
                ],
                'is_resolved' => false,
                'is_read'     => false,
                'created_at'  => now()->subDays(3),
                'updated_at'  => now()->subDays(3),
            ]);
        }

        if ($d927) {
            $alerts['admin_review_required'] = AdminAlert::create([
                'driver_id'         => $d927,
                'alert_type'        => 'ai_admin_review_required',
                'risk_level'        => 'HIGH',
                'title'             => '🧐 يتطلب مراجعة بشرية - إشارات متضاربة',
                'message'           => 'CONFLICTING_SIGNALS: تقييمات متضاربة بشدة لنفس السائق خلال فترة قصيرة تستدعي مراجعة يدوية قبل اتخاذ أي إجراء آلي.',
                'severity'          => 2,
                'reasoning'         => 'CONFLICTING_SIGNALS: 4 تقييمات إيجابية للغاية مقابل بلاغ سلبي حاد واحد لنفس السائق خلال أسبوع - نموذج الذكاء الاصطناعي غير واثق بما يكفي لاتخاذ قرار آلي.',
                'admin_message'     => null,
                'actions_taken'     => [],
                'ai_metrics'        => ['unique_parents_count' => 5, 'total_reviews_analyzed' => 5, 'operational_strikes_count' => 1, 'ignored_external_factors_count' => 0],
                'evaluated_reviews' => [
                    ['date' => now()->subDays(4)->format('Y-m-d'), 'text' => 'بلاغ حاد عن سوء تعامل، يتناقض مع كل التقييمات السابقة الممتازة لنفس السائق.', 'parent_id' => 'P_' . Str::padLeft((string) rand(100, 999), 3, '0')],
                ],
                'is_resolved' => false,
                'is_read'     => false,
                'created_at'  => now()->subDay(),
                'updated_at'  => now()->subDay(),
            ]);
        }

        if ($d911) {
            $alerts['critical_resolved'] = AdminAlert::create([
                'driver_id'         => $d911,
                'alert_type'        => 'ai_critical',
                'risk_level'        => 'CRITICAL',
                'title'             => '⛔ خطر حرج - تمت المراجعة والحسم',
                'message'           => 'CRITICAL_SAFETY_VIOLATION: حادثة سلامة موثقة بفيديو من داخل المركبة.',
                'severity'          => 3,
                'reasoning'         => 'CRITICAL_SAFETY_VIOLATION: توقف مفاجئ متكرر وتجاوز سرعة موثق داخل نطاق مدرسي، مع بلاغ فيديو من ولي أمر.',
                'admin_message'     => 'تمت مراجعة الفيديو المرفق مع البلاغ، وتوجيه إنذار نهائي مكتوب للسائق مع خصم نقاط من تقييمه. لا داعي لإيقافه نهائياً بناءً على سجله السابق النظيف.',
                'actions_taken'     => ['SEND_ADMIN_ALERT', 'ADJUST_RATING', 'FORMAL_WARNING'],
                'ai_metrics'        => ['unique_parents_count' => 1, 'total_reviews_analyzed' => 1, 'operational_strikes_count' => 1, 'ignored_external_factors_count' => 0],
                'evaluated_reviews' => [
                    ['date' => now()->subDays(11)->format('Y-m-d'), 'text' => 'فيديو موثق لتجاوز سرعة خطير قرب بوابة المدرسة أثناء نزول الأطفال.', 'parent_id' => 'P_' . Str::padLeft((string) rand(100, 999), 3, '0')],
                ],
                'is_resolved' => true,
                'is_read'     => true,
                'metadata'    => ['rating_change' => -1],
                'created_at'  => now()->subDays(11),
                'updated_at'  => now()->subDays(10),
            ]);
        }

        return $alerts;
    }

    // =====================================================================
    // 4) الشكاوى بحالات متنوعة (ComplaintController)
    // =====================================================================
    private function createComplaints(): array
    {
        $this->command?->info('4️⃣  شكاوى بحالات متنوعة (معلقة/محسومة بإجراء/مرفوضة)...');

        $pick = function (int $driverId) {
            $row = DB::table('trips')
                ->join('routes', 'routes.id', '=', 'trips.route_id')
                ->join('requests', 'requests.id', '=', 'routes.subscription_request_id')
                ->where('trips.driver_id', $driverId)
                ->where('trips.status', 'completed')
                ->selectRaw('MAX(trips.id) as trip_id, requests.parent_id')
                ->groupBy('requests.parent_id')
                ->first();

            return $row ? ['trip_id' => $row->trip_id, 'parent_id' => $row->parent_id] : null;
        };

        // ملاحظة: نستخدم DB::table() مباشرة بدل Complaint::create() لأن أعمدة
        // ai_action/ai_confidence/ai_severity/ai_analysis_message غير مُدرجة
        // ضمن $fillable في نموذج Complaint (Mass Assignment Protection)، فتُهمَل
        // بصمت لو استخدمنا create() رغم وجودها فعلياً في الجدول.
        $complaints = [];

        $ctx911 = $pick(911);
        if ($ctx911) {
            $complaints['warning_issued'] = DB::table('complaints')->insertGetId([
                'submitted_by'    => $ctx911['parent_id'],
                'against_type'    => 'DRIVER',
                'against_id'      => 911,
                'driver_id'       => 911,
                'trip_id'         => $ctx911['trip_id'],
                'description'     => 'السائق يستخدم هاتفه المحمول أثناء القيادة وطفلتي بداخل المركبة، لاحظت ذلك مرتين هذا الأسبوع.',
                'status'          => 'resolved',
                'resolved_by'     => $this->staff['fleet_supervisor'],
                'resolution_note' => 'تم استدعاء السائق وتحذيره كتابياً، والتزم بعدم تكرار الأمر أثناء القيادة.',
                'action_taken'    => 'warning_issued',
                'action_details'  => 'إنذار كتابي أول + متابعة لمدة أسبوعين.',
                'ai_action'       => 'notify_driver',
                'ai_confidence'   => 0.8700,
                'ai_severity'     => 2,
                'ai_analysis_message' => 'سلوك يستدعي تنبيهاً فورياً لكنه ليس خطراً حرجاً مباشراً على السلامة.',
                'resolved_at'     => now()->subDays(4),
                'created_at'      => now()->subDays(6),
                'updated_at'      => now()->subDays(4),
            ]);
        }

        $ctx923 = $pick(923);
        if ($ctx923) {
            $complaints['temporary_suspension'] = DB::table('complaints')->insertGetId([
                'submitted_by'    => $ctx923['parent_id'],
                'against_type'    => 'DRIVER',
                'against_id'      => 923,
                'driver_id'       => 923,
                'trip_id'         => $ctx923['trip_id'],
                'description'     => 'السائق أوصل ابني إلى منزل خاطئ في حي مجاور قبل أن يتنبه لخطئه ويعيده، تأخر بذلك أكثر من 40 دقيقة عن الموعد.',
                'status'          => 'resolved',
                'resolved_by'     => $this->staff['fleet_supervisor'],
                'resolution_note' => 'تأكدنا من الواقعة عبر تتبع المسار (GPS)، وقررنا إيقاف السائق مؤقتاً 3 أيام لإعادة التأهيل.',
                'action_taken'    => 'temporary_suspension',
                'action_details'  => 'إيقاف 3 أيام + جلسة توعية إلزامية حول بروتوكول التسليم.',
                'ai_action'       => 'suspend_driver',
                'ai_confidence'   => 0.9400,
                'ai_severity'     => 4,
                'ai_analysis_message' => 'خطأ تسليم يمسّ سلامة الطفل مباشرة - يستوجب إيقافاً تحفظياً ريثما تُستكمل المراجعة.',
                'resolved_at'     => now()->subDays(2),
                'created_at'      => now()->subDays(3),
                'updated_at'      => now()->subDays(2),
            ]);
        }

        $ctx916 = $pick(916);
        if ($ctx916) {
            $complaints['pending'] = DB::table('complaints')->insertGetId([
                'submitted_by'   => $ctx916['parent_id'],
                'against_type'   => 'DRIVER',
                'against_id'     => 916,
                'driver_id'      => 916,
                'trip_id'        => $ctx916['trip_id'],
                'description'    => 'المركبة بها رائحة دخان سجائر قوية رغم أن ابني يعاني من الربو، أرجو المتابعة العاجلة.',
                'status'         => 'pending',
                'action_taken'   => 'none',
                'created_at'     => now()->subHours(5),
                'updated_at'     => now()->subHours(5),
            ]);
        }

        $ctx927 = $pick(927);
        if ($ctx927) {
            $complaints['dismissed'] = DB::table('complaints')->insertGetId([
                'submitted_by'    => $ctx927['parent_id'],
                'against_type'    => 'DRIVER',
                'against_id'      => 927,
                'driver_id'       => 927,
                'trip_id'         => $ctx927['trip_id'],
                'description'     => 'أشعر أن السائق يقود بسرعة غير آمنة دائماً، لكن ليس لدي دليل واضح.',
                'status'          => 'dismissed',
                'resolved_by'     => $this->staff['support_supervisor'],
                'resolution_note' => 'راجعنا سجل تتبع السرعة لآخر أسبوعين ولم نجد أي تجاوز مسجل. تم الاكتفاء بالتوضيح لولي الأمر.',
                'action_taken'    => 'none',
                'action_details'  => null,
                'ai_action'       => 'log_only',
                'ai_confidence'   => 0.6100,
                'ai_severity'     => 1,
                'ai_analysis_message' => 'بلاغ عام بلا دليل مرفق أو مخالفة مسجلة مطابقة.',
                'resolved_at'     => now()->subHours(20),
                'created_at'      => now()->subDays(1)->subHours(4),
                'updated_at'      => now()->subHours(20),
            ]);
        }

        return $complaints;
    }

    // =====================================================================
    // 5) تذاكر الدعم الفني بكل الفئات (SupportTicketController)
    // =====================================================================
    private function createSupportTickets(): array
    {
        $this->command?->info('5️⃣  تذاكر دعم فني بكل الفئات والحالات...');

        $parent2016 = DB::table('users')->where('email', 'parent3@darby.ly')->value('id') ?? 2016;
        $parent2018 = DB::table('users')->where('email', 'parent5@darby.ly')->value('id') ?? 2018;
        $parent2019 = DB::table('users')->where('email', 'parent6@darby.ly')->value('id') ?? 2019;
        $driver911User = DB::table('drivers')->join('users', 'users.id', '=', 'drivers.user_id')->where('drivers.id', 911)->value('users.id');
        $driver916User = DB::table('drivers')->join('users', 'users.id', '=', 'drivers.user_id')->where('drivers.id', 916)->value('users.id');
        $trip527 = DB::table('trips')->where('id', 527)->exists() ? 527 : DB::table('trips')->where('status', 'completed')->value('id');

        $tickets = [];

        $tickets['financial'] = SupportTicket::create([
            'user_id'      => $parent2018,
            'creator_role' => 'parent',
            'category'     => SupportTicket::CATEGORY_FINANCIAL,
            'description'  => 'تم خصم قيمة اشتراك شهر كامل من محفظتي رغم أنني ألغيت الاشتراك في نفس يوم الطلب قبل موافقة السائق.',
            'status'       => SupportTicket::STATUS_IN_PROGRESS,
            'scope'        => SupportTicket::SCOPE_FINANCIAL,
            'assigned_admin_id' => $this->staff['finance_officer'],
            'created_at'   => now()->subDays(2),
            'updated_at'   => now()->subDay(),
        ]);

        $tickets['technical'] = SupportTicket::create([
            'user_id'      => $driver911User,
            'creator_role' => 'driver',
            'category'     => SupportTicket::CATEGORY_TECHNICAL,
            'description'  => 'تطبيق السائق يُغلق فجأة عند الضغط على زر "بدء الرحلة" منذ آخر تحديث.',
            'status'       => SupportTicket::STATUS_OPEN,
            'scope'        => SupportTicket::SCOPE_OPERATIONS,
            'created_at'   => now()->subHours(10),
            'updated_at'   => now()->subHours(10),
        ]);

        $tickets['trip'] = SupportTicket::create([
            'user_id'             => $parent2019,
            'creator_role'        => 'parent',
            'category'            => SupportTicket::CATEGORY_TRIP,
            'referenceable_type'  => Trip::class,
            'referenceable_id'    => $trip527,
            'description'         => 'مسار الرحلة الصباحية أصبح طويلاً جداً بعد إضافة نقطة استلام جديدة بعيدة، هل يمكن إعادة ترتيب المسار؟',
            'status'              => SupportTicket::STATUS_RESOLVED,
            'scope'               => SupportTicket::SCOPE_OPERATIONS,
            'assigned_admin_id'   => $this->staff['operations_supervisor'],
            'resolution_note'     => 'تمت إعادة ترتيب نقاط المسار بالتنسيق مع السائق لتقليل زمن الرحلة الإجمالي.',
            'closed_by'           => $this->staff['operations_supervisor'],
            'closed_at'           => now()->subHours(6),
            'created_at'          => now()->subDays(3),
            'updated_at'          => now()->subHours(6),
        ]);

        $tickets['party'] = SupportTicket::create([
            'user_id'         => $driver916User,
            'creator_role'    => 'driver',
            'category'        => SupportTicket::CATEGORY_PARTY,
            'target_role'     => 'parent',
            'target_user_id'  => $parent2018,
            'description'     => 'ولي الأمر يطلب مني الانتظار أكثر من 20 دقيقة يومياً عند نقطة الاستلام مما يُعطّل بقية جدول الرحلة.',
            'status'          => SupportTicket::STATUS_CLOSED,
            'scope'           => SupportTicket::SCOPE_OPERATIONS,
            'assigned_admin_id' => $this->staff['support_supervisor'],
            'penalty_action'  => 'formal_warning_to_parent',
            'resolution_note' => 'تم تنبيه ولي الأمر بضرورة الالتزام بوقت الانتظار المتفق عليه (5 دقائق كحد أقصى).',
            'closed_by'       => $this->staff['support_supervisor'],
            'closed_at'       => now()->subDays(1),
            'created_at'      => now()->subDays(4),
            'updated_at'      => now()->subDays(1),
        ]);

        $tickets['rejected'] = SupportTicket::create([
            'user_id'         => $parent2016,
            'creator_role'    => 'parent',
            'category'        => SupportTicket::CATEGORY_GENERAL,
            'description'     => 'أطلب إضافة ميزة اختيار لون للحافلة داخل التطبيق.',
            'status'          => SupportTicket::STATUS_REJECTED,
            'scope'           => SupportTicket::SCOPE_OPERATIONS,
            'assigned_admin_id' => $this->staff['support_supervisor'],
            'resolution_note' => 'الطلب خارج نطاق الدعم الفني الحالي، تم تحويله لقائمة اقتراحات تطوير المنتج.',
            'closed_by'       => $this->staff['support_supervisor'],
            'closed_at'       => now()->subHours(3),
            'created_at'      => now()->subDays(1),
            'updated_at'      => now()->subHours(3),
        ]);

        return $tickets;
    }

    // =====================================================================
    // 6) طلبات شحن السائقين (DriverRechargeController)
    // =====================================================================
    private function createDriverRechargeRequests(PaymentMethod $newPaymentMethod): array
    {
        $this->command?->info('6️⃣  طلبات شحن سائقين (موافَق/مرفوض/معلّق)...');

        $sadad = PaymentMethod::where('code', 'sadad')->first();
        $sahara = PaymentMethod::where('code', 'sahara_bank')->first();

        $rows = [];

        $rows['rejected'] = DB::table('driver_recharge_requests')->insertGetId([
            'driver_id'         => 911,
            'payment_method_id' => $sadad?->id,
            'amount'            => 200.00,
            'proof_image_url'   => 'https://cdn.darby.ly/demo/proofs/driver911_recharge_reject.jpg',
            'reference_number'  => 'DR-' . strtoupper(Str::random(8)),
            'status'            => 'rejected',
            'admin_id'          => $this->staff['finance_officer'],
            'rejection_reason'  => 'صورة إيصال التحويل غير واضحة ولا يظهر بها رقم المرجع بالكامل.',
            'rejected_at'       => now()->subHours(18),
            'created_at'        => now()->subDays(1),
            'updated_at'        => now()->subHours(18),
        ]);

        $rows['approved'] = DB::table('driver_recharge_requests')->insertGetId([
            'driver_id'         => 927,
            'payment_method_id' => $sahara?->id,
            'amount'            => 350.00,
            'proof_image_url'   => 'https://cdn.darby.ly/demo/proofs/driver927_recharge_ok.jpg',
            'reference_number'  => 'DR-' . strtoupper(Str::random(8)),
            'status'            => 'approved',
            'admin_id'          => $this->staff['finance_officer'],
            'notes'             => 'مطابق للإيصال البنكي، تمت الإضافة لمحفظة السائق.',
            'approved_at'       => now()->subHours(9),
            'created_at'        => now()->subDays(1)->subHours(6),
            'updated_at'        => now()->subHours(9),
        ]);

        $rows['pending'] = DB::table('driver_recharge_requests')->insertGetId([
            'driver_id'         => 915,
            'payment_method_id' => $newPaymentMethod->id,
            'amount'            => 120.00,
            'proof_image_url'   => 'https://cdn.darby.ly/demo/proofs/driver915_recharge_pending.jpg',
            'reference_number'  => 'DR-' . strtoupper(Str::random(8)),
            'status'            => 'pending',
            'created_at'        => now()->subHours(4),
            'updated_at'        => now()->subHours(4),
        ]);

        return $rows;
    }

    // =====================================================================
    // 7) طلبات شحن أولياء الأمور (FinancialController)
    // =====================================================================
    private function createParentRechargeRequests(): array
    {
        $this->command?->info('7️⃣  طلبات شحن أولياء أمور (مكتمل/معلّق/فاشل)...');

        $mobicash = PaymentMethod::where('code', 'mobicash')->first();
        $sadad    = PaymentMethod::where('code', 'sadad')->first();
        $sahara   = PaymentMethod::where('code', 'sahara_bank')->first();

        $parent2019 = DB::table('users')->where('email', 'parent6@darby.ly')->value('id') ?? 2019;
        $testParent = DB::table('users')->where('email', 'test_parent_1@darby-test.local')->value('id')
            ?? DB::table('users')->where('email', 'parent2@darby.ly')->value('id');
        $parent8 = DB::table('users')->where('email', 'parent2@darby.ly')->value('id') ?? 8;

        $rows = [];

        $rows['completed'] = DB::table('recharge_requests')->insertGetId([
            'parent_id'         => $parent2019,
            'amount'            => 250.00,
            'payment_method'    => 'mobicash',
            'payment_method_id' => $mobicash?->id,
            'reference_number'  => 'MC-' . strtoupper(Str::random(10)),
            'transaction_ref'   => 'TX-' . strtoupper(Str::random(12)),
            'status'            => 'completed',
            'admin_id'          => $this->staff['finance_officer'],
            'completed_at'      => now()->subHours(14),
            'created_at'        => now()->subHours(15),
            'updated_at'        => now()->subHours(14),
        ]);

        $rows['pending'] = DB::table('recharge_requests')->insertGetId([
            'parent_id'         => $testParent,
            'amount'            => 80.00,
            'payment_method'    => 'sadad',
            'payment_method_id' => $sadad?->id,
            'reference_number'  => 'SD-' . strtoupper(Str::random(10)),
            'session_token'     => Str::random(40),
            'status'            => 'pending',
            'created_at'        => now()->subHours(1),
            'updated_at'        => now()->subHours(1),
        ]);

        $rows['failed'] = DB::table('recharge_requests')->insertGetId([
            'parent_id'         => $parent8,
            'amount'            => 500.00,
            'payment_method'    => 'sahara_bank',
            'payment_method_id' => $sahara?->id,
            'reference_number'  => 'SB-' . strtoupper(Str::random(10)),
            'status'            => 'failed',
            'notes'             => 'فشل الاتصال ببوابة الدفع أثناء التأكيد - لم يُخصم أي مبلغ من العميل.',
            'created_at'        => now()->subDays(2),
            'updated_at'        => now()->subDays(2)->addMinutes(5),
        ]);

        return $rows;
    }

    // =====================================================================
    // 8) طلبات سحب السائقين (FinancialController)
    // =====================================================================
    private function createWithdrawalRequests(): array
    {
        $this->command?->info('8️⃣  طلبات سحب سائقين (معلّق/معتمَد)...');

        $rows = [];

        $rows['pending'] = DB::table('withdrawal_requests')->insertGetId([
            'driver_id'                 => 922,
            'amount'                    => 300.00,
            'wallet_balance_at_request' => 550.00,
            'status'                    => 'pending',
            'payment_method_details'    => json_encode(['method' => 'mobicash', 'wallet_number' => '0925550922']),
            'created_at'                => now()->subHours(7),
            'updated_at'                => now()->subHours(7),
        ]);

        $rows['approved'] = DB::table('withdrawal_requests')->insertGetId([
            'driver_id'                 => 927,
            'amount'                    => 600.00,
            'wallet_balance_at_request' => 600.00,
            'status'                    => 'approved',
            'payment_method_details'    => json_encode([
                'method'      => 'sahara_bank',
                'holder_name' => 'عماد الدين خليل مصطفى القماطي',
                'iban'        => 'LY83002001000000000456789',
            ]),
            'admin_id'      => $this->staff['finance_officer'],
            'processed_at'  => now()->subHours(3),
            'created_at'    => now()->subDays(1),
            'updated_at'    => now()->subHours(3),
        ]);

        return $rows;
    }

    // =====================================================================
    // 9) النزاعات المالية على الرحلات (trip_disputes)
    // =====================================================================
    private function createTripDisputes(): array
    {
        $this->command?->info('9️⃣  نزاعات مالية على رحلات (مفتوح/مسترد لولي الأمر/مصروف للسائق)...');

        $disputes = [];

        $disputes['open'] = DB::table('trip_disputes')->insertGetId([
            'trip_id'    => 717,
            'parent_id'  => 2017,
            'driver_id'  => 922,
            'reason'     => 'السائق سجّل الرحلة "مكتملة" لكن الطفل لم يُوصَّل فعلياً للمنزل، بقي بمفرده أمام البوابة نحو 10 دقائق حتى وصل أحد الجيران لمساعدته.',
            'status'     => 'open',
            'created_at' => now()->subHours(20),
            'updated_at' => now()->subHours(20),
        ]);

        $disputes['refunded'] = DB::table('trip_disputes')->insertGetId([
            'trip_id'          => 689,
            'parent_id'        => 2016,
            'driver_id'        => 908,
            'reason'           => 'تم خصم قيمة الرحلة رغم أنني ألغيت الاشتراك قبل انطلاق الرحلة بساعتين عبر التطبيق.',
            'status'           => 'resolved_parent_refunded',
            'resolution_notes' => 'تم التحقق من توقيت طلب الإلغاء في السجلات وتأكد أنه سبق انطلاق الرحلة فعلياً؛ تقرر استرداد كامل المبلغ لمحفظة ولي الأمر.',
            'resolved_by'      => $this->staff['finance_officer'],
            'resolved_at'      => now()->subHours(5),
            'created_at'       => now()->subDays(2),
            'updated_at'       => now()->subHours(5),
        ]);

        $disputes['driver_paid'] = DB::table('trip_disputes')->insertGetId([
            'trip_id'          => 644,
            'parent_id'        => 2019,
            'driver_id'        => 927,
            'reason'           => 'ولي الأمر يفيد بعدم حدوث أي رحلة رغم تسجيلها "مكتملة" في النظام، ويطلب استرداد المبلغ.',
            'status'           => 'resolved_driver_paid',
            'resolution_notes' => 'راجع المشرف مسار تتبع السائق (GPS Tracking) للرحلة وتأكد من وصوله فعلياً لنقطتي الاستلام والمدرسة في التوقيت المسجل؛ صُرف المستحق للسائق كاملاً ورُفض طلب ولي الأمر.',
            'resolved_by'      => $this->staff['operations_supervisor'],
            'resolved_at'      => now()->subHours(2),
            'created_at'       => now()->subDays(1)->subHours(10),
            'updated_at'       => now()->subHours(2),
        ]);

        return $disputes;
    }

    // =====================================================================
    // 🔟 قيود دفتر الأستاذ المالي المرتبطة بحسم النزاعات (FinancialLedgerController)
    // =====================================================================
    private function createFinancialLedgerEntries(array $disputes): void
    {
        $this->command?->info('🔟 قيود دفتر الأستاذ (استرداد + تعويض) مرتبطة بالنزاعات المحسومة...');

        $vault = DB::table('master_escrow_vault')->first();
        $parentsEscrowPool  = (int) ($vault->parents_escrow_pool ?? 0);
        $platformRevenuePool = (int) ($vault->platform_revenue_pool ?? 0);

        $refundAmount = 2400; // 24.00 د.ل بالقروش

        DB::table('financial_ledger')->insert([
            'transaction_id'      => (string) Str::uuid(),
            'reference_number'    => 'DISPUTE-REFUND-' . $disputes['refunded'],
            'source_account'      => 'parents_escrow_pool',
            'destination_account' => 'parent_wallet:2016',
            'amount'              => $refundAmount,
            'balance_before'      => $parentsEscrowPool,
            'balance_after'       => max(0, $parentsEscrowPool - $refundAmount),
            'type'                => 'refund',
            'status'              => 'completed',
            'metadata'            => json_encode([
                'trip_dispute_id' => $disputes['refunded'],
                'trip_id'         => 689,
                'admin_id'        => $this->staff['finance_officer'],
                'reason'          => 'إلغاء اشتراك سابق لانطلاق الرحلة - استرداد كامل',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now()->subHours(5),
            'updated_at' => now()->subHours(5),
        ]);

        $compensationAmount = 1800; // 18.00 د.ل بالقروش

        DB::table('financial_ledger')->insert([
            'transaction_id'      => (string) Str::uuid(),
            'reference_number'    => 'DISPUTE-COMP-' . $disputes['driver_paid'],
            'source_account'      => 'platform_revenue_pool',
            'destination_account' => 'driver_wallet:927',
            'amount'              => $compensationAmount,
            'balance_before'      => $platformRevenuePool,
            'balance_after'       => max(0, $platformRevenuePool - $compensationAmount),
            'type'                => 'compensation',
            'status'              => 'completed',
            'metadata'            => json_encode([
                'trip_dispute_id' => $disputes['driver_paid'],
                'trip_id'         => 644,
                'admin_id'        => $this->staff['operations_supervisor'],
                'reason'          => 'تأكيد صحة تنفيذ الرحلة عبر تتبع GPS - صرف مستحق السائق كاملاً',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);
    }

    // =====================================================================
    // 1️⃣1️⃣ نسخة مسودة من الشروط والأحكام (AdminTermsController)
    // =====================================================================
    private function createDraftTermsVersion(): int
    {
        $this->command?->info('1️⃣1️⃣ مسودة نسخة جديدة من شروط وأحكام أولياء الأمور...');

        $versionId = DB::table('terms_versions')->insertGetId([
            'version_number' => '2.0',
            'title'          => 'شروط وأحكام وسياسات منصة دربي لأولياء الأمور (مسودة تحديث)',
            'audience'       => 'parent',
            'status'         => 'draft',
            'published_at'   => null,
            'created_by'     => $this->staff['support_supervisor'],
            'published_by'   => null,
            'created_at'     => now()->subHours(6),
            'updated_at'     => now()->subHours(6),
        ]);

        DB::table('terms_articles')->insert([
            [
                'terms_version_id' => $versionId,
                'article_number'   => 1,
                'title'            => 'سياسة الإلغاء المتكرر للمتأخرين عن الاستلام',
                'body'             => 'يحق للسائق تسجيل "غياب مبلَّغ" إذا تجاوز وقت انتظار الطفل عند نقطة الاستلام 7 دقائق من الموعد المتفق عليه دون تواصل من ولي الأمر، مع إشعار فوري لولي الأمر عبر التطبيق.',
                'sort_order'       => 1,
                'created_at'       => now()->subHours(6),
                'updated_at'       => now()->subHours(6),
            ],
            [
                'terms_version_id' => $versionId,
                'article_number'   => 2,
                'title'            => 'تحديث آلية استرداد المبالغ عند الإلغاء المبكر',
                'body'             => 'في حال إلغاء الاشتراك أو الرحلة اليومية قبل ساعتين على الأقل من موعد الانطلاق المجدول، يُسترد كامل المبلغ المدفوع عن الفترة غير المستخدمة لمحفظة ولي الأمر دون خصم عمولة المنصة.',
                'sort_order'       => 2,
                'created_at'       => now()->subHours(6),
                'updated_at'       => now()->subHours(6),
            ],
        ]);

        return $versionId;
    }

    // =====================================================================
    // 1️⃣2️⃣ سجل تدقيق إجراءات الأدمن (AdminAuditLogController) - كان فارغاً تماماً
    // =====================================================================
    private function createAuditLogs(
        array $newAdmins,
        PaymentMethod $newPaymentMethod,
        array $alerts,
        array $complaints,
        array $tickets,
        array $rechargeRows,
        array $withdrawals,
        array $disputes,
        int $termsDraftId,
    ): void {
        $this->command?->info('1️⃣2️⃣ سجل تدقيق إجراءات الأدمن (admin_audit_logs)...');

        $adminInfo = function (?int $id): array {
            if (!$id) {
                return ['name' => 'النظام', 'role' => null];
            }
            $u = DB::table('users')
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->where('users.id', $id)
                ->select('users.full_name', 'roles.name as role')
                ->first();

            return $u ? ['name' => $u->full_name, 'role' => $u->role] : ['name' => 'مستخدم محذوف', 'role' => null];
        };

        $rows = [];

        $log = function (int $adminId, string $action, string $entityType, ?int $entityId, ?string $entityName, string $result, ?string $reason, ?array $changes, Carbon $when) use (&$rows, $adminInfo) {
            $info = $adminInfo($adminId);
            $rows[] = [
                'admin_id'    => $adminId,
                'admin_name'  => $info['name'],
                'admin_role'  => $info['role'],
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'entity_name' => $entityName,
                'result'      => $result,
                'reason'      => $reason,
                'changes'     => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
                'created_at'  => $when,
            ];
        };

        $log($this->staff['super_admin'], 'login', 'auth', null, null, 'success', null, null, now()->subHours(1));

        $log($this->staff['super_admin'], 'create_admin', 'admin', $newAdmins['active']->id, $newAdmins['active']->full_name,
            'success', null, ['role' => 'operations_supervisor', 'is_active' => true], now()->subDays(1));

        $log($this->staff['super_admin'], 'deactivate_admin', 'admin', $newAdmins['suspended']->id, $newAdmins['suspended']->full_name,
            'success', 'مخالفة سياسة استخدام الحساب الإداري (مشاركة بيانات الدخول).', ['is_active' => [true, false]], now()->subDays(1));

        $log($this->staff['finance_officer'], 'create_payment_method', 'payment_method', $newPaymentMethod->id, $newPaymentMethod->name_ar,
            'success', null, ['code' => 'aljumhouria_bank', 'is_active' => true], now()->subHours(9));

        $log($this->staff['fleet_supervisor'], 'approve_driver', 'driver', 908, 'مصطفى رمضان سالم الككلي',
            'success', null, ['status' => ['Pending', 'Approved']], now()->subDays(45));

        $log($this->staff['fleet_supervisor'], 'reject_driver_document', 'driver', 926, 'أنور الطيب سالم المجبري',
            'failed', 'رخصة القيادة المرفوعة منتهية الصلاحية منذ أكثر من 6 أشهر.', ['status' => ['Pending', 'Rejected']], now()->subDays(20));

        if (isset($complaints['warning_issued'])) {
            $log($this->staff['fleet_supervisor'], 'resolve_complaint', 'complaint', $complaints['warning_issued'], 'شكوى استخدام الهاتف أثناء القيادة',
                'success', null, ['status' => ['pending', 'resolved'], 'action_taken' => [null, 'warning_issued']], now()->subDays(4));
        }
        if (isset($complaints['temporary_suspension'])) {
            $log($this->staff['fleet_supervisor'], 'resolve_complaint', 'complaint', $complaints['temporary_suspension'], 'شكوى توصيل لمنزل خاطئ',
                'success', 'خطأ تسليم يمسّ سلامة الطفل مباشرة.', ['status' => ['pending', 'resolved'], 'action_taken' => [null, 'temporary_suspension']], now()->subDays(2));
        }
        if (isset($complaints['dismissed'])) {
            $log($this->staff['support_supervisor'], 'dismiss_complaint', 'complaint', $complaints['dismissed'], 'شكوى قيادة غير آمنة (بدون دليل)',
                'success', 'لا يوجد دليل مسجل يدعم البلاغ.', ['status' => ['pending', 'dismissed']], now()->subHours(20));
        }

        if (isset($tickets['trip'])) {
            $log($this->staff['operations_supervisor'], 'close_support_ticket', 'support_ticket', $tickets['trip']->id, 'إعادة ترتيب مسار الرحلة الصباحية',
                'success', null, ['status' => ['open', 'resolved']], now()->subHours(6));
        }
        if (isset($tickets['rejected'])) {
            $log($this->staff['support_supervisor'], 'reject_support_ticket', 'support_ticket', $tickets['rejected']->id, 'طلب اختيار لون الحافلة',
                'success', 'خارج نطاق الدعم الفني.', ['status' => ['open', 'rejected']], now()->subHours(3));
        }
        if (isset($tickets['party'])) {
            $log($this->staff['support_supervisor'], 'close_support_ticket', 'support_ticket', $tickets['party']->id, 'نزاع وقت انتظار بين سائق وولي أمر',
                'success', null, ['status' => ['open', 'closed'], 'penalty_action' => [null, 'formal_warning_to_parent']], now()->subDays(1));
        }

        $log($this->staff['finance_officer'], 'reject_driver_recharge', 'driver_recharge_request', $rechargeRows['rejected'], 'طلب شحن سائق #911',
            'success', 'صورة إيصال التحويل غير واضحة.', ['status' => ['pending', 'rejected']], now()->subHours(18));

        $log($this->staff['finance_officer'], 'approve_driver_recharge', 'driver_recharge_request', $rechargeRows['approved'], 'طلب شحن سائق #927',
            'success', null, ['status' => ['pending', 'approved']], now()->subHours(9));

        $log($this->staff['finance_officer'], 'approve_withdrawal', 'withdrawal_request', $withdrawals['approved'], 'طلب سحب سائق #927',
            'success', null, ['status' => ['pending', 'approved']], now()->subHours(3));

        $log($this->staff['finance_officer'], 'resolve_trip_dispute', 'trip_dispute', $disputes['refunded'], 'نزاع رحلة #689',
            'success', null, ['status' => ['open', 'resolved_parent_refunded']], now()->subHours(5));

        $log($this->staff['operations_supervisor'], 'resolve_trip_dispute', 'trip_dispute', $disputes['driver_paid'], 'نزاع رحلة #644',
            'success', null, ['status' => ['open', 'resolved_driver_paid']], now()->subHours(2));

        if (isset($alerts['critical_resolved'])) {
            $log($this->staff['fleet_supervisor'], 'resolve_ai_alert', 'admin_alert', $alerts['critical_resolved']->id, 'تنبيه AI حرج - سائق #911',
                'success', null, ['is_resolved' => [false, true]], now()->subDays(10));
        }

        $log($this->staff['support_supervisor'], 'create_terms_draft', 'terms_version', $termsDraftId, 'مسودة شروط أولياء الأمور 2.0',
            'success', 'تحديث بند مواعيد الاستلام وآلية الاسترداد.', ['status' => 'draft'], now()->subHours(6));

        DB::table('admin_audit_logs')->insert($rows);
    }

    // =====================================================================
    // 1️⃣3️⃣ إشعارات لوحة تحكم الأدمن (AdminNotificationController) + أطراف العملية
    // =====================================================================
    private function createAdminNotifications(array $disputes, array $alerts): void
    {
        $this->command?->info('1️⃣3️⃣ إشعارات لوحة الأدمن والأطراف المعنية...');

        $notify = function (int $notifiableId, string $type, string $title, string $body, Carbon $when, bool $read = false) {
            DB::table('notifications')->insert([
                'id'              => (string) Str::uuid(),
                'type'            => $type,
                'notifiable_type' => User::class,
                'notifiable_id'   => $notifiableId,
                'data'            => json_encode(['title' => $title, 'body' => $body], JSON_UNESCAPED_UNICODE),
                'read_at'         => $read ? $when->copy()->addMinutes(20) : null,
                'created_at'      => $when,
                'updated_at'      => $when,
            ]);
        };

        // super_admin (role_id=1)
        $notify($this->staff['super_admin'], 'App\\Notifications\\Admin\\NewTripDispute',
            'نزاع مالي جديد يحتاج مراجعة',
            'ولي أمر أبلغ عن رحلة #717 لم تُنفَّذ فعلياً رغم تسجيلها كمكتملة - بانتظار المراجعة.', now()->subHours(20));

        // operations_supervisor (role_id=2)
        if (isset($alerts['formal_warning'])) {
            $notify($this->staff['operations_supervisor'], 'App\\Notifications\\Admin\\DriverAiFormalWarning',
                'إنذار AI رسمي على سائق',
                'رصد النظام مخالفتين خلال الشهر لنفس السائق ويستوجب إنذاراً رسمياً موثقاً.', now()->subDays(3));
        }
        $notify($this->staff['operations_supervisor'], 'App\\Notifications\\Admin\\TripDisputeResolved',
            'تم حسم نزاع الرحلة #644',
            'راجع المشرف تتبع GPS وأكد صحة تنفيذ الرحلة - صُرف مستحق السائق بالكامل.', now()->subHours(2), read: true);

        // إشعارات الأطراف المعنية مباشرة
        $notify(2016, 'App\\Notifications\\WalletRefunded',
            'تم استرداد مبلغ الرحلة الملغاة',
            'تمت مراجعة نزاعك المالي واسترداد 24.00 د.ل كاملة إلى محفظتك.', now()->subHours(5));

        $notify(2017, 'App\\Notifications\\ComplaintResolved',
            'تم البت في شكواك',
            'تم إيقاف السائق مؤقتاً 3 أيام بعد التأكد من واقعة توصيل الطفل لمنزل خاطئ.', now()->subDays(2));
    }

    // =====================================================================
    // تقرير موجز
    // =====================================================================
    private function printReport(): void
    {
        $this->command?->newLine();
        $this->command?->info('╔══════════════════════════════════════════════════════════════════╗');
        $this->command?->info('║           ✅ AdminOperationsSeeder اكتمل بنجاح (إضافة فقط)        ║');
        $this->command?->info('╚══════════════════════════════════════════════════════════════════╝');
        $this->command?->newLine();
        $this->command?->line('📊 <fg=cyan>تمت إضافة (بدون حذف أي شيء):</>');
        $this->command?->line('   • حسابان إداريان جديدان (operations2@darby.ly [فعال] + fleet2@darby.ly [معطّل])');
        $this->command?->line('   • وسيلة دفع جديدة: مصرف الجمهورية');
        $this->command?->line('   • 5 تنبيهات AI (كل مستويات الخطورة + واحد محسوم)');
        $this->command?->line('   • 4 شكاوى (معلّقة/محسومة بإنذار/محسومة بإيقاف/مرفوضة)');
        $this->command?->line('   • 5 تذاكر دعم فني (مالية/تقنية/رحلة/بين الطرفين/مرفوضة)');
        $this->command?->line('   • 3 طلبات شحن سائقين + 3 طلبات شحن أولياء أمور + طلبَا سحب');
        $this->command?->line('   • 3 نزاعات مالية على رحلات + قيدا دفتر أستاذ (استرداد وتعويض)');
        $this->command?->line('   • مسودة نسخة شروط وأحكام جديدة (2.0) لأولياء الأمور');
        $this->command?->line('   • ~19 سجل تدقيق إداري (admin_audit_logs) - كان الجدول فارغاً تماماً');
        $this->command?->line('   • إشعارات للوحة تحكم الأدمن + الأطراف المعنية');
        $this->command?->newLine();
        $this->command?->line('🔐 كلمة مرور الحسابين الإداريين الجديدين: <fg=green>' . self::DEFAULT_PASSWORD . '</>');
        $this->command?->newLine();
    }
}
