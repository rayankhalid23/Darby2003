<?php

namespace App\Services\Admin;

use App\Models\Driver\Driver;
use App\Models\Driver\DriverApproval;
use App\Models\Driver\DriverDocument;
use App\Models\Driver\DriverAbsence;
use App\Models\Driver\Vehicle;
use App\Models\Shared\Trip;
use App\Models\Shared\ActiveSubscription;
use App\Models\User;

use App\Services\Notification\NotificationService;

use App\Services\Admin\AdminAuditLogService;
use App\Services\Shared\EmailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;
use Exception;

class AdminDriverService
{
    protected EmailService $emailService;
    protected AdminAuditLogService $auditLogService;
    protected NotificationService $notificationService;

    public function __construct(EmailService $emailService, AdminAuditLogService $auditLogService, NotificationService $notificationService)
    {
        $this->emailService = $emailService;
        $this->auditLogService = $auditLogService;
        $this->notificationService = $notificationService;
    }

    /**
     * جلب طلبات اشتراك السائقين المعلقة فقط حصرياً للآدمن
     */
    public function getDriversList(array $filters): LengthAwarePaginator
    {
        // القيم الصحيحة للـ enum في قاعدة البيانات
        $validStatuses = ['Pending', 'Approved', 'Suspended', 'Rejected', 'Offline', 'ON_TRIP'];

        // 1. بدء الاستعلام مع كسر حجب جدول المستخدمين (Users) المرتبطين بالسائقين
        $query = Driver::with(['user' => function($q) {
            $q->withTrashed()->withoutGlobalScopes();
        }]);

        // 2. ✅ فلترة الحالة: إذا أرسل الأدمن status يُطبَّق، وإلا يُجلب جميع السائقين
        if (!empty($filters['status']) && in_array($filters['status'], $validStatuses, true)) {
            $query->where('status', $filters['status']);
        }
        // ملاحظة: لا يوجد where افتراضي — الأدمن يرى الكل بدون فلتر

        // 3. فلترة البحث النصي (تُفعّل فقط إذا كتب الآدمن نصاً في خانة البحث)
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('user', function ($q) use ($search) {
                $q->withTrashed()->withoutGlobalScopes()
                  ->where(function ($sub) use ($search) {
                      $sub->where('full_name', 'like', "%{$search}%")
                          ->orWhere('email', 'like', "%{$search}%")
                          ->orWhere('phone_number', 'like', "%{$search}%");
                  });
            });
        }

        // 4. ترتيب تنازلي (الأحدث أولاً) مع الـ Pagination — per_page يحدده الأدمن أو افتراضي 15
        $perPage = isset($filters['per_page']) && is_numeric($filters['per_page'])
            ? (int) min($filters['per_page'], 100)  // حد أقصى 100 لمنع استنزاف الذاكرة
            : 15;

        return $query->orderBy('id', 'desc')->paginate($perPage);
    }
   

    /**
     * 2. جلب التفاصيل العميقة لسائق معين لمراجعته من قبل الإدارة
     */
   
    


   /**
     * جلب تفاصيل سائق محدد بالكامل دون أي حجب صامت أو قيود معالجة الوثائق
     */
    public function getDriverDetails(int $id): Driver
    {
        // 1. كسر حجب السائق بالكامل أولاً بشكل منفصل
        $driver = Driver::withoutGlobalScopes()->where('id', $id)->first();

        if (!$driver) {
            throw new \Exception("عذراً، السائق المطلوب غير موجود في النظام نهائياً.");
        }

        // 2. تحميل الحساب الشخصي بشكل آمن ومحمي من الحجب الصامت
        $driver->load([
            'user' => function($q) {
                $q->withTrashed()->withoutGlobalScopes(); 
            }
        ]);

        // 3. تحميل المركبات بشكل آمن وتخطي القيود
        if (method_exists($driver, 'vehicles')) {
            $driver->load(['vehicles' => function($q) {
                $q->withoutGlobalScopes();
            }]);
        }

        // 4. تحميل الوثائق بشكل آمن مع فك حجب القيود الصامتة
        if (method_exists($driver, 'documents')) {
            $driver->load(['documents' => function($q) {
                $q->withoutGlobalScopes();
            }]);

            // 🚀 هندسة ذكية: إصلاح مشكلة الـ null في الحقول والروابط مباشرة هنا لضمان قيم حقيقية وروابط كاملة
            $driver->documents->transform(function ($doc) {
                $doc->document_type = $doc->doc_type ?? $doc->document_type;
                $doc->document_url = $doc->file_url ? url($doc->file_url) : ($doc->document_url ?? null);
                return $doc;
            });
        }

        return $driver;
    }

    /**
     * 3. معالجة طلب السائق (تفعيل وقبول أو رفض مسبب) للإنشاء أول مرة
     */
    public function reviewDriverRequest(int $driverId, array $data, int $adminId): Driver
    {
        return DB::transaction(function () use ($driverId, $data, $adminId) {
            
            $driver = Driver::with('user')->lockForUpdate()->findOrFail($driverId);
            
            $status = $data['status']; // Approved أو Rejected
            $rejectionReason = $data['rejection_reason'] ?? null;

            $driver->update([
                'status'           => $status,
                'reviewed_by'      => $adminId,
                'rejection_reason' => $rejectionReason,
            ]);

            $vehicleIds = Vehicle::where('driver_id', $driver->id)->pluck('id');

            if ($status === 'Approved') {
                $driver->user->update([
                    'is_active' => true
                ]);

                // تفعيل وتأكيد المركبة التابعة للسائق تلقائياً
                Vehicle::where('driver_id', $driver->id)->update([
                    'status' => 'Active',
                ]);

                // اعتماد جميع وثائق المركبة المرفوعة
                \App\Models\Driver\VehicleDocument::whereIn('vehicle_id', $vehicleIds)->update([
                    'is_verified' => true,
                ]);
            } elseif ($status === 'Rejected') {
                Vehicle::where('driver_id', $driver->id)->update([
                    'status' => 'Maintenance',
                ]);

                \App\Models\Driver\VehicleDocument::whereIn('vehicle_id', $vehicleIds)->update([
                    'is_verified' => false,
                ]);
            }

            DriverApproval::create([
                'driver_id'        => $driver->id,
                'request_type'     => 'Registration',
                'admin_id'         => $adminId,
                'status'           => $status,
                'rejection_reason' => $rejectionReason,
                'reviewed_at'      => now(),
            ]);

            // 📝 تسجيل إجراء القرار في سجل تدقيق المشرفين
            $this->auditLogService->record(
                action: $status === 'Approved' ? 'approve_driver' : 'reject_driver',
                entityType: 'driver',
                entityId: $driver->id,
                entityName: $driver->user->full_name,
                result: $status === 'Approved' ? 'approved' : 'rejected',
                reason: $rejectionReason,
                changes: [],
                adminId: $adminId
            );

            $this->emailService->sendDriverReviewResult(
                $driver->user->email,
                $driver->user->full_name,
                $status,
                $rejectionReason,
                $driver->gender
            );

            // 🔔 إرسال إشعار فوري داخل التطبيق وعبر FCM للسائق
            try {
                if ($status === 'Approved') {
                    $this->notificationService->sendToUser($driver->user, 'driver_account_approved', [
                        'title'       => '🎉 تم اعتماد حسابك بنجاح',
                        'message'     => 'تهانينا كابتن! تمت مراجعة واعتماد حسابك ووثائقك بنجاح، يمكنك الآن البدء باستقبال طلبات النقل المدرسي.',
                        'entity_type' => 'driver',
                        'entity_id'   => (string) $driver->id,
                        'screen'      => 'DRIVER_HOME',
                    ]);
                } else {
                    $this->notificationService->sendToUser($driver->user, 'driver_account_rejected', [
                        'title'       => '⚠️ مراجعة حساب السائق',
                        'message'     => $rejectionReason ? "نأسف، لم يتم قبول طلب تسجيل الحساب للسبب التالي: {$rejectionReason}" : "نأسف، لم يتم قبول طلب تسجيل الحساب، يرجى مراجعة الإدارة.",
                        'entity_type' => 'driver',
                        'entity_id'   => (string) $driver->id,
                        'screen'      => 'DRIVER_PROFILE',
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning("فشل إرسال إشعار مراجعة حساب السائق #{$driver->id}: " . $e->getMessage());
            }

            return $driver;
        });
    }

    /**
     * 3-ب. تعديل بيانات السائق مباشرة من قبل المشرف / الأدمن مع التوثيق في سجل التدقيق
     */
    public function updateDriver(int $driverId, array $data, ?int $adminId = null): Driver
    {
        return DB::transaction(function () use ($driverId, $data, $adminId) {
            $driver = Driver::with('user')->lockForUpdate()->findOrFail($driverId);
            $user = $driver->user;

            // أخذ لقطة من القيم القديمة لحساب الفروقات
            $oldSnapshot = [
                'full_name'      => $user->full_name,
                'phone_number'   => $user->phone_number,
                'is_active'      => (bool) $user->is_active,
                'national_id'    => $driver->national_id,
                'license_number' => $driver->license_number,
                'license_expiry' => $driver->license_expiry ? \Carbon\Carbon::parse($driver->license_expiry)->format('Y-m-d') : null,
                'status'         => $driver->status,
            ];

            // تحضير بيانات تحديث المستخدم
            $userUpdates = [];
            if (isset($data['full_name'])) {
                $userUpdates['full_name'] = $data['full_name'];
            }
            if (isset($data['phone_number'])) {
                $userUpdates['phone_number'] = $data['phone_number'];
            }
            if (isset($data['is_active'])) {
                $userUpdates['is_active'] = (bool) $data['is_active'];
            }

            if (!empty($userUpdates)) {
                $user->update($userUpdates);
            }

            // تحضير بيانات تحديث السائق
            $driverUpdates = [];
            if (isset($data['national_id'])) {
                $driverUpdates['national_id'] = $data['national_id'];
            }
            if (isset($data['license_number'])) {
                $driverUpdates['license_number'] = $data['license_number'];
            }
            if (isset($data['license_expiry'])) {
                $driverUpdates['license_expiry'] = $data['license_expiry'];
                // تعديل تاريخ الانتهاء من الإدارة يُعيد ضبط عدّاد التذكيرات ويُلغي علامة "منتهية"
                $driverUpdates['license_expiry_notified_milestone'] = null;

                DriverDocument::where('driver_id', $driver->id)
                    ->where('doc_type', 'LICENSE')
                    ->where('status', 'Expired')
                    ->update(['status' => 'Pending']);
            }
            if (isset($data['status'])) {
                $driverUpdates['status'] = ucfirst(strtolower($data['status']));
                if ($driverUpdates['status'] === 'Active') {
                    $driverUpdates['status'] = 'Approved';
                }
            }

            if (!empty($driverUpdates)) {
                $driver->update($driverUpdates);
            }

            // ── ب) تحديث بيانات المركبة (اختياري — فقط إذا أرسل الأدمن أي حقل من حقولها) ──
            $vehicleFields = ['plate_number', 'brand', 'model', 'year', 'color', 'type', 'capacity_manual', 'has_ac', 'vehicle_image_path'];
            $vehicleOldSnapshot = [];
            $vehicleNewSnapshot = [];

            if (collect($vehicleFields)->contains(fn ($f) => array_key_exists($f, $data))) {
                $vehicle = null;
                if (!empty($data['vehicle_id'])) {
                    $vehicle = Vehicle::where('id', $data['vehicle_id'])->where('driver_id', $driver->id)->first();
                }
                if (!$vehicle) {
                    $vehicle = Vehicle::where('driver_id', $driver->id)->where('is_verified', true)->first()
                        ?? Vehicle::where('driver_id', $driver->id)->latest('id')->first();
                }
                if (!$vehicle) {
                    throw new Exception('لا توجد مركبة مسجلة لهذا السائق لتحديثها.');
                }

                $vehicleOldSnapshot = [
                    'vehicle_plate_number'    => $vehicle->plate_number,
                    'vehicle_brand'           => $vehicle->brand,
                    'vehicle_model'           => $vehicle->model,
                    'vehicle_year'            => $vehicle->year,
                    'vehicle_color'           => $vehicle->color,
                    'vehicle_type'            => $vehicle->type,
                    'vehicle_capacity_manual' => $vehicle->capacity_manual,
                    'vehicle_has_ac'          => (bool) $vehicle->has_ac,
                ];

                $vehicleUpdates = [];
                foreach (['plate_number', 'brand', 'model', 'year', 'color', 'type', 'capacity_manual'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $vehicleUpdates[$field] = $data[$field];
                    }
                }
                if (array_key_exists('has_ac', $data)) {
                    $vehicleUpdates['has_ac'] = (bool) $data['has_ac'];
                }
                if (!empty($data['vehicle_image_path'])) {
                    $vehicleUpdates['vehicle_image_url'] = $data['vehicle_image_path'];
                }

                $vehicle->update($vehicleUpdates);
                $vehicle->refresh();

                $vehicleNewSnapshot = [
                    'vehicle_plate_number'    => $vehicle->plate_number,
                    'vehicle_brand'           => $vehicle->brand,
                    'vehicle_model'           => $vehicle->model,
                    'vehicle_year'            => $vehicle->year,
                    'vehicle_color'           => $vehicle->color,
                    'vehicle_type'            => $vehicle->type,
                    'vehicle_capacity_manual' => $vehicle->capacity_manual,
                    'vehicle_has_ac'          => (bool) $vehicle->has_ac,
                ];
            }

            // ── ج) تحديث الوثائق الرسمية (اختياري — رفع ملفات جديدة و/أو تعديل تواريخ الانتهاء) ──
            $docFileMap = [
                'doc_license_path'              => 'LICENSE',
                'doc_logbook_path'               => 'VEHICLE_LOGBOOK',
                'doc_insurance_path'             => 'INSURANCE',
                'doc_booklet_page_path'          => 'BOOKLET_PERSONAL_PAGE',
                'doc_stamp_path'                 => 'STAMP',
                'doc_technical_inspection_path'  => 'TECHNICAL_INSPECTION',
            ];

            // كل نوع وثيقة له تاريخ انتهاء خاص بها (اسم الحقل في $data => اسم عمود driver_documents)
            $expiryFieldMap = [
                'INSURANCE'             => ['input' => 'insurance_expiry',             'column' => 'insurance_expiry_date'],
                'STAMP'                 => ['input' => 'stamp_expiry',                 'column' => 'stamp_expiry_date'],
                'TECHNICAL_INSPECTION'  => ['input' => 'technical_inspection_expiry',  'column' => 'technical_inspection_expiry_date'],
            ];

            $expiryOldSnapshot = [];
            foreach ($expiryFieldMap as $docType => $map) {
                $expiryOldSnapshot[$map['input']] = DriverDocument::where('driver_id', $driver->id)->where('doc_type', $docType)->value($map['column']);
            }

            foreach ($docFileMap as $pathKey => $docType) {
                if (!empty($data[$pathKey])) {
                    $updateFields = [
                        'file_url'    => $data[$pathKey],
                        'status'      => 'Pending',
                        'uploaded_at' => now(),
                    ];

                    if (isset($expiryFieldMap[$docType])) {
                        $updateFields['expiry_notified_milestone'] = null;
                        $expiryInput = $expiryFieldMap[$docType]['input'];
                        if (array_key_exists($expiryInput, $data)) {
                            $updateFields[$expiryFieldMap[$docType]['column']] = $data[$expiryInput];
                        }
                    }

                    DriverDocument::updateOrCreate(
                        ['driver_id' => $driver->id, 'doc_type' => $docType],
                        $updateFields
                    );
                }
            }

            // السماح بتعديل أي تاريخ انتهاء (تأمين/دمغة/فحص فني) بشكل مستقل دون رفع صورة جديدة
            foreach ($expiryFieldMap as $docType => $map) {
                $pathKey = array_search($docType, $docFileMap, true);
                if (array_key_exists($map['input'], $data) && empty($data[$pathKey])) {
                    DriverDocument::where('driver_id', $driver->id)
                        ->where('doc_type', $docType)
                        ->update([
                            $map['column']              => $data[$map['input']],
                            'expiry_notified_milestone' => null,
                            'status'                    => 'Pending',
                        ]);
                }
            }

            $expiryNewSnapshot = [];
            foreach ($expiryFieldMap as $docType => $map) {
                $expiryNewSnapshot[$map['input']] = DriverDocument::where('driver_id', $driver->id)->where('doc_type', $docType)->value($map['column']);
            }

            $driver->refresh()->load(['user', 'vehicles', 'documents']);

            // لقطة القيم الجديدة
            $newSnapshot = array_merge([
                'full_name'      => $driver->user->full_name,
                'phone_number'   => $driver->user->phone_number,
                'is_active'      => (bool) $driver->user->is_active,
                'national_id'    => $driver->national_id,
                'license_number' => $driver->license_number,
                'license_expiry' => $driver->license_expiry ? \Carbon\Carbon::parse($driver->license_expiry)->format('Y-m-d') : null,
                'status'         => $driver->status,
            ], $expiryNewSnapshot, $vehicleNewSnapshot);

            $oldSnapshot = array_merge($oldSnapshot, $expiryOldSnapshot, $vehicleOldSnapshot);

            // حساب التغييرات مع التسميات العربية
            $changes = $this->auditLogService->diff($oldSnapshot, $newSnapshot);

            $reason = $data['reason'] ?? 'تعديل بيانات السائق من قبل الإدارة';

            // تسجيل العملية في سجل التدقيق
            $this->auditLogService->record(
                action: 'update_driver',
                entityType: 'driver',
                entityId: $driver->id,
                entityName: $driver->user->full_name,
                result: null,
                reason: $reason,
                changes: $changes,
                adminId: $adminId
            );

            return $driver;
        });
    }
    /*
    |--------------------------------------------------------------------------
    | 🚀 إدارة التعديلات اللاحقة لبيانات السائقين (Admin Driver Service)
    |--------------------------------------------------------------------------
    */

    /**
     * 1. عرض كافة طلبات التعديل المعلقة بانتظار موافقة الأدمن مع الترقيم الصفحات
     *
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
   /**
     * 1. عرض كافة طلبات التعديل المعلقة بانتظار موافقة الأدمن مع الترقيم الصفحات
     *
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getPendingChangesList(int $perPage = 15): LengthAwarePaginator
    {
        return DB::table('driver_profile_changes')
            ->join('drivers', 'driver_profile_changes.driver_id', '=', 'drivers.id')
            ->join('users', 'drivers.user_id', '=', 'users.id')
            ->select(
                'driver_profile_changes.id',
                'driver_profile_changes.driver_id',
                'driver_profile_changes.status',
                'driver_profile_changes.old_values', // 👈 إضافة old_values
                'driver_profile_changes.new_values', // 👈 إضافة new_values
                'driver_profile_changes.created_at',
                'users.full_name as driver_name',
                'users.phone_number as driver_phone'
            )
            ->where('driver_profile_changes.status', 'Pending')
            ->orderBy('driver_profile_changes.created_at', 'asc')
            ->paginate($perPage);
    }

    /**
     * 2. عرض تفصيلي لطلب تعديل محدد للمقارنة الشاملة
     */
     public function getPendingChangeDetails(int $changeId): object
    {
        $change = DB::table('driver_profile_changes')->where('id', $changeId)->first();

        if (!$change) {
            throw new \Exception('طلب التعديل هذا غير موجود في النظام.', 404);
        }

        // جلب نموذج السائق مع المستخدم والمركبات والوثائق المرتفقة
        $change->driver = Driver::with(['user', 'vehicles', 'documents'])->find($change->driver_id);

        // 🚀 فك التشفير بأمان وحماية الكائن من أخطاء الخواص المفقودة
        $oldRaw = property_exists($change, 'old_values') ? $change->old_values : null;
        $newRaw = property_exists($change, 'new_values') ? $change->new_values : null;

        $change->old_values_decoded = is_string($oldRaw) ? json_decode($oldRaw, true) : ($oldRaw ?? []);
        $change->new_values_decoded = is_string($newRaw) ? json_decode($newRaw, true) : ($newRaw ?? []);

        return $change;
    }

    /**
     * 3. اتخاذ القرار (الموافقة أو الرفض) على طلب التعديل المعلق مع تطبيق التغييرات وإرسال الإشعارات والتدقيق
     *
     * @param int $changeId
     * @param string $decision ('Approved' | 'Rejected')
     * @param string|null $rejectionReason
     * @param int $adminId
     * @return bool
     * @throws \Exception
     */
    public function reviewProfileChangeRequest(int $changeId, string $decision, ?string $rejectionReason, int $adminId): bool
    {
        if (!in_array($decision, ['Approved', 'Rejected'], true)) {
            throw new \Exception('القرار المدخل غير صالح. يجب أن يكون Approved أو Rejected.', 422);
        }

        if ($decision === 'Rejected' && empty(trim((string)$rejectionReason))) {
            throw new \Exception('يرجى تحديد سبب الرفض لإبلاغ السائق به.', 422);
        }

        return DB::transaction(function () use ($changeId, $decision, $rejectionReason, $adminId) {

            // 1. قفل السجل لضمان منع المعالجة المزدوجة أثناء التزامن (Race Conditions)
            $change = DB::table('driver_profile_changes')
                ->where('id', $changeId)
                ->lockForUpdate()
                ->first();

            if (!$change) {
                throw new \Exception('طلب التعديل غير موجود.', 404);
            }

            if ($change->status !== 'Pending') {
                throw new \Exception('تم معالجة هذا الطلب مسبقاً وتتغير حالته إلى: ' . $change->status, 422);
            }

            $driver = Driver::with('user')->find($change->driver_id);

            if (!$driver || !$driver->user) {
                throw new \Exception('لم يتم العثور على ملف السائق أو حساب المستخدم الخاص به.', 404);
            }

            $newValues = is_string($change->new_values) ? json_decode($change->new_values, true) : ($change->new_values ?? []);
            $oldValues = is_string($change->old_values) ? json_decode($change->old_values, true) : ($change->old_values ?? []);

            if ($decision === 'Approved') {

                // أ) تحديث بيانات حساب المستخدم (User)
                $userFields = ['full_name', 'phone_number', 'alternative_phone', 'avatar_url'];
                $userDataToUpdate = array_intersect_key($newValues, array_flip($userFields));
                if (!empty($userDataToUpdate)) {
                    $driver->user->update($userDataToUpdate);
                }

                // ب) تحديث البيانات الأساسية لملف السائق (Driver)
                $driverFields = ['national_id', 'license_number', 'license_expiry'];
                $driverDataToUpdate = array_intersect_key($newValues, array_flip($driverFields));
                if (!empty($driverDataToUpdate)) {
                    $driver->update($driverDataToUpdate);
                }

                // ج) تحديث بيانات المركبة المرتبطة (Vehicle)
                $vehicleFields = ['plate_number', 'brand', 'model', 'year', 'color', 'type', 'capacity_manual', 'has_ac'];
                $vehicleDataToUpdate = array_intersect_key($newValues, array_flip($vehicleFields));

                if (isset($newValues['vehicle_image_path'])) {
                    $vehicleDataToUpdate['vehicle_image_url'] = $newValues['vehicle_image_path'];
                }

                if (!empty($vehicleDataToUpdate)) {
                    $vehicleDataToUpdate['status'] = 'Active';
                    $vehicleDataToUpdate['is_verified'] = true;

                    $vehicleId = $newValues['vehicle_id'] ?? $driver->vehicles()->first()?->id;

                    if ($vehicleId) {
                        Vehicle::where('id', $vehicleId)->where('driver_id', $driver->id)->update($vehicleDataToUpdate);
                    }
                }

                // د) تحديث وحفظ المستندات والوثائق الرسمية
                $docMap = [
                    'doc_license_path'              => 'LICENSE',
                    'doc_logbook_path'               => 'VEHICLE_LOGBOOK',
                    'doc_insurance_path'             => 'INSURANCE',
                    'doc_booklet_page_path'          => 'BOOKLET_PERSONAL_PAGE',
                    'doc_stamp_path'                 => 'STAMP',
                    'doc_technical_inspection_path'  => 'TECHNICAL_INSPECTION',
                ];

                foreach ($docMap as $pathKey => $docType) {
                    if (!empty($newValues[$pathKey])) {
                        DriverDocument::updateOrCreate(
                            ['driver_id' => $driver->id, 'doc_type' => $docType],
                            [
                                'file_url'    => $newValues[$pathKey],
                                'status'      => 'Verified',
                                'uploaded_at' => now(),
                            ]
                        );
                    }
                }

                // هـ) تحديث تواريخ الانتهاء المخصصة للوثائق
                $expiryMap = [
                    'insurance_expiry'            => ['doc' => 'INSURANCE',            'col' => 'insurance_expiry_date'],
                    'stamp_expiry'                => ['doc' => 'STAMP',                'col' => 'stamp_expiry_date'],
                    'technical_inspection_expiry' => ['doc' => 'TECHNICAL_INSPECTION', 'col' => 'technical_inspection_expiry_date'],
                ];

                foreach ($expiryMap as $inputKey => $config) {
                    if (!empty($newValues[$inputKey])) {
                        DriverDocument::where('driver_id', $driver->id)
                            ->where('doc_type', $config['doc'])
                            ->update([
                                $config['col'] => $newValues[$inputKey],
                                'status'       => 'Verified'
                            ]);
                    }
                }

                // و) تحويل كافة وثائق السائق المعلقة (Pending) إلى معتمدة (Verified)
                DriverDocument::where('driver_id', $driver->id)
                    ->where('status', 'Pending')
                    ->update(['status' => 'Verified']);

                // ز) تحديث حالة سجل الطلب إلى مقبوض وموثق
                DB::table('driver_profile_changes')->where('id', $changeId)->update([
                    'status'     => 'Approved',
                    'action_by'  => $adminId,
                    'action_at'  => now(),
                    'updated_at' => now(),
                ]);

                // ح) إشعار السائق بقبول التعديل
                try {
                    $this->notificationService->sendToUser($driver->user, 'driver_account_approved', [
                        'title'       => '🎉 تم قبول تحديث بياناتك',
                        'message'     => 'مرحباً بك كابتن، تمت الموافقة على تعديل بيانات ملفك الشخصي وتطبيقها بنجاح.',
                        'entity_type' => 'driver_profile_change',
                        'entity_id'   => (string) $changeId,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("فشل إرسال إشعار موافقة الأدمن للسائق: " . $e->getMessage());
                }

            } else {

                // أ) حالة الرفض: تحديث حالة سجل الطلب مع السبب
                DB::table('driver_profile_changes')->where('id', $changeId)->update([
                    'status'           => 'Rejected',
                    'rejection_reason' => $rejectionReason,
                    'action_by'        => $adminId,
                    'action_at'        => now(),
                    'updated_at'       => now(),
                ]);

                // ب) إشعار السائق برفض التعديل والسبب
                try {
                    $this->notificationService->sendToUser($driver->user, 'driver_account_rejected', [
                        'title'       => '📋 مراجعة تحديث البيانات',
                        'message'     => "نأسف لإبلاغك برفض طلب تعديل البيانات المرفق بملفك الشخصي بسبب: {$rejectionReason}",
                        'entity_type' => 'driver_profile_change',
                        'entity_id'   => (string) $changeId,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("فشل إرسال إشعار رفض الأدمن للسائق: " . $e->getMessage());
                }
            }

            // 📝 تسجيل الإجراء كبديل موثق لتدقيق المشرفين Audit Trail
            try {
                if (isset($this->auditLogService)) {
                    $this->auditLogService->record(
                        action: $decision === 'Approved' ? 'approve_driver_change' : 'reject_driver_change',
                        entityType: 'driver_change',
                        entityId: $changeId,
                        entityName: $driver->user->full_name,
                        result: strtolower($decision),
                        reason: $rejectionReason,
                        changes: $decision === 'Approved' ? ($this->auditLogService->diff($oldValues, $newValues) ?? []) : [],
                        adminId: $adminId
                    );
                }
            } catch (\Throwable $e) {
                Log::warning("فشل تسجيل حركات التدقيق (Audit Log): " . $e->getMessage());
            }

            return true;
        });
    }

    /**
     * جلب قائمة طلبات غياب السائقين مع الفلترة
     */
    public function getDriverAbsenceRequests(array $filters = []): LengthAwarePaginator
    {
        $query = DriverAbsence::with([
            'driver.user',
            'trips.route',
            'reviewer'
        ])->latest();

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['driver_id'])) {
            $query->where('driver_id', $filters['driver_id']);
        }

        if (!empty($filters['date'])) {
            $query->whereDate('absence_date', $filters['date']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('driver.user', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        $perPage = (int) ($filters['per_page'] ?? 15);
        return $query->paginate($perPage);
    }

    /**
     * جلب تفاصيل طلب غياب محدد
     */
    public function getDriverAbsenceDetails(int $absenceId): DriverAbsence
    {
        return DriverAbsence::with([
            'driver.user',
            'trips.route',
            'trips.stops',
            'reviewer'
        ])->findOrFail($absenceId);
    }

    /**
     * الموافقة على طلب غياب السائق ونزعه فقط من الرحلات المحددة
     */
    public function approveDriverAbsence(int $absenceId, ?string $adminNotes = null, int $adminId = 1): DriverAbsence
    {
        return DB::transaction(function () use ($absenceId, $adminNotes, $adminId) {
            $absence = DriverAbsence::with(['driver.user', 'trips.stops', 'trips.route'])->findOrFail($absenceId);

            if ($absence->status === DriverAbsence::STATUS_APPROVED) {
                throw new Exception('تمت الموافقة على طلب الغياب هذا مسبقاً.');
            }

            $absence->update([
                'status'      => DriverAbsence::STATUS_APPROVED,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
                'admin_notes' => $adminNotes,
            ]);

            // نزع السائق من الرحلات المحددة فقط
            $trips = $absence->trips;
            $unassignedTripIds = [];

            foreach ($trips as $trip) {
                $trip->update([
                    'driver_id' => null,
                    'status'    => 'pending',
                ]);
                $unassignedTripIds[] = $trip->id;
            }

            // إشعار السائق بالموافقة
            if ($absence->driver?->user) {
                try {
                    $tripsList = implode(', ', $unassignedTripIds);
                    $this->notificationService->sendToUser($absence->driver->user, 'driver_absence_approved', [
                        'title'       => '✅ تمت الموافقة على طلب الغياب',
                        'message'     => "تمت الموافقة على طلب غيابك لتاريخ ({$absence->absence_date->toDateString()}) وتم فصلك عن الرحلات رقم: [{$tripsList}].",
                        'entity_type' => 'driver_absence',
                        'entity_id'   => (string) $absence->id,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("فشل إرسال إشعار موافقة الغياب للسائق: " . $e->getMessage());
                }
            }

            try {
                if (!empty($unassignedTripIds)) {
                    $parentUserIds = ActiveSubscription::whereIn('route_id', $trips->pluck('route_id')->filter())
                        ->pluck('parent_id')
                        ->unique();

                    $usersToNotify = User::whereIn('id', $parentUserIds)->get();
                    if ($usersToNotify->isNotEmpty()) {
                        $this->notificationService->sendToUsers($usersToNotify, 'trip_driver_absence_update', [
                            'title'     => 'تنبيه بشأن رحلة طفلكم',
                            'message'   => "نفيدكم علماً بتحديث جدول الرحلة لتاريخ ({$absence->absence_date->toDateString()}) وجارٍ العمل على توفير سائق بديل.",
                            'entity_id' => (string) $absence->id,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("فشل إرسال إشعار غياب السائق لأولياء الأمور: " . $e->getMessage());
            }

            // تسجيل التدقيق الإداري
            try {
                if (isset($this->auditLogService)) {
                    $this->auditLogService->record(
                        action: 'approve_driver_absence',
                        entityType: 'driver_absence',
                        entityId: $absence->id,
                        entityName: $absence->driver?->user?->full_name ?? 'سائق',
                        result: 'approved',
                        reason: $adminNotes,
                        changes: ['unassigned_trip_ids' => $unassignedTripIds],
                        adminId: $adminId
                    );
                }
            } catch (\Throwable $e) {
                Log::warning("فشل تسجيل حركة التدقيق لغياب السائق: " . $e->getMessage());
            }

            return $absence->fresh(['driver.user', 'trips', 'reviewer']);
        });
    }

    /**
     * رفض طلب غياب السائق
     */
    public function rejectDriverAbsence(int $absenceId, string $rejectionReason, int $adminId = 1): DriverAbsence
    {
        return DB::transaction(function () use ($absenceId, $rejectionReason, $adminId) {
            $absence = DriverAbsence::with(['driver.user', 'trips'])->findOrFail($absenceId);

            $absence->update([
                'status'      => DriverAbsence::STATUS_REJECTED,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
                'admin_notes' => $rejectionReason,
            ]);

            // إشعار السائق بالرفض
            if ($absence->driver?->user) {
                try {
                    $this->notificationService->sendToUser($absence->driver->user, 'driver_absence_rejected', [
                        'title'       => '❌ رفض طلب الغياب',
                        'message'     => "تم رفض طلب غيابك لتاريخ ({$absence->absence_date->toDateString()}) بسبب: {$rejectionReason}",
                        'entity_type' => 'driver_absence',
                        'entity_id'   => (string) $absence->id,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("فشل إرسال إشعار رفض الغياب للسائق: " . $e->getMessage());
                }
            }

            // تسجيل التدقيق الإداري
            try {
                if (isset($this->auditLogService)) {
                    $this->auditLogService->record(
                        action: 'reject_driver_absence',
                        entityType: 'driver_absence',
                        entityId: $absence->id,
                        entityName: $absence->driver?->user?->full_name ?? 'سائق',
                        result: 'rejected',
                        reason: $rejectionReason,
                        changes: [],
                        adminId: $adminId
                    );
                }
            } catch (\Throwable $e) {
                Log::warning("فشل تسجيل حركة التدقيق لرفض غياب السائق: " . $e->getMessage());
            }

            return $absence->fresh(['driver.user', 'trips', 'reviewer']);
        });
    }
}