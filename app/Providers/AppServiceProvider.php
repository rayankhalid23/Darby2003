<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->allowFileUploadsOnArtisanServe();

        $this->app->singleton(\App\Services\Ai\ReviewClassifierService::class, function ($app) {
            $config = $app['config']->get('services.ai_classifier', []);
            return new \App\Services\Ai\ReviewClassifierService(
                baseUrl: (string) ($config['base_url'] ?? 'http://127.0.0.1:8001'),
                endpoint: (string) ($config['endpoint'] ?? '/classify'),
                timeoutSeconds: (int) ($config['timeout'] ?? 10),
            );
        });

        $this->app->singleton(\App\Services\Ai\AiDecisionService::class, function ($app) {
            $config = $app['config']->get('services.ai_classifier', []);
            return new \App\Services\Ai\AiDecisionService(
                aiBaseUrl:      (string) ($config['base_url'] ?? 'http://127.0.0.1:8001'),
                timeoutSeconds: (int)    ($config['timeout']  ?? 10),
            );
        });
    }

    /**
     * 🩹 إصلاح رفع الملفات عند استخدام `php artisan serve` على ويندوز.
     *
     * الأمر serve يمرّر قائمة محددة فقط من متغيرات البيئة للعملية الفرعية،
     * وليس من ضمنها TMP و TEMP. وبدونهما لا يجد PHP على ويندوز مجلداً مؤقتاً
     * فيفشل رفع أي ملف بالخطأ:
     *   "PHP Request Startup: File upload error - unable to create a temporary file"
     * والأسوأ أن هذا التحذير يُطبع كـ HTML قبل الـ JSON فيُفسد الرد على الواجهة.
     */
    private function allowFileUploadsOnArtisanServe(): void
    {
        if (! class_exists(ServeCommand::class) || ! property_exists(ServeCommand::class, 'passthroughVariables')) {
            return;
        }

        foreach (['TMP', 'TEMP', 'TMPDIR'] as $variable) {
            if (! in_array($variable, ServeCommand::$passthroughVariables, true)) {
                ServeCommand::$passthroughVariables[] = $variable;
            }
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        $this->registerRateLimiters();
    }

    /**
     * حدود معدل الطلبات على المسارات الحساسة لمنع القوة الغاشمة (brute force)
     * على تسجيل الدخول وأكواد الـ OTP، والتحكم في معدل تقديم التقييمات.
     */
    private function registerRateLimiters(): void
    {
        // تسجيل الدخول: يُحسب لكل (بريد إلكتروني + IP) معاً حتى لا يقفل مهاجم واحد
        // حساب مستخدم آخر بإرسال محاولات باسمه من IP مختلف.
        RateLimiter::for('login', function (Request $request) {
            $key = mb_strtolower((string) $request->input('email')) . '|' . $request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // إرسال/التحقق من أكواد OTP: حد لكل (بريد إلكتروني + IP) وحد إضافي عام لكل IP
        // لمنع تخمين الكود أو إغراق بوابة الرسائل/البريد بطلبات إرسال متكررة.
        RateLimiter::for('otp', function (Request $request) {
            $identifier = (string) ($request->input('email') ?? $request->input('phone_number'));
            $key = mb_strtolower($identifier) . '|' . $request->ip();

            return [
                Limit::perMinute(3)->by($key),
                Limit::perHour(20)->by($request->ip()),
            ];
        });

        // تقديم تقييمات السائقين: تُحسب لكل ولي أمر مصادَق، لمنع إغراق طبقة
        // قرار الذكاء الاصطناعي بتقييمات متكررة موجّهة ضد سائق معين.
        RateLimiter::for('reviews', function (Request $request) {
            $key = $request->user()?->id ?? $request->ip();

            return Limit::perHour(10)->by($key);
        });
    }
}