<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * تصحيح role_id للحسابات القديمة التي أُنشئت عبر DriverRegisterService/
 * ParentRegistrationService قبل إصلاح الرقمين الثابتين الخاطئين (4 و3 بدل
 * 8 و7 الفعليين بجدول roles). لا يمس أي حساب موظف حقيقي: الربط بالسائقين عبر
 * drivers.user_id غير قابل للالتباس، وحسابات role_id=3 المتبقية جميعها
 * self-registered (created_by=null) بلا استثناء وبعضها مرتبط بأطفال فعلاً.
 *
 * التشغيل: php artisan users:fix-misassigned-roles --dry-run  (للمعاينة أولاً)
 *          php artisan users:fix-misassigned-roles           (للتنفيذ الفعلي)
 */
class FixMisassignedUserRoles extends Command
{
    protected $signature = 'users:fix-misassigned-roles {--dry-run : عرض ما سيتغيّر دون تعديل قاعدة البيانات}';

    protected $description = 'تصحيح role_id للحسابات القديمة المسجّلة قبل إصلاح خلل الأدوار الثابتة (سائقون وأولياء أمور)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $driverRoleId = Role::where('name', 'driver')->value('id');
        $parentRoleId = Role::where('name', 'parent')->value('id');

        if (!$driverRoleId || !$parentRoleId) {
            $this->error('تعذّر العثور على دوري "driver"/"parent" بجدول roles.');
            return self::FAILURE;
        }

        $driverUserIds = DB::table('drivers')->pluck('user_id');

        $driversToFix = User::whereIn('id', $driverUserIds)->where('role_id', '!=', $driverRoleId)->get();
        $parentsToFix = User::where('role_id', 3)->whereNotIn('id', $driverUserIds)->get();

        $this->info("سائقون بدور خاطئ: {$driversToFix->count()}");
        foreach ($driversToFix as $u) {
            $this->line("  #{$u->id} {$u->full_name} — role_id {$u->role_id} → {$driverRoleId}");
        }

        $this->info("أولياء أمور بدور خاطئ (role_id=3): {$parentsToFix->count()}");
        foreach ($parentsToFix as $u) {
            $this->line("  #{$u->id} {$u->full_name} — role_id 3 → {$parentRoleId}");
        }

        if ($dryRun) {
            $this->comment('وضع المعاينة فقط — لم يُعدَّل شيء. أعد التشغيل بدون --dry-run للتنفيذ.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($driversToFix, $parentsToFix, $driverRoleId, $parentRoleId) {
            foreach ($driversToFix as $u) {
                $u->update(['role_id' => $driverRoleId]);
            }
            foreach ($parentsToFix as $u) {
                $u->update(['role_id' => $parentRoleId]);
            }
        });

        $this->info('تم التصحيح بنجاح.');
        return self::SUCCESS;
    }
}
