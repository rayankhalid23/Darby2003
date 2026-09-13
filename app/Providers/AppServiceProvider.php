<?php

namespace App\Providers;

use Illuminate\Foundation\Console\ServeCommand;
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
    }
}