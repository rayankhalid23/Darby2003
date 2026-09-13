<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * نقطة الدخول الرئيسية لسيدرات المشروع.
 *
 * التسلسل:
 *   1. BaseSystemSeeder  ← البنية الأساسية للنظام (أدوار، صلاحيات، جغرافيا،
 *                          مدارس، تسعير، وسائل دفع، شروط، مدير عام + مشرفين)
 *   2. DemoDataSeeder    ← بيانات تجريبية شاملة (أولياء أمور، سائقون، مركبات،
 *                          اشتراكات، رحلات، مالية، شكاوى، إشعارات)
 *
 * التشغيل:
 *   php artisan db:seed                          ← يشغّل الاثنين بالتسلسل
 *   php artisan db:seed --class=BaseSystemSeeder ← الأساسي فقط (بدون بيانات تجريبية)
 *   php artisan db:seed --class=DemoDataSeeder   ← البيانات التجريبية فقط (يفترض أن الأساسي شُغِّل)
 *
 * كلمة المرور الموحدة لجميع الحسابات: Password123!
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BaseSystemSeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}
