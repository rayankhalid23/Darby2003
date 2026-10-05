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