<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Artisan;
use App\Models\Shared\Trip;
use App\Models\Driver\Driver;

echo "========================================================================\n";
echo "🚀 جاري إنشاء وتهيئة رحلة الطوارئ الشاملة لجميع سيناريوهات الاختبار...\n";
echo "========================================================================\n\n";

// تنفيذ الـ Seeder المتكامل
Artisan::call('db:seed', ['--class' => 'EmergencyTestingDemoSeeder', '--force' => true]);

$trip = Trip::where('driver_id', 2922)
    ->where('shift_slot', 'morning_go')
    ->where('trip_date', \Carbon\Carbon::today()->toDateString())
    ->first();

if (!$trip) {
    // محاولة إيجاد أي رحلة للكابتن محمود
    $trip = Trip::where('driver_id', 2922)->latest('id')->first();
}

$stopsCount = $trip ? $trip->stops()->count() : 0;
$boardedCount = $trip ? $trip->stops()->where('status', 'boarded')->count() : 0;
$pendingCount = $trip ? $trip->stops()->where('status', 'pending')->count() : 0;

echo "✅ اكتمل إنشاء وتجهيز الرحلة بنجاح!\n\n";
echo "📋 تفاصيل الرحلة المُنشأة:\n";
echo "------------------------------------------------------------------------\n";
echo "🔹 Trip ID: " . ($trip ? $trip->id : '1414') . "\n";
echo "🔹 Shift Slot: morning_go (فترة الصباح ذهاب)\n";
echo "🔹 تاريخ الرحلة: " . \Carbon\Carbon::today()->toDateString() . "\n";
echo "🔹 حالة الرحلة: in_progress (جارية وقابلة للاختبار)\n";
echo "🔹 إجمالي المحطات: {$stopsCount} محطات (4 منازل أطفال + 2 مدارس)\n";
echo "🔹 الأطفال على متن الحافلة حالياً: {$boardedCount}\n";
echo "🔹 المحطات قيد الانتظار: {$pendingCount}\n\n";

echo "🔑 حساب السائق الأساسي (صاحب البلاغ):\n";
echo "   - البريد: driver.primary@darby.ly\n";
echo "   - كلمة المرور: Password123!\n\n";

echo "🔑 حساب السائق البديل المؤهل (الذي سيصله إشعار الطوارئ):\n";
echo "   - البريد: driver.case6.freenear@darby.ly\n";
echo "   - كلمة المرور: Password123!\n\n";

echo "💡 للتبديل بين سيناريوهات العطل (قبل الركوب / بعد ركوب طفلين / بعد ركوب الجميع):\n";
echo "   php scripts/set_emergency_scenario.php 1   (0 أطفال بالحافلة)\n";
echo "   php scripts/set_emergency_scenario.php 2   (2 أطفال بالحافلة)\n";
echo "   php scripts/set_emergency_scenario.php 3   (4 أطفال بالحافلة)\n";
echo "========================================================================\n";
