<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('discount_one_child', 5, 2)->default(0.00)->comment('نسبة خصم طفل واحد (0%)');
            $table->decimal('discount_two_children', 5, 2)->default(10.00)->comment('نسبة خصم طفلين (10%)');
            $table->decimal('discount_three_plus_children', 5, 2)->default(15.00)->comment('نسبة خصم 3 أطفال أو أكثر (15%)');
            $table->decimal('platform_commission_rate', 5, 2)->default(8.00)->comment('نسبة عمولة المنصة (8%)');
            $table->decimal('price_per_km_ac', 8, 2)->default(2.50)->comment('سعر الكيلومتر للمركبة المكيفة');
            $table->decimal('price_per_km_non_ac', 8, 2)->default(2.00)->comment('سعر الكيلومتر للمركبة غير المكيفة');
            $table->decimal('location_change_fee', 8, 2)->default(5.00)->comment('رسوم تغيير الموقع الافتراضية');
            $table->decimal('location_change_fee_under_2km', 8, 2)->default(5.00)->comment('رسوم تغيير الموقع أقل من 2 كم');
            $table->decimal('location_change_fee_2_to_6km', 8, 2)->default(10.00)->comment('رسوم تغيير الموقع من 2 إلى 6 كم');
            $table->decimal('location_change_fee_6_to_10km', 8, 2)->default(15.00)->comment('رسوم تغيير الموقع من 6 إلى 10 كم');
            $table->timestamps();
        });

        // إدراج القيم الافتراضية الأولية لضمان عمل حساب الأسعار والعمولات فوراً
        DB::table('pricing_settings')->insert([
            'discount_one_child'            => 0.00,
            'discount_two_children'        => 10.00,
            'discount_three_plus_children' => 15.00,
            'platform_commission_rate'      => 8.00,
            'price_per_km_ac'               => 2.50,
            'price_per_km_non_ac'           => 2.00,
            'location_change_fee'           => 5.00,
            'location_change_fee_under_2km' => 5.00,
            'location_change_fee_2_to_6km'  => 10.00,
            'location_change_fee_6_to_10km' => 15.00,
            'created_at'                    => now(),
            'updated_at'                    => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_settings');
    }
};
