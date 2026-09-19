<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Driver\DriverRegisterController;
use App\Http\Controllers\Api\Driver\ProfileController;
use App\Http\Controllers\Api\Driver\DriverPreferenceController;
use App\Http\Controllers\Api\Driver\AddressController;
use App\Http\Controllers\Api\Driver\ZoneController; 
use App\Http\Controllers\API\Trip\DriverTripController;
use App\Http\Controllers\Api\DriverTrackingController;
use App\Http\Controllers\Api\Driver\SupportTicketController;
use App\Http\Controllers\Api\Driver\DriverStatisticsController;

// أو إذا كان داخل مجلد فرعي:
 use App\Http\Controllers\API\Driver\DriverProfileController;
/*
|--------------------------------------------------------------------------
| Driver Routes (تم إزالة الـ prefix التكراري ليطابق v1/driver مباشرة)
|--------------------------------------------------------------------------
*/

// --- 🔓 مسارات مفتوحة: لا تتطلب تسجيل دخول مسبق ---

    
    // 1. طلب إرسال كود الـ OTP إلى بريد السائق
    Route::post('register', [DriverRegisterController::class, 'registerAccount'])
        ->middleware('throttle:otp')
        ->name('api.driver.register.account');

    // 2. التحقق من كود الـ OTP وإنشاء الحساب وتوليد التوكن فوراً
    Route::post('verify-otp', [DriverRegisterController::class, 'verifyOtp'])
        ->middleware('throttle:otp')
        ->name('api.driver.verify-otp');

    // روابط تأكيد وإلغاء البريد الإلكتروني (تعتمد على التوقيع الرقمي المحمي Signed URL)
    Route::get('email/approve/{id}', [ProfileController::class, 'approveEmailChange'])
        ->name('api.driver.email.approve')
        ->middleware('signed');

    Route::get('email/reject/{id}', [ProfileController::class, 'rejectEmailChange'])
        ->name('api.driver.email.reject')
        ->middleware('signed');


// --- 🔒 مسارات محمية: تتطلب توكن Sanctum ---
Route::middleware('auth:sanctum')->group(function () {
    
    // 3. المرحلة الثانية من التسجيل: رفع الوثائق وبيانات المركبة لإكمال الملف الشخصي
    Route::post('complete-profile/{userId}', [DriverRegisterController::class, 'completeProfile'])
        ->name('api.driver.complete-profile');

    // 4. إلغاء وحذف الحساب غير المكتمل بعد التحقق من الـ OTP (DELETE أو POST)
    Route::delete('abandon-registration', [DriverRegisterController::class, 'abandonRegistration'])
        ->name('api.driver.abandon-registration');
    Route::post('abandon-registration', [DriverRegisterController::class, 'abandonRegistration']);
    Route::delete('cancel-registration', [DriverRegisterController::class, 'abandonRegistration']);
    Route::post('cancel-registration', [DriverRegisterController::class, 'abandonRegistration'])
        ->name('api.driver.cancel-registration');

    Route::post('register-absence', [DriverTripController::class, 'registerAbsence']);

    // --- 🔔 موديول إشعارات السائق (Notifications Module) ---
    Route::prefix('notifications')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'index']);
        Route::get('/unread-count', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'unreadCount']);
        Route::post('/{id}/read', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'markAsRead']);
        Route::patch('/{id}/read', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'markAsRead']);
        Route::post('/read-all', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'markAllAsRead']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'destroy']);
    });

    // موديول العناوين (Addresses Module)
    Route::prefix('addresses')->group(function () {
        
        Route::get('/', [AddressController::class, 'index']);          // عرض كل العناوين
        Route::post('/', [AddressController::class, 'store']);         // إضافة عنوان جديد
        Route::put('/{address}', [AddressController::class, 'update']); // تعديل عنوان (ملاحظة: استخدمنا PUT ومطابق للاسم هندسياً)
        Route::delete('/{address}', [AddressController::class, 'destroy']); // حذف العنوان ناعماً

    });   
    

    
    Route::prefix('trips')->group(function () {
        Route::get('today', [DriverTripController::class, 'todayTrips']);
        Route::get('upcoming-for-absence', [DriverTripController::class, 'upcomingTripsForAbsence']);
        Route::get('history', [DriverTripController::class, 'history']);
        Route::get('history/{tripId}', [DriverTripController::class, 'historyDetails']);
        Route::get('{tripId}', [DriverTripController::class, 'show']);
        Route::post('{tripId}/start', [DriverTripController::class, 'start']);
        Route::post('start', [DriverTripController::class, 'start']);
        Route::get('{tripId}/live', [DriverTripController::class, 'live']);
        Route::get('{tripId}/stops', [DriverTripController::class, 'tripStops']);
        Route::post('{tripId}/location', [DriverTripController::class, 'updateLocation']);
        Route::post('{tripId}/pickup', [DriverTripController::class, 'pickup']);
        Route::post('{tripId}/absent', [DriverTripController::class, 'absent']);
        Route::post('{tripId}/dropoff', [DriverTripController::class, 'dropoff']);
        Route::post('{tripId}/skip/{childId}', [DriverTripController::class, 'skip']);
        Route::post('{tripId}/verify-qr/{childId}', [DriverTripController::class, 'verifyQr']);
        Route::post('{tripId}/children/{tripChildId}/status', [DriverTripController::class, 'updateChildTripStatus']);
        Route::post('register-absence', [DriverTripController::class, 'registerAbsence']);
        Route::post('{tripId}/report-breakdown', [DriverTripController::class, 'reportBreakdown']);
        Route::post('{tripId}/resume', [DriverTripController::class, 'resumeTrip']);
        Route::post('{tripId}/complete', [DriverTripController::class, 'complete']);
    });

    // 🚨 إدارة طوارئ تعطل الحافلات واستبدال السائقين
    Route::prefix('emergency-dispatches')->group(function () {
        Route::get('available', [DriverTripController::class, 'getAvailableBreakdownDispatches']);
        Route::get('{dispatchId}', [DriverTripController::class, 'getBreakdownDispatchDetails']);
        Route::post('{dispatchId}/accept', [DriverTripController::class, 'acceptBreakdownDispatch']);
        Route::post('{dispatchId}/reject', [DriverTripController::class, 'rejectBreakdownDispatch']);
    });

    // عرض بيانات الملف الشخصي للسائق وعلاقاته ومؤشرات الـ AI
    Route::get('profile', [ProfileController::class, 'show'])
        ->name('api.driver.profile.show');

    // 🌟 عرض تقييمات السائق وملاحظات أولياء الأمور وملخص الأداء
    Route::get('reviews', [\App\Http\Controllers\Api\Driver\DriverReviewController::class, 'index'])
        ->name('api.driver.reviews.index');

    // عرض حالة اعتماد الحساب فقط (Pending/Approved/Rejected) — لشاشة انتظار المراجعة
    Route::get('status', [ProfileController::class, 'status'])
        ->name('api.driver.status');
    
    // تحديث البيانات الشخصية والمظهر
    Route::match(['post', 'put'], 'preferences', [DriverPreferenceController::class, 'update'])
    ->name('api.driver.preferences.update');
    Route::match(['post', 'put'], 'profile', [ProfileController::class, 'update']);
    Route::match(['post', 'put'], 'profile/update', [ProfileController::class, 'update']);
    Route::get('profile/email-change/status', [ProfileController::class, 'checkEmailChangeStatus']);
    Route::post('profile/email-change/cancel', [ProfileController::class, 'cancelEmailChange']);
    Route::post('profile/email-change/resend', [ProfileController::class, 'resendEmailChange']);

    // تحديث وتجديد المستندات والوثائق الرسمية
    Route::post('profile/legal-data', [ProfileController::class, 'updateLegalData'])
        ->name('api.driver.profile.legal-data');

    // تحديث بيانات وتفاصيل المركبة
    Route::match(['post', 'put'], 'profile/vehicle/{vehicle}', [ProfileController::class, 'updateVehicle'])
        ->name('api.driver.profile.vehicle.update');
    Route::match(['post', 'put'], 'profile/vehicles/{vehicle}', [ProfileController::class, 'updateVehicle']);
    Route::match(['post', 'put'], 'profile/vehicle', [ProfileController::class, 'updateVehicle']);

    // 🗺️ مسارات تفضيلات العمل ومناطق الجغرافيا للسائق
    Route::get('preferences', [DriverPreferenceController::class, 'show'])
        ->name('api.driver.preferences.show');
        
    Route::post('preferences', [DriverPreferenceController::class, 'update'])
        ->name('api.driver.preferences.update');
        
    Route::post('preferences/zones/add', [DriverPreferenceController::class, 'addZone'])
        ->name('api.driver.preferences.zones.add');
        
    Route::post('preferences/zones/remove', [DriverPreferenceController::class, 'removeZone'])
        ->name('api.driver.preferences.zones.remove');
        
    Route::get('preferences/defaults', [DriverPreferenceController::class, 'defaults'])
        ->name('api.driver.preferences.defaults');

    // --- أضف هذه المسارات داخل مجموعة الـ auth:sanctum ---

// 1. مسار لعرض المستندات والوثائق الرسمية الحالية للسائق
Route::get('profile/legal-data', [ProfileController::class, 'showLegalData'])
->name('api.driver.profile.legal-data.show');

// 2. مسار لعرض بيانات المركبة الحالية للسائق
Route::get('profile/vehicle', [ProfileController::class, 'showVehicle'])
->name('api.driver.profile.vehicle.show');    

    // مسار لعرض جميع المناطق المتاحة في النظام للسائق ليتمكن من اختيارها
Route::get('zones', [ZoneController::class, 'index'])
->name('api.driver.zones.index');    

    // مسارات المحفظة المالية والشحن والسحب
    Route::prefix('wallet')->group(function () {
        Route::get('/balance', [App\Http\Controllers\Api\Driver\WithdrawalController::class, 'balance']);
        Route::get('/summary', [App\Http\Controllers\Api\Driver\WithdrawalController::class, 'summary']);
        Route::get('/payment-methods', [App\Http\Controllers\Api\Driver\DriverRechargeController::class, 'paymentMethods']);
        Route::post('/recharge-request', [App\Http\Controllers\Api\Driver\DriverRechargeController::class, 'store']);
        Route::get('/recharge-requests', [App\Http\Controllers\Api\Driver\DriverRechargeController::class, 'index']);
    });

    // مسارات السحب
    Route::prefix('withdrawals')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\Driver\WithdrawalController::class, 'index']);
        Route::post('/', [App\Http\Controllers\Api\Driver\WithdrawalController::class, 'store']);
    });

    // مسارات الفواتير
    Route::prefix('invoices')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\Shared\InvoiceController::class, 'index']);
        Route::get('/{id}', [App\Http\Controllers\Api\Shared\InvoiceController::class, 'show']);
    });

    // مسارات الدعم الفني للسائق
    Route::prefix('support-tickets')->group(function () {
        Route::get('/financial-history', [SupportTicketController::class, 'financialHistory']);
        Route::get('/parent-trips', [SupportTicketController::class, 'parentTrips']);
        Route::get('/', [SupportTicketController::class, 'index']);
        Route::post('/', [SupportTicketController::class, 'store']);
        Route::get('/{id}', [SupportTicketController::class, 'show']);
        Route::post('/{id}/reply', [SupportTicketController::class, 'reply']);
    });
    // --- مسارات إدارة مركبات السائق ---
    Route::prefix('vehicles')->group(function () {
        Route::get('/', [DriverProfileController::class, 'indexVehicles'])->name('api.driver.vehicles.index');
        Route::get('/{vehicle}', [DriverProfileController::class, 'showVehicle'])->name('api.driver.vehicles.show');
    });

    Route::post('/driver/update-location', [DriverTrackingController::class, 'updateLocation']);

    // -------------------------------------------------------------
    // 🗺️ موديول إدارة المسارات والتزام إسناد الاشتراكات (Routes & Assignments)
    // -------------------------------------------------------------
    Route::get('home-status', [App\Http\Controllers\Api\Driver\DriverRouteController::class, 'checkPendingAssignments']);

    Route::prefix('routes')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\Driver\DriverRouteController::class, 'index']);
        Route::post('/', [App\Http\Controllers\Api\Driver\DriverRouteController::class, 'store']);
        Route::get('/{routeId}', [App\Http\Controllers\Api\Driver\DriverRouteController::class, 'show']);
        Route::put('/{routeId}', [App\Http\Controllers\Api\Driver\DriverRouteController::class, 'update']);
        Route::delete('/{routeId}', [App\Http\Controllers\Api\Driver\DriverRouteController::class, 'destroy']);
        Route::put('/{routeId}/reorder', [App\Http\Controllers\Api\Driver\DriverRouteController::class, 'reorderStops']);
        Route::delete('/{routeId}/subscriptions/{subscriptionId}', [App\Http\Controllers\Api\Driver\DriverRouteController::class, 'unassignSubscription']);
    });

    Route::prefix('subscriptions')->group(function () {
        Route::get('/{subscriptionId}/route-recommendations', [App\Http\Controllers\Api\Driver\DriverRouteController::class, 'recommendations']);
        Route::post('/{subscriptionId}/assign-route', [App\Http\Controllers\Api\Driver\DriverRouteController::class, 'assignSubscription']);
        Route::post('/{subscriptionId}/move-route', [App\Http\Controllers\Api\Driver\DriverRouteController::class, 'moveSubscription']);
    });

    // -------------------------------------------------------------
    // 📩 طلبات الاشتراك والاشتراكات النشطة للسائق (Subscription Requests)
    // -------------------------------------------------------------
    Route::get('requests', [\App\Http\Controllers\Api\Driver\DriverSubscriptionController::class, 'index']);
    Route::get('subscription-requests', [\App\Http\Controllers\Api\Driver\DriverSubscriptionController::class, 'index']);

    Route::get('requests/{id}', [\App\Http\Controllers\Api\Driver\DriverSubscriptionController::class, 'show']);
    Route::get('subscription-requests/{id}', [\App\Http\Controllers\Api\Driver\DriverSubscriptionController::class, 'show']);

    Route::get('requests/{id}/trip-details', [\App\Http\Controllers\Api\Driver\DriverSubscriptionController::class, 'tripDetails']);
    Route::get('subscription-requests/{id}/trip-details', [\App\Http\Controllers\Api\Driver\DriverSubscriptionController::class, 'tripDetails']);

    Route::get('requests/{id}/feasibility-check', [\App\Http\Controllers\Api\Driver\DriverSubscriptionController::class, 'feasibilityCheck']);
    Route::get('subscription-requests/{id}/feasibility-check', [\App\Http\Controllers\Api\Driver\DriverSubscriptionController::class, 'feasibilityCheck']);

    Route::match(['put', 'patch', 'post'], 'requests/{id}/status', [\App\Http\Controllers\Api\Driver\DriverSubscriptionController::class, 'updateStatus']);
    Route::match(['put', 'patch', 'post'], 'subscription-requests/{id}/status', [\App\Http\Controllers\Api\Driver\DriverSubscriptionController::class, 'updateStatus']);

    Route::post('requests/{id}/accept', [\App\Http\Controllers\Api\Driver\DriverSubscriptionController::class, 'accept']);
    Route::post('subscription-requests/{id}/accept', [\App\Http\Controllers\Api\Driver\DriverSubscriptionController::class, 'accept']);

    Route::post('requests/{id}/reject', [\App\Http\Controllers\Api\Driver\DriverSubscriptionController::class, 'reject']);
    Route::post('subscription-requests/{id}/reject', [\App\Http\Controllers\Api\Driver\DriverSubscriptionController::class, 'reject']);

    // الاشتراكات النشطة للسائق
    Route::get('active-subscriptions', [\App\Http\Controllers\Api\Driver\DriverSubscriptionController::class, 'activeSubscriptions']);
    Route::get('active-subscriptions/{id}', [\App\Http\Controllers\Api\Driver\DriverSubscriptionController::class, 'activeSubscriptionDetails']);
    Route::post('active-subscriptions/{id}/cancel', [\App\Http\Controllers\Api\Driver\DriverSubscriptionController::class, 'cancelActiveSubscription']);

    Route::get('chats', [\App\Http\Controllers\Api\Driver\DriverSubscriptionController::class, 'getDriverChatList']);

    // -------------------------------------------------------------
    // 📍 طلبات تغيير موقع الاستلام/التسليم الواردة من أولياء الأمور
    // -------------------------------------------------------------
    Route::prefix('location-change-requests')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\Driver\LocationChangeController::class, 'index']);
        Route::post('/{id}/respond', [App\Http\Controllers\Api\Driver\LocationChangeController::class, 'respond']);
    });

    // -------------------------------------------------------------
    // 🕓 التأكيد اليدوي لرحلات سابقة لم يوثّقها التطبيق
    // -------------------------------------------------------------
    Route::prefix('trip-manual-confirmations')->group(function () {
        Route::get('/parents-and-children', [App\Http\Controllers\Api\Driver\TripManualConfirmationController::class, 'subscribedParentsAndChildren']);
        Route::get('/incomplete-trips', [App\Http\Controllers\Api\Driver\TripManualConfirmationController::class, 'incompleteTrips']);
        Route::get('/trips/{tripId}/children', [App\Http\Controllers\Api\Driver\TripManualConfirmationController::class, 'tripChildren']);
        Route::post('/', [App\Http\Controllers\Api\Driver\TripManualConfirmationController::class, 'store']);
    });

    // -------------------------------------------------------------
    // 📊 لوحة التحكم والإحصائيات الشاملة للسائق (Statistics & Dashboard)
    // -------------------------------------------------------------
    Route::get('statistics', [DriverStatisticsController::class, 'index'])->name('api.driver.statistics');
    Route::get('dashboard/stats', [DriverStatisticsController::class, 'index'])->name('api.driver.dashboard.stats');

});