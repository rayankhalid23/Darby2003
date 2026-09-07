<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إعادة إدراج العنوان الرئيسي (is_default) في جدول addresses.
 *
 * القاعدة: لكل ولي أمر عنوان رئيسي واحد فقط (is_default = true)، وكل أطفاله
 * مسنَدون إليه تلقائياً. باقي العناوين تُحفظ بحالة false كعناوين ثانوية.
 *
 * ملاحظة: العمود كان قد أُسقِط في مهاجرة 2026_08_21_000002 بينما بقي في
 * موديل Address والـ seeders والاختبارات، فكانت كل كتابة تفشل بخطأ
 * «Unknown column 'is_default'». هذه المهاجرة تعيده وتردم البيانات القديمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('addresses', 'is_default')) {
            Schema::table('addresses', function (Blueprint $table) {
                $table->boolean('is_default')
                      ->default(false)
                      ->after('lng')
                      ->comment('العنوان الرئيسي المفعل لولي الأمر - واحد فقط لكل مستخدم');

                $table->index(['user_id', 'is_default'], 'addresses_user_default_index');
            });
        }

        // ── ردم البيانات: ضمان عنوان رئيسي واحد لكل مستخدم لديه عناوين ──
        // الأولوية للعنوان المرتبط بأكبر عدد من الأطفال، ثم الأقدم إنشاءً.
        $usersWithAddresses = DB::table('addresses')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('user_id');

        foreach ($usersWithAddresses as $userId) {
            $alreadyDefault = DB::table('addresses')
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->where('is_default', true)
                ->count();

            if ($alreadyDefault === 1) {
                continue;
            }

            // تصفير أي تعدد سابق قبل إعادة التعيين
            DB::table('addresses')
                ->where('user_id', $userId)
                ->update(['is_default' => false]);

            $preferredId = DB::table('addresses as a')
                ->leftJoin('children as c', function ($join) {
                    $join->on('c.address_id', '=', 'a.id')->whereNull('c.deleted_at');
                })
                ->where('a.user_id', $userId)
                ->whereNull('a.deleted_at')
                ->groupBy('a.id')
                ->orderByRaw('COUNT(c.id) DESC')
                ->orderBy('a.id')
                ->value('a.id');

            if ($preferredId) {
                DB::table('addresses')->where('id', $preferredId)->update(['is_default' => true]);

                // إسناد كل أطفال ولي الأمر إلى العنوان الرئيسي المعتمد
                DB::table('children')
                    ->where('parent_id', $userId)
                    ->whereNull('deleted_at')
                    ->update(['address_id' => $preferredId]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('addresses', 'is_default')) {
            Schema::table('addresses', function (Blueprint $table) {
                try {
                    $table->dropIndex('addresses_user_default_index');
                } catch (\Throwable $e) {
                }
                $table->dropColumn('is_default');
            });
        }
    }
};
