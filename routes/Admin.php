<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\Admin\SchoolController;
use App\Http\Controllers\Api\Admin\AdminDriverController; 
use App\Http\Controllers\Api\Admin\ZoneController;
use App\Http\Controllers\Api\Admin\DriverReviewController as AdminDriverReviewController;
use App\Http\Controllers\Api\Admin\ComplaintController as AdminComplaintController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\AdminAvatarController;
use App\Http\Controllers\Api\Admin\AdminProfileController;
use App\Http\Controllers\Api\Admin\AdminAuditLogController;
use App\Http\Controllers\Api\Admin\MunicipalityController;
use App\Http\Controllers\Api\Admin\SubMunicipalityController;
use App\Http\Controllers\Api\Admin\MunicipalityZoneController;
use App\Http\Controllers\Api\Admin\AdminAiAlertsController;
use App\Http\Controllers\Api\Admin\AdminDriverActionController;

// =========================================================================
// 🖼️ صور المشرفين (عامة بلا توكن)
// =========================================================================
// المتصفح يجلب الصورة في وسم <img> أو عبر XHR بدون أي هيدر Authorization،
// لذلك يجب أن يكون المسار عاماً. ولأنه يمر عبر لارافيل فإنه يحصل على ترويسات
// CORS الخاصة بـ api/* تلقائياً، بعكس ملفات /storage الثابتة.
Route::get('/avatars/{filename}', [AdminAvatarController::class, 'show'])
    ->name('api.admin.avatars.show');

// =========================================================================
// 🔒 المسارات المحمية (تتطلب تسجيل الدخول وحمل توكن Sanctum)
// =========================================================================
Route::middleware(['auth:sanctum'])->group(function () {

    // --- 🛡️ مسار شجرة الأدوار والصلاحيات المتاحة للنظام ---
    Route::get('/roles-permissions', [AdminController::class, 'rolesAndPermissions'])
        ->name('api.admin.roles-permissions');

    // =========================================================================
    // 📊 مسارات لوحة التحكم الرئيسية (الداشبورد)
    // =========================================================================
    Route::prefix('dashboard')->group(function () {
        // إحصائيات الداشبورد (المستخدمين، السائقين، الاشتراكات، الرحلات)
        Route::get('/stats', [DashboardController::class, 'stats'])
            ->middleware('permission:dashboard.view_stats')
            ->name('api.admin.dashboard.stats');

        // الرحلات النشطة الآن للرادار الحي
        Route::get('/active-trips', [DashboardController::class, 'activeTrips'])
            ->middleware('permission:dashboard.view_radar')
            ->name('api.admin.dashboard.active-trips');
    });

    // تشغيل يدوي لتوليد الرحلات اليومية (Daily Trips) دون انتظار الـ Cron
    Route::post('/trips/generate-daily', [\App\Http\Controllers\Api\Admin\TripOpsController::class, 'generateDaily'])
        ->middleware('permission:trips.generate_daily')
        ->name('api.admin.trips.generate-daily');

    // --- 🔔 إشعارات لوحة تحكم الأدمن ---
    Route::prefix('notifications')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Admin\AdminNotificationController::class, 'index']);
        Route::get('/unread-count', [\App\Http\Controllers\Api\Admin\AdminNotificationController::class, 'unreadCount']);
        Route::patch('/{id}/read', [\App\Http\Controllers\Api\Admin\AdminNotificationController::class, 'markAsRead']);
        Route::post('/{id}/read', [\App\Http\Controllers\Api\Admin\AdminNotificationController::class, 'markAsRead']);
        Route::post('/read-all', [\App\Http\Controllers\Api\Admin\AdminNotificationController::class, 'markAllAsRead']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\AdminNotificationController::class, 'destroy']);
    });
    
    // --- 👤 الملف الشخصي للمشرف / مدير النظام (الحساب الحالي) ---
    Route::prefix('profile')->group(function () {
        Route::get('/',  [AdminProfileController::class, 'show'])->name('api.admin.profile.show');
        Route::post('/', [AdminProfileController::class, 'update'])->name('api.admin.profile.update');

        Route::get('/email-change/status',  [AdminProfileController::class, 'emailChangeStatus']);
        Route::post('/email-change/cancel', [AdminProfileController::class, 'cancelEmailChange']);
        Route::post('/email-change/resend', [AdminProfileController::class, 'resendEmailChange']);
    });

    // --- مجموعة روابط إدارة المشرفين ---
    Route::prefix('admins')->middleware('permission:admins.manage')->group(function () {
        Route::get('/', [AdminController::class, 'index']);
        Route::post('/', [AdminController::class, 'store']);

        // --- 📧 متابعة طلب تغيير البريد الإلكتروني ---
        Route::get('/{id}/email-change/status',  [AdminController::class, 'emailChangeStatus']);
        Route::post('/{id}/email-change/cancel', [AdminController::class, 'cancelEmailChange']);
        Route::post('/{id}/email-change/resend', [AdminController::class, 'resendEmailChange']);

        Route::get('/{id}', [AdminController::class, 'show']);
        Route::put('/{id}', [AdminController::class, 'update']);
        Route::post('/{id}', [AdminController::class, 'update']); 
        Route::delete('/{id}', [AdminController::class, 'destroy']);
    });

    // --- 👥 مجموعة روابط التحكم في السائقين ---
    Route::prefix('drivers')->group(function () {
        // 1. مسار جلب قائمة طلبات السائقين مع الفلترة والبحث الذكي
        Route::get('/', [AdminDriverController::class, 'index'])
            ->middleware('permission:drivers.view')
            ->name('api.admin.drivers.index');
        
        // 4. مسار جلب كافة طلبات تعديل البيانات والمركبات المعلقة للآدمن
        Route::get('/pending-changes', [AdminDriverController::class, 'pendingChanges'])
            ->middleware('permission:drivers.review_changes,drivers.view')
            ->name('api.admin.drivers.pending.list');
        
        // 5. مسار عرض تفصيلي مقارن لطلب تعديل محدد
        Route::get('/pending-changes/{id}', [AdminDriverController::class, 'showPendingChange'])
            ->middleware('permission:drivers.review_changes,drivers.view')
            ->name('api.admin.drivers.pending.show');
        
        // 6. مسار معالجة قرار الموافقة والتطبيق الفوري أو الرفض المسبب لتعديلات السائق
        Route::post('/pending-changes/{id}/review', [AdminDriverController::class, 'reviewProfileChange'])
            ->middleware('permission:drivers.review_changes')
            ->name('api.admin.drivers.pending.review');

        // 2. مسار جلب تفاصيل ووثائق وإحصائيات سائق معين بعمق لمراجعته
        Route::get('/{id}', [AdminDriverController::class, 'show'])
            ->middleware('permission:drivers.view')
            ->name('api.admin.drivers.show');
        
        // 2-ب. مسار تعديل بيانات السائق مباشرة من قبل المشرف / الأدمن
        Route::put('/{id}', [AdminDriverController::class, 'update'])
            ->middleware('permission:drivers.edit_data')
            ->name('api.admin.drivers.update');
        Route::post('/{id}', [AdminDriverController::class, 'update'])
            ->middleware('permission:drivers.edit_data');

        // 3. مسار اتخاذ قرار المراجعة لإنشاء الحساب (قبول أو رفض مسبب)
        Route::post('/{id}/review', [AdminDriverController::class, 'review'])
            ->middleware('permission:drivers.review_initial')
            ->name('api.admin.drivers.review');

        // 7. مسار إلغاء حظر وإعادة تفعيل السائق يدوياً للإدارة
        Route::post('/{driverId}/unblock', [AdminDriverActionController::class, 'unblockDriver'])
            ->name('api.admin.drivers.unblock');
    });

    // =========================================================================
    // 🤖 مسارات مراجعة تنبيهات وإجراءات الذكاء الاصطناعي للإدارة (360 Breakdown)
    // =========================================================================
    Route::prefix('ai-alerts')->group(function () {
        Route::get('/', [AdminAiAlertsController::class, 'index'])
            ->name('api.admin.ai-alerts.index');
        Route::get('/{id}', [AdminAiAlertsController::class, 'show'])
            ->name('api.admin.ai-alerts.show');
        Route::post('/{id}/resolve', [AdminDriverActionController::class, 'resolveAlert'])
            ->name('api.admin.ai-alerts.resolve');
    });

    // =========================================================================
    // 🛑 مسارات إدارة ومراجعة طلبات غياب السائقين
    // =========================================================================
    Route::prefix('driver-absences')->group(function () {
        Route::get('/', [AdminDriverController::class, 'getAbsenceRequests'])
            ->name('api.admin.driver-absences.index');
        Route::get('/{id}', [AdminDriverController::class, 'showAbsenceRequest'])
            ->name('api.admin.driver-absences.show');
        Route::post('/{id}/approve', [AdminDriverController::class, 'approveAbsenceRequest'])
            ->name('api.admin.driver-absences.approve');
        Route::post('/{id}/reject', [AdminDriverController::class, 'rejectAbsenceRequest'])
            ->name('api.admin.driver-absences.reject');
    });

    Route::prefix('drivers/absences')->group(function () {
        Route::get('/', [AdminDriverController::class, 'getAbsenceRequests']);
        Route::get('/{id}', [AdminDriverController::class, 'showAbsenceRequest']);
        Route::post('/{id}/approve', [AdminDriverController::class, 'approveAbsenceRequest']);
        Route::post('/{id}/reject', [AdminDriverController::class, 'rejectAbsenceRequest']);
    });

    // =========================================================================
    // 📜 مسارات سجل تدقيق إجراءات المشرفين والمدراء
    // =========================================================================
    Route::get('/admin-audit-logs', [AdminAuditLogController::class, 'index'])
        ->middleware('permission:audit_logs.view')
        ->name('api.admin.audit-logs.index');
    Route::get('/admin-audit-logs/{id}', [AdminAuditLogController::class, 'show'])
        ->middleware('permission:audit_logs.view')
        ->name('api.admin.audit-logs.show');

    // --- مجموعة روابط إدارة المدارس ---
    Route::prefix('schools')->middleware('permission:schools.manage')->group(function () {
        Route::get('/', [SchoolController::class, 'index']);
        Route::post('/', [SchoolController::class, 'store']);
        Route::get('/{id}', [SchoolController::class, 'show']);
        // POST مطلوب: هو الفعل الموثّق في FRONTEND_ADMIN_API_GUIDE للتعديل، وهو ما
        // ترسله لوحة الإدارة فعلياً (اتساقاً مع بقية مسارات التعديل في المشروع).
        // PUT/PATCH مُبقاة للتوافق مع أي عميل قديم.
        Route::match(['post', 'put', 'patch'], '/{id}', [SchoolController::class, 'update']);
        Route::delete('/{id}', [SchoolController::class, 'destroy']);
    });

    // =========================================================================
    // 🗺️ مسارات إدارة الجغرافيا والمناطق والبلديات
    // =========================================================================
    Route::match(['get', 'post'], '/geography/search', [\App\Http\Controllers\Api\Shared\GeographySearchController::class, 'search'])
        ->middleware('permission:geography.manage')
        ->name('api.admin.geography.search');

    Route::prefix('zones')->middleware('permission:geography.manage')->group(function () {
        Route::get('/', [ZoneController::class, 'index']);
        Route::post('/', [ZoneController::class, 'store']);
        Route::get('/{id}', [ZoneController::class, 'show']);
        Route::put('/{id}', [ZoneController::class, 'update']);
        Route::post('/{id}', [ZoneController::class, 'update']);
        Route::delete('/{id}', [ZoneController::class, 'destroy']);
    });
    Route::get('/zones-tree', [ZoneController::class, 'index'])
        ->middleware('permission:geography.manage');

    Route::prefix('municipalities')->middleware('permission:geography.manage')->group(function () {
        Route::get('/', [MunicipalityController::class, 'index']);
        Route::post('/', [MunicipalityController::class, 'store']);
        Route::get('/{id}/sub-municipalities', [SubMunicipalityController::class, 'index']);
        Route::post('/{id}/sub-municipalities', [SubMunicipalityController::class, 'store']);
        Route::get('/{id}/zones', [MunicipalityZoneController::class, 'indexByMunicipality']);
        Route::get('/{id}', [MunicipalityController::class, 'show']);
        Route::put('/{id}', [MunicipalityController::class, 'update']);
        Route::post('/{id}', [MunicipalityController::class, 'update']);
        Route::delete('/{id}', [MunicipalityController::class, 'destroy']);
    });

    Route::prefix('sub-municipalities')->middleware('permission:geography.manage')->group(function () {
        Route::get('/{id}/zones', [MunicipalityZoneController::class, 'index']);
        Route::post('/{id}/zones', [MunicipalityZoneController::class, 'store']);
        Route::get('/{id}', [SubMunicipalityController::class, 'show']);
        Route::put('/{id}', [SubMunicipalityController::class, 'update']);
        Route::post('/{id}', [SubMunicipalityController::class, 'update']);
        Route::delete('/{id}', [SubMunicipalityController::class, 'destroy']);
    });

    Route::prefix('admin-zones')->middleware('permission:geography.manage')->group(function () {
        Route::get('/{id}', [MunicipalityZoneController::class, 'show']);
        Route::put('/{id}', [MunicipalityZoneController::class, 'update']);
        Route::post('/{id}', [MunicipalityZoneController::class, 'update']);
        Route::delete('/{id}', [MunicipalityZoneController::class, 'destroy']);
    });

    // --- 📊 مسارات إدارة تقييمات السائقين للأدمن ---
    Route::prefix('driver-reviews')->middleware('permission:driver_reviews.manage')->group(function () {
        Route::get('/all', [\App\Http\Controllers\Api\Admin\DriverReviewController::class, 'allReviews']); 
        Route::get('/driver/{driverId}', [\App\Http\Controllers\Api\Admin\DriverReviewController::class, 'index']); 
        Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\DriverReviewController::class, 'destroy']); 
    });

    // --- 📋 مسارات إدارة الشكاوى للأدمن ---
    Route::prefix('complaints')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Admin\ComplaintController::class, 'index'])
            ->middleware('permission:complaints.view');
        Route::get('/{id}', [\App\Http\Controllers\Api\Admin\ComplaintController::class, 'show'])
            ->middleware('permission:complaints.view');
        Route::get('/driver/{driverId}', [\App\Http\Controllers\Api\Admin\ComplaintController::class, 'driverComplaints'])
            ->middleware('permission:complaints.view');
        Route::post('/{id}/review', [\App\Http\Controllers\Api\Admin\ComplaintController::class, 'review'])
            ->middleware('permission:complaints.resolve');
    });

    // --- 🎫 مسارات الدعم الفني وتذاكر المشاكل للأدمن ---
    Route::prefix('support-tickets')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Admin\SupportTicketController::class, 'index']);
        Route::get('/operations', [\App\Http\Controllers\Api\Admin\SupportTicketController::class, 'operationsIndex']);
        Route::get('/financial', [\App\Http\Controllers\Api\Admin\SupportTicketController::class, 'financialIndex']);
        Route::get('/{id}', [\App\Http\Controllers\Api\Admin\SupportTicketController::class, 'show']);
        Route::post('/{id}/reply', [\App\Http\Controllers\Api\Admin\SupportTicketController::class, 'reply']);
        Route::post('/{id}/status', [\App\Http\Controllers\Api\Admin\SupportTicketController::class, 'updateStatus']);
        Route::post('/{id}/resolve', [\App\Http\Controllers\Api\Admin\SupportTicketController::class, 'updateStatus']);
        Route::post('/{id}/apply-penalty', [\App\Http\Controllers\Api\Admin\SupportTicketController::class, 'applyPenalty']);
        Route::post('/{id}/transfer-to-financial', [\App\Http\Controllers\Api\Admin\SupportTicketController::class, 'transferToFinancial']);
        Route::post('/{id}/execute-settlement', [\App\Http\Controllers\Api\Admin\SupportTicketController::class, 'executeSettlement']);
        Route::post('/{id}/close', [\App\Http\Controllers\Api\Admin\SupportTicketController::class, 'close']);
    });

    // --- 📜 مسارات إدارة الشروط والأحكام (نسخ + مواد + نشر + إحصاء الموافقات) ---
    Route::prefix('terms')->middleware('permission:content.manage_terms')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Admin\AdminTermsController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\Admin\AdminTermsController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\Api\Admin\AdminTermsController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\Admin\AdminTermsController::class, 'update']);
        Route::post('/{id}', [\App\Http\Controllers\Api\Admin\AdminTermsController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\AdminTermsController::class, 'destroy']);
        Route::post('/{id}/publish', [\App\Http\Controllers\Api\Admin\AdminTermsController::class, 'publish']);
        Route::get('/{id}/acceptance-stats', [\App\Http\Controllers\Api\Admin\AdminTermsController::class, 'acceptanceStats']);

        Route::post('/{versionId}/articles', [\App\Http\Controllers\Api\Admin\AdminTermsController::class, 'storeArticle']);
        Route::put('/{versionId}/articles/{articleId}', [\App\Http\Controllers\Api\Admin\AdminTermsController::class, 'updateArticle']);
        Route::post('/{versionId}/articles/{articleId}', [\App\Http\Controllers\Api\Admin\AdminTermsController::class, 'updateArticle']);
        Route::delete('/{versionId}/articles/{articleId}', [\App\Http\Controllers\Api\Admin\AdminTermsController::class, 'destroyArticle']);
    });

    // --- 💰 مسارات الإدارة المالية الشاملة للأدمن ---
    Route::prefix('financial')->group(function () {
        Route::match(['get', 'post', 'put'], '/pricing-settings', [\App\Http\Controllers\Api\Admin\PricingSettingController::class, 'manage'])
            ->middleware('permission:financial.manage_pricing')
            ->name('api.admin.financial.pricing.manage');
            
        // 1. الداشبورد والملخص والسلامة المالية والسجلات
        Route::get('/summary', [\App\Http\Controllers\Api\Admin\FinancialLedgerController::class, 'summary'])
            ->middleware('permission:financial.view_summary');
        Route::get('/solvency-check', [\App\Http\Controllers\Api\Admin\FinancialLedgerController::class, 'solvencyCheck'])
            ->middleware('permission:financial.view_summary');
        Route::get('/ledger', [\App\Http\Controllers\Api\Admin\FinancialLedgerController::class, 'ledgerLogs'])
            ->middleware('permission:financial.view_ledger');
        Route::get('/audit-logs', [\App\Http\Controllers\Api\Admin\FinancialLedgerController::class, 'auditLogs'])
            ->middleware('permission:financial.view_ledger');

        // 2. الفواتير
        Route::get('/invoices', [\App\Http\Controllers\Api\Admin\FinancialController::class, 'invoices'])
            ->middleware('permission:financial.view_ledger,financial.view_summary');
        Route::get('/invoices/{id}', [\App\Http\Controllers\Api\Admin\FinancialController::class, 'invoiceDetail'])
            ->middleware('permission:financial.view_ledger,financial.view_summary');

        // 3. طلبات السحب
        Route::get('/withdrawals', [\App\Http\Controllers\Api\Admin\FinancialController::class, 'withdrawals'])
            ->middleware('permission:financial.manage_withdrawals');
        Route::get('/withdrawals/{id}', [\App\Http\Controllers\Api\Admin\FinancialController::class, 'withdrawalDetail'])
            ->middleware('permission:financial.manage_withdrawals');
        Route::post('/withdrawals/{id}/process', [\App\Http\Controllers\Api\Admin\FinancialController::class, 'processWithdrawal'])
            ->middleware('permission:financial.manage_withdrawals');

        // 4. طلبات الشحن
        Route::get('/recharges', [\App\Http\Controllers\Api\Admin\FinancialController::class, 'rechargeRequests'])
            ->middleware('permission:financial.manage_recharges');
        Route::get('/recharges/{id}', [\App\Http\Controllers\Api\Admin\FinancialController::class, 'rechargeDetail'])
            ->middleware('permission:financial.manage_recharges');
        Route::post('/recharges/{id}/process', [\App\Http\Controllers\Api\Admin\FinancialController::class, 'processRecharge'])
            ->middleware('permission:financial.manage_recharges');

        // 5. الأمانات المعلقة وتفاصيل التحرير
        Route::get('/escrows', [\App\Http\Controllers\Api\Admin\FinancialLedgerController::class, 'escrowOverview'])
            ->middleware('permission:financial.release_escrows');
        Route::post('/release-escrows', [\App\Http\Controllers\Api\Admin\FinancialLedgerController::class, 'releaseEscrows'])
            ->middleware('permission:financial.release_escrows');

        // 6. النزاعات المالية
        Route::get('/disputes', [\App\Http\Controllers\Api\Admin\FinancialLedgerController::class, 'disputesList'])
            ->middleware('permission:financial.resolve_disputes');
        Route::get('/disputes/{id}', [\App\Http\Controllers\Api\Admin\FinancialLedgerController::class, 'disputeDetail'])
            ->middleware('permission:financial.resolve_disputes');
        Route::post('/disputes/{disputeId}/resolve', [\App\Http\Controllers\Api\Admin\FinancialLedgerController::class, 'resolveDispute'])
            ->middleware('permission:financial.resolve_disputes');

        // 7. تسويات العقود الإغلاقية والإلغاء المبكر والمعاينة
        Route::get('/contracts/pending-settlements', [\App\Http\Controllers\Api\Admin\FinancialLedgerController::class, 'pendingSettlements'])
            ->middleware('permission:financial.manage_settlements');
        Route::post('/contracts/{contractId}/settle-monthly', [\App\Http\Controllers\Api\Admin\FinancialLedgerController::class, 'settleMonthly'])
            ->middleware('permission:financial.manage_settlements');
        Route::get('/contracts/{contractId}/termination-preview', [\App\Http\Controllers\Api\Admin\FinancialLedgerController::class, 'terminationPreview'])
            ->middleware('permission:financial.manage_settlements');
        Route::post('/contracts/{contractId}/terminate-mid-month', [\App\Http\Controllers\Api\Admin\FinancialLedgerController::class, 'terminateMidMonth'])
            ->middleware('permission:financial.manage_settlements');

        // 8. إلغاء الرحلات بمصفوفة الغرامات والمعاينة
        Route::get('/trips/{tripId}/cancel-preview', [\App\Http\Controllers\Api\Admin\FinancialLedgerController::class, 'cancellationPreview'])
            ->middleware('permission:trips.emergency_cancel,financial.manage_settlements');
        Route::post('/trips/{tripId}/cancel-with-matrix', [\App\Http\Controllers\Api\Admin\FinancialLedgerController::class, 'cancelTripWithMatrix'])
            ->middleware('permission:trips.emergency_cancel,financial.manage_settlements');
    });

    // --- 💳 مسارات إدارة طرق الدفع للأدمن ---
    Route::prefix('payment-methods')->middleware('permission:financial.manage_payment_methods')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Admin\PaymentMethodController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\Admin\PaymentMethodController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\Api\Admin\PaymentMethodController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\Admin\PaymentMethodController::class, 'update']);
        Route::patch('/{id}/toggle-status', [\App\Http\Controllers\Api\Admin\PaymentMethodController::class, 'toggleStatus']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\PaymentMethodController::class, 'destroy']);
    });

    // --- 🚖 مسارات إدارة طلبات شحن السائقين للأدمن ---
    Route::prefix('driver-recharges')->middleware('permission:financial.manage_recharges')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Admin\DriverRechargeController::class, 'index']);
        Route::get('/{id}', [\App\Http\Controllers\Api\Admin\DriverRechargeController::class, 'show']);
        Route::post('/{id}/approve', [\App\Http\Controllers\Api\Admin\DriverRechargeController::class, 'approve']);
        Route::post('/{id}/reject', [\App\Http\Controllers\Api\Admin\DriverRechargeController::class, 'reject']);
    });

    // مسارات الفواتير للأدمن
    Route::prefix('invoices')->middleware('permission:financial.view_ledger,financial.view_summary')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Shared\InvoiceController::class, 'index']);
        Route::get('/{id}', [\App\Http\Controllers\Api\Shared\InvoiceController::class, 'show']);
    });

    // =========================================================================
    // 📈 مسارات تقارير لوحة التحكم والإحصائيات التحليلية
    // =========================================================================
    Route::prefix('reports')->group(function () {
        Route::get('/kpi-summary', [\App\Http\Controllers\Api\Admin\ReportController::class, 'kpiSummary'])
            ->middleware('permission:reports.view')
            ->name('api.admin.reports.kpi');
        Route::get('/financial', [\App\Http\Controllers\Api\Admin\ReportController::class, 'financialReport'])
            ->middleware('permission:reports.view,financial.view_summary')
            ->name('api.admin.reports.financial');
        Route::get('/trips', [\App\Http\Controllers\Api\Admin\ReportController::class, 'tripsReport'])
            ->middleware('permission:reports.view')
            ->name('api.admin.reports.trips');
        Route::get('/subscriptions', [\App\Http\Controllers\Api\Admin\ReportController::class, 'subscriptionsReport'])
            ->middleware('permission:reports.view')
            ->name('api.admin.reports.subscriptions');
        Route::get('/drivers-performance', [\App\Http\Controllers\Api\Admin\ReportController::class, 'driversPerformanceReport'])
            ->middleware('permission:reports.view,drivers.view')
            ->name('api.admin.reports.drivers-performance');
        Route::get('/export', [\App\Http\Controllers\Api\Admin\ReportController::class, 'exportReport'])
            ->middleware('permission:reports.export')
            ->name('api.admin.reports.export');
    });

});

// =========================================================================
// 🔓 المسارات العامة الموقعة (تُفتح مباشرة من المتصفح عبر رابط الإيميل السحري)
// =========================================================================
Route::prefix('admin/email')->group(function () {
    Route::get('/approve/{token}', [AdminController::class, 'approveEmailChange'])->name('admin.email.approve');
    Route::get('/reject/{token}', [AdminController::class, 'rejectEmailChange'])->name('admin.email.reject');
});

