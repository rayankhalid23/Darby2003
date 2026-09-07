<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 1/ج من تطبيع جداول الاشتراك — إضافات غير هدّامة على active_subscriptions.
 *
 * ⭐ التغيير المفصلي: request_child_id. اليوم يُربط الاشتراك النشط بالطلب وبالطفل
 * منفصلَين (subscription_request_id + child_id)، فلا سبيل مباشر من صفّ الاشتراك
 * النشط إلى الصفّ الذي يحمل مدرسة ذلك الطفل ومسافته وحصته من المال — كل قراءة
 * تعيد البحث عن الزوج داخل $req->children. الرابط الجديد يجعل تلك البيانات على
 * بُعد انضمام واحد ويمنع بنيوياً أي انحراف بين الاشتراك النشط والطفل الذي يقيسه.
 *
 * start_date/end_date: منسوختان عند التفعيل حتى لا يحتاج اشتراك جارٍ تحميل الطلب
 * ليعرف مدته، وتسمحان لاحقاً بتقصير مدة طفل واحد دون المساس بالطلب كله.
 *
 * school_id: علاقة ActiveSubscription::school() موجودة في الموديل وتشير إلى عمود
 * غير موجود أصلاً في هذا الجدول — أي استدعاء لها يرمي "Unknown column".
 *
 * حقول الإلغاء: الإلغاء يحرّك مالاً من مسبح الأمانة (رجوع إلى
 * SubscriptionRequestService::refundHeldFundsOnCancellation) ولا يُسجَّل من ألغى
 * ولا متى ولا لماذا على صفّ الاشتراك نفسه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('active_subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('active_subscriptions', 'request_child_id')) {
                $table->foreignId('request_child_id')
                      ->nullable()
                      ->after('subscription_request_id')
                      ->constrained('request_children')
                      ->cascadeOnDelete()
                      ->cascadeOnUpdate();
            }

            if (!Schema::hasColumn('active_subscriptions', 'start_date')) {
                $table->date('start_date')->nullable()->after('sort_order');
            }

            if (!Schema::hasColumn('active_subscriptions', 'end_date')) {
                $table->date('end_date')->nullable()->after('start_date');
            }

            if (!Schema::hasColumn('active_subscriptions', 'school_id')) {
                $table->foreignId('school_id')
                      ->nullable()
                      ->after('route_id')
                      ->constrained('schools')
                      ->nullOnDelete()
                      ->cascadeOnUpdate();
            }

            if (!Schema::hasColumn('active_subscriptions', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('status');
            }

            if (!Schema::hasColumn('active_subscriptions', 'cancelled_by')) {
                $table->string('cancelled_by', 20)->nullable()->after('cancelled_at')
                      ->comment('parent | driver | admin | system');
            }

            if (!Schema::hasColumn('active_subscriptions', 'cancellation_reason')) {
                $table->string('cancellation_reason', 255)->nullable()->after('cancelled_by');
            }
        });

        $this->extendStatusEnum();
    }

    /**
     * resolveState() في SubscriptionRequest/ActiveSubscription يقرأ حالة 'paused'
     * (السطر الذي يتحقق من $activeStatus === 'paused') لكنّ الـenum الحيّ لا يملكها
     * أصلاً — أي محاولة تخزينها تفشل بخطأ "Data truncated". و'pending' لا معنى له
     * هنا: هذا الجدول لا يُنشأ فيه صفّ إلا بعد قبول السائق (createActiveSubscriptions)،
     * فالاشتراك يُخلق نشطاً دائماً أو لا يُخلق إطلاقاً.
     */
    private function extendStatusEnum(): void
    {
        DB::statement(
            "ALTER TABLE active_subscriptions
             MODIFY COLUMN status ENUM('active','paused','completed','cancelled','suspended_unpaid','terminated')
             NOT NULL DEFAULT 'active'"
        );
    }

    public function down(): void
    {
        // إرجاع أي صف اكتسب حالة 'paused' كي لا يفشل drop الأعمدة على enum لا يقبلها لاحقاً.
        DB::table('active_subscriptions')->where('status', 'paused')->update(['status' => 'active']);

        DB::statement(
            "ALTER TABLE active_subscriptions
             MODIFY COLUMN status ENUM('active','pending','completed','cancelled','suspended_unpaid','terminated')
             NOT NULL DEFAULT 'active'"
        );

        Schema::table('active_subscriptions', function (Blueprint $table) {
            foreach (['request_child_id', 'school_id'] as $fkColumn) {
                if (Schema::hasColumn('active_subscriptions', $fkColumn)) {
                    $table->dropForeign(['' . $fkColumn]);
                }
            }

            $columns = array_values(array_filter([
                'request_child_id',
                'start_date',
                'end_date',
                'school_id',
                'cancelled_at',
                'cancelled_by',
                'cancellation_reason',
            ], fn ($column) => Schema::hasColumn('active_subscriptions', $column)));

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
