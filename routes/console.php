<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// توليد الرحلات اليومية (Daily Trips) لأي مسار دخل نافذة T-30 دقيقة قبل وقت انطلاقه
Schedule::command('trips:generate-daily')->everyMinute();

// فحص الطلبات المعلقة كل 6 ساعات وإلغاء غير القابلة للتنفيذ
Schedule::command('subscriptions:check-pending')->everySixHours();

// فحص يومي لتواريخ انتهاء رخص القيادة ووثائق التأمين للسائقين + إرسال تذكيرات/تنبيهات
Schedule::command('drivers:check-document-expiry')->dailyAt('08:00');

// اكتشاف الرحلات التي لم يبدأها سائقها خلال 90 دقيقة من موعدها المحدد وتسجيل غياب تلقائي عنها
Schedule::command('trips:detect-driver-no-show')->everyFiveMinutes()->withoutOverlapping();

// ────────────────────────── الجدولة المالية ──────────────────────────
// ⚠️ هذه المهام كانت معرّفة في الكود وغير مجدولة إطلاقاً، فبقيت آثارها معلّقة:
// الأرباح المحجوزة لا تصل السائقين، والفواتير النهائية لا تصدر.

// تحرير أرباح الرحلات اليومية المعلّقة بعد انقضاء نافذة النزاع (24 ساعة)
Schedule::call(function () {
    app(\App\Services\Shared\FinancialLedgerService::class)->releasePendingTripEscrows();
})->hourly()->name('escrow:release-pending')->withoutOverlapping();

// إصدار الفواتير النهائية للاشتراكات المنتهية وإرسال تذكيرات ما قبل الاستحقاق
Schedule::command('subscriptions:settle')->dailyAt('01:00')->withoutOverlapping();

// فحص يومي للسلامة المالية — يسجّل Log::emergency عند أي انحراف بين أحواض
// الخزينة والالتزامات الفعلية، دون تعديل أي بيانات (التصحيح يدوي عبر --fix).
Schedule::command('finance:reconcile')->dailyAt('03:00')->withoutOverlapping();

// ────────────────────────── نظام الذكاء الاصطناعي ──────────────────────────
// يستعيد السائقين الذين انتهت مدة إيقافهم المؤقت تلقائياً (سلم العقوبات).
// لا يُعيد السائقين ذوي الإيقاف الدائم (suspended_until = null + suspension_count > 0).
Schedule::command('drivers:restore-suspended')->everyFifteenMinutes()->withoutOverlapping();
