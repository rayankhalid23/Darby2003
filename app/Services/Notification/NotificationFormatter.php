<?php

namespace App\Services\Notification;

class NotificationFormatter
{
    /**
     * ثوابت أنواع الإشعارات التابعة للنظام
     */
    // --- الرحلات والتتبع اليومي ---
    public const TYPE_TRIP_STARTED              = 'trip_started';
    public const TYPE_DRIVER_ARRIVED            = 'driver_arrived';
    public const TYPE_CHILD_PICKED_UP           = 'child_picked_up';
    public const TYPE_CHILD_DROPPED_OFF          = 'child_dropped_off';
    public const TYPE_STUDENT_ABSENT            = 'student_absent';
    public const TYPE_CHILD_ABSENT              = 'child_absent';
    public const TYPE_TRIP_COMPLETED            = 'trip_completed';
    public const TYPE_TRIP_CANCELLED            = 'trip_cancelled';
    public const TYPE_TRIP_READY                = 'trip_ready';
    public const TYPE_TRIP_UPCOMING             = 'trip_upcoming';
    public const TYPE_TRIP_SUSPENDED            = 'trip_suspended';
    public const TYPE_CHILD_SKIPPED             = 'child_skipped';
    public const TYPE_CHILD_SKIP                = 'child_skip';
    public const TYPE_CHILD_DROPOFF_FAILED      = 'child_dropoff_failed';
    public const TYPE_CHILD_DIRECT_PARENT_HANDLING = 'child_direct_parent_handling';
    public const TYPE_MANUAL_PICKUP_CONFIRMED   = 'manual_pickup_confirmed';
    public const TYPE_DRIVER_ABSENCE            = 'driver_absence';

    // --- طوارئ وتوقف المركبة واستبدال السائقين ---
    public const TYPE_EMERGENCY_SUBSTITUTE_REQUEST           = 'emergency_substitute_request';
    public const TYPE_EMERGENCY_SUBSTITUTE_ACCEPTED_PARENT   = 'emergency_substitute_accepted_parent';
    public const TYPE_EMERGENCY_SUBSTITUTE_ACCEPTED_ORIGINAL = 'emergency_substitute_accepted_original';
    public const TYPE_EMERGENCY_BREAKDOWN_PARENT_PICKUP      = 'emergency_breakdown_parent_pickup';
    public const TYPE_EMERGENCY_DRIVER_CALL_PARENTS         = 'emergency_driver_call_parents';
    public const TYPE_EMERGENCY_REQUEST_CANCELLED_OTHER      = 'emergency_request_cancelled_other';

    // --- العقود والاشتراكات ---
    public const TYPE_CONTRACT_CREATED          = 'contract_created';
    public const TYPE_CONTRACT_SIGNED           = 'contract_signed';
    public const TYPE_CONTRACT_APPROVED         = 'contract_approved';
    public const TYPE_CONTRACT_REJECTED         = 'contract_rejected';
    public const TYPE_SUBSCRIPTION_SUBMITTED    = 'subscription_submitted';
    public const TYPE_SUBSCRIPTION_APPROVED     = 'subscription_approved';
    public const TYPE_SUBSCRIPTION_REJECTED     = 'subscription_rejected';
    public const TYPE_SUBSCRIPTION_PAYMENT_REQ  = 'subscription_payment_required';
    public const TYPE_NEW_SUBSCRIPTION_REQUEST  = 'new_subscription_request';
    public const TYPE_REQUEST_ACCEPTED          = 'request_accepted';
    public const TYPE_REQUEST_REJECTED          = 'request_rejected';
    public const TYPE_CANCELLATION_PARENT       = 'cancellation_parent';
    public const TYPE_CANCELLATION_DRIVER       = 'cancellation_driver';
    public const TYPE_CANCELLATION_AUTO_PARENT  = 'cancellation_auto_parent';
    public const TYPE_CANCELLATION_AUTO_DRIVER  = 'cancellation_auto_driver';
    public const TYPE_SUBSCRIPTION_RENEWED      = 'subscription_renewed';

    // --- تغيير موقع الاستلام/التسليم ---
    public const TYPE_LOCATION_CHANGE_REQUESTED = 'location_change_requested';
    public const TYPE_LOCATION_CHANGE_APPROVED  = 'location_change_approved';
    public const TYPE_LOCATION_CHANGE_REJECTED  = 'location_change_rejected';

    // --- التأكيد اليدوي لرحلة سابقة لم تُوثَّق ---
    public const TYPE_TRIP_MANUAL_CONFIRMATION_REQUEST   = 'trip_manual_confirmation_request';
    public const TYPE_TRIP_MANUAL_CONFIRMATION_CONFIRMED = 'trip_manual_confirmation_confirmed';
    public const TYPE_TRIP_MANUAL_CONFIRMATION_DENIED    = 'trip_manual_confirmation_denied';

    // --- العمليات المالية والمحفظة والفواتير (بالدينار الليبي د.ل) ---
    public const TYPE_RECHARGE_APPROVED         = 'recharge_approved';
    public const TYPE_RECHARGE_REJECTED         = 'recharge_rejected';
    public const TYPE_RECHARGE_COMPLETED        = 'recharge_completed';
    public const TYPE_WITHDRAWAL_APPROVED       = 'withdrawal_approved';
    public const TYPE_WITHDRAWAL_REJECTED       = 'withdrawal_rejected';
    public const TYPE_INVOICE_GENERATED         = 'invoice_generated';
    public const TYPE_SETTLEMENT_PAID           = 'settlement_paid';
    public const TYPE_SETTLEMENT_RECEIVED       = 'settlement_received';
    public const TYPE_SETTLEMENT_OVERDUE        = 'settlement_overdue';
    public const TYPE_SETTLEMENT_WARNING        = 'settlement_warning';
    public const TYPE_DISPUTE_OPENED            = 'dispute_opened';
    public const TYPE_DISPUTE_RESOLVED          = 'dispute_resolved';

    // --- حسابات السائقين، المركبات، والوثائق ---
    public const TYPE_DRIVER_ACCOUNT_APPROVED   = 'driver_account_approved';
    public const TYPE_DRIVER_ACCOUNT_REJECTED   = 'driver_account_rejected';
    public const TYPE_NEW_DRIVER_REGISTERED     = 'new_driver_registered';
    public const TYPE_DRIVER_DOCUMENTS_UPDATED  = 'driver_documents_updated';
    public const TYPE_DRIVER_VEHICLE_UPDATED    = 'driver_vehicle_updated';
    public const TYPE_DRIVER_DOC_EXPIRING_SOON  = 'driver_document_expiring_soon';
    public const TYPE_DRIVER_DOC_EXPIRED        = 'driver_document_expired';
    public const TYPE_DRIVER_DOC_EXPIRED_ADMIN  = 'driver_document_expired_admin_alert';

    // --- الشكاوى والدعم الفني ---
    public const TYPE_NEW_COMPLAINT_SUBMITTED          = 'new_complaint_submitted';
    public const TYPE_COMPLAINT_RESOLVED               = 'complaint_resolved';
    public const TYPE_DRIVER_SUSPENDED                 = 'driver_suspended';
    // ملاحظة: اسم النوع 'driver_ai_alert' مُبقى كما هو حفاظاً على توافق تطبيقات الواجهة
    // التي تتعامل معه، لكنه الآن تنبيه إداري بحت يرسله الأدمن عند البت في شكوى.
    public const TYPE_DRIVER_AI_ALERT                  = 'driver_ai_alert';
    public const TYPE_GENERAL_ANNOUNCEMENT             = 'general_announcement';
    public const TYPE_SUPPORT_TICKET_CREATED           = 'support_ticket_created';
    public const TYPE_SUPPORT_TICKET_REPLY             = 'support_ticket_reply';
    public const TYPE_SUPPORT_TICKET_STATUS_CHANGED    = 'support_ticket_status_changed';
    public const TYPE_SUPPORT_TICKET_RESOLVED          = 'support_ticket_resolved';
    public const TYPE_SUPPORT_TICKET_CLOSED            = 'support_ticket_closed';

    /**
     * صياغة كائن الإشعار الموحد مع نصوص واضحة ودقيقة في كل الحالات
     */
    public static function format(string $type, array $data = []): array
    {
        $customTitle = !empty($data['title']) ? (string) $data['title'] : null;
        $customMessage = !empty($data['message']) ? (string) $data['message'] : null;

        $actionUrl = $data['action_url'] ?? null;
        $entityId = $data['entity_id'] ?? null;
        $entityType = $data['entity_type'] ?? null;
        $screen = $data['screen'] ?? 'HOME';
        $action = $data['action'] ?? 'open';
        $extraPayload = $data['payload'] ?? [];

        // قيم افتراضية للمتغيرات الشائعة
        $tripId = $data['trip_id'] ?? $entityId ?? '';
        $childName = $data['child_name'] ?? 'الطالب';
        $driverName = $data['driver_name'] ?? 'السائق';
        $parentName = $data['parent_name'] ?? 'ولي الأمر';
        $reqId = $data['request_id'] ?? $entityId ?? '';
        $contractNo = $data['contract_number'] ?? $data['contract_id'] ?? $entityId ?? '';
        $invNo = $data['invoice_number'] ?? $data['invoice_id'] ?? $entityId ?? '';
        $amount = isset($data['amount']) ? (is_numeric($data['amount']) ? number_format((float) $data['amount'], 2) : $data['amount']) : '';

        $title = $customTitle ?? 'تنبيه جديد 🔔';
        $message = $customMessage ?? 'لديك تنبيه جديد في حسابك، يرجى مراجعة التطبيق.';

        switch ($type) {
            // =========================================================
            // 🚌 1. الرحلات والتتبع المباشر
            // =========================================================
            case self::TYPE_TRIP_STARTED:
                $title = $customTitle ?? 'بدء الرحلة 🚌';
                $message = $customMessage ?? ($tripId ? "انطلقت الرحلة رقم #{$tripId}، السائق في الطريق الآن." : "انطلقت الرحلة المدرسية، السائق في الطريق الآن.");
                $screen = 'TRIP_DETAILS';
                $entityType = 'trip';
                $entityId = $tripId;
                $action = 'open_trip';
                break;

            case self::TYPE_DRIVER_ARRIVED:
                $title = $customTitle ?? 'وصول السائق 📍';
                $message = $customMessage ?? 'وصل السائق إلى نقطة الانطلاق لتسلم الطلاب.';
                $screen = 'TRIP_TRACKING';
                $entityType = 'trip';
                $entityId = $tripId;
                $action = 'open_trip';
                break;

            case self::TYPE_CHILD_PICKED_UP:
                $title = $customTitle ?? 'ركوب الطالب 🎒';
                $message = $customMessage ?? "تم صعود الطالب ({$childName}) إلى الحافلة بنجاح.";
                $screen = 'TRIP_LIVE';
                $entityType = 'trip';
                $entityId = $tripId;
                $action = 'open_trip';
                break;

            case self::TYPE_CHILD_DROPPED_OFF:
                $title = $customTitle ?? 'وصول الطالب 🏡';
                $message = $customMessage ?? "تم نزول الطالب ({$childName}) ووصوله بسلام إلى وجهته.";
                $screen = 'TRIP_LIVE';
                $entityType = 'trip';
                $entityId = $tripId;
                $action = 'open_trip';
                break;

            case self::TYPE_STUDENT_ABSENT:
            case self::TYPE_CHILD_ABSENT:
                $title = $customTitle ?? 'تسجيل غياب ⚠️';
                $message = $customMessage ?? "تم تسجيل غياب الطالب ({$childName}) عن الرحلة الحالية.";
                $screen = 'ATTENDANCE_LOG';
                $entityType = 'trip';
                $entityId = $tripId;
                $action = 'open_trip';
                break;

            case self::TYPE_CHILD_SKIPPED:
            case self::TYPE_CHILD_SKIP:
                $title = $customTitle ?? 'تجاوز المحطة ⚠️';
                $message = $customMessage ?? "انتهى وقت الانتظار دون استجابة، تحركت الحافلة وتجاوزت محطة ({$childName}).";
                $screen = 'TRIP_DETAILS';
                $entityType = 'trip';
                $entityId = $tripId;
                $action = 'open_trip';
                break;

            case self::TYPE_CHILD_DROPOFF_FAILED:
                $title = $customTitle ?? '🚨 تعذر تسليم الطالب';
                $message = $customMessage ?? "تعذر على السائق تسليم ({$childName}) في محطة النزول، يرجى التواصل الفوري مع السائق أو الإدارة.";
                $screen = 'TRIP_DETAILS';
                $entityType = 'trip';
                $entityId = $tripId;
                $action = 'open_trip';
                break;

            case self::TYPE_CHILD_DIRECT_PARENT_HANDLING:
                $title = $customTitle ?? 'ℹ️ استلام مباشر من ولي الأمر';
                $message = $customMessage ?? "تم تسليم ({$childName}) مباشرة لولي الأمر خارج الإجراء المعتاد.";
                $screen = 'TRIP_DETAILS';
                $entityType = 'trip';
                $entityId = $tripId;
                $action = 'open_trip';
                break;

            case self::TYPE_MANUAL_PICKUP_CONFIRMED:
                $title = $customTitle ?? 'تأكيد استلام يدوي 🖐️';
                $message = $customMessage ?? "قام ولي الأمر بتأكيد استلام الطالب ({$childName}) يدوياً.";
                $screen = 'TRIP_LIVE';
                $entityType = 'trip';
                $entityId = $tripId;
                $action = 'open_trip';
                break;

            case self::TYPE_TRIP_COMPLETED:
                $title = $customTitle ?? 'اكتملت الرحلة ✅';
                $message = $customMessage ?? ($tripId ? "تمت الرحلة رقم #{$tripId} بنجاح ووصل جميع الطلاب بسلام." : "تمت الرحلة المدرسية بنجاح ووصل جميع الطلاب بسلام.");
                $screen = 'TRIP_SUMMARY';
                $entityType = 'trip';
                $entityId = $tripId;
                $action = 'open_trip';
                break;

            case self::TYPE_TRIP_CANCELLED:
                $title = $customTitle ?? 'إلغاء الرحلة ❌';
                $message = $customMessage ?? ($tripId ? "تم إلغاء الرحلة رقم #{$tripId}." : "تم إلغاء الرحلة المدرسية المقررة.");
                $screen = 'TRIP_DETAILS';
                $entityType = 'trip';
                $entityId = $tripId;
                $action = 'open_trip';
                break;

            case self::TYPE_TRIP_READY:
                $title = $customTitle ?? 'الرحلة جاهزة 🚌';
                $message = $customMessage ?? ($tripId ? "تم تجهيز جدول رحلتك رقم #{$tripId} لليوم." : "تم تجهيز خط سير رحلتك لليوم.");
                $screen = 'TRIP_DETAILS';
                $entityType = 'trip';
                $entityId = $tripId;
                $action = 'open_trip';
                break;

            case self::TYPE_TRIP_UPCOMING:
                $title = $customTitle ?? 'رحلة اليوم قادمة 🕒';
                $message = $customMessage ?? "رحلة اليوم على وشك الانطلاق، يرجى تجهيز الأبناء في نقطة الانطلاق.";
                $screen = 'TRIP_DETAILS';
                $entityType = 'trip';
                $entityId = $tripId;
                $action = 'open_trip';
                break;

            case self::TYPE_TRIP_SUSPENDED:
                $title = $customTitle ?? 'تعليق الرحلة ⚠️';
                $message = $customMessage ?? ($tripId ? "تم تعليق الرحلة رقم #{$tripId} مؤقتاً، سيتم إعلامك بالمستجدات." : "تم تعليق الرحلة مؤقتاً، سيتم إعلامك بالمستجدات.");
                $screen = 'TRIP_DETAILS';
                $entityType = 'trip';
                $entityId = $tripId;
                $action = 'open_trip';
                break;

            case self::TYPE_DRIVER_ABSENCE:
                $title = $customTitle ?? 'غياب السائق ⚠️';
                $message = $customMessage ?? 'السائق المسؤول عن رحلتك غائب اليوم، سيتم إعلامك بالبديل إن وجد.';
                $screen = 'TRIP_DETAILS';
                $entityType = 'driver_absence';
                $action = 'open';
                break;

            // =========================================================
            // 🚨 حالات الطوارئ وتعطل الحافلة واستبدال السائقين
            // =========================================================
            case self::TYPE_EMERGENCY_SUBSTITUTE_REQUEST:
                $title = $customTitle ?? '🚨 طلب طارئ: نقل أطفال من حافلة متوقفة';
                $message = $customMessage ?? 'يوجد حافلة متوقفة بالقرب منك وبها أطفال بحاجة لنقل فوري. هل تود قبول المهمة؟';
                $screen = 'EMERGENCY_DISPATCH';
                $entityType = 'trip_breakdown_dispatch';
                $entityId = $data['dispatch_id'] ?? $entityId ?? '';
                $action = 'open_emergency_dispatch';
                break;

            case self::TYPE_EMERGENCY_SUBSTITUTE_ACCEPTED_PARENT:
                $title = $customTitle ?? '🔄 تم تعيين سائق بديل للرحلة';
                $subDriverName = $data['substitute_driver_name'] ?? $driverName;
                $message = $customMessage ?? "توقفت الحافلة بسبب عطل طارئ، وتم تكليف السائق البديل ({$subDriverName}) لاستكمال نقل الأبناء بسلام.";
                $screen = 'TRIP_LIVE';
                $entityType = 'trip';
                $entityId = $tripId;
                $action = 'open_trip';
                break;

            case self::TYPE_EMERGENCY_SUBSTITUTE_ACCEPTED_ORIGINAL:
                $title = $customTitle ?? '✅ تم قبول مهمة الإنقاذ';
                $subDriverName = $data['substitute_driver_name'] ?? $driverName;
                $message = $customMessage ?? "وافق السائق البديل ({$subDriverName}) على استلام ونقل الطلاب العالقين.";
                $screen = 'TRIP_DETAILS';
                $entityType = 'trip';
                $entityId = $tripId;
                $action = 'open_trip';
                break;

            case self::TYPE_EMERGENCY_BREAKDOWN_PARENT_PICKUP:
                $title = $customTitle ?? '⚠️ توقف الرحلة: يرجى استلام طفلك';
                $locationText = $data['location_text'] ?? 'الموقع الحالي للأبناء';
                $message = $customMessage ?? "تعطلت الحافلة وتعذر توفير سائق بديل حالياً. الأبناء في أمان، يرجى التوجه لموقعهم لاستلامهم: ({$locationText}).";
                $screen = 'TRIP_LIVE';
                $entityType = 'trip';
                $entityId = $tripId;
                $action = 'open_trip';
                break;

            case self::TYPE_EMERGENCY_DRIVER_CALL_PARENTS:
                $title = $customTitle ?? '📞 تعذر توفير بديل - تواصل مع أولياء الأمور';
                $message = $customMessage ?? 'تعذر العثور على سائق بديل متاح حالياً. يرجى التواصل مباشرة مع أولياء الأمور وإرشادهم لموقع الحافلة.';
                $screen = 'TRIP_DETAILS';
                $entityType = 'trip';
                $entityId = $tripId;
                $action = 'open_trip';
                break;

            case self::TYPE_EMERGENCY_REQUEST_CANCELLED_OTHER:
                $title = $customTitle ?? 'ℹ️ تم قبول المهمة الطارئة من سائق آخر';
                $message = $customMessage ?? 'شكراً لك، تم قبول مهمة نقل الأطفال العالقين من قِبل سائق آخر أسرع استجابة.';
                $screen = 'TRIP_DETAILS';
                $entityType = 'trip_breakdown_dispatch';
                $entityId = $data['dispatch_id'] ?? $entityId ?? '';
                $action = 'open';
                break;

            // =========================================================
            // 📝 2. العقود والاشتراكات
            // =========================================================
            case self::TYPE_NEW_SUBSCRIPTION_REQUEST:
                $title = $customTitle ?? 'طلب اشتراك جديد 📩';
                $message = $customMessage ?? ($reqId ? "لديك طلب اشتراك جديد رقم #{$reqId} بانتظار المراجعة." : "لديك طلب اشتراك مدرسي جديد بانتظار المراجعة.");
                $screen = 'SUBSCRIPTION_DETAILS';
                $entityType = 'subscription_request';
                $entityId = $reqId;
                $action = 'open_subscription_request';
                break;

            case self::TYPE_REQUEST_ACCEPTED:
            case self::TYPE_SUBSCRIPTION_APPROVED:
                $title = $customTitle ?? 'تم قبول طلب الاشتراك 🟢';
                $message = $customMessage ?? ($reqId ? "تمت الموافقة على طلب الاشتراك رقم #{$reqId}." : "تمت الموافقة على طلب الاشتراك المدرسي.");
                $screen = 'SUBSCRIPTION_DETAILS';
                $entityType = 'subscription_request';
                $entityId = $reqId;
                $action = 'open_subscription_request';
                break;

            case self::TYPE_REQUEST_REJECTED:
            case self::TYPE_SUBSCRIPTION_REJECTED:
                $title = $customTitle ?? 'تم رفض طلب الاشتراك 🔴';
                $message = $customMessage ?? ($reqId ? "عذراً، تم رفض طلب الاشتراك رقم #{$reqId}." : "عذراً، تعذر قبول طلب الاشتراك المدرسي.");
                $screen = 'SUBSCRIPTION_DETAILS';
                $entityType = 'subscription_request';
                $entityId = $reqId;
                $action = 'open_subscription_request';
                break;

            case self::TYPE_SUBSCRIPTION_PAYMENT_REQ:
                $title = $customTitle ?? 'مطلوب سداد الاشتراك 💳';
                $message = $customMessage ?? ($amount ? "تمت الترقية واعتماد الطلب، يرجى سداد المبلغ ({$amount} د.ل) لتفعيل الاشتراك والبدء بالرحلات." : "يرجى سداد قيمة الاشتراك لتفعيل العقد والبدء بالرحلات.");
                $screen = 'PAYMENT_SCREEN';
                $entityType = 'subscription_request';
                $entityId = $reqId;
                $action = 'open_subscription_request';
                break;

            case self::TYPE_CONTRACT_CREATED:
                $title = $customTitle ?? 'عقد جديد 📝';
                $message = $customMessage ?? ($contractNo ? "تم إنشاء العقد رقم #{$contractNo} وبانتظار التوقيع." : "تم إنشاء عقد النقل المدرسي وبانتظار التوقيع.");
                $screen = 'CONTRACT_DETAILS';
                $entityType = 'contract';
                $entityId = $contractNo;
                $action = 'open_contract';
                break;

            case self::TYPE_CONTRACT_SIGNED:
                $title = $customTitle ?? 'توقيع العقد ✍️';
                $message = $customMessage ?? ($contractNo ? "تم توقيع العقد رقم #{$contractNo} بنجاح." : "تم توقيع عقد النقل المدرسي بنجاح.");
                $screen = 'CONTRACT_DETAILS';
                $entityType = 'contract';
                $entityId = $contractNo;
                $action = 'open_contract';
                break;

            case self::TYPE_CONTRACT_APPROVED:
                $title = $customTitle ?? 'اعتماد العقد 🎉';
                $message = $customMessage ?? ($contractNo ? "تمت الموافقة واعتماد العقد رقم #{$contractNo}." : "تمت الموافقة واعتماد عقد النقل المدرسي.");
                $screen = 'CONTRACT_DETAILS';
                $entityType = 'contract';
                $entityId = $contractNo;
                $action = 'open_contract';
                break;

            case self::TYPE_CONTRACT_REJECTED:
                $title = $customTitle ?? 'رفض العقد ⚠️';
                $message = $customMessage ?? ($contractNo ? "تعذر قبول أو اعتماد العقد رقم #{$contractNo}." : "تعذر قبول أو اعتماد عقد النقل المدرسي.");
                $screen = 'CONTRACT_DETAILS';
                $entityType = 'contract';
                $entityId = $contractNo;
                $action = 'open_contract';
                break;

            case self::TYPE_CANCELLATION_PARENT:
            case self::TYPE_CANCELLATION_AUTO_PARENT:
                $title = $customTitle ?? 'إلغاء طلب الاشتراك ❌';
                $message = $customMessage ?? ($reqId ? "تم إلغاء طلب الاشتراك رقم #{$reqId}." : "تم إلغاء طلب الاشتراك المدرسي.");
                $screen = 'SUBSCRIPTION_DETAILS';
                $entityType = 'subscription_request';
                $entityId = $reqId;
                $action = 'open_subscription_request';
                break;

            case self::TYPE_CANCELLATION_DRIVER:
            case self::TYPE_CANCELLATION_AUTO_DRIVER:
                $title = $customTitle ?? 'إلغاء طلب اشتراك ⚠️';
                $message = $customMessage ?? ($reqId ? "تم إلغاء طلب الاشتراك رقم #{$reqId} من قبل ولي الأمر [{$parentName}]." : "تم إلغاء طلب الاشتراك من قبل ولي الأمر.");
                $screen = 'SUBSCRIPTION_DETAILS';
                $entityType = 'subscription_request';
                $entityId = $reqId;
                $action = 'open_subscription_request';
                break;

            case self::TYPE_SUBSCRIPTION_RENEWED:
                $title = $customTitle ?? 'تجديد الاشتراك بنجاح 🔄';
                $message = $customMessage ?? 'تم تجديد اشتراكك المدرسي بنجاح للشهر الجديد.';
                $screen = 'SUBSCRIPTION_DETAILS';
                $entityType = 'subscription';
                $action = 'open_subscription_request';
                break;

            // =========================================================
            // 📍 3. تغيير موقع الاستلام/التسليم
            // =========================================================
            case self::TYPE_LOCATION_CHANGE_REQUESTED:
                $title = $customTitle ?? 'طلب تغيير موقع 📍';
                $message = $customMessage ?? "طلب ولي أمر الطفل ({$childName}) تغيير موقع الاستلام/التسليم، بانتظار موافقتك.";
                $screen = 'LOCATION_CHANGE_REQUEST_DETAILS';
                $entityType = 'location_change_request';
                $action = 'open_location_change_request';
                break;

            case self::TYPE_LOCATION_CHANGE_APPROVED:
                $title = $customTitle ?? 'تمت الموافقة على تغيير الموقع 🟢';
                $message = $customMessage ?? "وافق السائق على طلب تغيير موقع ({$childName})، وتم تحديث مسار الرحلة.";
                $screen = 'LOCATION_CHANGE_REQUEST_DETAILS';
                $entityType = 'location_change_request';
                $action = 'open_location_change_request';
                break;

            case self::TYPE_LOCATION_CHANGE_REJECTED:
                $title = $customTitle ?? 'تم رفض تغيير الموقع 🔴';
                $message = $customMessage ?? "عذراً، رفض السائق طلب تغيير موقع الطفل ({$childName}).";
                $screen = 'LOCATION_CHANGE_REQUEST_DETAILS';
                $entityType = 'location_change_request';
                $action = 'open_location_change_request';
                break;

            // =========================================================
            // 🖐️ 4. التأكيد اليدوي لرحلة سابقة لم تُوثَّق
            // =========================================================
            case self::TYPE_TRIP_MANUAL_CONFIRMATION_REQUEST:
                $title = $customTitle ?? 'يرجى التأكيد 🙏';
                $message = $customMessage ?? "يطلب السائق تأكيدك بشأن رحلة سابقة للطفل ({$childName}).";
                $screen = 'TRIP_MANUAL_CONFIRMATION_DETAILS';
                $entityType = 'trip_manual_confirmation';
                $action = 'open_trip_manual_confirmation';
                break;

            case self::TYPE_TRIP_MANUAL_CONFIRMATION_CONFIRMED:
                $title = $customTitle ?? 'تم تأكيد ولي الأمر ✅';
                $message = $customMessage ?? "أكّد ولي أمر الطفل ({$childName}) إتمام الرحلة، وتم تحديث حالتها.";
                $screen = 'TRIP_MANUAL_CONFIRMATION_DETAILS';
                $entityType = 'trip_manual_confirmation';
                $action = 'open_trip_manual_confirmation';
                break;

            case self::TYPE_TRIP_MANUAL_CONFIRMATION_DENIED:
                $title = $customTitle ?? 'لم يتم التأكيد ⚠️';
                $message = $customMessage ?? "لم يؤكد ولي أمر الطفل ({$childName}) إتمام الرحلة السابقة.";
                $screen = 'TRIP_MANUAL_CONFIRMATION_DETAILS';
                $entityType = 'trip_manual_confirmation';
                $action = 'open_trip_manual_confirmation';
                break;

            // =========================================================
            // 💰 5. العمليات المالية والمحفظة (دينار ليبي د.ل)
            // =========================================================
            case self::TYPE_RECHARGE_APPROVED:
                $title = $customTitle ?? 'تم شحن المحفظة 💰';
                $message = $customMessage ?? ($amount ? "تمت الموافقة على طلب الشحن بمبلغ ({$amount} د.ل) وإيداعه في محفظتك بنجاح." : "تمت الموافقة على طلب شحن محفظتك بنجاح.");
                $screen = 'WALLET';
                $entityType = 'wallet';
                $action = 'open_wallet';
                break;

            case self::TYPE_RECHARGE_REJECTED:
                $title = $customTitle ?? 'رفض طلب الشحن ⚠️';
                $message = $customMessage ?? ($amount ? "تم رفض طلب شحن المحفظة بمبلغ ({$amount} د.ل)، يرجى مراجعة التفاصيل." : "تم رفض طلب شحن المحفظة، يرجى مراجعة التفاصيل.");
                $screen = 'WALLET';
                $entityType = 'wallet';
                $action = 'open_wallet';
                break;

            case self::TYPE_RECHARGE_COMPLETED:
                $title = $customTitle ?? '💳 تم شحن المحفظة بنجاح';
                $message = $customMessage ?? ($amount ? "تم شحن محفظتك بمبلغ ({$amount} د.ل) بنجاح." : "تم شحن محفظتك بنجاح.");
                $screen = 'WALLET';
                $entityType = 'wallet';
                $action = 'open_wallet';
                break;

            case self::TYPE_WITHDRAWAL_APPROVED:
                $title = $customTitle ?? 'سحب الرصيد 💵';
                $message = $customMessage ?? ($amount ? "تمت الموافقة على سحب مبلغ ({$amount} د.ل) وتحويله إلى حسابك البنكي." : "تمت الموافقة على طلب سحب الرصيد بنجاح.");
                $screen = 'WALLET';
                $entityType = 'wallet';
                $action = 'open_wallet';
                break;

            case self::TYPE_WITHDRAWAL_REJECTED:
                $title = $customTitle ?? 'رفض سحب الرصيد ⚠️';
                $message = $customMessage ?? ($amount ? "تم رفض طلب سحب مبلغ ({$amount} د.ل)، يرجى التواصل مع الدعم." : "تم رفض طلب سحب الرصيد، يرجى التواصل مع الدعم.");
                $screen = 'WALLET';
                $entityType = 'wallet';
                $action = 'open_wallet';
                break;

            case self::TYPE_INVOICE_GENERATED:
                $title = $customTitle ?? 'فاتورة جديدة 📄';
                $message = $customMessage ?? ($invNo ? "تم إصدار فاتورة جديدة برقم #{$invNo}" . ($amount ? " بمبلغ ({$amount} د.ل)." : ".") : "تم إصدار فاتورة جديدة لحسابك.");
                $screen = 'INVOICE_DETAILS';
                $entityType = 'invoice';
                $entityId = $invNo;
                $action = 'open_invoice';
                break;

            case self::TYPE_SETTLEMENT_PAID:
                $title = $customTitle ?? 'تمت التسوية المالية 💰';
                $message = $customMessage ?? ($amount ? "تم دفع مبلغ التسوية الشهرية ({$amount} د.ل) بنجاح." : "تم دفع مبلغ التسوية المالية بنجاح.");
                $screen = 'INVOICE_DETAILS';
                $entityType = 'invoice';
                $action = 'open_invoice';
                break;

            case self::TYPE_SETTLEMENT_RECEIVED:
                $title = $customTitle ?? 'استلام دفعة تسوية 💵';
                $message = $customMessage ?? ($amount ? "تم استلام مبلغ التسوية الشهرية ({$amount} د.ل) في محفظتك." : "تم إيداع مبلغ التسوية المالية في محفظتك.");
                $screen = 'WALLET';
                $entityType = 'invoice';
                $action = 'open_wallet';
                break;

            case self::TYPE_SETTLEMENT_OVERDUE:
                $title = $customTitle ?? 'تسوية متأخرة ⚠️';
                $message = $customMessage ?? ($amount ? "تسوية بمبلغ ({$amount} د.ل) متأخرة عن السداد، يرجى المبادرة بالدفع." : "لديك تسوية مالية متأخرة عن السداد، يرجى المبادرة بالدفع.");
                $screen = 'INVOICE_DETAILS';
                $entityType = 'invoice';
                $action = 'open_invoice';
                break;

            case self::TYPE_SETTLEMENT_WARNING:
                $title = $customTitle ?? 'تذكير بالتسوية القادمة ⏰';
                $message = $customMessage ?? ($amount ? "تذكير: تسوية بمبلغ ({$amount} د.ل) مستحقة قريباً." : "تذكير: لديك تسوية مالية مستحقة السداد قريباً.");
                $screen = 'WALLET';
                $entityType = 'invoice';
                $action = 'open_wallet';
                break;

            case self::TYPE_DISPUTE_OPENED:
                $title = $customTitle ?? 'نزاع مالي جديد ⚖️';
                $message = $customMessage ?? ($tripId ? "تم فتح اعتراض مالي على الرحلة رقم #{$tripId} وبانتظار قرار الإدارة." : "تم فتح اعتراض مالي وبانتظار مراجعة الإدارة.");
                $screen = 'DISPUTE_DETAILS';
                $entityType = 'dispute';
                $action = 'open_dispute';
                break;

            case self::TYPE_DISPUTE_RESOLVED:
                $title = $customTitle ?? 'حل النزاع المالي ✅';
                $message = $customMessage ?? 'تمت مراجعة النزاع المالي والبت في التسوية النهائية بنجاح.';
                $screen = 'DISPUTE_DETAILS';
                $entityType = 'dispute';
                $action = 'open_dispute';
                break;

            // =========================================================
            // 🚖 6. حسابات السائقين والوثائق والمركبات
            // =========================================================
            case self::TYPE_DRIVER_ACCOUNT_APPROVED:
                $title = $customTitle ?? 'اعتماد حساب السائق 🥳';
                $message = $customMessage ?? 'تهانينا! تمت الموافقة على حسابك ويمكنك الآن البدء بتلقي طلبات الاشتراكات والرحلات.';
                $screen = 'DRIVER_HOME';
                $entityType = 'driver_profile_change';
                $action = 'open';
                break;

            case self::TYPE_DRIVER_ACCOUNT_REJECTED:
                $title = $customTitle ?? 'تحديث بيانات الحساب ⚠️';
                $message = $customMessage ?? 'نأمل مراجعة وتحديث بيانات التسجيل والمستندات المرفقة لاستكمال اعتماد الحساب.';
                $screen = 'DRIVER_PROFILE';
                $entityType = 'driver_profile_change';
                $action = 'open';
                break;

            case self::TYPE_NEW_DRIVER_REGISTERED:
                $title = $customTitle ?? 'تسجيل سائق جديد 🚐';
                $message = $customMessage ?? ($driverName !== 'السائق' ? "قام السائق ({$driverName}) بالتسجيل وبانتظار المراجعة." : "قام سائق جديد بالتسجيل وبانتظار المراجعة.");
                $screen = 'ADMIN_DRIVER_REVIEW';
                $entityType = 'driver';
                $action = 'open_driver_review';
                break;

            case self::TYPE_DRIVER_DOCUMENTS_UPDATED:
                $title = $customTitle ?? 'تحديث وثائق رسمية للسائق 📄';
                $message = $customMessage ?? ($driverName !== 'السائق' ? "قام السائق ({$driverName}) بتحديث وثائقه الرسمية وبانتظار المراجعة." : "قام سائق بتحديث وثائقه الرسمية وبانتظار مراجعة الإدارة.");
                $screen = 'ADMIN_DRIVER_CHANGE';
                $entityType = 'driver_document';
                $action = 'open_driver_review';
                break;

            case self::TYPE_DRIVER_VEHICLE_UPDATED:
                $title = $customTitle ?? 'طلب تعديل بيانات مركبة 🚗';
                $message = $customMessage ?? ($driverName !== 'السائق' ? "قام السائق ({$driverName}) بطلب تعديل بيانات مركبته وبانتظار مراجعة الإدارة." : "قام سائق بطلب تعديل بيانات مركبته وبانتظار المراجعة.");
                $screen = 'ADMIN_DRIVER_CHANGE';
                $entityType = 'driver_vehicle';
                $action = 'open_driver_review';
                break;

            case self::TYPE_DRIVER_DOC_EXPIRING_SOON:
                $title = $customTitle ?? '⏰ اقتراب انتهاء صلاحية الوثيقة';
                $message = $customMessage ?? 'تنبيه: إحدى وثائقك الرسمية قاربت على الانتهاء، يرجى تجديدها ورفعها من خلال التطبيق.';
                $screen = 'DRIVER_PROFILE';
                $entityType = 'driver_document';
                $action = 'open';
                break;

            case self::TYPE_DRIVER_DOC_EXPIRED:
                $title = $customTitle ?? '🚫 انتهت صلاحية وثيقة رسمية';
                $message = $customMessage ?? 'انتهت صلاحية إحدى وثائقك الرسمية، تم إيقاف قبول اشتراكات جديدة مؤقتاً حتى تحديث الوثيقة.';
                $screen = 'DRIVER_PROFILE';
                $entityType = 'driver_document';
                $action = 'open';
                break;

            case self::TYPE_DRIVER_DOC_EXPIRED_ADMIN:
                $title = $customTitle ?? '⚠️ وثيقة سائق منتهية الصلاحية';
                $message = $customMessage ?? 'انتهت صلاحية وثيقة رسمية لأحد السائقين، يرجى المراجعة والتدقيق.';
                $screen = 'ADMIN_DRIVER_DETAILS';
                $entityType = 'driver_document';
                $action = 'open_driver_review';
                break;

            // =========================================================
            // 📩 7. الشكاوى والقرارات الإدارية
            // =========================================================
            case self::TYPE_NEW_COMPLAINT_SUBMITTED:
                $title = $customTitle ?? 'شكوى جديدة 📩';
                $message = $customMessage ?? 'تم تقديم شكوى جديدة في النظام وتحتاج إلى مراجعة وتدقيق.';
                $screen = 'ADMIN_COMPLAINT_DETAILS';
                $entityType = 'complaint';
                $action = 'open_complaint';
                break;

            case self::TYPE_COMPLAINT_RESOLVED:
                $title = $customTitle ?? 'تمت معالجة الشكوى ✅';
                $message = $customMessage ?? 'تمت مراجعة الشكوى والبت فيها من قبل إدارة العمليات.';
                $screen = 'COMPLAINT_DETAILS';
                $entityType = 'complaint';
                $action = 'open_complaint';
                break;

            case self::TYPE_DRIVER_SUSPENDED:
                $title = $customTitle ?? 'تم إيقاف حسابك مؤقتاً ⛔';
                $message = $customMessage ?? 'تم إيقاف حسابك بناءً على مراجعة شكوى وردت بحقك، يرجى التواصل مع الدعم.';
                $screen = 'DRIVER_PROFILE';
                $entityType = 'complaint';
                $action = 'open';
                break;

            case self::TYPE_DRIVER_AI_ALERT:
                $title = $customTitle ?? 'تنبيه إداري رسمي ⚠️';
                $message = $customMessage ?? 'تم تسجيل تنبيه إداري بحقك بعد مراجعة شكوى، يرجى الالتزام بمعايير جودة وأمان الخدمة.';
                $screen = 'DRIVER_PROFILE';
                $entityType = 'complaint';
                $action = 'open';
                break;

            case self::TYPE_DRIVER_AI_ALERT:
                $title = $customTitle ?? 'تنبيه نظام التقييم الذكي 🔔';
                $message = $customMessage ?? 'تم تحديث سجل تقييمك في النظام.';
                $screen = 'PROFILE';
                $entityType = 'driver';
                $action = 'open_profile';
                break;

            case self::TYPE_GENERAL_ANNOUNCEMENT:
                $title = $customTitle ?? 'إشعار من الإدارة 📢';
                $message = $customMessage ?? 'إشعار وتنبيه عام لكافة مستخدمي المنصة.';
                $screen = 'HOME';
                $entityType = 'announcement';
                $action = 'open';
                break;

            // =========================================================
            // 🎫 8. الدعم الفني وتذاكر المشاكل
            // =========================================================
            case self::TYPE_SUPPORT_TICKET_CREATED:
                $title = $customTitle ?? 'تذكرة دعم فني جديدة 🎫';
                $message = $customMessage ?? 'تم فتح تذكرة دعم فني جديدة وتحتاج إلى مراجعة.';
                $screen = 'SUPPORT_TICKETS';
                $entityType = 'support_ticket';
                $action = 'open_support_ticket';
                break;

            case self::TYPE_SUPPORT_TICKET_REPLY:
                $title = $customTitle ?? 'رد جديد على تذكرتك 💬';
                $message = $customMessage ?? 'تمت إضافة رد جديد على تذكرة الدعم الفني، يرجى المراجعة.';
                $screen = 'SUPPORT_TICKETS';
                $entityType = 'support_ticket';
                $action = 'open_support_ticket';
                break;

            case self::TYPE_SUPPORT_TICKET_STATUS_CHANGED:
                $title = $customTitle ?? 'تحديث حالة التذكرة 🔄';
                $message = $customMessage ?? 'تم تحديث حالة تذكرة الدعم الفني الخاصة بك.';
                $screen = 'SUPPORT_TICKETS';
                $entityType = 'support_ticket';
                $action = 'open_support_ticket';
                break;

            case self::TYPE_SUPPORT_TICKET_RESOLVED:
                $title = $customTitle ?? 'تم حل التذكرة بنجاح ✅';
                $message = $customMessage ?? 'قام المشرف بحل الإشكالية وتوثيق تفاصيل الحل.';
                $screen = 'SUPPORT_TICKETS';
                $entityType = 'support_ticket';
                $action = 'open_support_ticket';
                break;

            case self::TYPE_SUPPORT_TICKET_CLOSED:
                $title = $customTitle ?? 'تم إغلاق تذكرتك 🔒';
                $message = $customMessage ?? 'تم إغلاق وتوثيق تذكرة الدعم الفني من قبل الإدارة.';
                $screen = 'SUPPORT_TICKETS';
                $entityType = 'support_ticket';
                $action = 'open_support_ticket';
                break;
        }

        return [
            'title'       => $title,
            'message'     => $message,
            'type'        => $type,
            'action_url'  => $actionUrl,
            'entity_type' => $entityType,
            'entity_id'   => $entityId ? (string) $entityId : null,
            'screen'      => $screen,
            'action'      => $action,
            'payload'     => array_merge([
                'type'        => $type,
                'entity_type' => $entityType,
                'entity_id'   => $entityId ? (string) $entityId : null,
                'screen'      => $screen,
                'action'      => $action,
            ], $extraPayload),
        ];
    }
}
