<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * يمنح صلاحيات إدارة أولياء الأمور (parents.view / parents.suspend) لمشرف خدمة العملاء
     * على قواعد البيانات المزروعة مسبقاً بـ BaseSystemSeeder (التي لا تُعاد بشكل تلقائي).
     */
    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('permissions') || !DB::getSchemaBuilder()->hasTable('roles')) {
            return;
        }

        $now = now();

        $permissions = [
            [
                'key'            => 'parents.view',
                'group_key'      => 'parents_accounts',
                'name_ar'        => 'استعراض قائمة أولياء الأمور',
                'description_ar' => 'الاطلاع على حسابات أولياء الأمور وبياناتهم وأبنائهم المسجلين',
            ],
            [
                'key'            => 'parents.suspend',
                'group_key'      => 'parents_accounts',
                'name_ar'        => 'إيقاف / تفعيل حسابات أولياء الأمور',
                'description_ar' => 'تجميد أو إعادة تفعيل حساب ولي الأمر في النظام',
            ],
        ];

        foreach ($permissions as $perm) {
            $exists = DB::table('permissions')->where('key', $perm['key'])->exists();
            if (!$exists) {
                DB::table('permissions')->insert($perm + ['created_at' => $now, 'updated_at' => $now]);
            }
        }

        $roleId = DB::table('roles')->where('name', 'support_supervisor')->value('id');
        if (!$roleId) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', ['parents.view', 'parents.suspend'])
            ->pluck('id', 'key');

        foreach ($permissionIds as $permissionId) {
            $alreadyGranted = DB::table('permission_role')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->exists();

            if (!$alreadyGranted) {
                DB::table('permission_role')->insert([
                    'role_id'       => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', ['parents.view', 'parents.suspend'])
            ->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
