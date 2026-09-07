<?php

namespace App\Services\Driver;

use App\Models\Admin\Admin;
use App\Models\Driver\Driver;
use App\Models\Driver\DriverDocument;
use App\Models\Driver\VehicleDocument;
use App\Services\Notification\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * فحص يومي لتواريخ انتهاء رخصة القيادة ووثيقة التأمين لدى السائقين:
 * - يُرسل تذكيرات عند نقاط محددة (15 / 7 / 3 / 0 يوم) قبل الانتهاء دون تكرار.
 * - عند الانتهاء الفعلي: يُعلّم المستند كـ "Expired"، يمنع قبول اشتراكات جديدة فقط
 *   (عبر DriverMatchingService)، ويُبقي الاشتراكات النشطة الحالية تعمل، مع تنبيه الإدارة.
 */
class DriverExpiryNotificationService
{
    /** نقاط التذكير مرتبة تصاعدياً — أول نقطة تُساوي أو تتجاوزها الأيام المتبقية هي "الجرعة" الحالية */
    private const MILESTONES_ASC = [0, 3, 7, 15];

    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /** أنواع المستندات ذات تاريخ انتهاء يُتابَعها هذا الفحص (بخلاف الرخصة التي تُتابَع من جدول drivers مباشرة) */
    private const DOCUMENT_EXPIRY_TYPES = [
        'insurance'             => ['doc_type' => 'INSURANCE',            'column' => 'insurance_expiry_date',            'label' => 'وثيقة التأمين'],
        'stamp'                 => ['doc_type' => 'STAMP',                'column' => 'stamp_expiry_date',                'label' => 'الدمغة'],
        'technical_inspection'  => ['doc_type' => 'TECHNICAL_INSPECTION', 'column' => 'technical_inspection_expiry_date', 'label' => 'الفحص الفني'],
    ];

    public function run(): array
    {
        $stats = [
            'license_reminders'   => 0,
            'license_expired'     => 0,
            'insurance_reminders' => 0,
            'insurance_expired'   => 0,
            'document_reminders'  => 0,
            'document_expired'    => 0,
        ];
        foreach (array_keys(self::DOCUMENT_EXPIRY_TYPES) as $docKey) {
            $stats["{$docKey}_reminders"] = 0;
            $stats["{$docKey}_expired"] = 0;
        }

        $this->processLicenseExpiries($stats);

        // 1. فحص جدول vehicle_documents (الجدول الأساسي المطبع)
        $this->processVehicleDocumentExpiries($stats);

        // 2. دعم رجعي لجدول driver_documents القديم في حال كان موجوداً في بيئة ما
        if (Schema::hasTable('driver_documents')) {
            foreach (self::DOCUMENT_EXPIRY_TYPES as $docKey => $config) {
                $this->processDocumentExpiries($stats, $docKey, $config['doc_type'], $config['column'], $config['label']);
            }
        }

        return $stats;
    }

    /** فحص وتحديث وثائق المركبات المنتهية الصلاحية من جدول vehicle_documents المطبع */
    private function processVehicleDocumentExpiries(array &$stats): void
    {
        if (!Schema::hasTable('vehicle_documents')) {
            return;
        }

        $today = Carbon::today();

        VehicleDocument::whereNotNull('expiry_date')
            ->with(['vehicle.driver.user'])
            ->chunkById(100, function ($documents) use ($today, &$stats) {
                foreach ($documents as $document) {
                    $driver = $document->vehicle?->driver;
                    if (!$driver || !$driver->user) {
                        continue;
                    }

                    $expiryDate = $document->expiry_date instanceof Carbon
                        ? $document->expiry_date->format('Y-m-d')
                        : Carbon::parse($document->expiry_date)->format('Y-m-d');

                    $daysRemaining = $this->daysRemaining($today, $expiryDate);
                    $docLabel = match ($document->doc_type) {
                        'INSURANCE'        => 'وثيقة التأمين',
                        'LOGBOOK'          => 'رخصة السير (الاستمارة)',
                        'INSPECTION'       => 'الفحص الفني الدوري',
                        'OPERATING_PERMIT' => 'تصريح التشغيل',
                        default            => 'الوثيقة الرسمية',
                    };
                    $docKey = strtolower($document->doc_type ?? 'document');

                    if ($daysRemaining < 0) {
                        if ($document->state !== VehicleDocument::STATE_EXPIRED) {
                            $document->update([
                                'state'       => VehicleDocument::STATE_EXPIRED,
                                'is_verified' => false,
                            ]);

                            $this->notifyDriverExpired($driver->user, $docKey, $docLabel, $expiryDate, $driver->id);
                            $this->notifyAdminsExpired($driver, $docKey, $docLabel, $expiryDate);

                            if ($document->doc_type === 'INSURANCE') {
                                $stats['insurance_expired']++;
                            }
                            $stats["{$docKey}_expired"] = ($stats["{$docKey}_expired"] ?? 0) + 1;
                            $stats['document_expired']++;
                        }
                        continue;
                    }

                    $milestone = $this->currentMilestone($daysRemaining);
                    if ($milestone !== null) {
                        $this->notifyDriverReminder($driver->user, $docKey, $docLabel, $daysRemaining, $milestone, $expiryDate, $driver->id);
                        if ($document->doc_type === 'INSURANCE') {
                            $stats['insurance_reminders']++;
                        }
                        $stats["{$docKey}_reminders"] = ($stats["{$docKey}_reminders"] ?? 0) + 1;
                        $stats['document_reminders']++;
                    }
                }
            });
    }

    private function processLicenseExpiries(array &$stats): void
    {
        $today = Carbon::today();

        Driver::whereNotNull('license_expiry')
            ->with('user')
            ->chunkById(100, function ($drivers) use ($today, &$stats) {
                foreach ($drivers as $driver) {
                    if (!$driver->user) {
                        continue;
                    }

                    $daysRemaining = $this->daysRemaining($today, $driver->license_expiry);

                    if ($daysRemaining < 0) {
                        if ($this->markLicenseExpired($driver)) {
                            $stats['license_expired']++;
                        }
                        continue;
                    }

                    $milestone = $this->currentMilestone($daysRemaining);
                    if ($milestone !== null && $this->shouldNotify($milestone, $driver->license_expiry_notified_milestone)) {
                        $this->notifyDriverReminder($driver->user, 'license', 'رخصة القيادة', $daysRemaining, $milestone, $driver->license_expiry, $driver->id);
                        $driver->update(['license_expiry_notified_milestone' => $milestone]);
                        $stats['license_reminders']++;
                    }
                }
            });
    }

    /** فحص عام لأي نوع مستند له عمود تاريخ انتهاء خاص به (تأمين / دمغة / فحص فني) */
    private function processDocumentExpiries(array &$stats, string $docKey, string $docType, string $expiryColumn, string $docLabel): void
    {
        $today = Carbon::today();

        DriverDocument::where('doc_type', $docType)
            ->whereNotNull($expiryColumn)
            ->with('driver.user')
            ->chunkById(100, function ($documents) use ($today, &$stats, $docKey, $expiryColumn, $docLabel) {
                foreach ($documents as $document) {
                    $driver = $document->driver;
                    if (!$driver || !$driver->user) {
                        continue;
                    }

                    $daysRemaining = $this->daysRemaining($today, $document->{$expiryColumn});

                    if ($daysRemaining < 0) {
                        if ($this->markDocumentExpired($document, $driver, $docKey, $docLabel, $expiryColumn)) {
                            $stats["{$docKey}_expired"]++;
                        }
                        continue;
                    }

                    $milestone = $this->currentMilestone($daysRemaining);
                    if ($milestone !== null && $this->shouldNotify($milestone, $document->expiry_notified_milestone)) {
                        $this->notifyDriverReminder($driver->user, $docKey, $docLabel, $daysRemaining, $milestone, $document->{$expiryColumn}, $driver->id);
                        $document->update(['expiry_notified_milestone' => $milestone]);
                        $stats["{$docKey}_reminders"]++;
                    }
                }
            });
    }

    private function daysRemaining(Carbon $today, string $expiryDate): int
    {
        return (int) $today->diffInDays(Carbon::parse($expiryDate)->startOfDay(), false);
    }

    /** أصغر نقطة تذكير تساوي أو تتجاوز الأيام المتبقية؛ null لو أكثر من أبعد نقطة (15 يوم) */
    private function currentMilestone(int $daysRemaining): ?int
    {
        foreach (self::MILESTONES_ASC as $milestone) {
            if ($daysRemaining <= $milestone) {
                return $milestone;
            }
        }
        return null;
    }

    /** نُرسل فقط لو لم تُرسل من قبل، أو لو النقطة الحالية أكثر إلحاحاً (رقم أصغر) من آخر نقطة أُرسلت */
    private function shouldNotify(int $currentMilestone, ?int $alreadyNotified): bool
    {
        return $alreadyNotified === null || $currentMilestone < $alreadyNotified;
    }

    private function notifyDriverReminder($user, string $docKey, string $docLabel, int $daysRemaining, int $milestone, string $expiryDate, int $driverId): void
    {
        $expiryLabel = Carbon::parse($expiryDate)->format('Y-m-d');
        $whenLabel = $daysRemaining === 0
            ? 'اليوم'
            : "خلال {$daysRemaining} يوم";

        try {
            $this->notificationService->sendToUser($user, 'driver_document_expiring_soon', [
                'title'       => '⏰ اقتراب انتهاء ' . $docLabel,
                'message'     => "تنبيه: {$docLabel} الخاصة بك ستنتهي {$whenLabel} (بتاريخ {$expiryLabel}). يرجى تجديدها ورفع النسخة الجديدة من خلال التطبيق قبل الانتهاء.",
                'screen'      => 'DRIVER_PROFILE',
                'entity_type' => 'driver_document',
                'entity_id'   => "{$driverId}-{$docKey}-reminder-{$milestone}",
            ]);
        } catch (\Throwable $e) {
            Log::warning("DriverExpiryNotificationService: failed sending reminder to driver #{$driverId}: " . $e->getMessage());
        }
    }

    /** يُعلّم مستند الرخصة كمنتهي ويُنبّه السائق + الإدارة — مرة واحدة فقط بفضل فحص الحالة الحالية */
    private function markLicenseExpired(Driver $driver): bool
    {
        if ($driver->license_expiry_notified_milestone === -1) {
            return false; // أُعلن انتهاؤه مسبقاً
        }

        if (Schema::hasTable('driver_documents')) {
            $document = DriverDocument::where('driver_id', $driver->id)->where('doc_type', 'LICENSE')->first();
            if ($document) {
                $document->update(['status' => 'Expired']);
            }
        }

        $driver->update(['license_expiry_notified_milestone' => -1]);

        $this->notifyDriverExpired($driver->user, 'license', 'رخصة القيادة', $driver->license_expiry, $driver->id);
        $this->notifyAdminsExpired($driver, 'license', 'رخصة القيادة', $driver->license_expiry);

        return true;
    }

    private function markDocumentExpired(DriverDocument $document, Driver $driver, string $docKey, string $docLabel, string $expiryColumn): bool
    {
        if ($document->status === 'Expired') {
            return false;
        }

        $expiryDate = $document->{$expiryColumn};
        $document->update(['status' => 'Expired']);

        $this->notifyDriverExpired($driver->user, $docKey, $docLabel, $expiryDate, $driver->id);
        $this->notifyAdminsExpired($driver, $docKey, $docLabel, $expiryDate);

        return true;
    }

    private function notifyDriverExpired($user, string $docKey, string $docLabel, string $expiryDate, int $driverId): void
    {
        $expiryLabel = Carbon::parse($expiryDate)->format('Y-m-d');

        try {
            $this->notificationService->sendToUser($user, 'driver_document_expired', [
                'title'       => '🚫 انتهت صلاحية ' . $docLabel,
                'message'     => "انتهت صلاحية {$docLabel} الخاصة بك بتاريخ {$expiryLabel}. تم إيقاف قبول اشتراكات جديدة مؤقتاً حتى تُحدّث الوثيقة من خلال التطبيق — اشتراكاتك النشطة الحالية تستمر بشكل طبيعي.",
                'screen'      => 'DRIVER_PROFILE',
                'entity_type' => 'driver_document',
                'entity_id'   => "{$driverId}-{$docKey}-expired",
            ]);
        } catch (\Throwable $e) {
            Log::warning("DriverExpiryNotificationService: failed sending expiry alert to driver #{$driverId}: " . $e->getMessage());
        }
    }

    private function notifyAdminsExpired(Driver $driver, string $docKey, string $docLabel, string $expiryDate): void
    {
        $expiryLabel = Carbon::parse($expiryDate)->format('Y-m-d');
        $driverName = $driver->user->full_name ?? "#{$driver->id}";

        $adminUsers = Admin::with('user')->get()
            ->pluck('user')
            ->filter();

        if ($adminUsers->isEmpty()) {
            return;
        }

        try {
            $this->notificationService->sendToUsers($adminUsers, 'driver_document_expired_admin_alert', [
                'title'       => '⚠️ وثيقة سائق منتهية الصلاحية',
                'message'     => "انتهت صلاحية {$docLabel} للسائق {$driverName} (#{$driver->id}) بتاريخ {$expiryLabel}. تم منعه تلقائياً من قبول اشتراكات جديدة، واشتراكاته النشطة الحالية مستمرة — يرجى المراجعة والتصرف يدوياً إذا لزم.",
                'screen'      => 'ADMIN_DRIVER_DETAILS',
                'entity_type' => 'driver_document',
                'entity_id'   => "{$driver->id}-{$docKey}-expired",
            ]);
        } catch (\Throwable $e) {
            Log::warning("DriverExpiryNotificationService: failed sending admin alert for driver #{$driver->id}: " . $e->getMessage());
        }
    }
}
