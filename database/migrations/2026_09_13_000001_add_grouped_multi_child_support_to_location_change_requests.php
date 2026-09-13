<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * تحديث بنية طلبات تغيير الموقع لتدعم:
 *  1) طلباً واحداً يحمل عدة أطفال (تجميع تلقائي بالسائق).
 *  2) اتجاه محدد لكل صف (ذهاب/إياب) بدل تعديل كل رحلات اليوم على المسار.
 *
 * ⚠️ لا نُسقط عمود child_id القديم ولا أي بيانات — الطلبات السابقة تبقى كما هي
 *    وتُقرأ عبر التوافقية في الـ Resource (طفل واحد داخل مصفوفة children).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_change_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('location_change_requests', 'direction')) {
                // to_school = الذهاب (pickup=home, dropoff=school)
                // to_home   = الإياب (pickup=school, dropoff=home)
                // both      = الرحلتان معاً (سلوك الطلبات القديمة قبل هذا التحديث)
                $table->enum('direction', ['to_school', 'to_home', 'both'])
                      ->default('both')
                      ->after('point_type');
            }
        });

        // child_id يصير nullable لأن الطلب المُجمَّع يحمل أطفاله في جدول الربط،
        // بينما يبقى للطلبات القديمة يحمل الطفل الوحيد كما هو.
        if (Schema::hasColumn('location_change_requests', 'child_id')) {
            DB::statement('ALTER TABLE location_change_requests MODIFY COLUMN child_id BIGINT UNSIGNED NULL');
        }

        // active_subscription_id يصير nullable لأن الطلب المُجمَّع يحمل الاشتراكات في جدول الربط،
        // بينما يبقى للطلبات القديمة يحمل الاشتراك الوحيد كما هو.
        if (Schema::hasColumn('location_change_requests', 'active_subscription_id')) {
            DB::statement('ALTER TABLE location_change_requests MODIFY COLUMN active_subscription_id BIGINT UNSIGNED NULL');
        }

        if (!Schema::hasTable('location_change_request_children')) {
            Schema::create('location_change_request_children', function (Blueprint $table) {
                $table->id();

                $table->foreignId('location_change_request_id');
                $table->foreignId('child_id');
                $table->foreignId('active_subscription_id');

                $table->foreign('location_change_request_id', 'lcrc_req_fk')
                      ->references('id')->on('location_change_requests')
                      ->cascadeOnDelete();
                $table->foreign('child_id', 'lcrc_child_fk')
                      ->references('id')->on('children')
                      ->cascadeOnDelete();
                $table->foreign('active_subscription_id', 'lcrc_sub_fk')
                      ->references('id')->on('active_subscriptions')
                      ->cascadeOnDelete();

                // trip_id قد يكون null وقت إنشاء الطلب (لم تُولَّد الرحلة بعد)،
                // ويُحلّ من route_id + change_date وقت موافقة السائق.
                $table->unsignedBigInteger('trip_id')->nullable();

                // اتجاه هذه المحطة تحديداً: to_school = ذهاب، to_home = إياب.
                $table->enum('direction', ['to_school', 'to_home']);

                // موقع الطفل قبل التغيير — snapshot يبقى للأرشيف لا يتأثر بأي تعديل لاحق للاشتراك.
                $table->decimal('previous_lat', 10, 8)->nullable();
                $table->decimal('previous_lng', 11, 8)->nullable();
                $table->string('previous_label', 255)->nullable();

                // trip_stop_id يُملأ بعد الموافقة إن نُفذ التحديث فعلاً.
                $table->unsignedBigInteger('trip_stop_id')->nullable();
                $table->boolean('applied')->default(false);
                $table->string('skip_reason', 255)->nullable();

                $table->timestamps();

                $table->unique(
                    ['location_change_request_id', 'child_id', 'direction'],
                    'lcrc_req_child_dir_unique'
                );
                $table->index(['child_id', 'direction'], 'lcrc_child_dir_idx');
            });
        }
    }

    public function down(): void
    {
        // ⚠️ نتحاشى إسقاط أي بيانات فعلية عن الاشتراك المرتبط. down يقتصر على
        // إزالة الجدول الرابط والعمود المضاف — تحويل child_id/active_subscription_id
        // إلى NOT NULL يفشل إن وُجدت طلبات مُجمَّعة، لذلك نُبقيها nullable.
        if (Schema::hasTable('location_change_request_children')) {
            Schema::drop('location_change_request_children');
        }

        if (Schema::hasColumn('location_change_requests', 'direction')) {
            Schema::table('location_change_requests', function (Blueprint $table) {
                $table->dropColumn('direction');
            });
        }
    }
};
