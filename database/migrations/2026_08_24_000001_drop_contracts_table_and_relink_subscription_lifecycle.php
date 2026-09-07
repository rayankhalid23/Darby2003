<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إزالة جدول العقود (contracts) نهائياً من دورة حياة الاشتراك.
 *
 * الطلب (requests) أصبح هو السجل الوحيد والمرجعي للاتفاق بين ولي الأمر والسائق،
 * فهو يحمل بالفعل كل الحقول التي كان يكرّرها العقد:
 * (subscription_type, direction, timing, start_date, end_date, days_count,
 *  total_price, pickup_time, dropoff_time, max_waiting_time).
 *
 * لذلك تُستبدل كل مفاتيح contract_id في الجداول التابعة بمفتاح
 * subscription_request_id يشير مباشرة إلى requests، مع ترحيل البيانات القائمة.
 */
return new class extends Migration
{
    /**
     * الجداول التي كانت ترتبط بالعقد: اسم الجدول => [nullable?, onDelete]
     */
    private array $tables = [
        'active_subscriptions' => ['nullable' => false, 'onDelete' => 'cascade'],
        'routes'               => ['nullable' => true,  'onDelete' => 'set null'],
        'invoices'             => ['nullable' => false, 'onDelete' => 'cascade'],
        'driver_reviews'       => ['nullable' => true,  'onDelete' => 'set null'],
    ];

    public function up(): void
    {
        $contractsExist = Schema::hasTable('contracts');

        // 0) الطلب يحمل الآن لحظة الحسم (قبول/رفض السائق) بدلاً من contracts.signed_at
        if (!Schema::hasColumn('requests', 'responded_at')) {
            Schema::table('requests', function (Blueprint $t) {
                $t->timestamp('responded_at')->nullable()->comment('لحظة قبول أو رفض السائق للطلب');
            });

            if ($contractsExist && DB::getDriverName() === 'mysql') {
                DB::statement(
                    'UPDATE requests r
                        JOIN contracts c ON c.subscription_request_id = r.id
                        SET r.responded_at = c.signed_at
                      WHERE c.signed_at IS NOT NULL'
                );
            }
        }

        // 1) إضافة العمود الجديد subscription_request_id لكل جدول تابع
        foreach ($this->tables as $table => $opts) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            if (!Schema::hasColumn($table, 'subscription_request_id')) {
                Schema::table($table, function (Blueprint $t) {
                    // يُضاف مؤقتاً كـ nullable لتمكين ترحيل البيانات قبل تثبيت القيد
                    $t->unsignedBigInteger('subscription_request_id')->nullable()->after('id');
                });
            }

            // 2) ترحيل البيانات: contract_id => contracts.subscription_request_id
            if ($contractsExist && Schema::hasColumn($table, 'contract_id')) {
                DB::table($table)
                    ->whereNull('subscription_request_id')
                    ->whereNotNull('contract_id')
                    ->update([
                        'subscription_request_id' => DB::raw(
                            "(SELECT c.subscription_request_id FROM contracts c WHERE c.id = {$table}.contract_id)"
                        ),
                    ]);
            }
        }

        // 3) حذف السجلات اليتيمة التي لا يمكن ربطها بأي طلب اشتراك
        //    (في الجداول التي لا تقبل NULL) حتى لا يفشل تثبيت المفتاح الأجنبي
        foreach ($this->tables as $table => $opts) {
            if (!Schema::hasTable($table) || $opts['nullable']) {
                continue;
            }

            DB::table($table)->whereNull('subscription_request_id')->delete();
        }

        // 4) إسقاط عمود contract_id ومفتاحه الأجنبي من كل جدول تابع
        foreach (array_keys($this->tables) as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'contract_id')) {
                continue;
            }

            $this->dropForeignKeyIfExists($table, 'contract_id');

            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('contract_id');
            });
        }

        // 5) تثبيت المفتاح الأجنبي الجديد + الفهرس
        foreach ($this->tables as $table => $opts) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($opts) {
                if (!$opts['nullable']) {
                    $t->unsignedBigInteger('subscription_request_id')->nullable(false)->change();
                }
            });

            try {
                Schema::table($table, function (Blueprint $t) use ($opts) {
                    $t->foreign('subscription_request_id')
                      ->references('id')->on('requests')
                      ->onDelete($opts['onDelete'] === 'cascade' ? 'cascade' : 'set null');
                });
            } catch (\Exception $e) {
                // Ignore if it already exists
            }
        }

        // 6) توسعة حالات الاشتراك النشط لتغطية الحالات التي كان يحملها العقد
        if (Schema::hasTable('active_subscriptions') && DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `active_subscriptions` MODIFY COLUMN `status`
                 ENUM('active','pending','completed','cancelled','suspended_unpaid','terminated')
                 NOT NULL DEFAULT 'active'"
            );
        }

        // 7) إسقاط جدول العقود نهائياً
        Schema::dropIfExists('contracts');
    }

    /**
     * إسقاط المفتاح الأجنبي لعمود معيّن إن وُجد فعلاً (بدون افتراض اسم القيد)
     */
    private function dropForeignKeyIfExists(string $table, string $column): void
    {
        if (DB::getDriverName() !== 'mysql' || !Schema::hasTable($table)) {
            return;
        }

        try {
            $constraints = DB::select(
                'SELECT CONSTRAINT_NAME AS name
                   FROM information_schema.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?
                    AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$table, $column]
            );

            foreach ($constraints as $constraint) {
                DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint->name}`");
            }
        } catch (\Throwable $e) {
            // ignore if table or fk does not exist
        }
    }

    public function down(): void
    {
        // إعادة إنشاء جدول العقود بهيكله الأخير قبل الحذف
        if (!Schema::hasTable('contracts')) {
            Schema::create('contracts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('subscription_request_id');
                $table->foreign('subscription_request_id')->references('id')->on('requests')->onDelete('cascade');
                $table->foreignId('parent_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('driver_id')->constrained('users')->onDelete('cascade');
                $table->string('contract_number')->nullable()->unique();
                $table->string('subscription_type')->nullable();
                $table->string('direction')->nullable();
                $table->string('timing')->nullable();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->integer('days_count')->nullable();
                $table->decimal('total_price', 10, 2)->nullable();
                $table->time('pickup_time')->nullable();
                $table->time('dropoff_time')->nullable();
                $table->integer('max_waiting_time')->default(15);
                $table->json('clauses')->nullable();
                $table->string('status')->default('pending_parent_approval');
                $table->timestamp('signed_at')->nullable();
                $table->string('pdf_path')->nullable();
                $table->timestamps();
            });
        }

        foreach ($this->tables as $table => $opts) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            if (!Schema::hasColumn($table, 'contract_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('contract_id')->nullable()->after('id');
                });
            }

            if (Schema::hasColumn($table, 'subscription_request_id')) {
                $this->dropForeignKeyIfExists($table, 'subscription_request_id');

                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('subscription_request_id');
                });
            }
        }

        if (Schema::hasColumn('requests', 'responded_at')) {
            Schema::table('requests', function (Blueprint $t) {
                $t->dropColumn('responded_at');
            });
        }
    }
};
