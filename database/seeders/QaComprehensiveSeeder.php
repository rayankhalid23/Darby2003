<?php

namespace Database\Seeders;

use App\Models\Admin\AdminAlert;
use App\Models\Driver\Driver;
use App\Models\Driver\DriverSeatSlot;
use App\Models\Driver\Vehicle;
use App\Models\Parent\Address;
use App\Models\Parent\Child;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\FinancialLedger;
use App\Models\Shared\Invoice;
use App\Models\Shared\MasterEscrowVault;
use App\Models\Shared\PaymentMethod;
use App\Models\Shared\PlatformFinance;
use App\Models\Shared\PricingSetting;
use App\Models\Shared\RechargeRequest;
use App\Models\Shared\Route as RouteModel;
use App\Models\Shared\RouteStop;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\Trip;
use App\Models\Shared\TripEscrowHold;
use App\Models\Shared\TripEvent;
use App\Models\Shared\TripStop;
use App\Models\Shared\TripTracking;
use App\Models\Shared\WithdrawalRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * QaComprehensiveSeeder - بيانات اختبار شاملة ودقيقة لفحص منطق التطبيق يدوياً.
 *
 * ========================================================================
 * قواعد هذا السيدر:
 *   1) APPEND فقط - لا يحذف ولا يُفرّغ أي جدول موجود في قاعدة البيانات إطلاقاً.
 *   2) idempotent - إذا شُغّل مرتين، يتخطى أي سائق/ولي أمر موجود بالفعل
 *      (بحث بالـ email) ولا يُنشئ تكراراً.
 *   3) يعتمد على BaseSystemSeeder (الأدوار + الجغرافيا + المدارس + التسعير
 *      + وسائل الدفع) - يجب أن يكون قد عمل قبله. لا يتعارض مع DemoDataSeeder
 *      أو ExtraDriversSeeder (يستخدم بريد إلكتروني مختلف تماماً: driver13
 *      إلى driver32، وparent3 إلى parent7).
 *   4) كل رقم مالي يمر عبر نفس مسار الخدمات الحقيقية في الكود
 *      (SubscriptionRequestService::holdSubscriptionFundsOnAcceptance)
 *      وليس أرقاماً عشوائية - محفظة الأب/السائق + financial_ledger +
 *      master_escrow_vault + platform_finances تتحرك معاً بشكل متسق.
 *
 * السيناريوهات المُغطاة (راجع تقرير التشغيل في آخر الملف لكل التفاصيل
 * والتواريخ الفعلية المحسوبة وقت التشغيل):
 *
 *   أ) 20 سائقاً (driver13..driver32) يغطون كل فرع في DriverMatchingService:
 *      تطابق مثالي / فلتر الجنس (سائقة أنثى + سائق ذكور فقط) / بدون تكييف /
 *      منطقة مختلفة تماماً / نوبة صباحية فقط / مقاعد ممتلئة بالكامل /
 *      مقاعد ممتلئة جزئياً / غياب معتمد / غياب معلّق / رخصة منتهية /
 *      موقوف حالياً (قرار AI) / إنذار رسمي (AI) / مكافأة (AI) / مرشح
 *      لالتئام تلقائي (AI self-healing) / قيد المراجعة الأولى (Pending) /
 *      تسجيل مرفوض (Rejected) / سائق تغطية واسعة / وثائق قاربت على الانتهاء.
 *
 *   ب) 7 أولياء أمور (parent3..parent7) - الاثنان الأساسيان لسيناريو
 *      الاختبار اليدوي (parent3, parent4) عندهما 4 أطفال لكل واحد، والثلاثة
 *      الباقون (parent5, parent6, parent7) بيانات حجم إضافية واقعية.
 *
 *   ج) 8 اشتراكات نشطة جارية الآن (تولّد 15 رحلة "in_progress" اليوم +
 *      عشرات الرحلات السابقة المكتملة + عشرات الرحلات القادمة المجدولة).
 *
 *   د) اشتراكان مكتملان سابقان (تاريخ كامل مُسدَّد) + اشتراك واحد ملغى
 *      + طلب معلّق + طلب مرفوض + اشتراك مستقبلي مجدول بالكامل (لم يبدأ
 *      بعد) + حجز يوم واحد (single_day) سابق بنموذج escrow/per-trip الكامل.
 *
 *   هـ) سيناريو "تعارض الاشتراك النشط + استثناء غياب السائق" بالضبط كما
 *      يطبّقه SubscriptionRequestService::validateChildActiveSubscriptionConflicts.
 *
 *   و) شحن/سحب/فواتير/تقييمات/شكاوى/غياب أطفال - كل الحالات (معلّق/مكتمل/مرفوض).
 *
 * كلمة المرور الموحدة لكل الحسابات المُنشأة هنا: Password123!
 *
 *   php artisan db:seed --class=QaComprehensiveSeeder
 * ========================================================================
 */
class QaComprehensiveSeeder extends Seeder
{
    public const DEFAULT_PASSWORD = 'Password123!';

    private PricingSetting $pricing;
    /** @var array<string,int> zone_id by name */
    private array $zones = [];
    /** @var array<string,\stdClass> school row by name */
    private array $schools = [];
    private array $paymentMethods = [];

    private int $driverRoleId;
    private int $parentRoleId;
    private int $adminFleetId;
    private int $adminOpsId;
    private int $adminFinanceId;
    private int $adminSupportId;

    /** @var array<int,array{user:User,driver:Driver,vehicle:Vehicle}> keyed by driver index 13..32 */
    private array $D = [];

    /** @var array<int,array{user:User,address:Address,children:array<int,Child>}> keyed by parent index 3..7 */
    private array $P = [];

    /** تواريخ محورية يعاد استخدامها في كل الدوال ويُطبع بعضها في التقرير النهائي */
    private Carbon $today;
    private Carbon $activeStart;
    private Carbon $activeEnd;
    private Carbon $exceptionThursday;
    private Carbon $exceptionBlockedDay;

    /** نتائج تُجمَع للتقرير النهائي */
    private array $report = [];

    public function run(): void
    {
        $this->command?->info('🧪 QaComprehensiveSeeder: بدء زرع بيانات اختبار شاملة (APPEND ONLY)...');

        $this->assertBaseSeeded();
        $this->loadReferences();
        $this->computeKeyDates();

        if (User::where('email', 'driver13@darby.ly')->exists()) {
            $this->command?->warn('⏭  QaComprehensiveSeeder يبدو أنه شُغّل من قبل (driver13@darby.ly موجود) - سيتم تخطي كل خطوة موجودة مسبقاً بأمان.');
        }

        $this->seedDrivers();
        $this->seedParentsAndChildren();

        $this->seedActiveBundles();
        $this->seedPastCompletedSubscriptions();
        $this->seedCancelledSubscription();
        $this->seedPendingRequest();
        $this->seedRejectedRequest();
        $this->seedFutureScheduledSubscription();
        $this->seedSingleDayHistoricalBooking();

        $this->seedDriverAbsences();
        $this->seedSeatCapacityTopUps();
        $this->seedAiDecisionScenarios();

        $this->seedRechargeAndWithdrawalRequests();
        $this->seedReviewsAndComplaints();
        $this->seedChildAbsenceLogs();

        $this->printReport();
    }

    // =====================================================================
    // 0) تحقّق من الأساسيات + مراجع + تواريخ محورية
    // =====================================================================
    private function assertBaseSeeded(): void
    {
        if (!DB::table('users')->where('email', 'admin@darby.ly')->exists()) {
            throw new \RuntimeException('يجب تشغيل BaseSystemSeeder أولاً (لا يوجد super_admin).');
        }
        if (DB::table('zones')->count() === 0 || DB::table('schools')->count() === 0 || DB::table('pricing_settings')->count() === 0) {
            throw new \RuntimeException('جداول أساسية فارغة - شغّل BaseSystemSeeder أولاً.');
        }
    }

    private function loadReferences(): void
    {
        $this->pricing = PricingSetting::first();
        foreach (DB::table('zones')->get() as $z) {
            $this->zones[$z->name] = $z->id;
        }
        foreach (DB::table('schools')->get() as $s) {
            $this->schools[$s->name] = $s;
        }
        foreach (PaymentMethod::all() as $pm) {
            $this->paymentMethods[$pm->code] = $pm;
        }

        $this->driverRoleId   = (int) DB::table('roles')->where('name', 'driver')->value('id');
        $this->parentRoleId   = (int) DB::table('roles')->where('name', 'parent')->value('id');
        $this->adminFleetId   = (int) User::where('email', 'fleet@darby.ly')->value('id');
        $this->adminOpsId     = (int) User::where('email', 'operations@darby.ly')->value('id');
        $this->adminFinanceId = (int) User::where('email', 'finance@darby.ly')->value('id');
        $this->adminSupportId = (int) User::where('email', 'support@darby.ly')->value('id');
    }

    private function computeKeyDates(): void
    {
        $this->today = Carbon::today();

        $this->activeStart = $this->today->copy()->subWeeks(2)->startOfWeek(Carbon::SATURDAY);
        $this->activeEnd   = $this->today->copy()->addWeeks(2)->endOfWeek(Carbon::THURSDAY);

        // أول خميس قادم (استراتيجية استثناء الغياب) - Carbon::next() يعيد دائماً
        // تاريخاً بعد اليوم الحالي، حتى لو كان اليوم نفسه خميساً.
        $this->exceptionThursday = $this->today->copy()->next(Carbon::THURSDAY);

        // يوم عمل آخر (أحد) داخل نفس فترة الاشتراك النشط، ليس فيه غياب - يُستخدم
        // في التقرير كـ"يوم يُفترض أن يُرفض فيه الطلب" (اختبار سلبي مقابل الخميس).
        $this->exceptionBlockedDay = $this->exceptionThursday->copy()->next(Carbon::SUNDAY);
    }

    /** ليبيا: الجمعة/السبت عطلة، الأحد-الخميس عمل */
    private function isWorkingDay(Carbon $date): bool
    {
        return in_array($date->dayOfWeek, [
            Carbon::SUNDAY, Carbon::MONDAY, Carbon::TUESDAY, Carbon::WEDNESDAY, Carbon::THURSDAY,
        ]);
    }

    private function workingDaysBetween(Carbon $start, Carbon $end): int
    {
        $days = 0;
        $d = $start->copy();
        while ($d->lte($end)) {
            if ($this->isWorkingDay($d)) $days++;
            $d->addDay();
        }
        return $days;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    private function getDiscountPct(int $childrenCount): float
    {
        if ($childrenCount >= 3) return (float) $this->pricing->discount_three_plus_children;
        if ($childrenCount === 2) return (float) $this->pricing->discount_two_children;
        return (float) $this->pricing->discount_one_child;
    }

    // =====================================================================
    // 1) 20 سائقاً - كل حالة فلترة ممكنة
    // =====================================================================
    private const DRIVER_SPECS = [
        // ------ تطابق مثالي لولي الأمر #3 (بن عاشور) ------
        ['idx'=>13,'name'=>'مصطفى رمضان سالم الككلي','gender'=>'male','zones'=>['بن عاشور','زاوية الدهماني','شارع النصر'],
         'accepted_gender'=>'both','flags'=>[1,1,1,1],'ac'=>1,'type'=>'Bus','cap'=>24,'rating'=>4.80,'approval'=>'Approved','license_days'=>900,
         'note'=>'تطابق مثالي - منطقة وليّ الأمر #3 (بن عاشور)'],
        // ------ تطابق مثالي لولي الأمر #4 (حي الأندلس) ------
        ['idx'=>14,'name'=>'فتحي عبدالناصر محمد المرابط','gender'=>'male','zones'=>['حي الأندلس','قرقارش','السراج'],
         'accepted_gender'=>'both','flags'=>[1,1,1,1],'ac'=>1,'type'=>'Bus','cap'=>20,'rating'=>4.75,'approval'=>'Approved','license_days'=>850,
         'note'=>'تطابق مثالي - منطقة وليّ الأمر #4 (حي الأندلس)'],
        // ------ فلتر الجنس: سائقة تقبل بنات فقط ------
        ['idx'=>15,'name'=>'أمل الصادق عبدالله بالحاج','gender'=>'female','zones'=>['بن عاشور','زاوية الدهماني','حي الأندلس','قرقارش'],
         'accepted_gender'=>'female','flags'=>[1,1,1,1],'ac'=>1,'type'=>'Van','cap'=>10,'rating'=>4.92,'approval'=>'Approved','license_days'=>700,
         'note'=>'فلتر driver_gender=female + accepted_gender=female (بنات فقط)'],
        // ------ بدون تكييف ------
        ['idx'=>16,'name'=>'جمعة الطاهر ميلاد الككلي','gender'=>'male','zones'=>['حي الأندلس','السياحية'],
         'accepted_gender'=>'both','flags'=>[1,1,1,1],'ac'=>0,'type'=>'Van','cap'=>12,'rating'=>4.35,'approval'=>'Approved','license_days'=>600,
         'note'=>'بدون تكييف - يُستبعد عند فلتر has_ac=true (وسائق تاريخي لاشتراك ملغى)'],
        // ------ منطقة مختلفة تماماً (لن يظهر لأولياء أمور #3/#4) ------
        ['idx'=>17,'name'=>'الصديق عمر فرج أبوراوي','gender'=>'male','zones'=>['أبو سليم','الهضبة الخضراء','صلاح الدين','عين زارة الشرقية','خلة الفرجان'],
         'accepted_gender'=>'both','flags'=>[1,1,1,1],'ac'=>1,'type'=>'Van','cap'=>14,'rating'=>4.60,'approval'=>'Approved','license_days'=>500,
         'note'=>'منطقة بعيدة (أبو سليم) - فلتر zone يستبعده + سائق طلب مرفوض'],
        // ------ فلتر الجنس: ذكور فقط ------
        ['idx'=>18,'name'=>'نوري الأمين خليفة الطرابلسي','gender'=>'male','zones'=>['بن عاشور','حي الأندلس'],
         'accepted_gender'=>'male','flags'=>[1,1,1,1],'ac'=>1,'type'=>'Van','cap'=>13,'rating'=>4.55,'approval'=>'Approved','license_days'=>450,
         'note'=>'accepted_gender=male - يُستبعد عند اختيار أطفال مختلطي الجنس'],
        // ------ نوبة صباحية فقط + اشتراك نشط (طفل واحد) ------
        ['idx'=>19,'name'=>'محمد الهادي سالم بن يونس','gender'=>'male','zones'=>['حي الأندلس','قرقارش'],
         'accepted_gender'=>'both','flags'=>[1,1,0,0],'ac'=>1,'type'=>'Bus','cap'=>16,'rating'=>4.45,'approval'=>'Approved','license_days'=>400,
         'note'=>'نوبة صباحية فقط (afternoon_go=afternoon_return=0) - يُستبعد عند trip_direction يتطلب مسائية'],
        // ------ مقاعد ممتلئة بالكامل + اشتراك نشط ------
        ['idx'=>20,'name'=>'كمال البشير عاشور الفقهي','gender'=>'male','zones'=>['بن عاشور','الظهرة الشرقية','زاوية الدهماني'],
         'accepted_gender'=>'both','flags'=>[1,1,1,1],'ac'=>1,'type'=>'Van','cap'=>6,'rating'=>4.15,'approval'=>'Approved','license_days'=>380,
         'note'=>'مقاعده ممتلئة بالكامل (اشتراك نشط حقيقي + تعبئة كامل الأسبوعين القادمين) - "ماعندهوش كراسي فاضية"'],
        // ------ مقاعد ممتلئة جزئياً + اشتراك نشط ------
        ['idx'=>21,'name'=>'أشرف الطيب محمود العبيدي','gender'=>'male','zones'=>['بن عاشور','شارع النصر'],
         'accepted_gender'=>'both','flags'=>[1,1,1,1],'ac'=>1,'type'=>'Van','cap'=>9,'rating'=>4.25,'approval'=>'Approved','license_days'=>350,
         'note'=>'مقاعد ممتلئة جزئياً (بعض الأيام كاملة وبعضها متاح)'],
        // ------ غياب معتمد يشمل الخميس القادم + اشتراك نشط (سيناريو التعارض) ------
        ['idx'=>22,'name'=>'سالم إدريس محمد الزنتاني','gender'=>'male','zones'=>['بن عاشور','زاوية الدهماني'],
         'accepted_gender'=>'both','flags'=>[1,1,1,1],'ac'=>1,'type'=>'Bus','cap'=>18,'rating'=>4.50,'approval'=>'Approved','license_days'=>300,
         'note'=>'غياب معتمد يشمل "الخميس القادم" - محور سيناريو استثناء التعارض'],
        // ------ غياب معلّق (لم يُوافَق عليه بعد) + اشتراك مستقبلي مجدول ------
        ['idx'=>23,'name'=>'عزالدين مفتاح سعد القذافي','gender'=>'male','zones'=>['بن عاشور','زاوية الدهماني'],
         'accepted_gender'=>'both','flags'=>[1,1,1,1],'ac'=>1,'type'=>'Van','cap'=>11,'rating'=>4.10,'approval'=>'Approved','license_days'=>280,
         'note'=>'طلب غياب معلّق (status=pending) لتاريخ بعيد + اشتراك مستقبلي لم يبدأ بعد'],
        // ------ رخصة منتهية (يجب ألا يظهر إطلاقاً) ------
        ['idx'=>24,'name'=>'عبدالمنعم صالح غيث الورفلي','gender'=>'male','zones'=>['بن عاشور','حي الأندلس'],
         'accepted_gender'=>'both','flags'=>[1,1,1,1],'ac'=>1,'type'=>'Van','cap'=>15,'rating'=>4.00,'approval'=>'Approved','license_days'=>-20,
         'note'=>'رخصة منتهية قبل 20 يوماً - يجب ألا يظهر في أي بحث'],
        // ------ موقوف حالياً (قرار AI: مخالفة متوسطة) ------
        ['idx'=>25,'name'=>'وليد فوزي عبدالسلام تكبالي','gender'=>'male','zones'=>['حي الأندلس','قرقارش'],
         'accepted_gender'=>'both','flags'=>[1,1,1,1],'ac'=>1,'type'=>'Bus','cap'=>22,'rating'=>4.20,'approval'=>'Approved','license_days'=>500,
         'note'=>'موقوف حالياً - قرار AI: MODERATE_VIOLATION (شكويان متطابقتا الفئة خلال 15 يوماً)'],
        // ------ إنذار رسمي (AI) لكن غير موقوف + اشتراك نشط ------
        ['idx'=>26,'name'=>'الهادي مسعود إبراهيم الشريف','gender'=>'male','zones'=>['حي الأندلس','قرجي الغربي'],
         'accepted_gender'=>'both','flags'=>[1,1,1,1],'ac'=>1,'type'=>'Van','cap'=>14,'rating'=>4.30,'approval'=>'Approved','license_days'=>420,
         'note'=>'قرار AI: FORMAL_WARNING (إنذار دون إيقاف) - يظل يعمل باشتراك نشط'],
        // ------ مكافأة (AI) + اشتراك سابق مكتمل ------
        ['idx'=>27,'name'=>'رامز الأمين محمد بوخريص','gender'=>'male','zones'=>['حي الأندلس','قرجي الغربي'],
         'accepted_gender'=>'both','flags'=>[1,1,1,1],'ac'=>1,'type'=>'Bus','cap'=>20,'rating'=>4.90,'approval'=>'Approved','license_days'=>650,
         'note'=>'قرار AI: REWARD (تقييمات إيجابية متتالية) - له تاريخ اشتراك مكتمل حقيقي'],
        // ------ مرشح لالتئام تلقائي (AI self-healing) + اشتراك نشط ------
        ['idx'=>28,'name'=>'حاتم علي محمد الجهاني','gender'=>'male','zones'=>['حي الأندلس','قرقارش'],
         'accepted_gender'=>'both','flags'=>[1,1,1,1],'ac'=>1,'type'=>'Van','cap'=>12,'rating'=>4.55,'approval'=>'Approved','license_days'=>380,
         'note'=>'مخالفة قديمة (35 يوماً) بلا تكرار - مرشح لأمر RestoreSuspendedDrivers/self-healing'],
        // ------ قيد المراجعة الأولى (لم يُعتمد بعد) ------
        ['idx'=>29,'name'=>'سيف الإسلام مراد أحمد الغرياني','gender'=>'male','zones'=>['بن عاشور','حي الأندلس'],
         'accepted_gender'=>'both','flags'=>[1,1,1,1],'ac'=>1,'type'=>'Van','cap'=>10,'rating'=>0,'approval'=>'Pending','license_days'=>365,
         'note'=>'تسجيل قيد المراجعة الأولى (status=Pending) - لن يظهر في أي بحث حتى تتم الموافقة'],
        // ------ تسجيل مرفوض نهائياً ------
        ['idx'=>30,'name'=>'أنور الطيب سالم المجبري','gender'=>'male','zones'=>['بن عاشور','حي الأندلس'],
         'accepted_gender'=>'both','flags'=>[1,1,1,1],'ac'=>1,'type'=>'Van','cap'=>10,'rating'=>0,'approval'=>'Rejected','license_days'=>365,
         'note'=>'تسجيل مرفوض (وثائق ناقصة) - لن يظهر إطلاقاً'],
        // ------ سائق تغطية واسعة (لاستخدامه في اختبارات يدوية QA) ------
        ['idx'=>31,'name'=>'عماد الدين خليل مصطفى القماطي','gender'=>'male','zones'=>['بن عاشور','زاوية الدهماني','حي الأندلس','قرقارش','السراج','السياحية','قرجي الغربي','شارع النصر','الظهرة الشرقية'],
         'accepted_gender'=>'both','flags'=>[1,1,1,1],'ac'=>1,'type'=>'Bus','cap'=>30,'rating'=>4.70,'approval'=>'Approved','license_days'=>1000,
         'note'=>'حافلة كبيرة بتغطية واسعة جداً - سائق الطلب المعلّق + سائق "اليوم الاستثنائي" في اختبار التعارض'],
        // ------ وثائق قاربت على الانتهاء ------
        ['idx'=>32,'name'=>'فرج الله محمد أبوبكر الفيتوري','gender'=>'male','zones'=>['بن عاشور','حي الأندلس'],
         'accepted_gender'=>'both','flags'=>[1,1,1,1],'ac'=>1,'type'=>'Bus','cap'=>20,'rating'=>4.40,'approval'=>'Approved','license_days'=>200,
         'note'=>'وثائق مركبة/سائق قاربت على الانتهاء (~18 يوماً) - لاختبار تنبيهات انتهاء الصلاحية'],
    ];

    private function seedDrivers(): void
    {
        $this->command?->info('1️⃣  إنشاء 20 سائقاً (driver13..driver32) يغطون كل حالات الفلترة...');

        foreach (self::DRIVER_SPECS as $spec) {
            $idx   = $spec['idx'];
            $email = "driver{$idx}@darby.ly";

            if (User::where('email', $email)->exists()) {
                $this->command?->line("   ⏭  {$email} موجود بالفعل - تخطي");
                $user     = User::where('email', $email)->first();
                $driver   = Driver::where('user_id', $user->id)->first();
                $vehicle  = Vehicle::where('driver_id', $driver->id)->first();
                $this->D[$idx] = compact('user', 'driver', 'vehicle');
                continue;
            }

            $user = User::create([
                'full_name'         => $spec['name'],
                'email'             => $email,
                'phone_number'      => '0919' . str_pad((string) $idx, 6, '0', STR_PAD_LEFT),
                'alternative_phone' => '0929' . str_pad((string) $idx, 6, '0', STR_PAD_LEFT),
                'password'          => Hash::make(self::DEFAULT_PASSWORD),
                'role_id'           => $this->driverRoleId,
                'is_active'         => true,
                'is_trusted'        => true,
                'gender'            => $spec['gender'],
                'email_verified_at' => now(),
                'last_login_at'     => now()->subMinutes(rand(5, 500)),
            ]);

            $licenseExpiry = Carbon::now()->addDays($spec['license_days']);
            $docExpiryLong = $idx === 32 ? Carbon::now()->addDays(18) : Carbon::now()->addMonths(10);

            $driver = Driver::create([
                'user_id'                => $user->id,
                'national_id'            => '1198' . str_pad((string) $idx, 8, '0', STR_PAD_LEFT),
                'license_number'         => 'LY-TR-9' . str_pad((string) $idx, 4, '0', STR_PAD_LEFT),
                'license_expiry'         => $licenseExpiry->format('Y-m-d'),
                'license_image_url'      => "https://cdn.darby.ly/qa/licenses/{$email}.jpg",
                'status'                 => $spec['approval'] === 'Approved' ? 'Approved' : ($spec['approval'] === 'Pending' ? 'Pending' : 'Rejected'),
                'reviewed_by'            => $this->adminFleetId,
                'rejection_reason'       => $spec['approval'] === 'Rejected' ? 'وثائق ناقصة: صورة الرخصة غير واضحة ولا يوجد فحص فني ساري.' : null,
                'shift'                  => ($spec['flags'][2] === 0 && $spec['flags'][3] === 0) ? 'morning' : 'both',
                'morning_go'             => $spec['flags'][0],
                'morning_return'         => $spec['flags'][1],
                'afternoon_go'           => $spec['flags'][2],
                'afternoon_return'       => $spec['flags'][3],
                'subscription_type'      => 'multi_day',
                'accepted_gender'        => $spec['accepted_gender'],
                'school_stages'          => json_encode(['kindergarten', 'primary', 'middle', 'secondary']),
                'current_lat'            => 32.8750 + (mt_rand(-300, 300) / 10000),
                'current_lng'            => 13.1800 + (mt_rand(-300, 300) / 10000),
                'last_ping_at'           => now()->subMinutes(rand(1, 90)),
                'rating_avg'             => $spec['rating'] ?: 5.00,
                'is_searchable'          => $spec['approval'] === 'Approved' ? 1 : 0,
                'driver_waiting_minutes' => 10,
            ]);

            $vehicle = Vehicle::create([
                'driver_id'         => $driver->id,
                'plate_number'      => 'ط ط ' . (2000 + $idx),
                'brand'             => ['Toyota', 'Hyundai', 'Kia', 'Nissan', 'Ford', 'Isuzu', 'Mercedes'][array_rand(['Toyota', 'Hyundai', 'Kia', 'Nissan', 'Ford', 'Isuzu', 'Mercedes'])],
                'model'             => $spec['type'] === 'Bus' ? 'Coaster' : 'Hiace',
                'year'              => rand(2016, 2023),
                'color'             => ['أبيض', 'فضي', 'رمادي', 'أزرق', 'أصفر'][array_rand(['أبيض', 'فضي', 'رمادي', 'أزرق', 'أصفر'])],
                'type'              => $spec['type'],
                'capacity_manual'   => $spec['cap'],
                'has_ac'            => $spec['ac'],
                'status'            => $spec['approval'] === 'Approved' ? 'Active' : 'Maintenance',
                'vehicle_image_url' => "https://cdn.darby.ly/qa/vehicles/{$email}.jpg",
            ]);

            $this->seedVehicleDocs($vehicle, $docExpiryLong);
            $this->seedDriverDocuments($driver, $vehicle, $docExpiryLong);
            $this->seedDriverZones($driver, $spec['zones']);
            $this->seedApproval($driver, $spec['approval']);

            $this->D[$idx] = compact('user', 'driver', 'vehicle');
            $this->report['drivers'][] = "driver{$idx}@darby.ly — {$spec['name']} — {$spec['note']}";
        }
    }

    private function seedVehicleDocs(Vehicle $vehicle, Carbon $baseExpiry): void
    {
        $docs = [
            'LOGBOOK'          => $baseExpiry->copy()->addYears(2),
            'INSURANCE'        => $baseExpiry->copy(),
            'INSPECTION'       => $baseExpiry->copy()->subDays(5),
            'OPERATING_PERMIT' => $baseExpiry->copy()->subDays(10),
        ];
        foreach ($docs as $type => $expiry) {
            DB::table('vehicle_documents')->insert([
                'vehicle_id'  => $vehicle->id,
                'doc_type'    => $type,
                'file_url'    => "https://cdn.darby.ly/qa/docs/vehicle_{$vehicle->id}_" . strtolower($type) . '.pdf',
                'expiry_date' => $expiry->format('Y-m-d'),
                'is_verified' => 1,
                'state'       => 'active',
                'created_at'  => now()->subDays(60),
                'updated_at'  => now()->subDays(60),
            ]);
        }
    }

    /**
     * ملاحظة: الجدول الفعلي في قاعدة البيانات الحيّة أبسط بكثير مما توحي به
     * ملفات الهجرة/النموذج (لا يوجد vehicle_id ولا أعمدة انتهاء متعددة) -
     * الأعمدة الحقيقية: driver_id, document_type, file_url, status, expires_at.
     */
    private function seedDriverDocuments(Driver $driver, Vehicle $vehicle, Carbon $baseExpiry): void
    {
        $rows = [
            ['document_type' => 'national_id',          'expires_at' => null],
            ['document_type' => 'vehicle_license',       'expires_at' => $baseExpiry->copy()->addYears(2)->format('Y-m-d')],
            ['document_type' => 'insurance',              'expires_at' => $baseExpiry->format('Y-m-d')],
            ['document_type' => 'technical_inspection',   'expires_at' => $baseExpiry->copy()->subDays(5)->format('Y-m-d')],
        ];

        foreach ($rows as $r) {
            DB::table('driver_documents')->insert([
                'driver_id'      => $driver->id,
                'document_type'  => $r['document_type'],
                'file_url'       => "https://cdn.darby.ly/qa/driver_docs/{$driver->id}_{$r['document_type']}.pdf",
                'status'         => 'approved',
                'expires_at'     => $r['expires_at'],
                'created_at'     => now()->subDays(45),
                'updated_at'     => now()->subDays(44),
            ]);
        }
    }

    private function seedDriverZones(Driver $driver, array $zoneNames): void
    {
        foreach ($zoneNames as $zoneName) {
            if (!isset($this->zones[$zoneName])) {
                $this->command?->warn("   ⚠️  منطقة غير موجودة (تخطي): {$zoneName}");
                continue;
            }
            DB::table('driver_zone')->insert([
                'driver_id'  => $driver->id,
                'zone_id'    => $this->zones[$zoneName],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedApproval(Driver $driver, string $status): void
    {
        DB::table('driver_approvals')->insert([
            'driver_id'        => $driver->id,
            'request_type'     => 'Registration',
            'status'           => $status,
            'admin_id'         => $this->adminFleetId,
            'rejection_reason' => $status === 'Rejected' ? 'وثائق ناقصة: صورة الرخصة غير واضحة ولا يوجد فحص فني ساري.' : null,
            'reviewed_at'      => $status === 'Pending' ? null : now()->subDays(20),
            'created_at'       => now()->subDays(22),
            'updated_at'       => $status === 'Pending' ? now()->subDays(22) : now()->subDays(20),
        ]);
    }

    // =====================================================================
    // 2) 7 أولياء أمور (parent3..parent7) + عناوين + أطفال
    //    parent3/parent4: النواة الأساسية لسيناريو الاختبار اليدوي (4 أطفال لكل واحد)
    //    parent5/parent6/parent7: بيانات حجم إضافية واقعية
    // =====================================================================
    private const PARENT_SPECS = [
        3 => [
            'name' => 'خالد إبراهيم محمد الككلي', 'gender' => 'male',
            'zone' => 'بن عاشور', 'lat' => 32.87550, 'lng' => 13.19350, 'label' => 'المنزل - بن عاشور الشمالي',
            'pickup' => '06:45:00', 'dropoff' => '13:30:00',
            'children' => [
                ['name' => 'عمر خالد إبراهيم الككلي',  'gender' => 'male',   'age' => 8,  'grade' => 2, 'school' => 'مدرسة بن عاشور الابتدائية والإعدادية', 'medical' => null],
                ['name' => 'لينا خالد إبراهيم الككلي',  'gender' => 'female', 'age' => 10, 'grade' => 4, 'school' => 'مدرسة النصر النموذجية للتعليم الأساسي', 'medical' => 'حساسية من حبوب اللقاح في فصل الربيع - تحمل بخاخاً مضاداً للهيستامين.'],
                ['name' => 'زياد خالد إبراهيم الككلي', 'gender' => 'male',   'age' => 14, 'grade' => 8, 'school' => 'مدرسة زاوية الدهماني الثانوية للبنين', 'medical' => null],
                ['name' => 'هدى خالد إبراهيم الككلي',  'gender' => 'female', 'age' => 6,  'grade' => 1, 'school' => 'مدرسة النوفليين الأهلية النموذجية', 'medical' => null],
            ],
        ],
        4 => [
            'name' => 'عبدالسلام أحمد محمود المرابط', 'gender' => 'male',
            'zone' => 'حي الأندلس', 'lat' => 32.89350, 'lng' => 13.16550, 'label' => 'المنزل - حي الأندلس',
            'pickup' => '07:00:00', 'dropoff' => '13:15:00',
            'children' => [
                ['name' => 'يوسف عبدالسلام أحمد المرابط', 'gender' => 'male',   'age' => 9,  'grade' => 3, 'school' => 'مدرسة حي الأندلس النموذجية الدولية', 'medical' => null],
                ['name' => 'رهف عبدالسلام أحمد المرابط',  'gender' => 'female', 'age' => 11, 'grade' => 5, 'school' => 'مدرسة قرقارش الحديثة للتعليم الأساسي', 'medical' => null],
                ['name' => 'كريم عبدالسلام أحمد المرابط', 'gender' => 'male',   'age' => 13, 'grade' => 7, 'school' => 'مدرسة قرجي النموذجية للتعليم الأساسي',  'medical' => 'يعاني من ربو خفيف - يحمل بخاخاً في حقيبته.'],
                ['name' => 'سلمى عبدالسلام أحمد المرابط', 'gender' => 'female', 'age' => 7,  'grade' => 2, 'school' => 'مدرسة الجيل الجديد الدولية', 'medical' => null],
            ],
        ],
        5 => [
            'name' => 'إدريس المبروك سالم القبلاوي', 'gender' => 'male',
            'zone' => 'بن عاشور', 'lat' => 32.88100, 'lng' => 13.19400, 'label' => 'المنزل - شارع النصر',
            'pickup' => '06:45:00', 'dropoff' => '13:30:00',
            'children' => [
                ['name' => 'براء إدريس المبروك القبلاوي',  'gender' => 'male',   'age' => 9, 'grade' => 3, 'school' => 'مدرسة النصر النموذجية للتعليم الأساسي', 'medical' => null],
                ['name' => 'إيناس إدريس المبروك القبلاوي', 'gender' => 'female', 'age' => 8, 'grade' => 2, 'school' => 'مدرسة الظهرة الابتدائية المختلطة',      'medical' => null],
            ],
        ],
        6 => [
            'name' => 'ناصر السنوسي عبدالله الفاخري', 'gender' => 'male',
            'zone' => 'حي الأندلس', 'lat' => 32.88500, 'lng' => 13.14200, 'label' => 'المنزل - قرقارش',
            'pickup' => '07:00:00', 'dropoff' => '13:15:00',
            'children' => [
                ['name' => 'عبدالرحمن ناصر السنوسي الفاخري', 'gender' => 'male',   'age' => 10, 'grade' => 4, 'school' => 'مدرسة حي الأندلس النموذجية الدولية',   'medical' => null],
                ['name' => 'جود ناصر السنوسي الفاخري',       'gender' => 'female', 'age' => 9,  'grade' => 3, 'school' => 'مدرسة قرقارش الحديثة للتعليم الأساسي', 'medical' => null],
            ],
        ],
        7 => [
            'name' => 'صلاح الترهوني عمر بن نافع', 'gender' => 'male',
            'zone' => 'بن عاشور', 'lat' => 32.87700, 'lng' => 13.18700, 'label' => 'المنزل - زاوية الدهماني',
            'pickup' => '06:45:00', 'dropoff' => '13:30:00',
            'children' => [
                ['name' => 'أنس صلاح الترهوني بن نافع', 'gender' => 'male', 'age' => 9, 'grade' => 3, 'school' => 'مدرسة زاوية الدهماني الثانوية للبنين', 'medical' => null],
            ],
        ],
    ];

    private function seedParentsAndChildren(): void
    {
        $this->command?->info('2️⃣  إنشاء 7 أولياء أمور (parent3..parent7) وأطفالهم...');

        foreach (self::PARENT_SPECS as $idx => $spec) {
            $email = "parent{$idx}@darby.ly";

            if (User::where('email', $email)->exists()) {
                $this->command?->line("   ⏭  {$email} موجود بالفعل - تخطي");
                $user    = User::where('email', $email)->first();
                $address = Address::where('user_id', $user->id)->first();
                $children = Child::where('parent_id', $user->id)->orderBy('id')->get()->all();
                $this->P[$idx] = ['user' => $user, 'address' => $address, 'children' => $children];
                continue;
            }

            $user = User::create([
                'full_name'         => $spec['name'],
                'email'             => $email,
                'phone_number'      => '0917' . str_pad((string) $idx, 6, '0', STR_PAD_LEFT),
                'alternative_phone' => '0927' . str_pad((string) $idx, 6, '0', STR_PAD_LEFT),
                'password'          => Hash::make(self::DEFAULT_PASSWORD),
                'role_id'           => $this->parentRoleId,
                'is_active'         => true,
                'is_trusted'        => true,
                'gender'            => $spec['gender'],
                'email_verified_at' => now(),
                'last_login_at'     => now()->subHours(rand(1, 48)),
            ]);

            $address = Address::create([
                'user_id'    => $user->id,
                'zone_id'    => $this->zones[$spec['zone']],
                'label'      => $spec['label'],
                'lat'        => $spec['lat'],
                'lng'        => $spec['lng'],
                'is_default' => true,
            ]);

            // رصيد ابتدائي كافٍ لتغطية عدة اشتراكات شهرية دفعة واحدة
            $user->deposit(300000); // 3000 د.ل

            $children = [];
            foreach ($spec['children'] as $c) {
                $school = $this->schools[$c['school']];
                $children[] = Child::create([
                    'parent_id'           => $user->id,
                    'school_id'           => $school->id,
                    'address_id'          => $address->id,
                    'full_name'           => $c['name'],
                    'birth_date'          => Carbon::now()->subYears($c['age'])->format('Y-m-d'),
                    'gender'              => $c['gender'],
                    'grade'               => $c['grade'],
                    'medical_notes'       => $c['medical'],
                    'notification_radius' => 500,
                    'qr_code_token'       => (string) Str::uuid(),
                    'preferred_time_slot' => 'morning',
                    'pickup_time'         => $spec['pickup'],
                    'dropoff_time'        => $spec['dropoff'],
                    'is_active'           => true,
                ]);
            }

            $this->P[$idx] = ['user' => $user, 'address' => $address, 'children' => $children];
            $this->report['parents'][] = "parent{$idx}@darby.ly — {$spec['name']} — " . count($children) . ' أطفال — منطقة ' . $spec['zone'];
        }
    }

    // =====================================================================
    // 🔧 هيكل عام: طلب اشتراك (requests) + request_children
    // =====================================================================
    private function createRequestWithChildren(
        User $parent,
        array $children,
        Driver $driver,
        Vehicle $vehicle,
        Address $parentAddr,
        Carbon $startDate,
        Carbon $endDate,
        string $status,
        ?Carbon $respondedAt,
        string $subscriptionType = 'multi_day',
    ): SubscriptionRequest {
        $workDays = $subscriptionType === 'single_day' ? 1 : $this->workingDaysBetween($startDate, $endDate);
        $childrenCount = count($children);
        $pricePerKm    = (float) ($vehicle->has_ac ? $this->pricing->price_per_km_ac : $this->pricing->price_per_km_non_ac);
        $discountPct   = $this->getDiscountPct($childrenCount);
        $commissionPct = (float) $this->pricing->platform_commission_rate;

        $totalDailyPrice = 0.0;
        $childPricing = [];
        foreach ($children as $child) {
            $school = DB::table('schools')->where('id', $child->school_id)->first();
            $dist   = $this->haversineKm((float) $parentAddr->lat, (float) $parentAddr->lng, (float) $school->lat, (float) $school->lng);
            $billableDist = round($dist, 2);
            $tripPrice    = round($billableDist * $pricePerKm, 2);
            $dailyPrice   = round($tripPrice * 2, 2);
            $childPricing[$child->id] = compact('school', 'billableDist', 'tripPrice', 'dailyPrice');
            $totalDailyPrice += $dailyPrice;
        }

        $totalPrice       = round($totalDailyPrice * $workDays, 2);
        $discountAmount   = round($totalPrice * $discountPct / 100, 2);
        $afterDiscount    = round($totalPrice - $discountAmount, 2);
        $commissionAmount = round($afterDiscount * $commissionPct / 100, 2);
        $driverNet        = round($afterDiscount - $commissionAmount, 2);

        $req = SubscriptionRequest::create([
            'parent_id'                   => $parent->id,
            'driver_id'                   => $driver->id,
            'status'                      => $status,
            'total_price'                 => $totalPrice,
            'discount_amount'             => $discountAmount,
            'total_amount_after_discount' => $afterDiscount,
            'platform_commission_amount'  => $commissionAmount,
            'driver_net_amount'           => $driverNet,
            'children_count'              => $childrenCount,
            'pickup_time'                 => $children[0]->pickup_time,
            'dropoff_time'                => $children[0]->dropoff_time,
            'max_waiting_time'            => 15,
            'subscription_type'           => $subscriptionType,
            'trip_direction'               => 'both',
            'start_date'                  => $startDate->format('Y-m-d'),
            'end_date'                    => $endDate->format('Y-m-d'),
            'working_days_count'          => $workDays,
            'home_label'                  => $parentAddr->label,
            'home_lat'                    => $parentAddr->lat,
            'home_lng'                    => $parentAddr->lng,
            'home_address_id'             => $parentAddr->id,
            'pricing_setting_id'          => $this->pricing->id,
            'price_per_km'                => $pricePerKm,
            'vehicle_has_ac'              => $vehicle->has_ac,
            'discount_percent'            => $discountPct,
            'platform_commission_rate'    => $commissionPct,
            'responded_at'                => $respondedAt,
            'created_at'                  => $respondedAt ? $respondedAt->copy()->subDay() : now(),
            'updated_at'                  => $respondedAt ?? now(),
        ]);

        foreach ($children as $child) {
            $p = $childPricing[$child->id];
            $childDailyAfterDisc = round($p['dailyPrice'] * (1 - $discountPct / 100), 2);
            $childAfterDisc      = round($childDailyAfterDisc * $workDays, 2);
            $childDiscAmount     = round($p['dailyPrice'] * $workDays - $childAfterDisc, 2);
            $childTripAfterDisc  = round($p['tripPrice'] * (1 - $discountPct / 100), 2);
            $childDriverNet      = round($childAfterDisc * (1 - $commissionPct / 100), 2);

            DB::table('request_children')->insert([
                'request_id'                  => $req->id,
                'child_id'                    => $child->id,
                'school_id'                   => $child->school_id,
                'timing'                      => 'both',
                'distance_km'                 => $p['billableDist'],
                'billable_distance_km'        => $p['billableDist'],
                'school_label'                => $p['school']->name,
                'school_lat'                  => $p['school']->lat,
                'school_lng'                  => $p['school']->lng,
                'price_per_child'             => $childDailyAfterDisc,
                'trip_price'                  => $p['tripPrice'],
                'trip_price_after_discount'   => $childTripAfterDisc,
                'daily_price'                 => $p['dailyPrice'],
                'discount_amount'             => $childDiscAmount,
                'total_amount_after_discount' => $childAfterDisc,
                'driver_net_price'            => $childDriverNet,
                'created_at'                  => $req->created_at,
                'updated_at'                  => $req->updated_at,
            ]);
        }

        return $req->fresh();
    }

    /** @return ActiveSubscription[] */
    private function createActiveSubscriptionsForRequest(SubscriptionRequest $req, array $children, string $status): array
    {
        $subs = [];
        $sort = 0;
        foreach ($children as $child) {
            $rc = DB::table('request_children')->where('request_id', $req->id)->where('child_id', $child->id)->first();
            $school = DB::table('schools')->where('id', $child->school_id)->first();
            $sort++;
            $subs[] = ActiveSubscription::create([
                'subscription_request_id' => $req->id,
                'request_child_id'        => $rc->id,
                'pickup_lat'              => $req->home_lat,
                'pickup_lng'              => $req->home_lng,
                'pickup_label'            => $req->home_label,
                'dropoff_lat'             => $school->lat,
                'dropoff_lng'             => $school->lng,
                'dropoff_label'           => $school->name,
                'pickup_time'             => $req->pickup_time,
                'dropoff_time'            => $req->dropoff_time,
                'sort_order'              => $sort,
                'status'                  => $status,
            ]);
        }
        return $subs;
    }

    /** @return array{0:RouteModel,1:?RouteModel} [مسار صباحي, مسار مسائي أو null لسائق صباحي فقط] */
    private function createRoutePair(SubscriptionRequest $req, Driver $driver, Vehicle $vehicle, array $children, Address $parentAddr, bool $hasAfternoon = true): array
    {
        $morning = RouteModel::create([
            'subscription_request_id' => $req->id,
            'driver_id'               => $driver->id,
            'vehicle_id'              => $vehicle->id,
            'route_name'              => 'مسار صباحي - ' . $parentAddr->label,
            'route_type'              => 'Morning',
            'shift_slot'              => 'morning_go',
            'start_time'              => $req->pickup_time,
            'total_distance'          => 0,
            'estimated_duration'      => 45,
            'status'                  => 'Active',
        ]);

        RouteStop::create(['route_id' => $morning->id, 'stop_type' => 'home', 'lat' => $parentAddr->lat, 'lng' => $parentAddr->lng, 'label' => $parentAddr->label, 'sequence_order' => 1]);

        $seq = 2;
        $seen = [];
        foreach ($children as $child) {
            $school = DB::table('schools')->where('id', $child->school_id)->first();
            if (isset($seen[$school->id])) continue;
            $seen[$school->id] = true;
            RouteStop::create(['route_id' => $morning->id, 'stop_type' => 'school', 'child_id' => $child->id, 'school_id' => $school->id, 'lat' => $school->lat, 'lng' => $school->lng, 'label' => $school->name, 'sequence_order' => $seq++]);
        }

        if (!$hasAfternoon) {
            return [$morning, null];
        }

        $afternoon = RouteModel::create([
            'subscription_request_id' => $req->id,
            'driver_id'               => $driver->id,
            'vehicle_id'              => $vehicle->id,
            'route_name'              => 'مسار مسائي - ' . $parentAddr->label,
            'route_type'              => 'Afternoon',
            'shift_slot'              => 'afternoon_return',
            'start_time'              => $req->dropoff_time,
            'total_distance'          => 0,
            'estimated_duration'      => 45,
            'status'                  => 'Active',
        ]);

        $seq = 1;
        $seen = [];
        foreach ($children as $child) {
            $school = DB::table('schools')->where('id', $child->school_id)->first();
            if (isset($seen[$school->id])) continue;
            $seen[$school->id] = true;
            RouteStop::create(['route_id' => $afternoon->id, 'stop_type' => 'school', 'child_id' => $child->id, 'school_id' => $school->id, 'lat' => $school->lat, 'lng' => $school->lng, 'label' => $school->name, 'sequence_order' => $seq++]);
        }
        RouteStop::create(['route_id' => $afternoon->id, 'stop_type' => 'home', 'lat' => $parentAddr->lat, 'lng' => $parentAddr->lng, 'label' => $parentAddr->label, 'sequence_order' => $seq]);

        return [$morning, $afternoon];
    }

    // =====================================================================
    // 🔧 توليد الرحلات عبر فترة الاشتراك: سابقة=مكتملة، اليوم=جارية (in_progress)،
    //    قادمة=مجدولة (pending). قيم status هي القيم الصحيحة الوحيدة الفعلية:
    //    pending | in_progress | completed | suspended_breakdown
    //    (وليس 'planned' كما في DemoDataSeeder القديم - تلك القيمة غير صالحة).
    //
    // @return int عدد رحلات in_progress التي أُنشئت اليوم (لإحصاء "الرحلات النشطة الـ15")
    // =====================================================================
    private function generateTripsForRange(
        Driver $driver,
        RouteModel $morning,
        ?RouteModel $afternoon,
        array $children,
        array $subs,
        Carbon $start,
        Carbon $end,
    ): int {
        $liveCount = 0;
        $day = $start->copy();
        while ($day->lte($end)) {
            $isToday = $day->isSameDay($this->today);
            // "اليوم" يُنتج دائماً رحلات in_progress بصرف النظر عن كونه عطلة أسبوعية
            // (حتى تظهر رحلات نشطة حقيقية فور فتح لوحة التتبع الحي وقت الاختبار)،
            // أما بقية أيام النطاق فتخضع لتقويم أيام العمل الفعلي (أحد-خميس).
            if ($isToday) {
                $this->createShiftTrip($driver, $morning, $children, $subs, $day, 'morning', 'in_progress');
                $liveCount++;
                if ($afternoon) {
                    $this->createShiftTrip($driver, $afternoon, $children, $subs, $day, 'afternoon', 'in_progress');
                    $liveCount++;
                }
            } elseif ($this->isWorkingDay($day)) {
                if ($day->lt($this->today)) {
                    $this->createShiftTrip($driver, $morning, $children, $subs, $day, 'morning', 'completed');
                    if ($afternoon) $this->createShiftTrip($driver, $afternoon, $children, $subs, $day, 'afternoon', 'completed');
                } else {
                    $this->createShiftTrip($driver, $morning, $children, $subs, $day, 'morning', 'pending');
                    if ($afternoon) $this->createShiftTrip($driver, $afternoon, $children, $subs, $day, 'afternoon', 'pending');
                }
            }
            $day->addDay();
        }
        return $liveCount;
    }

    private function createShiftTrip(Driver $driver, RouteModel $route, array $children, array $subs, Carbon $day, string $tripType, string $status): Trip
    {
        $shiftSlot = $tripType === 'morning' ? 'morning_go' : 'afternoon_return';
        $scheduled = $day->copy()->setTimeFromTimeString($route->start_time);

        $data = [
            'driver_id'            => $driver->id,
            'route_id'             => $route->id,
            'trip_type'            => $tripType,
            'shift_slot'           => $shiftSlot,
            'status'               => $status,
            'scheduled_at'         => $scheduled,
            'scheduled_start_time' => $route->start_time,
            'start_lat'            => 32.8750,
            'start_lng'            => 13.1900,
            'trip_date'            => $day->format('Y-m-d'),
            'created_at'           => $day->copy()->startOfDay(),
            'updated_at'           => $day->copy()->startOfDay(),
        ];

        if ($status === 'completed') {
            $actualStart = $scheduled->copy()->addMinutes(rand(-3, 7));
            $completedAt = $actualStart->copy()->addMinutes(rand(30, 50));
            $data['started_at']        = $actualStart;
            $data['completed_at']      = $completedAt;
            $data['actual_start_time'] = $actualStart->format('H:i:s');
            $data['updated_at']        = $completedAt;
            $trip = Trip::create($data);
            $this->createTripStopsAndEvents($trip, $route, $children, $subs, $tripType, $actualStart, 'completed');
            $this->seedTripTracking($trip, $actualStart, $completedAt);
        } elseif ($status === 'in_progress') {
            $actualStart = $scheduled->copy()->subMinutes(rand(5, 25));
            $data['started_at']        = $actualStart;
            $data['actual_start_time'] = $actualStart->format('H:i:s');
            $data['updated_at']        = now();
            $trip = Trip::create($data);
            $this->createTripStopsAndEvents($trip, $route, $children, $subs, $tripType, $actualStart, 'in_progress');
            $this->seedTripTracking($trip, $actualStart, now());
        } else {
            $trip = Trip::create($data);
            $this->createTripStopsAndEvents($trip, $route, $children, $subs, $tripType, $scheduled, 'pending');
        }

        return $trip;
    }

    private function createTripStopsAndEvents(Trip $trip, RouteModel $route, array $children, array $subs, string $tripType, Carbon $startTime, string $mode): void
    {
        $stops = RouteStop::where('route_id', $route->id)->orderBy('sequence_order')->get();
        $subsByChild = collect($subs)->keyBy(fn ($s) => DB::table('request_children')->where('id', $s->request_child_id)->value('child_id'));

        $now = $startTime->copy();
        foreach ($stops as $i => $stop) {
            if ($mode === 'pending') {
                $tripStopStatus = 'pending';
            } elseif ($mode === 'in_progress') {
                // أول محطة فقط اكتملت (الجميع صعدوا)، البقية لا تزال قيد الانتظار
                $tripStopStatus = $i === 0
                    ? ($tripType === 'morning' ? 'boarded' : 'boarded')
                    : 'pending';
            } else {
                $tripStopStatus = $tripType === 'morning'
                    ? ($stop->stop_type === 'home' ? 'boarded' : 'dropped_off_school')
                    : ($stop->stop_type === 'school' ? 'boarded' : 'delivered_home');
            }

            TripStop::create([
                'trip_id'        => $trip->id,
                'route_stop_id'  => $stop->id,
                'stop_type'      => $stop->stop_type,
                'child_id'       => $stop->child_id,
                'school_id'      => $stop->school_id,
                'lat'            => $stop->lat,
                'lng'            => $stop->lng,
                'label'          => $stop->label,
                'sequence_order' => $stop->sequence_order,
                'status'         => $tripStopStatus,
                'eta'            => $now->format('H:i:s'),
                'eta_minutes'    => 5,
            ]);

            if ($mode === 'pending') continue;
            if ($mode === 'in_progress' && $i > 0) continue; // لا أحداث لمحطات لم تُنفَّذ بعد

            $childrenAtStop = [];
            if ($stop->stop_type === 'home') {
                $childrenAtStop = $children;
            } else {
                foreach ($children as $c) {
                    if ($c->school_id == $stop->school_id) $childrenAtStop[] = $c;
                }
            }

            foreach ($childrenAtStop as $c) {
                $sub = $subsByChild->get($c->id);
                if (!$sub) continue;
                $action = $tripType === 'morning'
                    ? ($stop->stop_type === 'home' ? 'picked_up' : 'dropped_off')
                    : ($stop->stop_type === 'school' ? 'picked_up' : 'delivered_home');

                TripEvent::create([
                    'trip_id'         => $trip->id,
                    'child_id'        => $c->id,
                    'subscription_id' => $sub->id,
                    'action_type'     => $action,
                    'trip_type'       => $tripType,
                    'location_lat'    => $stop->lat,
                    'location_lng'    => $stop->lng,
                    'scanned_at'      => $now,
                    'trip_cost'       => 0,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
            }

            $now->addMinutes(rand(5, 12));
        }
    }

    private function seedTripTracking(Trip $trip, Carbon $start, Carbon $end): void
    {
        $minutes = max(1, $start->diffInMinutes($end));
        $samples = max(2, min(6, (int) floor($minutes / 8)));
        for ($i = 0; $i <= $samples; $i++) {
            $t = $start->copy()->addMinutes((int) ($i * ($minutes / $samples)));
            TripTracking::create([
                'trip_id'     => $trip->id,
                'latitude'    => 32.8700 + (mt_rand(-200, 200) / 10000),
                'longitude'   => 13.1900 + (mt_rand(-200, 200) / 10000),
                'speed'       => rand(20, 55),
                'accuracy'    => rand(3, 15),
                'recorded_at' => $t,
            ]);
        }
    }

    // =====================================================================
    // 🔧 التسوية المالية الحقيقية لاشتراك multi_day عند "القبول" - نفس مسار
    //    SubscriptionRequestService::holdSubscriptionFundsOnAcceptance() فرع
    //    multi_day بالضبط: خصم فوري من محفظة الأب + إيداع فوري وكامل لصافي
    //    السائق + عمولة المنصة تُضاف لـ platform_revenue_pool + سطرا دفتر
    //    أستاذ + platform_finances بحالة completed فوراً (لا escrow هنا،
    //    فهذا مسار الاشتراك الشهري متعدد الأيام وليس مسار اليوم الواحد).
    // =====================================================================
    private function settleMultiDayFinancials(SubscriptionRequest $req, User $parent, Driver $driver, int $expectedTripsCount, ?Carbon $when = null): void
    {
        $when ??= $req->responded_at ?? now();

        $totalAmount = (float) $req->total_amount_after_discount;
        $commission  = (float) $req->platform_commission_amount;
        $driverNet   = (float) $req->driver_net_amount;
        $amountCents = (int) round($totalAmount * 100);
        $commCents   = (int) round($commission * 100);
        $netCents    = (int) round($driverNet * 100);

        $parentBalanceBefore = (int) ($parent->fresh()->balance ?? 0);
        if ($parentBalanceBefore < $amountCents) {
            $parent->deposit($amountCents - $parentBalanceBefore + 10000);
        }
        $parent->withdraw($amountCents);
        $parentBalanceAfter = (int) ($parent->fresh()->balance ?? 0);

        $driver->deposit($netCents);

        $vault = MasterEscrowVault::first() ?? MasterEscrowVault::create([]);
        $vault->increment('platform_revenue_pool', $commCents);

        FinancialLedger::create([
            'transaction_id'      => (string) Str::uuid(),
            'reference_number'    => "SUB-{$req->id}-COMM",
            'source_account'      => "parent_wallet:{$parent->id}",
            'destination_account' => 'platform_revenue_pool',
            'amount'              => $commCents,
            'balance_before'      => $parentBalanceBefore,
            'balance_after'       => $parentBalanceAfter,
            'type'                => 'platform_commission',
            'status'              => 'completed',
            'metadata'            => json_encode(['subscription_request_id' => $req->id]),
            'created_at'          => $when,
            'updated_at'          => $when,
        ]);

        FinancialLedger::create([
            'transaction_id'      => (string) Str::uuid(),
            'reference_number'    => "SUB-{$req->id}-PAY",
            'source_account'      => "parent_wallet:{$parent->id}",
            'destination_account' => "driver_wallet:{$driver->id}",
            'amount'              => $netCents,
            'balance_before'      => $parentBalanceBefore,
            'balance_after'       => $parentBalanceAfter,
            'type'                => 'subscription_payment',
            'status'              => 'completed',
            'metadata'            => json_encode(['subscription_request_id' => $req->id]),
            'created_at'          => $when,
            'updated_at'          => $when,
        ]);

        PlatformFinance::create([
            'subscription_request_id'    => $req->id,
            'parent_id'                  => $this->parentModelIdFor($parent),
            'driver_id'                  => $driver->id,
            'total_amount'                => $totalAmount,
            'platform_commission_rate'   => (float) $req->platform_commission_rate,
            'platform_commission_amount' => $commission,
            'driver_net_amount'          => $driverNet,
            'expected_trips_count'       => $expectedTripsCount,
            'settled_trips_count'        => $expectedTripsCount,
            'settled_amount'             => $totalAmount,
            'status'                     => PlatformFinance::STATUS_COMPLETED,
            'held_at'                    => $when,
            'settled_at'                 => $when,
            'notes'                      => 'تسوية فورية كاملة عند قبول اشتراك متعدد الأيام (multi_day) - يطابق مسار الكود الفعلي.',
        ]);

        Invoice::create([
            'subscription_request_id' => $req->id,
            'parent_id'                => $parent->id,
            'driver_id'                 => $driver->id,
            'invoice_number'            => 'INV-' . $req->id . '-' . Str::upper(Str::random(6)),
            'amount'                    => $totalAmount,
            'type'                      => 'final',
            'status'                    => 'paid',
            'due_date'                  => $when->format('Y-m-d'),
            'subscription_type'         => $req->subscription_type,
            'total_trips'               => $expectedTripsCount,
            'completed_trips'           => $expectedTripsCount,
            'calculated_amount'         => $totalAmount,
            'action_taken'              => 'settled',
            'payment_method'            => 'wallet',
            'paid_at'                   => $when,
            'created_at'                => $when,
            'updated_at'                => $when,
        ]);
    }

    /** platform_finances.parent_id يخزن ParentModel::id (=users.id فعلياً هنا) */
    private function parentModelIdFor(User $parent): int
    {
        return $parent->id;
    }

    // =====================================================================
    // 3) 8 اشتراكات نشطة جارية الآن - تُنتج بالضبط 15 رحلة in_progress اليوم
    //    (راجع رأس الملف لشرح كيفية توزيع 15 = 7×2 + 1×1)
    // =====================================================================
    private function seedActiveBundles(): void
    {
        $this->command?->info('3️⃣  8 اشتراكات نشطة (تولّد 15 رحلة "in_progress" اليوم بالضبط)...');

        $liveTotal = 0;

        // 1) driver13 × parent3 (الطفلان #1 و #2)
        $liveTotal += $this->createBundle(13, 3, [0, 1], true);
        // 2) driver22 × parent3 (الطفل #3) - هذا هو السائق الأصلي في سيناريو استثناء الغياب
        $liveTotal += $this->createBundle(22, 3, [2], true);
        // 3) driver14 × parent4 (الطفلان #1 و #2)
        $liveTotal += $this->createBundle(14, 4, [0, 1], true);
        // 4) driver26 × parent4 (الطفل #3)
        $liveTotal += $this->createBundle(26, 4, [2], true);
        // 5) driver21 × parent5 (الطفل #1) - سعة جزئية
        $liveTotal += $this->createBundle(21, 5, [0], true);
        // 6) driver20 × parent5 (الطفل #2) - سعة ستُملأ بالكامل لاحقاً
        $liveTotal += $this->createBundle(20, 5, [1], true);
        // 7) driver19 × parent6 (الطفل #1) - نوبة صباحية فقط (رحلة واحدة فقط اليوم)
        $liveTotal += $this->createBundle(19, 6, [0], false);
        // 8) driver28 × parent6 (الطفل #2)
        $liveTotal += $this->createBundle(28, 6, [1], true);

        $this->report['live_trips_today'] = $liveTotal;
        $this->command?->line("   ✅ إجمالي رحلات in_progress اليوم: {$liveTotal}");
    }

    /**
     * ينشئ حِزمة اشتراك نشط كاملة (طلب + اشتراكات + مسارات + رحلات + تسوية مالية) -
     * أو، إذا كانت الحِزمة موجودة بالفعل من تشغيل سابق (نفس السائق+ولي الأمر+حالة
     * accepted)، يكتفي بإكمال رحلات "اليوم" الناقصة فقط دون إعادة إنشاء أي شيء
     * (ودون لمس المحفظة/الخزينة مرة ثانية) - هذا يجعل السيدر قابلاً للاستئناف
     * بأمان لو تعطّل التنفيذ في منتصف الطريق.
     */
    private function createBundle(int $driverIdx, int $parentIdx, array $childIndexes, bool $hasAfternoon): int
    {
        $driver  = $this->D[$driverIdx]['driver'];
        $vehicle = $this->D[$driverIdx]['vehicle'];
        $parent  = $this->P[$parentIdx]['user'];
        $addr    = $this->P[$parentIdx]['address'];
        $allChildren = $this->P[$parentIdx]['children'];
        $children = array_map(fn ($i) => $allChildren[$i], $childIndexes);

        $existingReq = SubscriptionRequest::where('driver_id', $driver->id)->where('parent_id', $parent->id)->where('status', 'accepted')->first();

        if ($existingReq) {
            $morning = RouteModel::where('subscription_request_id', $existingReq->id)->where('route_type', 'Morning')->first();
            $afternoon = RouteModel::where('subscription_request_id', $existingReq->id)->where('route_type', 'Afternoon')->first();
            $subs = ActiveSubscription::where('subscription_request_id', $existingReq->id)->get()->all();

            $hasToday = Trip::where('driver_id', $driver->id)->where('trip_date', $this->today->format('Y-m-d'))->exists();
            if ($hasToday) {
                return 0; // مكتمل بالفعل من تشغيل سابق - لا شيء لفعله
            }

            // إكمال رحلة/رحلات "اليوم" الناقصة فقط
            $liveCount = 0;
            $this->createShiftTrip($driver, $morning, $children, $subs, $this->today, 'morning', 'in_progress');
            $liveCount++;
            if ($afternoon) {
                $this->createShiftTrip($driver, $afternoon, $children, $subs, $this->today, 'afternoon', 'in_progress');
                $liveCount++;
            }
            return $liveCount;
        }

        $req = $this->createRequestWithChildren(
            parent:      $parent,
            children:    $children,
            driver:      $driver,
            vehicle:     $vehicle,
            parentAddr:  $addr,
            startDate:   $this->activeStart,
            endDate:     $this->activeEnd,
            status:      'accepted',
            respondedAt: $this->activeStart->copy()->subDays(2),
        );

        $subs = $this->createActiveSubscriptionsForRequest($req, $children, 'active');
        [$morning, $afternoon] = $this->createRoutePair($req, $driver, $vehicle, $children, $addr, $hasAfternoon);
        foreach ($subs as $s) {
            $s->route_id = $morning->id;
            $s->save();
        }

        $liveCount = $this->generateTripsForRange($driver, $morning, $afternoon, $children, $subs, $this->activeStart, $this->activeEnd);

        $workDays = $this->workingDaysBetween($this->activeStart, $this->activeEnd);
        $expectedTrips = $workDays * ($hasAfternoon ? 2 : 1);
        $this->settleMultiDayFinancials($req, $parent, $driver, $expectedTrips, $req->responded_at);

        return $liveCount;
    }

    // =====================================================================
    // 4) اشتراكان سابقان مكتملان بالكامل (تاريخ مُسدَّد بالكامل)
    // =====================================================================
    private function seedPastCompletedSubscriptions(): void
    {
        $this->command?->info('4️⃣  اشتراكان سابقان مكتملان (تاريخ مُسدَّد)...');

        if (SubscriptionRequest::where('driver_id', $this->D[13]['driver']->id)->where('parent_id', $this->P[3]['user']->id)->where('status', 'accepted')->whereDate('end_date', '<', $this->activeStart)->exists()) {
            $this->command?->line('   ⏭  موجودة بالفعل - تخطي');
            return;
        }

        // أ) parent3 c1 مع driver13 - فصل سابق انتهى قبل بداية الاشتراك النشط الحالي
        $this->createCompletedTerm(13, 3, [0], true, $this->activeStart->copy()->subWeeks(6)->startOfWeek(Carbon::SATURDAY), 20);

        // ب) parent4 c3 مع driver27 - الفصل الذي سبق انتقاله لـ driver26 (ويُثبت السبب وراء
        //    مكافأة driver27 في محرك القرار AI: خدمة سابقة حقيقية بتقييم ممتاز)
        $this->createCompletedTerm(27, 4, [2], true, $this->activeStart->copy()->subWeeks(5)->startOfWeek(Carbon::SATURDAY), 18);
    }

    private function createCompletedTerm(int $driverIdx, int $parentIdx, array $childIndexes, bool $hasAfternoon, Carbon $start, int $days): void
    {
        $end = $start->copy()->addDays($days);
        // نضمن الانتهاء قبل بداية الفترة النشطة الحالية بيوم عمل واحد على الأقل
        if ($end->gte($this->activeStart)) {
            $end = $this->activeStart->copy()->subDay();
        }

        $driver  = $this->D[$driverIdx]['driver'];
        $vehicle = $this->D[$driverIdx]['vehicle'];
        $parent  = $this->P[$parentIdx]['user'];
        $addr    = $this->P[$parentIdx]['address'];
        $allChildren = $this->P[$parentIdx]['children'];
        $children = array_map(fn ($i) => $allChildren[$i], $childIndexes);

        $req = $this->createRequestWithChildren(
            parent: $parent, children: $children, driver: $driver, vehicle: $vehicle, parentAddr: $addr,
            startDate: $start, endDate: $end, status: 'accepted', respondedAt: $start->copy()->subDays(2),
        );

        $subs = $this->createActiveSubscriptionsForRequest($req, $children, 'completed');
        [$morning, $afternoon] = $this->createRoutePair($req, $driver, $vehicle, $children, $addr, $hasAfternoon);
        foreach ($subs as $s) { $s->route_id = $morning->id; $s->save(); }

        $this->generateTripsForRange($driver, $morning, $afternoon, $children, $subs, $start, $end);

        $workDays = $this->workingDaysBetween($start, $end);
        $this->settleMultiDayFinancials($req, $parent, $driver, $workDays * ($hasAfternoon ? 2 : 1), $req->responded_at);
    }

    // =====================================================================
    // 5) اشتراك مُلغى (parent4 c4 مع driver16 - بدون تكييف، أُلغي بعد أسبوع)
    // =====================================================================
    private function seedCancelledSubscription(): void
    {
        $this->command?->info('5️⃣  اشتراك ملغى (parent4 c4 × driver16، أُلغي بعد أسبوع بسبب عدم التكييف)...');

        if (SubscriptionRequest::where('driver_id', $this->D[16]['driver']->id)->where('parent_id', $this->P[4]['user']->id)->exists()) {
            $this->command?->line('   ⏭  موجود بالفعل - تخطي');
            return;
        }

        $driver  = $this->D[16]['driver'];
        $vehicle = $this->D[16]['vehicle'];
        $parent  = $this->P[4]['user'];
        $addr    = $this->P[4]['address'];
        $child   = $this->P[4]['children'][3];

        $start = $this->activeStart->copy()->subWeeks(4)->startOfWeek(Carbon::SATURDAY);
        $end   = $start->copy()->addMonth();
        $cancelledAt = $start->copy()->addDays(7);

        $req = $this->createRequestWithChildren(
            parent: $parent, children: [$child], driver: $driver, vehicle: $vehicle, parentAddr: $addr,
            startDate: $start, endDate: $end, status: 'accepted', respondedAt: $start->copy()->subDays(1),
        );

        $subs = $this->createActiveSubscriptionsForRequest($req, [$child], 'active');
        [$morning, $afternoon] = $this->createRoutePair($req, $driver, $vehicle, [$child], $addr, true);
        foreach ($subs as $s) { $s->route_id = $morning->id; $s->save(); }

        // رحلات الأسبوع الأول فقط (قبل الإلغاء) مكتملة، والبقية أُلغيت قبل تنفيذها
        $this->generateTripsForRange($driver, $morning, $afternoon, [$child], $subs, $start, $cancelledAt->copy()->subDay());

        $workDaysBeforeCancel = $this->workingDaysBetween($start, $cancelledAt->copy()->subDay());
        $this->settleMultiDayFinancials($req, $parent, $driver, $workDaysBeforeCancel * 2, $req->responded_at);

        foreach ($subs as $s) {
            DB::table('active_subscriptions')->where('id', $s->id)->update([
                'status'              => 'cancelled',
                'cancelled_at'        => $cancelledAt,
                'cancelled_by'        => 'parent',
                'cancellation_reason' => 'المركبة بدون تكييف والجو حار جداً - طلب ولي الأمر الإلغاء والتحويل لسائق آخر.',
                'updated_at'          => $cancelledAt,
            ]);
        }
    }

    // =====================================================================
    // 6) طلب معلّق (parent4 c4 → driver31) بانتظار قرار السائق
    // =====================================================================
    private function seedPendingRequest(): void
    {
        $this->command?->info('6️⃣  طلب اشتراك معلّق (parent4 c4 → driver31)...');

        $driver  = $this->D[31]['driver'];
        $vehicle = $this->D[31]['vehicle'];
        $parent  = $this->P[4]['user'];
        $addr    = $this->P[4]['address'];
        $child   = $this->P[4]['children'][3];

        if (SubscriptionRequest::where('parent_id', $parent->id)->where('driver_id', $driver->id)->where('status', 'pending')->exists()) {
            $this->command?->line('   ⏭  موجود بالفعل - تخطي');
            return;
        }

        $start = $this->today->copy()->addWeek()->startOfWeek(Carbon::SATURDAY);
        $end   = $start->copy()->addMonth();

        $this->createRequestWithChildren(
            parent: $parent, children: [$child], driver: $driver, vehicle: $vehicle, parentAddr: $addr,
            startDate: $start, endDate: $end, status: 'pending', respondedAt: null,
        );
    }

    // =====================================================================
    // 7) طلب مرفوض (parent3 c4 → driver17 - خارج نطاق منطقته الجغرافية)
    // =====================================================================
    private function seedRejectedRequest(): void
    {
        $this->command?->info('7️⃣  طلب اشتراك مرفوض (parent3 c4 → driver17، خارج المنطقة)...');

        $driver  = $this->D[17]['driver'];
        $vehicle = $this->D[17]['vehicle'];
        $parent  = $this->P[3]['user'];
        $addr    = $this->P[3]['address'];
        $child   = $this->P[3]['children'][3];

        if (SubscriptionRequest::where('parent_id', $parent->id)->where('driver_id', $driver->id)->where('status', 'rejected')->exists()) {
            $this->command?->line('   ⏭  موجود بالفعل - تخطي');
            return;
        }

        $start = $this->activeStart->copy()->subWeeks(3);
        $end   = $start->copy()->addMonth();

        $req = $this->createRequestWithChildren(
            parent: $parent, children: [$child], driver: $driver, vehicle: $vehicle, parentAddr: $addr,
            startDate: $start, endDate: $end, status: 'rejected', respondedAt: $start->copy()->addDay(),
        );

        $req->rejection_reason = 'المنطقة الجغرافية خارج نطاق تغطية السائق (يخدم أبو سليم والمناطق المجاورة فقط).';
        $req->save();
    }

    // =====================================================================
    // 8) اشتراك مستقبلي مجدول بالكامل - لم يبدأ بعد (parent7 c1 × driver23)
    // =====================================================================
    private function seedFutureScheduledSubscription(): void
    {
        $this->command?->info('8️⃣  اشتراك مستقبلي مجدول لم يبدأ بعد (parent7 c1 × driver23)...');

        $driver  = $this->D[23]['driver'];
        $vehicle = $this->D[23]['vehicle'];
        $parent  = $this->P[7]['user'];
        $addr    = $this->P[7]['address'];
        $child   = $this->P[7]['children'][0];

        $start = $this->today->copy()->addWeeks(2)->startOfWeek(Carbon::SATURDAY);
        $end   = $start->copy()->addWeeks(4);
        $this->report['future_scheduled'] = "parent7@darby.ly / driver23@darby.ly — يبدأ {$start->format('Y-m-d')} (لم تبدأ أي رحلة بعد، الكل pending)";

        if (SubscriptionRequest::where('parent_id', $parent->id)->where('driver_id', $driver->id)->exists()) {
            $this->command?->line('   ⏭  موجود بالفعل - تخطي');
            return;
        }

        $req = $this->createRequestWithChildren(
            parent: $parent, children: [$child], driver: $driver, vehicle: $vehicle, parentAddr: $addr,
            startDate: $start, endDate: $end, status: 'accepted', respondedAt: now(),
        );

        $subs = $this->createActiveSubscriptionsForRequest($req, [$child], 'active');
        [$morning, $afternoon] = $this->createRoutePair($req, $driver, $vehicle, [$child], $addr, true);
        foreach ($subs as $s) { $s->route_id = $morning->id; $s->save(); }

        // كل الرحلات مستقبلية (pending) - لم يبدأ أي شيء بعد
        $this->generateTripsForRange($driver, $morning, $afternoon, [$child], $subs, $start, $end);

        $workDays = $this->workingDaysBetween($start, $end);
        $this->settleMultiDayFinancials($req, $parent, $driver, $workDays * 2, now());
    }

    // =====================================================================
    // 9) حجز يوم واحد (single_day) سابق - يُثبت مسار escrow/per-trip الكامل
    //    (المسار المختلف تماماً عن multi_day: حجز مبدئي "held" ثم "تحصيل"
    //    عند اكتمال الرحلة عبر platform_finance_trip_settlements)
    // =====================================================================
    private function seedSingleDayHistoricalBooking(): void
    {
        $this->command?->info('9️⃣  حجز "يوم واحد" (single_day) سابق - مسار escrow الكامل...');

        $driver  = $this->D[31]['driver'];
        $vehicle = $this->D[31]['vehicle'];
        $parent  = $this->P[6]['user'];
        $addr    = $this->P[6]['address'];
        $child   = $this->P[6]['children'][0];

        if (SubscriptionRequest::where('parent_id', $parent->id)->where('driver_id', $driver->id)->where('subscription_type', 'single_day')->exists()) {
            $this->command?->line('   ⏭  موجود بالفعل - تخطي');
            return;
        }

        $day = $this->today->copy()->subDays(25);
        while (!$this->isWorkingDay($day)) $day->subDay();

        $req = $this->createRequestWithChildren(
            parent: $parent, children: [$child], driver: $driver, vehicle: $vehicle, parentAddr: $addr,
            startDate: $day, endDate: $day, status: 'accepted', respondedAt: $day->copy()->subHours(20),
            subscriptionType: 'single_day',
        );

        $subs = $this->createActiveSubscriptionsForRequest($req, [$child], 'completed');
        [$morning, $afternoon] = $this->createRoutePair($req, $driver, $vehicle, [$child], $addr, true);
        foreach ($subs as $s) { $s->route_id = $morning->id; $s->save(); }

        $morningTrip   = $this->createShiftTrip($driver, $morning, [$child], $subs, $day, 'morning', 'completed');
        $afternoonTrip = $this->createShiftTrip($driver, $afternoon, [$child], $subs, $day, 'afternoon', 'completed');

        // --- مسار escrow/single_day الحقيقي: حجز عند القبول ثم تحصيل عند اكتمال كل رحلة ---
        $amountCents  = (int) round(((float) $req->total_amount_after_discount) * 100);
        $commCents    = (int) round(((float) $req->platform_commission_amount) * 100);
        $netCents     = (int) round(((float) $req->driver_net_amount) * 100);

        $balanceBefore = (int) ($parent->fresh()->balance ?? 0);
        $parent->withdraw($amountCents);
        $balanceAfter = (int) ($parent->fresh()->balance ?? 0);

        $vault = MasterEscrowVault::first() ?? MasterEscrowVault::create([]);
        $vault->increment('parents_escrow_pool', $amountCents);

        FinancialLedger::create([
            'transaction_id' => (string) Str::uuid(), 'reference_number' => "REQ-HOLD-{$req->id}",
            'source_account' => "parent_wallet:{$parent->id}", 'destination_account' => 'parents_escrow_pool',
            'amount' => $amountCents, 'balance_before' => $balanceBefore, 'balance_after' => $balanceAfter,
            'type' => 'subscription_hold', 'status' => 'completed',
            'metadata' => json_encode(['subscription_request_id' => $req->id]),
            'created_at' => $req->responded_at, 'updated_at' => $req->responded_at,
        ]);

        $platformFinance = PlatformFinance::create([
            'subscription_request_id'    => $req->id,
            'parent_id'                  => $parent->id,
            'driver_id'                  => $driver->id,
            'total_amount'               => $req->total_amount_after_discount,
            'platform_commission_rate'   => (float) $req->platform_commission_rate,
            'platform_commission_amount' => $req->platform_commission_amount,
            'driver_net_amount'          => $req->driver_net_amount,
            'expected_trips_count'       => 2,
            'settled_trips_count'        => 0,
            'settled_amount'             => 0,
            'status'                     => PlatformFinance::STATUS_HELD,
            'held_at'                    => $req->responded_at,
        ]);

        foreach ([$morningTrip, $afternoonTrip] as $trip) {
            $shareCents      = intdiv($amountCents, 2);
            $shareCommission = (int) round($shareCents * ((float) $req->platform_commission_rate) / 100);
            $shareNet        = $shareCents - $shareCommission;

            TripEscrowHold::create([
                'trip_id' => $trip->id, 'parent_id' => $parent->id, 'driver_id' => $driver->id,
                'amount' => $shareCents, 'hold_status' => 'released_available',
                'held_at' => $req->responded_at, 'captured_at' => $trip->completed_at,
                'available_at' => $trip->completed_at?->copy()?->addDays(3),
            ]);

            DB::table('platform_finance_trip_settlements')->insert([
                'platform_finance_id' => $platformFinance->id, 'trip_id' => $trip->id,
                'gross_amount' => $shareCents / 100, 'commission_amount' => $shareCommission / 100,
                'driver_net_amount' => $shareNet / 100, 'created_at' => $trip->completed_at, 'updated_at' => $trip->completed_at,
            ]);

            $vault->decrement('parents_escrow_pool', $shareCents);
            $vault->increment('platform_revenue_pool', $shareCommission);
            $vault->increment('driver_available_pool', $shareNet);
            $driver->deposit($shareNet);

            FinancialLedger::create([
                'transaction_id' => (string) Str::uuid(), 'reference_number' => "TRIP-{$trip->id}-PAYOUT",
                'source_account' => 'parents_escrow_pool', 'destination_account' => "driver_wallet:{$driver->id}",
                'amount' => $shareNet, 'balance_before' => 0, 'balance_after' => 0,
                'type' => 'driver_payout', 'status' => 'completed',
                'metadata' => json_encode(['trip_id' => $trip->id]),
                'created_at' => $trip->completed_at, 'updated_at' => $trip->completed_at,
            ]);
        }

        $platformFinance->update(['settled_trips_count' => 2, 'settled_amount' => $req->total_amount_after_discount, 'status' => PlatformFinance::STATUS_COMPLETED, 'settled_at' => $afternoonTrip->completed_at]);

        Invoice::create([
            'subscription_request_id' => $req->id, 'parent_id' => $parent->id, 'driver_id' => $driver->id,
            'invoice_number' => 'INV-' . $req->id . '-' . Str::upper(Str::random(6)), 'amount' => $req->total_amount_after_discount,
            'type' => 'receipt', 'status' => 'paid', 'due_date' => $day->format('Y-m-d'),
            'subscription_type' => 'single_day', 'total_trips' => 2, 'completed_trips' => 2,
            'calculated_amount' => $req->total_amount_after_discount, 'action_taken' => 'settled', 'payment_method' => 'wallet',
            'paid_at' => $afternoonTrip->completed_at, 'created_at' => $req->responded_at, 'updated_at' => $afternoonTrip->completed_at,
        ]);
    }

    // =====================================================================
    // 10) غيابات السائقين: driver22 (يشمل "الخميس القادم" - محور سيناريو
    //     الاستثناء) + driver23 (طلب غياب معلّق لتاريخ بعيد لا يمس أي اشتراك)
    // =====================================================================
    private function seedDriverAbsences(): void
    {
        $this->command?->info('🔟 غيابات السائقين (driver22 يوم الخميس القادم + driver23 طلب معلّق)...');

        $driver22 = $this->D[22]['driver'];
        $driver23 = $this->D[23]['driver'];

        $this->report['exception_scenario'] = [
            'driver_absent'   => 'driver22@darby.ly',
            'thursday_date'   => $this->exceptionThursday->format('Y-m-d'),
            'blocked_day'     => $this->exceptionBlockedDay->format('Y-m-d'),
            'affected_child'  => $this->P[3]['children'][2]->full_name . ' (parent3@darby.ly، الطفل الثالث)',
            'coverage_driver' => 'driver31@darby.ly',
        ];

        if (DB::table('driver_absences')->where('driver_id', $driver22->id)->where('absence_date', $this->exceptionThursday->format('Y-m-d'))->exists()) {
            $this->command?->line('   ⏭  موجود بالفعل - تخطي');
            return;
        }

        // غياب ماضٍ (موافَق عليه) لإثراء السجل
        DB::table('driver_absences')->insert([
            'driver_id'    => $driver22->id,
            'absence_date' => $this->today->copy()->subDays(9)->format('Y-m-d'),
            'reason'       => 'مراجعة طبية دورية.',
            'status'       => 'approved',
            'reviewed_by'  => $this->adminOpsId,
            'reviewed_at'  => $this->today->copy()->subDays(10),
            'admin_notes'  => 'موافقة عادية.',
            'created_at'   => $this->today->copy()->subDays(11),
            'updated_at'   => $this->today->copy()->subDays(10),
        ]);

        // ★ الغياب المحوري: الخميس القادم بالضبط - داخل فترة اشتراك parent3/c3 النشط
        DB::table('driver_absences')->insert([
            'driver_id'    => $driver22->id,
            'absence_date' => $this->exceptionThursday->format('Y-m-d'),
            'reason'       => 'ظرف عائلي طارئ - إجازة يوم واحد معتمدة.',
            'status'       => 'approved',
            'reviewed_by'  => $this->adminOpsId,
            'reviewed_at'  => now()->subHours(6),
            'admin_notes'  => 'مُعتمد من مشرف العمليات.',
            'created_at'   => now()->subHours(10),
            'updated_at'   => now()->subHours(6),
        ]);

        // غياب معلّق (لم يُبتّ فيه بعد) لسائق آخر - بعيد تماماً عن فترة اشتراك parent7
        DB::table('driver_absences')->insert([
            'driver_id'    => $driver23->id,
            'absence_date' => $this->today->copy()->addWeeks(10)->format('Y-m-d'),
            'reason'       => 'سفر خارج المدينة لمدة يوم - بانتظار موافقة الإدارة.',
            'status'       => 'pending',
            'reviewed_by'  => null,
            'reviewed_at'  => null,
            'admin_notes'  => null,
            'created_at'   => now()->subHours(3),
            'updated_at'   => now()->subHours(3),
        ]);
    }

    // =====================================================================
    // 11) تعبئة سعة المقاعد: driver20 (ممتلئ بالكامل) + driver21 (جزئياً)
    // =====================================================================
    private function seedSeatCapacityTopUps(): void
    {
        $this->command?->info('1️⃣1️⃣ تعبئة سعة المقاعد (driver20 كامل، driver21 جزئي)...');

        $driver20 = $this->D[20]['driver'];
        $vehicle20 = $this->D[20]['vehicle'];
        $driver21 = $this->D[21]['driver'];
        $vehicle21 = $this->D[21]['vehicle'];

        if (DB::table('driver_seat_slots')->where('driver_id', $driver20->id)->exists()) {
            $this->command?->line('   ⏭  موجودة بالفعل - تخطي');
            return;
        }

        $slots = DriverSeatSlot::ALL_SLOTS;
        $start = $this->today->copy();
        $end   = $this->today->copy()->addDays(14);

        // driver20: تعبئة كاملة (booked = capacity) لكل الفترات القادمة أسبوعين
        $day = $start->copy();
        while ($day->lte($end)) {
            if ($this->isWorkingDay($day)) {
                foreach ($slots as $slot) {
                    DB::table('driver_seat_slots')->updateOrInsert(
                        ['driver_id' => $driver20->id, 'slot' => $slot, 'date' => $day->format('Y-m-d')],
                        ['booked' => $vehicle20->capacity_manual, 'created_at' => now(), 'updated_at' => now()]
                    );
                }
            }
            $day->addDay();
        }

        // driver21: بعض الأيام ممتلئة تماماً وبعضها متاح جزئياً (سعة=9، محجوز يتراوح 6..9)
        $day = $start->copy();
        $i = 0;
        while ($day->lte($end)) {
            if ($this->isWorkingDay($day)) {
                $booked = $i % 3 === 0 ? $vehicle21->capacity_manual : max(0, $vehicle21->capacity_manual - rand(1, 3));
                foreach ($slots as $slot) {
                    DB::table('driver_seat_slots')->updateOrInsert(
                        ['driver_id' => $driver21->id, 'slot' => $slot, 'date' => $day->format('Y-m-d')],
                        ['booked' => $booked, 'created_at' => now(), 'updated_at' => now()]
                    );
                }
                $i++;
            }
            $day->addDay();
        }
    }

    // =====================================================================
    // 12) سيناريوهات محرك قرار الذكاء الاصطناعي (AiDecisionService) - محاكاة
    //     النتيجة النهائية مباشرة (بدون استدعاء خدمة AI الخارجية الحيّة، لأنها
    //     غير مضمونة التشغيل وقت تنفيذ السيدر) لكن بنفس قواعد القرار تماماً.
    // =====================================================================
    private function seedAiDecisionScenarios(): void
    {
        $this->command?->info('1️⃣2️⃣ سيناريوهات قرار AI (موقوف/إنذار/مكافأة/التئام تلقائي)...');

        if (DB::table('ai_decision_audits')->where('driver_id', $this->D[25]['driver']->id)->exists()) {
            $this->command?->line('   ⏭  موجودة بالفعل - تخطي');
            return;
        }

        $this->seedModerateViolation();
        $this->seedFormalWarning();
        $this->seedReward();
        $this->seedSelfHealCandidate();
    }

    /** driver25: مخالفة متوسطة (شكويان متطابقتا الفئة من وليّي أمر مختلفين خلال 15 يوماً) → إيقاف 24 ساعة */
    private function seedModerateViolation(): void
    {
        $driver = $this->D[25]['driver'];
        $ratingBefore = (float) $driver->rating_avg;

        $review1 = DB::table('driver_reviews')->insertGetId([
            'parent_id' => $this->P[3]['user']->id, 'driver_id' => $driver->id, 'subscription_request_id' => null,
            'rating' => 2, 'comment' => 'السائق يتأخر بشكل متكرر عن موعد الاستلام الصباحي بأكثر من 10 دقائق.',
            'ai_label' => 'Negative', 'ai_category' => 'Punctuality', 'ai_severity' => 1,
            'ai_classified_at' => now()->subDays(3), 'ai_sentiment_pred' => 2, 'ai_sentiment_confidence' => 0.91,
            'ai_category_pred' => 1, 'ai_category_confidence' => 0.88, 'is_processed_in_decision' => 0,
            'status' => 'active', 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
        ]);
        $review2 = DB::table('driver_reviews')->insertGetId([
            'parent_id' => $this->P[5]['user']->id, 'driver_id' => $driver->id, 'subscription_request_id' => null,
            'rating' => 2, 'comment' => 'تأخر السائق اليوم أكثر من 15 دقيقة عن الموعد المتفق عليه دون إشعار مسبق.',
            'ai_label' => 'Negative', 'ai_category' => 'Punctuality', 'ai_severity' => 1,
            'ai_classified_at' => now()->subHours(20), 'ai_sentiment_pred' => 2, 'ai_sentiment_confidence' => 0.87,
            'ai_category_pred' => 1, 'ai_category_confidence' => 0.85, 'is_processed_in_decision' => 1,
            'status' => 'active', 'created_at' => now()->subHours(20), 'updated_at' => now()->subHours(20),
        ]);

        $suspendedUntil = now()->addHours(20);
        DB::table('driver_reviews')->where('id', $review2)->update([
            'ai_decision_code' => 2, 'ai_decision_confidence' => 0.82,
        ]);

        DB::table('ai_decision_audits')->insert([
            'driver_id' => $driver->id, 'review_id' => $review2, 'current_rating' => round($ratingBefore * 0.95, 2),
            'previous_warnings' => 0, 'trips_count' => 10, 'sentiment_pred' => 2, 'sentiment_confidence' => 0.87,
            'category_pred' => 1, 'category_confidence' => 0.85, 'decision_code' => 2, 'decision_name' => 'MODERATE_VIOLATION',
            'decision_confidence' => 0.82, 'probabilities' => json_encode([0 => 0.05, 1 => 0.03, 2 => 0.82, 3 => 0.08, 4 => 0.02]),
            'action_applied' => 'rating_penalty_5pct_suspend_24h', 'action_details' => json_encode(['category_label' => 'Punctuality', 'distinct_complainants' => 2]),
            'suspended_until' => $suspendedUntil, 'admin_override' => 0, 'admin_id' => null,
            'created_at' => now()->subHours(20), 'updated_at' => now()->subHours(20),
        ]);

        $driver->update([
            'rating_avg'            => round($ratingBefore * 0.95, 2),
            'active_warnings_count' => 1,
            'suspended_until'       => $suspendedUntil,
            'last_incident_at'      => now()->subHours(20),
            'is_searchable'         => 0,
        ]);

        AdminAlert::create([
            'driver_id' => $driver->id, 'risk_level' => 'HIGH', 'alert_type' => 'ai_decision', 'severity' => 2,
            'title' => 'مخالفة متوسطة - إيقاف مؤقت 24 ساعة', 'message' => 'شكويان متطابقتا الفئة (Punctuality) من وليّي أمر مختلفين خلال 15 يوماً.',
            'reasoning' => 'negative_ratio مرتفع + شكويان من مصدرين مختلفين بنفس الفئة خلال نافذة 15 يوماً.',
            'ai_metrics' => json_encode(['decision_code' => 2, 'confidence' => 0.82]),
            'evaluated_reviews' => json_encode([$review1, $review2]), 'is_resolved' => 0, 'action_required' => 'review', 'is_read' => 0,
        ]);
    }

    /** driver26: إنذار رسمي دون إيقاف (سلبية واحدة تكفي لتجاوز عتبة 40%) */
    private function seedFormalWarning(): void
    {
        $driver = $this->D[26]['driver'];
        $ratingBefore = (float) $driver->rating_avg;

        $req = SubscriptionRequest::where('driver_id', $driver->id)->where('status', 'accepted')->first();

        $reviewId = DB::table('driver_reviews')->insertGetId([
            'parent_id' => $this->P[4]['user']->id, 'driver_id' => $driver->id, 'subscription_request_id' => $req?->id,
            'rating' => 2, 'comment' => 'السائق كان فظاً بعض الشيء عند التواصل معه بخصوص تغيير موعد الاستلام.',
            'ai_label' => 'Negative', 'ai_category' => 'Behavior', 'ai_severity' => 1,
            'ai_classified_at' => now()->subDays(2), 'ai_sentiment_pred' => 2, 'ai_sentiment_confidence' => 0.79,
            'ai_category_pred' => 2, 'ai_category_confidence' => 0.74, 'is_processed_in_decision' => 1,
            'ai_decision_code' => 3, 'ai_decision_confidence' => 0.68,
            'status' => 'active', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
        ]);

        DB::table('ai_decision_audits')->insert([
            'driver_id' => $driver->id, 'review_id' => $reviewId, 'current_rating' => round($ratingBefore * 0.95, 2),
            'previous_warnings' => 0, 'trips_count' => 30, 'sentiment_pred' => 2, 'sentiment_confidence' => 0.79,
            'category_pred' => 2, 'category_confidence' => 0.74, 'decision_code' => 3, 'decision_name' => 'FORMAL_WARNING',
            'decision_confidence' => 0.68, 'probabilities' => json_encode([0 => 0.10, 1 => 0.02, 2 => 0.18, 3 => 0.68, 4 => 0.02]),
            'action_applied' => 'rating_penalty_5pct_formal_warning', 'action_details' => json_encode(['category_label' => 'Behavior']),
            'suspended_until' => null, 'admin_override' => 0, 'admin_id' => null,
            'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
        ]);

        $driver->update([
            'rating_avg'            => round($ratingBefore * 0.95, 2),
            'active_warnings_count' => 1,
            'last_incident_at'      => now()->subDays(2),
        ]);

        AdminAlert::create([
            'driver_id' => $driver->id, 'risk_level' => 'HIGH', 'alert_type' => 'ai_decision', 'severity' => 1,
            'title' => 'إنذار رسمي - سلوك مع ولي الأمر', 'message' => 'شكوى سلبية بفئة السلوك تجاوزت عتبة الإنذار (40%) دون داعٍ للإيقاف الفوري.',
            'reasoning' => 'negative_ratio ضمن النافذة تجاوز 0.40 لكن دون تكرار من مصادر متعددة أو خطورة أمان.',
            'ai_metrics' => json_encode(['decision_code' => 3, 'confidence' => 0.68]),
            'evaluated_reviews' => json_encode([$reviewId]), 'is_resolved' => 0, 'action_required' => null, 'is_read' => 0,
        ]);
    }

    /** driver27: مكافأة (سلسلة تقييمات إيجابية من مصادر متعددة، لا شكاوى أمان نشطة) */
    private function seedReward(): void
    {
        $driver = $this->D[27]['driver'];
        $ratingBefore = (float) $driver->rating_avg;

        $req = SubscriptionRequest::where('driver_id', $driver->id)->where('status', 'accepted')->first();

        $r1 = DB::table('driver_reviews')->insertGetId([
            'parent_id' => $this->P[4]['user']->id, 'driver_id' => $driver->id, 'subscription_request_id' => $req?->id,
            'rating' => 5, 'comment' => 'سائق رائع، ملتزم بالمواعيد ويتعامل مع الأطفال برفق كبير. أنصح به بشدة.',
            'ai_label' => 'Positive', 'ai_category' => 'General', 'ai_severity' => 0,
            'ai_classified_at' => now()->subDays(10), 'ai_sentiment_pred' => 0, 'ai_sentiment_confidence' => 0.95,
            'ai_category_pred' => 0, 'ai_category_confidence' => 0.90, 'is_processed_in_decision' => 1,
            'status' => 'active', 'created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10),
        ]);
        $r2 = DB::table('driver_reviews')->insertGetId([
            'parent_id' => $this->P[6]['user']->id, 'driver_id' => $driver->id, 'subscription_request_id' => null,
            'rating' => 5, 'comment' => 'من أفضل السائقين الذين تعاملنا معهم - دقيق جداً في المواعيد ومركبته نظيفة ومريحة.',
            'ai_label' => 'Positive', 'ai_category' => 'Punctuality', 'ai_severity' => 0,
            'ai_classified_at' => now()->subDays(4), 'ai_sentiment_pred' => 0, 'ai_sentiment_confidence' => 0.93,
            'ai_category_pred' => 1, 'ai_category_confidence' => 0.89, 'is_processed_in_decision' => 1,
            'ai_decision_code' => 1, 'ai_decision_confidence' => 0.86,
            'status' => 'active', 'created_at' => now()->subDays(4), 'updated_at' => now()->subDays(4),
        ]);

        DB::table('ai_decision_audits')->insert([
            'driver_id' => $driver->id, 'review_id' => $r2, 'current_rating' => round(min(5, $ratingBefore * 1.03), 2),
            'previous_warnings' => 0, 'trips_count' => 40, 'sentiment_pred' => 0, 'sentiment_confidence' => 0.93,
            'category_pred' => 1, 'category_confidence' => 0.89, 'decision_code' => 1, 'decision_name' => 'REWARD',
            'decision_confidence' => 0.86, 'probabilities' => json_encode([0 => 0.05, 1 => 0.86, 2 => 0.03, 3 => 0.04, 4 => 0.02]),
            'action_applied' => 'rating_boost_3pct', 'action_details' => json_encode(['category_label' => 'Punctuality', 'positive_ratio' => 1.0]),
            'suspended_until' => null, 'admin_override' => 0, 'admin_id' => null,
            'created_at' => now()->subDays(4), 'updated_at' => now()->subDays(4),
        ]);

        $driver->update([
            'rating_avg'            => round(min(5, $ratingBefore * 1.03), 2),
            'active_warnings_count' => 0,
        ]);
    }

    /** driver28: مخالفة قديمة (35 يوماً) بلا تكرار منذ ذلك الحين - مرشح لالتئام تلقائي */
    private function seedSelfHealCandidate(): void
    {
        $driver = $this->D[28]['driver'];
        $ratingBefore = (float) $driver->rating_avg;
        $oldDate = now()->subDays(40);

        $reviewId = DB::table('driver_reviews')->insertGetId([
            'parent_id' => $this->P[6]['user']->id, 'driver_id' => $driver->id, 'subscription_request_id' => null,
            'rating' => 2, 'comment' => 'كانت المركبة غير نظيفة بشكل ملحوظ في تلك الرحلة.',
            'ai_label' => 'Negative', 'ai_category' => 'Vehicle_Condition', 'ai_severity' => 1,
            'ai_classified_at' => $oldDate, 'ai_sentiment_pred' => 2, 'ai_sentiment_confidence' => 0.72,
            'ai_category_pred' => 4, 'ai_category_confidence' => 0.70, 'is_processed_in_decision' => 1,
            'ai_decision_code' => 3, 'ai_decision_confidence' => 0.60,
            'status' => 'active', 'created_at' => $oldDate, 'updated_at' => $oldDate,
        ]);

        DB::table('ai_decision_audits')->insert([
            'driver_id' => $driver->id, 'review_id' => $reviewId, 'current_rating' => round($ratingBefore * 0.95, 2),
            'previous_warnings' => 0, 'trips_count' => 15, 'sentiment_pred' => 2, 'sentiment_confidence' => 0.72,
            'category_pred' => 4, 'category_confidence' => 0.70, 'decision_code' => 3, 'decision_name' => 'FORMAL_WARNING',
            'decision_confidence' => 0.60, 'probabilities' => json_encode([0 => 0.15, 1 => 0.02, 2 => 0.13, 3 => 0.60, 4 => 0.10]),
            'action_applied' => 'rating_penalty_5pct_formal_warning', 'action_details' => json_encode(['category_label' => 'Vehicle_Condition']),
            'suspended_until' => null, 'admin_override' => 0, 'admin_id' => null,
            'created_at' => $oldDate, 'updated_at' => $oldDate,
        ]);

        $driver->update([
            'rating_avg'            => round($ratingBefore * 0.95, 2),
            'active_warnings_count' => 1,
            'last_incident_at'      => $oldDate,
        ]);
    }

    // =====================================================================
    // 13) طلبات شحن وسحب - كل الحالات (مكتمل/معلّق/مرفوض) لأولياء أمور وسائقين
    // =====================================================================
    private function seedRechargeAndWithdrawalRequests(): void
    {
        $this->command?->info('1️⃣3️⃣ طلبات الشحن والسحب (كل الحالات)...');

        $sadad    = $this->paymentMethods['sadad'];
        $mobicash = $this->paymentMethods['mobicash'];
        $sahara   = $this->paymentMethods['sahara_bank'];

        if (RechargeRequest::where('parent_id', $this->P[3]['user']->id)->exists()) {
            $this->command?->line('   ⏭  طلبات الشحن/السحب موجودة بالفعل - تخطي');
        } else {

        // شحن مكتمل - parent3
        RechargeRequest::create([
            'parent_id' => $this->P[3]['user']->id, 'amount' => 500.00, 'payment_method' => 'sadad', 'payment_method_id' => $sadad->id,
            'reference_number' => 'SD-' . strtoupper(Str::random(10)), 'transaction_ref' => 'TX-' . strtoupper(Str::random(12)),
            'status' => 'completed', 'admin_id' => $this->adminFinanceId, 'completed_at' => now()->subDays(18),
            'created_at' => now()->subDays(18), 'updated_at' => now()->subDays(18),
        ]);
        // شحن معلّق - parent4
        RechargeRequest::create([
            'parent_id' => $this->P[4]['user']->id, 'amount' => 400.00, 'payment_method' => 'mobicash', 'payment_method_id' => $mobicash->id,
            'reference_number' => 'MC-' . strtoupper(Str::random(10)), 'status' => 'pending',
            'created_at' => now()->subHours(5), 'updated_at' => now()->subHours(5),
        ]);
        // شحن فاشل - parent5
        RechargeRequest::create([
            'parent_id' => $this->P[5]['user']->id, 'amount' => 200.00, 'payment_method' => 'sadad', 'payment_method_id' => $sadad->id,
            'reference_number' => 'SD-' . strtoupper(Str::random(10)), 'status' => 'failed',
            'notes' => 'فشلت عملية الدفع لدى مزوّد الخدمة - انتهت مهلة الجلسة.',
            'created_at' => now()->subDays(4), 'updated_at' => now()->subDays(4),
        ]);

        // سحب مُعتمد - driver13
        WithdrawalRequest::create([
            'driver_id' => $this->D[13]['driver']->id, 'amount' => 600.00, 'wallet_balance_at_request' => 1200.00,
            'status' => 'approved', 'payment_method_details' => json_encode(['method' => 'sahara_bank', 'holder_name' => 'مصطفى رمضان سالم الككلي', 'iban' => 'LY83002001000000000998877']),
            'admin_id' => $this->adminFinanceId, 'processed_at' => now()->subDays(10),
            'created_at' => now()->subDays(11), 'updated_at' => now()->subDays(10),
        ]);
        // سحب معلّق - driver14
        WithdrawalRequest::create([
            'driver_id' => $this->D[14]['driver']->id, 'amount' => 350.00, 'wallet_balance_at_request' => 700.00,
            'status' => 'pending', 'payment_method_details' => json_encode(['method' => 'sadad', 'sadad_number' => '0919000014']),
            'created_at' => now()->subDays(1), 'updated_at' => now()->subDays(1),
        ]);
        // سحب مرفوض - driver20
        WithdrawalRequest::create([
            'driver_id' => $this->D[20]['driver']->id, 'amount' => 2000.00, 'wallet_balance_at_request' => 400.00,
            'status' => 'rejected', 'rejection_reason' => 'المبلغ المطلوب يتجاوز الرصيد المتاح في المحفظة.',
            'payment_method_details' => json_encode(['method' => 'mobicash']), 'admin_id' => $this->adminFinanceId,
            'processed_at' => now()->subDays(6), 'created_at' => now()->subDays(7), 'updated_at' => now()->subDays(6),
        ]);

        } // نهاية شرط recharge/withdrawal الأساسية

        if (DB::table('driver_recharge_requests')->where('driver_id', $this->D[21]['driver']->id)->exists()) {
            $this->command?->line('   ⏭  driver_recharge_requests موجودة بالفعل - تخطي');
            return;
        }

        // driver_recharge_requests (شحن السائق لعمولات/رسوم إضافية) - حالتان
        DB::table('driver_recharge_requests')->insert([
            [
                'driver_id' => $this->D[21]['driver']->id, 'payment_method_id' => $sahara->id, 'amount' => 150.00,
                'proof_image_url' => 'https://cdn.darby.ly/qa/proofs/driver21_recharge.jpg', 'reference_number' => 'DR-' . strtoupper(Str::random(8)),
                'status' => 'approved', 'admin_id' => $this->adminFinanceId, 'approved_at' => now()->subDays(5),
                'created_at' => now()->subDays(6), 'updated_at' => now()->subDays(5),
            ],
            [
                'driver_id' => $this->D[19]['driver']->id, 'payment_method_id' => $sadad->id, 'amount' => 100.00,
                'proof_image_url' => 'https://cdn.darby.ly/qa/proofs/driver19_recharge.jpg', 'reference_number' => 'DR-' . strtoupper(Str::random(8)),
                'status' => 'pending', 'admin_id' => null, 'approved_at' => null,
                'created_at' => now()->subHours(8), 'updated_at' => now()->subHours(8),
            ],
        ]);
    }

    // =====================================================================
    // 14) تقييمات وشكاوى إضافية (support_tickets) - تكمّل تغطية سيناريوهات AI
    // =====================================================================
    private function seedReviewsAndComplaints(): void
    {
        $this->command?->info('1️⃣4️⃣ تقييمات وشكاوى إضافية...');

        if (DB::table('support_tickets')->where('description', 'like', '%driver13-qa%')->exists()
            || DB::table('driver_reviews')->where('driver_id', $this->D[13]['driver']->id)->exists()) {
            $this->command?->line('   ⏭  موجودة بالفعل - تخطي');
            return;
        }

        $req13 = SubscriptionRequest::where('driver_id', $this->D[13]['driver']->id)->where('status', 'accepted')->first();
        DB::table('driver_reviews')->insert([
            'subscription_request_id' => $req13?->id, 'parent_id' => $this->P[3]['user']->id, 'driver_id' => $this->D[13]['driver']->id,
            'rating' => 5, 'comment' => 'سائق منظم جداً ويصل في الموعد كل يوم تقريباً، الأطفال مرتاحون معه.',
            'status' => 'active', 'created_at' => now()->subDays(6), 'updated_at' => now()->subDays(6),
        ]);

        $req14 = SubscriptionRequest::where('driver_id', $this->D[14]['driver']->id)->where('status', 'accepted')->first();
        DB::table('driver_reviews')->insert([
            'subscription_request_id' => $req14?->id, 'parent_id' => $this->P[4]['user']->id, 'driver_id' => $this->D[14]['driver']->id,
            'rating' => 4, 'comment' => 'خدمة جيدة عموماً، أتمنى تحسين التواصل عند تغيير المواعيد.',
            'status' => 'active', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
        ]);

        // شكوى (support_ticket): parent4 على driver26 - داخل نطاق حادثة الإنذار الرسمي
        DB::table('support_tickets')->insert([
            'user_id' => $this->P[4]['user']->id, 'creator_role' => 'parent', 'category' => 'behavior',
            'target_role' => 'driver', 'target_user_id' => $this->D[26]['user']->id,
            'description' => 'السائق تعامل بحدة عند الاتصال به لتغيير وقت الاستلام - أرجو المتابعة (driver13-qa placeholder marker).',
            'status' => 'in_progress', 'scope' => 'operations', 'assigned_admin_id' => $this->adminSupportId,
            'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(1),
        ]);

        // شكوى من سائق على ولي أمر
        DB::table('support_tickets')->insert([
            'user_id' => $this->D[21]['user']->id, 'creator_role' => 'driver', 'category' => 'communication',
            'target_role' => 'parent', 'target_user_id' => $this->P[5]['user']->id,
            'description' => 'ولي الأمر لم يستجب عند وصول السائق لنقطة الاستلام، انتظر 12 دقيقة قبل المغادرة.',
            'status' => 'resolved', 'scope' => 'operations', 'assigned_admin_id' => $this->adminSupportId,
            'resolution_note' => 'تم التواصل مع ولي الأمر وتذكيره بأهمية الاستجابة السريعة. لن يُحتسب اليوم غياباً مبلَّغاً.',
            'closed_by' => $this->adminSupportId, 'closed_at' => now()->subDays(3),
            'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(3),
        ]);

        // شكوى معلّقة جديدة (لم تُسنَد بعد)
        DB::table('support_tickets')->insert([
            'user_id' => $this->P[6]['user']->id, 'creator_role' => 'parent', 'category' => 'other',
            'target_role' => 'driver', 'target_user_id' => $this->D[28]['user']->id,
            'description' => 'أرغب في الاستفسار عن إمكانية تعديل نقطة النزول لابني بشكل دائم.',
            'status' => 'open', 'scope' => 'operations', 'assigned_admin_id' => null,
            'created_at' => now()->subHours(4), 'updated_at' => now()->subHours(4),
        ]);
    }

    // =====================================================================
    // 15) سجلات غياب أطفال (absence_logs) - لتغطية منطق تخطي محطة الطفل الغائب
    // =====================================================================
    private function seedChildAbsenceLogs(): void
    {
        $this->command?->info('1️⃣5️⃣ سجلات غياب أطفال (absence_logs)...');

        $c1 = $this->P[3]['children'][0];
        if (DB::table('absence_logs')->where('child_id', $c1->id)->exists()) {
            $this->command?->line('   ⏭  موجودة بالفعل - تخطي');
            return;
        }

        DB::table('absence_logs')->insert([
            [
                'child_id' => $c1->id, 'absence_date' => $this->today->copy()->subDays(3)->format('Y-m-d'),
                'absence_type' => 'both', 'created_at' => $this->today->copy()->subDays(4), 'updated_at' => $this->today->copy()->subDays(4),
            ],
            [
                'child_id' => $this->P[4]['children'][0]->id, 'absence_date' => $this->today->copy()->subDays(5)->format('Y-m-d'),
                'absence_type' => 'pickup', 'created_at' => $this->today->copy()->subDays(6), 'updated_at' => $this->today->copy()->subDays(6),
            ],
            [
                'child_id' => $this->P[5]['children'][0]->id, 'absence_date' => $this->today->copy()->subDays(2)->format('Y-m-d'),
                'absence_type' => 'dropoff', 'created_at' => $this->today->copy()->subDays(3), 'updated_at' => $this->today->copy()->subDays(3),
            ],
        ]);
    }

    // =====================================================================
    // تقرير ختامي - كل التواريخ/المعرّفات التي تحتاجها للاختبار اليدوي
    // =====================================================================
    private function printReport(): void
    {
        $this->command?->newLine();
        $this->command?->info('╔══════════════════════════════════════════════════════════════════╗');
        $this->command?->info('║        ✅ QaComprehensiveSeeder اكتمل بنجاح (APPEND ONLY)        ║');
        $this->command?->info('╚══════════════════════════════════════════════════════════════════╝');
        $this->command?->newLine();

        $this->command?->line('🔐 <fg=yellow>كلمة المرور الموحدة لكل الحسابات: <fg=green>' . self::DEFAULT_PASSWORD . '</>');
        $this->command?->newLine();

        $this->command?->line('👨‍👩‍👧 <fg=cyan>أولياء الأمور الأساسيون لسيناريو الاختبار اليدوي:</>');
        $this->command?->line('   • parent3@darby.ly — ' . self::PARENT_SPECS[3]['name'] . ' — 4 أطفال (بن عاشور)');
        $this->command?->line('   • parent4@darby.ly — ' . self::PARENT_SPECS[4]['name'] . ' — 4 أطفال (حي الأندلس)');
        $this->command?->line('   • parent5/parent6/parent7@darby.ly — بيانات حجم إضافية');
        $this->command?->newLine();

        $exc = $this->report['exception_scenario'] ?? null;
        if ($exc) {
            $this->command?->line('⭐ <fg=cyan>سيناريو "تعارض الاشتراك النشط + استثناء غياب السائق":</>');
            $this->command?->line("   الطفل: {$exc['affected_child']}");
            $this->command?->line("   السائق الأصلي الغائب: {$exc['driver_absent']} — غائب (معتمد) بتاريخ {$exc['thursday_date']}");
            $this->command?->line("   سائق التغطية المقترح: {$exc['coverage_driver']}");
            $this->command?->line("   ✅ اختبار إيجابي: أرسلي طلب اشتراك جديد (single_day) لنفس الطفل مع {$exc['coverage_driver']} بتاريخ {$exc['thursday_date']} — يجب أن يُقبل رغم وجود اشتراك نشط قائم مع driver22، لأن driver22 مسجَّل غائباً في ذلك اليوم بالضبط.");
            $this->command?->line("   ❌ اختبار سلبي: كرّري نفس الطلب لكن بتاريخ {$exc['blocked_day']} (يوم عمل عادي بلا غياب) — يجب أن يُرفض برسالة \"الطفل لديه اشتراك نشط بالفعل ... والسائق غير مسجل كغائب في هذه الأيام\".");
        }
        $this->command?->newLine();

        $this->command?->line('🚌 <fg=cyan>ملخص السائقين (20 سائقاً driver13..driver32):</>');
        foreach (self::DRIVER_SPECS as $spec) {
            $this->command?->line("   • driver{$spec['idx']}@darby.ly — {$spec['name']} — {$spec['note']}");
        }
        $this->command?->newLine();

        $this->command?->line('📊 <fg=cyan>إحصائيات:</>');
        $this->command?->line('   • مستخدمون جدد: ' . (7 + 20) . ' (7 أولياء أمور + 20 سائقاً)');
        $this->command?->line('   • أطفال جدد: ' . DB::table('children')->whereIn('parent_id', collect($this->P)->pluck('user.id'))->count());
        $this->command?->line('   • طلبات اشتراك (requests): ' . DB::table('requests')->whereIn('driver_id', collect($this->D)->pluck('driver.id'))->count());
        $this->command?->line('   • اشتراكات نشطة (active_subscriptions): ' . ActiveSubscription::whereHas('subscriptionRequest', fn ($q) => $q->whereIn('driver_id', collect($this->D)->pluck('driver.id')))->count());
        $this->command?->line('   • رحلات (trips): ' . DB::table('trips')->whereIn('driver_id', collect($this->D)->pluck('driver.id'))->count());
        $this->command?->line('     - منها "in_progress" الآن: ' . DB::table('trips')->whereIn('driver_id', collect($this->D)->pluck('driver.id'))->where('status', 'in_progress')->count() . ' (الهدف: 15)');
        $this->command?->line('     - "completed": ' . DB::table('trips')->whereIn('driver_id', collect($this->D)->pluck('driver.id'))->where('status', 'completed')->count());
        $this->command?->line('     - "pending" (قادمة/مجدولة): ' . DB::table('trips')->whereIn('driver_id', collect($this->D)->pluck('driver.id'))->where('status', 'pending')->count());
        $this->command?->line('   • driver_seat_slots: ' . DB::table('driver_seat_slots')->whereIn('driver_id', [$this->D[20]['driver']->id, $this->D[21]['driver']->id])->count());
        $this->command?->line('   • driver_absences: ' . DB::table('driver_absences')->whereIn('driver_id', [$this->D[22]['driver']->id, $this->D[23]['driver']->id])->count());
        $this->command?->line('   • driver_reviews: ' . DB::table('driver_reviews')->whereIn('driver_id', collect($this->D)->pluck('driver.id'))->count());
        $this->command?->line('   • ai_decision_audits: ' . DB::table('ai_decision_audits')->count());
        $this->command?->line('   • admin_alerts: ' . AdminAlert::whereIn('driver_id', collect($this->D)->pluck('driver.id'))->count());
        $this->command?->line('   • financial_ledger: ' . FinancialLedger::count());
        $this->command?->line('   • platform_finances: ' . PlatformFinance::count());
        $this->command?->line('   • invoices: ' . Invoice::whereIn('driver_id', collect($this->D)->pluck('driver.id'))->count());
        $this->command?->line('   • recharge_requests جديدة: 3 | withdrawal_requests جديدة: 3 | driver_recharge_requests: 2');
        $this->command?->line('   • support_tickets جديدة: 3 | absence_logs جديدة: 3');
        $this->command?->newLine();

        $future = $this->report['future_scheduled'] ?? null;
        if ($future) {
            $this->command?->line("📅 اشتراك مستقبلي لم يبدأ بعد (كل رحلاته pending): {$future}");
        }
        $this->command?->newLine();

        $this->command?->line('💡 للتفاصيل الكاملة لكل سيناريو (بيانات دخول الطلبات، الأرقام المالية المتوقعة،');
        $this->command?->line('   خطوات الاختبار خطوة بخطوة) راجعي دليل الاختبار المرفق مع هذا السيدر.');
    }
}
