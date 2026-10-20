<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController; // استدعاء الـ Controller الخاص باللوحة لو وجد


// 1. مسار عرض صفحة تسجيل الدخول
Route::get('/login', function () {
    return view('auth.login');
})->name('login');

// 2. الصفحة الرئيسية تحول لـ login (أو لوحة التحكم إذا كان مسجلاً دخول)
Route::get('/', function () {
    return redirect()->route('login');
});

// 3. مسارات لوحة التحكم المحمية (تظهر فيها القالب الثابت Sidebar & Topbar)
Route::middleware(['auth'])->prefix('admin')->group(function () {
    
    // صفحة الرئيسية والمتابعة الحية
    Route::get('/dashboard', function () {
        return view('admin.dashboard');
    })->name('admin.dashboard');

    // أضف أي مسارات أخرى هنا لتفتح داخل نفس القالب الثابت
    // Route::get('/drivers', [DriverController::class, 'index'])->name('admin.drivers');
});

// 4. مسار تشغيل سريع لإنشاء رحلة الـ 5 أطفال (Morning Go) وتجهيز حساب السائق
Route::get('/run-trip-script', function () {
    \Illuminate\Support\Facades\Artisan::call('trip:create-5children-go');
    $output = \Illuminate\Support\Facades\Artisan::output();

    // ضبط كلمة مرور السائق 908 وتثبيت الهاتف ليسهل تسجيل الدخول
    $driver = \App\Models\Driver\Driver::find(908);
    if ($driver && $driver->user) {
        $user = $driver->user;
        $user->password = \Illuminate\Support\Facades\Hash::make('123456');
        $user->phone = '0916466628';
        $user->phone_number = '0916466628';
        $user->is_active = true;
        $user->save();

        $output .= "\n---------------------------------------------------\n";
        $output .= "🔐 بيانات دخول السائق (Driver 908):\n";
        $output .= "📧 Email: " . $user->email . "\n";
        $output .= "📱 Phone: " . $user->phone . "\n";
        $output .= "🔑 Password: " . "123456" . "\n";
    }

    return response($output, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
});

// 5. مسار تشخيص اتصال البريد الإلكتروني على السيرفر
Route::get('/test-email-diagnostics', function () {
    $results = [];

    // فحص إمكانية فتح اتصال TCP على المنافذ 465 و 587
    foreach ([465, 587] as $port) {
        $t0 = microtime(true);
        $fp = @fsockopen('smtp.gmail.com', $port, $errno, $errstr, 5);
        $elapsed = round((microtime(true) - $t0) * 1000, 2);
        if ($fp) {
            fclose($fp);
            $results["port_{$port}"] = "CONNECTED OK ({$elapsed}ms)";
        } else {
            $results["port_{$port}"] = "BLOCKED / FAILED: [{$errno}] {$errstr} ({$elapsed}ms)";
        }
    }

    // فحص إعدادات البريد الحالية
    $results['current_mailer'] = config('mail.default');
    $results['current_host']   = config('mail.mailers.smtp.host');
    $results['current_port']   = config('mail.mailers.smtp.port');
    $results['current_enc']    = config('mail.mailers.smtp.encryption');
    $results['current_user']   = config('mail.mailers.smtp.username');

    // محاولة إرسال بريد تجريبي
    try {
        \Illuminate\Support\Facades\Mail::raw('Test connection from Darby server', function ($msg) {
            $msg->to(config('mail.from.address') ?: 'mhamedms000123@gmail.com')
                ->subject('Darby Server SMTP Test');
        });
        $results['mail_send'] = "SUCCESS: Email sent successfully!";
    } catch (\Throwable $e) {
        $results['mail_send'] = "FAILED: " . $e->getMessage();
    }

    return response()->json($results, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
});