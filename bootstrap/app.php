<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        
        // تسجيل وإدراج ملفات المسارات المخصصة والمستقلة للموديولات
        then: function () { 
            // 1. تسجيل مسارات أولياء الأمور (Parents Module)
            Route::middleware('api')
                ->prefix('api/parent')
                ->group(base_path('routes/parent.php'));

            // 2. تسجيل مسارات السائقين (Drivers Module)
            Route::middleware('api')
                ->prefix('api/v1/driver')
                ->group(base_path('routes/Driver.php'));

            Route::middleware('api')
                ->prefix('api/driver')
                ->group(base_path('routes/Driver.php'));
                

            // 3. تسجيل مسارات المشرفين ولوحة التحكم (Admin Module)
            Route::middleware('api')
                ->prefix('api/admin')
                ->group(base_path('routes/Admin.php'));

            Route::middleware('api')
                ->prefix('api/v1/admin')
                ->group(base_path('routes/Admin.php'));    
            
            // 4. تسجيل مسارات طلبات الاشتراكات الموحدة (Requests Module)
            Route::middleware('api')
                ->prefix('api') 
                ->group(base_path('routes/request.php'));   
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'permission'      => \App\Http\Middleware\CheckAdminPermission::class,
            'terms.accepted'  => \App\Http\Middleware\EnsureTermsAccepted::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        
        // معالجة واصطياد أخطاء الـ API لمنع الانهيار وتقديم ردود تجارية احترافية المظهر
        $exceptions->render(function (Throwable $e, Request $request) {
            
            // نطبق الفحص الموحد والمنظم فقط إذا كان الطلب موجهاً لمسارات الـ API
            if ($request->is('api/*')) {

                // إذا كان الخطأ بسبب فحص البيانات (Validation) أو استجابة HTTP معتمدة، نتركه يمر للـ Requests والـ Controllers
                if ($e instanceof ValidationException || $e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                    return null;
                }

                // عدم التوثيق (Unauthenticated)
                if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    return response()->json([
                        'status'     => false,
                        'error_code' => 'UNAUTHENTICATED',
                        'message'    => 'غير مصرح بالوصول، يرجى تسجيل الدخول أولاً.'
                    ], 401);
                }

                // [الحالة 1]: ضغط وحمل زائد على السيرفر (تخطي الـ Rate Limit)
                if ($e instanceof TooManyRequestsHttpException) {
                    return response()->json([
                        'status'     => false,
                        'error_code' => 'TOO_MANY_REQUESTS',
                        'message'    => 'السيرفر تحت ضغط حالياً! يرجى إعادة المحاولة بعد قليل.'
                    ], 429);
                }

                // [الحالة 2]: خطأ في الاتصال بقاعدة البيانات أو سقوط سيرفر الـ DB مؤقتاً
                if ($e instanceof QueryException) {
                    return response()->json([
                        'status'     => false,
                        'error_code' => 'DATABASE_ERROR',
                        'message'    => 'نواجه مشكلة مؤقتة في الاتصال بخادم البيانات، يرجى التحقق من استقرار اتصالك وإعادة المحاولة.'
                    ], 500);
                }

                // [الحالة 3]: السيرفر قيد الصيانة أو متوقف مؤقتاً للتحديث
                if ($e instanceof ServiceUnavailableHttpException) {
                    return response()->json([
                        'status'     => false,
                        'error_code' => 'SERVER_MAINTENANCE',
                        'message'    => 'المنصة خاضعة للتحديثات الدورية المجدولة، يرجى المحاولة بعد قليل.'
                    ], 503);
                }

                // [الحالة 4]: طلب عنصر غير موجود (سجل محذوف، مدرسة ملغية، أو رابط خطأ)
                if ($e instanceof NotFoundHttpException) {
                    $response = [
                        'status'     => false,
                        'error_code' => 'NOT_FOUND',
                        'message'    => 'العنصر أو الرابط الذي تحاول الوصول إليه غير موجود أو تم حذفه.'
                    ];

                    // إظهار تفاصيل التتبع الحساسة فقط إذا كان وضع التطوير نشطاً (حماية الإنتاج)
                    if (config('app.debug')) {
                        $response['debug_message'] = $e->getMessage();
                        $response['real_file']     = $e->getFile();
                        $response['real_line']     = $e->getLine();
                        $response['caused_by']     = $e->getPrevious() ? get_class($e->getPrevious()) : 'رابط خطأ (Route)';
                    }

                    return response()->json($response, 404);
                }

                // [الحالة 4-ب]: فعل HTTP غير مدعوم لهذا المسار (POST على مسار PUT مثلاً)
                // بدون هذه الحالة كان الخطأ يسقط في شبكة الأمان ويعود 500، فيبدو
                // خطأ خادم بينما هو خطأ في الطلب نفسه — يُضيّع وقت التشخيص ويُخفي
                // عدم تطابق حقيقي بين المسارات والتوثيق.
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
                    $response = [
                        'status'     => false,
                        'error_code' => 'METHOD_NOT_ALLOWED',
                        'message'    => 'طريقة الطلب المستخدمة غير مدعومة لهذا المسار.'
                    ];

                    if (config('app.debug')) {
                        $response['debug_message'] = $e->getMessage();
                    }

                    return response()->json($response, 405);
                }

                // [الحالة 5]: شبكة الأمان المطلقة للأخطاء غير المتوقعة (Server Error)
                $serverErrorResponse = [
                    'status'     => false,
                    'error_code' => 'SERVER_ERROR',
                    'message'    => 'حدث خطأ داخلي في النظام، يرجى التواصل مع الدعم الفني.'
                ];

                if (config('app.debug')) {
                    $serverErrorResponse['message'] = 'حدث خطأ غير متوقع: ' . $e->getMessage();
                    $serverErrorResponse['file']    = $e->getFile();
                    $serverErrorResponse['line']    = $e->getLine();
                }

                return response()->json($serverErrorResponse, 500);
            }
        });
        
    })->create();