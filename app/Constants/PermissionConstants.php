<?php

namespace App\Constants;

class PermissionConstants
{
    // ==========================================
    // 📊 لوحة التحكم والعمليات (Dashboard & Operations)
    // ==========================================
    public const DASHBOARD_STATS          = 'dashboard.view_stats';
    public const DASHBOARD_RADAR          = 'dashboard.view_radar';
    public const TRIPS_GENERATE_DAILY     = 'trips.generate_daily';
    public const TRIPS_EMERGENCY_CANCEL   = 'trips.emergency_cancel';

    // ==========================================
    // 🚐 شؤون السائقين والأسطول (Drivers & Fleet)
    // ==========================================
    public const DRIVERS_VIEW             = 'drivers.view';
    public const DRIVERS_REVIEW_INITIAL   = 'drivers.review_initial';
    public const DRIVERS_REVIEW_CHANGES   = 'drivers.review_changes';
    public const DRIVERS_EDIT_DATA        = 'drivers.edit_data';
    public const DRIVERS_SUSPEND          = 'drivers.suspend';

    // ==========================================
    // 🎧 الشكاوى والتقييمات والجودة (Complaints & Quality)
    // ==========================================
    public const COMPLAINTS_VIEW          = 'complaints.view';
    public const COMPLAINTS_RESOLVE       = 'complaints.resolve';
    public const DRIVER_REVIEWS_MANAGE    = 'driver_reviews.manage';

    // ==========================================
    // 💰 الإدارة المالية والخزينة (Financial Operations)
    // ==========================================
    public const FINANCIAL_VIEW_SUMMARY   = 'financial.view_summary';
    public const FINANCIAL_VIEW_LEDGER    = 'financial.view_ledger';
    public const FINANCIAL_WITHDRAWALS    = 'financial.manage_withdrawals';
    public const FINANCIAL_RECHARGES      = 'financial.manage_recharges';
    public const FINANCIAL_RELEASE_ESCROW = 'financial.release_escrows';
    public const FINANCIAL_DISPUTES       = 'financial.resolve_disputes';
    public const FINANCIAL_SETTLEMENTS    = 'financial.manage_settlements';
    public const FINANCIAL_PAYMENT_METHODS= 'financial.manage_payment_methods';
    public const FINANCIAL_PRICING        = 'financial.manage_pricing';

    // ==========================================
    // 🏫 المدارس والجغرافيا (Schools & Geography)
    // ==========================================
    public const SCHOOLS_MANAGE           = 'schools.manage';
    public const GEOGRAPHY_MANAGE         = 'geography.manage';

    // ==========================================
    // 🛡️ إدارة المشرفين والتدقيق (Admins & Audit)
    // ==========================================
    public const ADMINS_MANAGE            = 'admins.manage';
    public const AUDIT_LOGS_VIEW          = 'audit_logs.view';
    public const NOTIFICATIONS_BROADCAST  = 'notifications.broadcast';

    // ==========================================
    // 📈 التقارير والتحليلات والتصدير (Reports & Analytics)
    // ==========================================
    public const REPORTS_VIEW             = 'reports.view';
    public const REPORTS_EXPORT           = 'reports.export';

    // ==========================================
    // 📜 المحتوى القانوني (Legal Content)
    // ==========================================
    public const CONTENT_MANAGE_TERMS     = 'content.manage_terms';

    /**
     * إرجاع شجرة الصلاحيات الكاملة مع المسميات العربية لسهولة عرضها في واجهة المستخدم (UI)
     */
    public static function getPermissionsTree(): array
    {
        return [
            [
                'group_key'   => 'dashboard_operations',
                'group_name'  => 'لوحة التحكم والعمليات الحية',
                'permissions' => [
                    [
                        'key'         => self::DASHBOARD_STATS,
                        'name'        => 'عرض إحصائيات الداشبورد',
                        'description' => 'الاطلاع على أعداد المستخدمين والإحصائيات العامة للمنصة',
                    ],
                    [
                        'key'         => self::DASHBOARD_RADAR,
                        'name'        => 'عرض رادار الرحلات الحية',
                        'description' => 'متابعة حركة الحافلات النشطة على الخريطة التفاعلية في الوقت الفعلي',
                    ],
                    [
                        'key'         => self::TRIPS_GENERATE_DAILY,
                        'name'        => 'توليد الرحلات اليومية يدوياً',
                        'description' => 'تشغيل أمر بناء جداول الرحلات اليومية دون انتظار الجدولة التلقائية',
                    ],
                    [
                        'key'         => self::TRIPS_EMERGENCY_CANCEL,
                        'name'        => 'إلغاء الرحلات الطارئة',
                        'description' => 'إلغاء الرحلات الجارية مع تطبيق مصفوفة غرامات الإلغاء',
                    ],
                ]
            ],
            [
                'group_key'   => 'drivers_fleet',
                'group_name'  => 'شؤون السائقين والمركبات',
                'permissions' => [
                    [
                        'key'         => self::DRIVERS_VIEW,
                        'name'        => 'استعراض قائمة السائقين',
                        'description' => 'الاطلاع على ملفات السائقين وبياناتهم الشخصية وتاريخ رحلاتهم',
                    ],
                    [
                        'key'         => self::DRIVERS_REVIEW_INITIAL,
                        'name'        => 'اعتماد / رفض طلبات السائقين الجدد',
                        'description' => 'مراجعة الوثائق الرسمية واتخاذ قرار القبول أو الرفض المسبب',
                    ],
                    [
                        'key'         => self::DRIVERS_REVIEW_CHANGES,
                        'name'        => 'مراجعة طلبات تحديث بيانات السائقين',
                        'description' => 'مراجعة تحديثات الرخص والمركبات وتطبيق التغييرات',
                    ],
                    [
                        'key'         => self::DRIVERS_EDIT_DATA,
                        'name'        => 'تعديل بيانات السائقين مباشرة',
                        'description' => 'تعديل بيانات وملفات السائقين من قبل الإدارة',
                    ],
                    [
                        'key'         => self::DRIVERS_SUSPEND,
                        'name'        => 'إيقاف / تجميد حسابات السائقين',
                        'description' => 'إيقاف السائقين المخالفين وتجميد نشاطهم في النظام',
                    ],
                ]
            ],
            [
                'group_key'   => 'complaints_quality',
                'group_name'  => 'خدمة العملاء والشكاوى وضبط الجودة',
                'permissions' => [
                    [
                        'key'         => self::COMPLAINTS_VIEW,
                        'name'        => 'عرض الشكاوى والاعتراضات',
                        'description' => 'الاطلاع على شكاوى أولياء الأمور والسائقين',
                    ],
                    [
                        'key'         => self::COMPLAINTS_RESOLVE,
                        'name'        => 'معالجة الشكاوى والبت فيها',
                        'description' => 'اتخاذ القرارات الإدارية وحل الشكاوى وتوجيه الإنذارات',
                    ],
                    [
                        'key'         => self::DRIVER_REVIEWS_MANAGE,
                        'name'        => 'إدارة تقييمات السائقين',
                        'description' => 'مراجعة التقييمات وحذف التعليقات غير اللائقة',
                    ],
                ]
            ],
            [
                'group_key'   => 'financial_management',
                'group_name'  => 'الإدارة المالية والخزينة المركزية',
                'permissions' => [
                    [
                        'key'         => self::FINANCIAL_VIEW_SUMMARY,
                        'name'        => 'عرض الملخص المالي والملاءة',
                        'description' => 'الاطلاع على رصيد الخزينة، إجمالي الأمانات المعلقة، وفحص الملاءة',
                    ],
                    [
                        'key'         => self::FINANCIAL_VIEW_LEDGER,
                        'name'        => 'سجل القيود المالية المزدوجة',
                        'description' => 'الاطلاع على دفتر الأستاذ وسجلات التدقيق المالي للمنصة',
                    ],
                    [
                        'key'         => self::FINANCIAL_WITHDRAWALS,
                        'name'        => 'معالجة طلبات سحب الأرباح',
                        'description' => 'اعتماد أو رفض تحويل مستحقات السائقين للحسابات المصرفية',
                    ],
                    [
                        'key'         => self::FINANCIAL_RECHARGES,
                        'name'        => 'معالجة طلبات شحن المحافظ',
                        'description' => 'اعتماد الإيداعات وشحن محافظ أولياء الأمور والسائقين',
                    ],
                    [
                        'key'         => self::FINANCIAL_RELEASE_ESCROW,
                        'name'        => 'تحرير مبالغ الأمانات المعلقة',
                        'description' => 'تحرير أمانات الرحلات المكتملة إلى محافظ السائقين',
                    ],
                    [
                        'key'         => self::FINANCIAL_DISPUTES,
                        'name'        => 'البت في النزاعات المالية والاسترداد',
                        'description' => 'معالجة الاعتراضات المالية وإصدار أوامر استرداد الأموال (Refunds)',
                    ],
                    [
                        'key'         => self::FINANCIAL_SETTLEMENTS,
                        'name'        => 'التسويات الشهرية وإنهاء العقود',
                        'description' => 'إجراء التسويات المالية الختامية والإلغاء المبكر للاشتراكات',
                    ],
                    [
                        'key'         => self::FINANCIAL_PAYMENT_METHODS,
                        'name'        => 'إدارة وتفعيل طرق الدفع',
                        'description' => 'إضافة وتعديل بوابات الدفع الإلكتروني (سداد، تداول، البطاقة، النقدي)',
                    ],
                    [
                        'key'         => self::FINANCIAL_PRICING,
                        'name'        => 'إعدادات التسعير وعمولة المنصة',
                        'description' => 'تعديل أسعار الكيلومتر، نسبة عمولة المنصة، ومصفوفات الخصم',
                    ],
                ]
            ],
            [
                'group_key'   => 'schools_geography',
                'group_name'  => 'المدارس والبيانات الجغرافية',
                'permissions' => [
                    [
                        'key'         => self::SCHOOLS_MANAGE,
                        'name'        => 'إدارة المدارس',
                        'description' => 'إضافة، تعديل، وإلغاء المدارس وإحداثياتها وبوابات الدخول',
                    ],
                    [
                        'key'         => self::GEOGRAPHY_MANAGE,
                        'name'        => 'إدارة الهيكل الجغرافي والبلديات',
                        'description' => 'إدارة البلديات والمحلات والمناطق الجغرافية الدقيقة',
                    ],
                ]
            ],
            [
                'group_key'   => 'admins_audit',
                'group_name'  => 'إدارة المشرفين وسجلات التدقيق والأمان',
                'permissions' => [
                    [
                        'key'         => self::ADMINS_MANAGE,
                        'name'        => 'إدارة حسابات المشرفين',
                        'description' => 'إنشاء المشرفين، تعيين أدوارهم وصلاحياتهم وتجميد حساباتهم',
                    ],
                    [
                        'key'         => self::AUDIT_LOGS_VIEW,
                        'name'        => 'سجل تدقيق إجراءات المشرفين',
                        'description' => 'مراقبة سجل الحركات والقرارات الإدارية للمشرفين والمدراء',
                    ],
                    [
                        'key'         => self::NOTIFICATIONS_BROADCAST,
                        'name'        => 'إرسال الإشعارات والتعاميم العامة',
                        'description' => 'إرسال إشعارات جماعية لكافة المستخدمين أو فئات محددة',
                    ],
                ]
            ],
            [
                'group_key'   => 'reports_analytics',
                'group_name'  => 'التقارير والإحصائيات والتصدير',
                'permissions' => [
                    [
                        'key'         => self::REPORTS_VIEW,
                        'name'        => 'استعراض التقارير والتحليلات',
                        'description' => 'الاطلاع على مؤشرات الأداء والتقارير المالية والتشغيلية',
                    ],
                    [
                        'key'         => self::REPORTS_EXPORT,
                        'name'        => 'تصدير البيانات والتقارير',
                        'description' => 'تصدير الجداول والتقارير المالية بصيغ CSV و JSON',
                    ],
                ]
            ],
            [
                'group_key'   => 'legal_content',
                'group_name'  => 'المحتوى القانوني (الشروط والأحكام)',
                'permissions' => [
                    [
                        'key'         => self::CONTENT_MANAGE_TERMS,
                        'name'        => 'إدارة الشروط والأحكام',
                        'description' => 'إنشاء وتعديل ونشر نسخ الشروط والأحكام الخاصة بأولياء الأمور والسائقين',
                    ],
                ]
            ],
        ];
    }

    /**
     * الصلاحيات الافتراضية لكل دور إداري
     */
    public static function getDefaultRolePermissions(): array
    {
        return [
            // 1. مدير النظام العام (Super Admin) - صلاحيات مطلقة
            'super_admin' => ['*'],

            // 2. مشرف العمليات والرادار الميداني (Operations Supervisor)
            'operations_supervisor' => [
                self::DASHBOARD_STATS,
                self::DASHBOARD_RADAR,
                self::TRIPS_GENERATE_DAILY,
                self::TRIPS_EMERGENCY_CANCEL,
                self::DRIVERS_VIEW,
                self::SCHOOLS_MANAGE,
                self::GEOGRAPHY_MANAGE,
                self::REPORTS_VIEW,
            ],

            // 3. مشرف شؤون السائقين والأسطول (Fleet Supervisor)
            'fleet_supervisor' => [
                self::DASHBOARD_STATS,
                self::DRIVERS_VIEW,
                self::DRIVERS_REVIEW_INITIAL,
                self::DRIVERS_REVIEW_CHANGES,
                self::DRIVERS_EDIT_DATA,
                self::DRIVERS_SUSPEND,
                self::DRIVER_REVIEWS_MANAGE,
                self::REPORTS_VIEW,
            ],

            // 4. مشرف خدمة العملاء والشكاوى (Support Supervisor)
            'support_supervisor' => [
                self::DASHBOARD_STATS,
                self::COMPLAINTS_VIEW,
                self::COMPLAINTS_RESOLVE,
                self::DRIVER_REVIEWS_MANAGE,
                self::DRIVERS_VIEW,
                self::NOTIFICATIONS_BROADCAST,
                self::CONTENT_MANAGE_TERMS,
            ],

            // 5. المشرف المالي ومسؤول الخزينة (Finance Officer)
            'finance_officer' => [
                self::DASHBOARD_STATS,
                self::FINANCIAL_VIEW_SUMMARY,
                self::FINANCIAL_VIEW_LEDGER,
                self::FINANCIAL_WITHDRAWALS,
                self::FINANCIAL_RECHARGES,
                self::FINANCIAL_RELEASE_ESCROW,
                self::FINANCIAL_DISPUTES,
                self::FINANCIAL_SETTLEMENTS,
                self::FINANCIAL_PAYMENT_METHODS,
                self::FINANCIAL_PRICING,
                self::REPORTS_VIEW,
                self::REPORTS_EXPORT,
            ],

            // 6. مشرف المدارس والجغرافيا (Geography Coordinator)
            'geography_supervisor' => [
                self::DASHBOARD_STATS,
                self::SCHOOLS_MANAGE,
                self::GEOGRAPHY_MANAGE,
            ],
        ];
    }
}
