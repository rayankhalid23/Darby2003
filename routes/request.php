<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\Parent\ParentSubscriptionController;
use App\Http\Controllers\API\Driver\DriverSubscriptionController;
use App\Http\Controllers\Api\Shared\ChatController;
use App\Http\Controllers\API\Driver\DriverRouteController;
use App\Http\Controllers\Api\Trip\DriverTripController;


Route::middleware('auth:sanctum')->group(function () {

    // ============================================================
    // مسارات أولياء الأمور لإرسال واستعراض طلبات الاشتراك
    // ============================================================
    Route::prefix('parent')->group(function () {

        Route::post('/', [ParentSubscriptionController::class, 'store']); 
        Route::post('/requests', [ParentSubscriptionController::class, 'store']); 
        // المسار الموحد لجلب طلبات الاشتراك (الطلبات الأولية المعلقة والمرفوضة)
        Route::get('/requests', [ParentSubscriptionController::class, 'index']); 
        // مسار إلغاء طلب الاشتراك (المعلق)
        Route::post('/requests/{id}/cancel', [ParentSubscriptionController::class, 'cancel']);
        
        // المسار الموحد والوحيد الجديد لجلب الاشتراكات المفعّلة والموافَق عليها بالفلاتر
        Route::get('/active-subscriptions', [ParentSubscriptionController::class, 'activeSubscriptions']); 
        // المسار الخاص بجلب تفاصيل اشتراك نشط معين
        Route::get('/active-subscriptions/{id}', [ParentSubscriptionController::class, 'showActive']);
        Route::get('/chats', [ChatController::class, 'getParentChatList']);
        Route::post('/active-subscriptions/{id}/cancel', [ParentSubscriptionController::class, 'cancelActiveSubscription']);
        
        Route::get('/requests/{id}', [ParentSubscriptionController::class, 'showRequest']); 
        Route::get('/drivers', [ParentSubscriptionController::class, 'getDrivers']); 
        
    });

    // ============================================================
    // مسارات السائقين المحدثة
    // ============================================================
    Route::prefix('driver')->group(function () {
        // 1. مسارات ثابتة (Static Routes) - توضع أولاً
        
        // المسار الموحد الجديد لجلب طلبات الاشتراك المبدئية بالفلاتر
        Route::get('/requests', [DriverSubscriptionController::class, 'index']);
        Route::get('/requests/{id}/trip-details', [DriverSubscriptionController::class, 'tripDetails']);
        Route::get('/requests/{id}/feasibility-check', [DriverSubscriptionController::class, 'feasibilityCheck']);
        Route::get('/requests/{id}', [DriverSubscriptionController::class, 'show']);
        
        // المسار الموحد الجديد لجلب الاشتراكات الفعلية والمثبتة بالفلاتر
        Route::get('/active-subscriptions', [DriverSubscriptionController::class, 'activeSubscriptions']);
        Route::get('/active-subscriptions/{id}', [DriverSubscriptionController::class, 'activeSubscriptionDetails']);
        Route::post('/active-subscriptions/{id}/cancel', [DriverSubscriptionController::class, 'cancelActiveSubscription']);
        Route::get('/chats', [ChatController::class, 'getDriverChatList']);
  
        Route::get('/routes', [DriverRouteController::class, 'index']); 
        Route::post('/trips/start', [DriverTripController::class, 'start']);
        
        // 2. مسارات تحتوي على متغيرات (Dynamic Parameters) - توضع أخيراً
        Route::put('/routes/{route}', [DriverRouteController::class, 'update']);
        Route::put('/requests/{id}/status', [DriverSubscriptionController::class, 'updateStatus']); 
        Route::put('{id}/status', [DriverSubscriptionController::class, 'updateStatus']); 
    });
    
});