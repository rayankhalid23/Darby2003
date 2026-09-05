<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeder داخل Migration لإضافة شروط العقد المبسطة التي تحمي جميع الأطراف
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('clauses')) {
            return;
        }

        // حذف الشروط القديمة وإعادة الإنشاء بنسخة محسّنة
        DB::table('clauses')->truncate();

        $clauses = [
            [
                'category'    => 'الالتزام بالخدمة',
                'clause_text' => 'يلتزم السائق بنقل الطفل وفق المسار والتوقيت المحددَين في هذا العقد، وأي تعديل يستلزم إشعار ولي الأمر مسبقاً.',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'category'    => 'السلامة',
                'clause_text' => 'يحق للمنصة إيقاف أو إلغاء هذا العقد فوراً في حال ثبوت أي إهمال أو تصرف يهدد سلامة الطفل.',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'category'    => 'الدفع',
                'clause_text' => 'يُسدَّد مبلغ الاشتراك مسبقاً وفق الجدول المتفق عليه، ولا يُستردّ في حال غياب الطفل غير المبلَّغ عنه.',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'category'    => 'التزامات ولي الأمر',
                'clause_text' => 'يلتزم ولي الأمر بإشعار السائق عبر التطبيق قبل 24 ساعة على الأقل في حال غياب الطفل أو تغيير مؤقت في المسار.',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'category'    => 'دور المنصة',
                'clause_text' => 'تعمل منصة Darby بصفة وسيط تقني فقط وتسهم في توثيق الاتفاق، وهي غير مسؤولة عن أي اتفاقيات مالية جانبية خارج نطاق هذا العقد.',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'category'    => 'سريان العقد',
                'clause_text' => 'يُعدّ هذا العقد نافذاً وملزماً لكلا الطرفين منذ تاريخ إصداره تلقائياً، ويظل سارياً حتى انقضاء مدة الاشتراك أو إنهائه وفق شروط هذه الاتفاقية.',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ];

        DB::table('clauses')->insert($clauses);
    }

    public function down(): void
    {
        DB::table('clauses')->truncate();
    }
};
