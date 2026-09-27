<?php

namespace App\Services\Driver;

use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Driver\Vehicle;
use App\Models\Driver\DriverDocument;
use App\Models\Driver\DriverApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Log;
use App\Services\Shared\EmailService;
use App\Services\Notification\NotificationService;
use Exception;

class DriverProfileService
{
    protected EmailService $emailService;

    // حقن خدمة الإيميل للتعامل مع روابط التحقق الآمنة
    public function __construct(EmailService $emailService)
    {
        $this->emailService = $emailService;
    }

    /**
     * تحديث بيانات السائق الفورية وتجميد الحساسة للمراجعة
     */
    public function updateDriverProfile(int $userId, array $data): array
    {
        return DB::transaction(function () use ($userId, $data) {
            $user = User::findOrFail($userId); // يعتمد على علاقة $user->driver لا على role_id الثابت
            $driver = $user->driver;

            if (!$driver) {
                throw new Exception("لم يتم العثور على ملف السائق الخاص بهذا المستخدم.");
            }

            // الحقول التي يتم تحديثها فوراً وبشكل مباشر دون موافقة الأدمن
            $userUpdateData = [];

            // هندسة معالجة ورفع ملف الصورة الشخصية فوراً إن وُجدت
            if (!empty($data['avatar_url'])) {
                $userUpdateData['avatar_url'] = $data['avatar_url'];
            } elseif (request()->hasFile('avatar_url') || request()->hasFile('avatar') || request()->hasFile('photo')) {
                $file = request()->file('avatar_url') ?? request()->file('avatar') ?? request()->file('photo');
                if (!empty($user->avatar_url)) {
                    $oldPath = str_replace('storage/', '', $user->avatar_url);
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($oldPath);
                }
                $path = $file->store('drivers/avatars', 'public');
                $userUpdateData['avatar_url'] = 'storage/' . $path;
            }

            if (array_key_exists('alternative_phone', $data)) {
                $userUpdateData['alternative_phone'] = $data['alternative_phone'];
            }

            if (array_key_exists('full_name', $data) && !empty($data['full_name'])) {
                $userUpdateData['full_name'] = $data['full_name'];
            }

            if (array_key_exists('phone_number', $data) && !empty($data['phone_number'])) {
                $userUpdateData['phone_number'] = $data['phone_number'];
            }
            
            if (!empty($data['password'])) {
                $userUpdateData['password_hash'] = Hash::make($data['password']);
            }

            if (array_key_exists('gender', $data) && !empty($data['gender'])) {
                $userUpdateData['gender'] = $data['gender'];
                $driver->update(['gender' => $data['gender']]);
            }

            if (!empty($userUpdateData)) {
                $user->update($userUpdateData);
            }

            $requiresApproval = false;

            // هندسة حماية الحساب: التعديل الآمن لبريد السائق الإلكتروني بدون إضافة أعمدة
            if (!empty($data['email']) && strtolower($data['email']) !== strtolower($user->email)) {
                if (User::where('email', $data['email'])->where('id', '!=', $userId)->exists()) {
                    throw new Exception("عذراً، البريد الإلكتروني الجديد مستخدم بالفعل في حساب آخر.");
                }

                $pendingChanges['email'] = $data['email'];
                $oldValues['email'] = $user->email;
                $requiresApproval = true;

                \Illuminate\Support\Facades\Cache::put("driver_email_change_{$user->id}", $data['email'], now()->addMinutes(30));
                \Illuminate\Support\Facades\Cache::put("driver_email_change_status_{$user->id}", 'pending', now()->addMinutes(30));

                // تمرير الإيميل الجديد داخل الروابط الموقّعة لقراءته عند الضغط عليه
                $approveUrl = URL::temporarySignedRoute('api.driver.email.approve', now()->addMinutes(30), [
                    'id' => $user->id,
                    'new_email' => $data['email']
                ]);
                $rejectUrl  = URL::temporarySignedRoute('api.driver.email.reject', now()->addMinutes(30), [
                    'id' => $user->id
                ]);

                $this->emailService->sendDriverEmailChangeLink(
                    $data['email'], 
                    $user->full_name, 
                    $approveUrl, 
                    $rejectUrl, 
                    $driver->gender
                );    
            }

            return [
                'driver'            => $driver->fresh(['user']),
                'requires_approval' => $requiresApproval,
                'is_email_changed'  => !empty($pendingChanges['email']),
                'pending_email'     => $pendingChanges['email'] ?? null,
                'message'           => $requiresApproval 
                    ? "تم تحديث البيانات، وبرابط التحقق مرسل إلى بريدك الجديد للاعتماد." 
                    : "تم تحديث الملف الشخصي بنجاح."
            ];
        });
    }

    /**
     * تغيير كلمة المرور للسائق: يتطلب كلمة المرور الحالية للتحقق قبل الاعتماد.
     */
    public function changePassword(int $userId, string $oldPassword, string $newPassword): User
    {
        Log::info("Service: Starting changePassword for Driver User ID: {$userId}");

        $user = User::findOrFail($userId);

        if (!Hash::check($oldPassword, $user->password)) {
            Log::warning("Service: changePassword blocked. Wrong current password for Driver User ID: {$userId}");
            throw new Exception("كلمة المرور الحالية غير صحيحة.");
        }

        if (Hash::check($newPassword, $user->password)) {
            Log::warning("Service: changePassword blocked. New password matches current password for Driver User ID: {$userId}");
            throw new Exception("كلمة المرور الجديدة يجب أن تختلف عن كلمة المرور الحالية.");
        }

        $user->password_hash = Hash::make($newPassword);
        $user->save();

        Log::info("Service: Password changed successfully for Driver User ID: {$userId}");

        return $user;
    }

    /**
     * دالة اعتماد البريد الجديد للسائق عبر الرابط الموقّع المفتوح من الإيميل
     */
    public function approveEmailChange(int $userId): bool
    {
        return DB::transaction(function () use ($userId) {
            $user = User::findOrFail($userId); // يعتمد على علاقة $user->driver لا على role_id الثابت
            
            $newEmail = request()->query('new_email') ?? \Illuminate\Support\Facades\Cache::get("driver_email_change_{$userId}");

            if (empty($newEmail)) {
                \Illuminate\Support\Facades\Cache::put("driver_email_change_status_{$userId}", 'expired', now()->addMinutes(15));
                throw new Exception("رابط التأكيد غير صالح أو لا يحتوي على البريد الجديد.");
            }

            $user->email = $newEmail;
            $user->save();

            \Illuminate\Support\Facades\Cache::forget("driver_email_change_{$userId}");
            \Illuminate\Support\Facades\Cache::put("driver_email_change_status_{$userId}", 'verified', now()->addMinutes(15));

            return true;
        });
    }

    /**
     * دالة إلغاء طلب تعديل البريد من الرابط
     */
    public function rejectEmailChange(int $userId): bool
    {
        \Illuminate\Support\Facades\Cache::forget("driver_email_change_{$userId}");
        \Illuminate\Support\Facades\Cache::put("driver_email_change_status_{$userId}", 'rejected', now()->addMinutes(15));
        return true;
    }

    public function cancelEmailChange(int $userId): bool
    {
        \Illuminate\Support\Facades\Cache::forget("driver_email_change_{$userId}");
        \Illuminate\Support\Facades\Cache::forget("driver_email_change_status_{$userId}");
        return true;
    }

    public function resendEmailChange(int $userId): bool
    {
        $cacheKey = "driver_email_change_{$userId}";
        if (!\Illuminate\Support\Facades\Cache::has($cacheKey)) {
            throw new Exception("لا يوجد طلب معلق لتعديل البريد الإلكتروني لإعادة إرساله.");
        }

        $newEmail = \Illuminate\Support\Facades\Cache::get($cacheKey);
        $user = User::findOrFail($userId);
        $driver = $user->driver;

        $approveUrl = URL::temporarySignedRoute('api.driver.email.approve', now()->addMinutes(30), [
            'id' => $user->id,
            'new_email' => $newEmail
        ]);
        $rejectUrl  = URL::temporarySignedRoute('api.driver.email.reject', now()->addMinutes(30), [
            'id' => $user->id
        ]);

        $this->emailService->sendDriverEmailChangeLink(
            $newEmail, 
            $user->full_name, 
            $approveUrl, 
            $rejectUrl, 
            $driver?->gender
        );

        return true;
    }

    /**
     * جلب حالة اعتماد حساب السائق الحالية (للاستخدام أثناء انتظار مراجعة الإدارة)
     */
    public function getDriverStatus(int $userId): array
    {
        $user = User::findOrFail($userId); // يعتمد على علاقة $user->driver لا على role_id الثابت
        $driver = $user->driver;

        if (!$driver) {
            throw new Exception("لم يتم العثور على ملف السائق.");
        }

        $rejectionReason = null;
        if ($driver->status === 'Rejected') {
            $latestApproval = DriverApproval::where('driver_id', $driver->id)
                ->latest('created_at')
                ->first();
            $rejectionReason = $latestApproval->rejection_reason ?? null;
        }

        return [
            'is_active'        => (bool) $user->is_active,
            'driver_status'    => $driver->status,
            'rejection_reason' => $rejectionReason,
        ];
    }

    /**
     * تحديث البيانات القانونية والوثائق الرسمية للسائق
     */
    /**
     * تحديث البيانات القانونية والوثائق الرسمية للسائق وتسجيل الطلب للمراجعة الإدارية
     *
     * @param int $userId
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function updateLegalDocuments(int $userId, array $data): array
    {
        return DB::transaction(function () use ($userId, $data) {
            // 1. التحقق من وجود المستخدم وصلاحية السائق
            $user = User::find($userId); // يعتمد على علاقة $user->driver لا على role_id الثابت

            if (!$user) {
                throw new \Exception("المستخدم غير موجود أو لا يملك صلاحية سائق.", 404);
            }

            $driver = $user->driver;

            if (!$driver) {
                throw new \Exception("لم يتم العثور على ملف السائق الخاص بهذا المستخدم.", 404);
            }

            // 2. تجميد جلب البيانات القديمة للوثائق والمستندات قبل إجراء أي تحديث للربط الدقيق
            $hasDriverDocs = \Illuminate\Support\Facades\Schema::hasTable('driver_documents');
            $docTypeCol = 'doc_type';
            if ($hasDriverDocs) {
                if (\Illuminate\Support\Facades\Schema::hasColumn('driver_documents', 'doc_type')) {
                    $docTypeCol = 'doc_type';
                } elseif (\Illuminate\Support\Facades\Schema::hasColumn('driver_documents', 'document_type')) {
                    $docTypeCol = 'document_type';
                }
            }

            $existingDocs = $hasDriverDocs
                ? DriverDocument::where('driver_id', $driver->id)->get()->keyBy($docTypeCol)
                : collect();

            $oldValues = [
                'national_id'                   => $driver->national_id,
                'license_number'                => $driver->license_number,
                'license_expiry'                => $driver->license_expiry ? (is_string($driver->license_expiry) ? $driver->license_expiry : $driver->license_expiry->format('Y-m-d')) : null,
                'insurance_expiry'              => $existingDocs->get('INSURANCE')?->insurance_expiry_date ?? $existingDocs->get('INSURANCE')?->expires_at,
                'stamp_expiry'                  => $existingDocs->get('STAMP')?->stamp_expiry_date ?? $existingDocs->get('STAMP')?->expires_at,
                'technical_inspection_expiry'   => $existingDocs->get('TECHNICAL_INSPECTION')?->technical_inspection_expiry_date ?? $existingDocs->get('TECHNICAL_INSPECTION')?->expires_at,
                'doc_license_path'              => $driver->license_image_url ?? $existingDocs->get('LICENSE')?->file_url,
                'license_image_url'             => $driver->license_image_url,
                'doc_logbook_path'              => $existingDocs->get('VEHICLE_LOGBOOK')?->file_url,
                'doc_insurance_path'            => $existingDocs->get('INSURANCE')?->file_url,
                'doc_booklet_page_path'         => $existingDocs->get('BOOKLET_PERSONAL_PAGE')?->file_url,
                'doc_stamp_path'                => $existingDocs->get('STAMP')?->file_url,
                'doc_technical_inspection_path' => $existingDocs->get('TECHNICAL_INSPECTION')?->file_url,
            ];

            // فلترة القيم القديمة لإبقاء فقط العناصر التي تم إرسال تعديل لها في $data
            $filteredOldValues = array_intersect_key($oldValues, $data);

            // 3. تحديث البيانات الأساسية لملف السائق (الرقم القومي والرخصة)
            $driverUpdate = [];
            foreach (['national_id', 'license_number', 'license_expiry'] as $field) {
                if (array_key_exists($field, $data)) {
                    $driverUpdate[$field] = $data[$field];
                }
            }

            if (!empty($data['doc_license_path'])) {
                $driverUpdate['license_image_url'] = $data['doc_license_path'];
            } elseif (!empty($data['license_image_url'])) {
                $driverUpdate['license_image_url'] = $data['license_image_url'];
            }

            if (array_key_exists('license_expiry', $data)) {
                $driverUpdate['license_expiry_notified_milestone'] = null;

                if ($hasDriverDocs) {
                    DriverDocument::where('driver_id', $driver->id)
                        ->where($docTypeCol, 'LICENSE')
                        ->where('status', 'Expired')
                        ->update(['status' => 'Pending']);
                }
            }

            if (!empty($driverUpdate)) {
                $updated = $driver->update($driverUpdate);
                if (!$updated) {
                    throw new \Exception("حدث خطأ أثناء تحديث البيانات الأساسية للسائق.", 500);
                }
            }

            // 4. معالجة الوثائق والمستندات المرفوعة
            $docMap = [
                'doc_license_path'              => 'LICENSE',
                'doc_logbook_path'               => 'VEHICLE_LOGBOOK',
                'doc_insurance_path'             => 'INSURANCE',
                'doc_booklet_page_path'          => 'BOOKLET_PERSONAL_PAGE',
                'doc_stamp_path'                 => 'STAMP',
                'doc_technical_inspection_path'  => 'TECHNICAL_INSPECTION',
            ];

            $expiryFieldMap = [
                'INSURANCE'            => ['input' => 'insurance_expiry',             'column' => 'insurance_expiry_date'],
                'STAMP'                => ['input' => 'stamp_expiry',                 'column' => 'stamp_expiry_date'],
                'TECHNICAL_INSPECTION' => ['input' => 'technical_inspection_expiry',  'column' => 'technical_inspection_expiry_date'],
            ];

            $vehicle = $driver->vehicles()->first();
            $vehicleDocTypeMap = [
                'LICENSE'                  => null, // رخصة القيادة تُحفظ في جدول drivers
                'VEHICLE_LOGBOOK'          => 'LOGBOOK',
                'INSURANCE'                => 'INSURANCE',
                'TECHNICAL_INSPECTION'     => 'INSPECTION',
                'STAMP'                    => 'OPERATING_PERMIT',
                'BOOKLET_PERSONAL_PAGE'    => 'OPERATING_PERMIT',
            ];

            $v2DocTypeMap = [
                'LICENSE'                  => 'vehicle_license',
                'VEHICLE_LOGBOOK'          => 'vehicle_license',
                'INSURANCE'                => 'insurance',
                'TECHNICAL_INSPECTION'     => 'technical_inspection',
                'STAMP'                    => 'technical_inspection',
                'BOOKLET_PERSONAL_PAGE'    => 'national_id',
            ];

            $driverDocPendingStatus = ($docTypeCol === 'document_type') ? 'pending' : 'Pending';

            foreach ($docMap as $pathKey => $docType) {
                if (!empty($data[$pathKey])) {
                    $updateFields = [
                        'file_url'    => $data[$pathKey],
                        'status'      => $driverDocPendingStatus,
                    ];

                    if (\Illuminate\Support\Facades\Schema::hasColumn('driver_documents', 'uploaded_at')) {
                        $updateFields['uploaded_at'] = now();
                    }

                    $docExpiry = null;
                    if (isset($expiryFieldMap[$docType])) {
                        if (\Illuminate\Support\Facades\Schema::hasColumn('driver_documents', 'expiry_notified_milestone')) {
                            $updateFields['expiry_notified_milestone'] = null;
                        }
                        $expiryInput = $expiryFieldMap[$docType]['input'];
                        if (array_key_exists($expiryInput, $data)) {
                            $docExpiry = $data[$expiryInput];
                            $specCol = $expiryFieldMap[$docType]['column'];
                            if (\Illuminate\Support\Facades\Schema::hasColumn('driver_documents', $specCol)) {
                                $updateFields[$specCol] = $docExpiry;
                            }
                            if (\Illuminate\Support\Facades\Schema::hasColumn('driver_documents', 'expires_at')) {
                                $updateFields['expires_at'] = $docExpiry;
                            }
                        }
                    }

                    if ($hasDriverDocs) {
                        $actualDocType = ($docTypeCol === 'document_type') ? ($v2DocTypeMap[$docType] ?? 'vehicle_license') : $docType;
                        DriverDocument::updateOrCreate(
                            ['driver_id' => $driver->id, $docTypeCol => $actualDocType],
                            $updateFields
                        );
                    }

                    // تحديث الوثيقة المحددة في جدول vehicle_documents وإسناد حالتها إلى "معلقة" (Pending)
                    $targetVehicleDocType = $vehicleDocTypeMap[$docType] ?? null;
                    if ($vehicle && $targetVehicleDocType && \Illuminate\Support\Facades\Schema::hasTable('vehicle_documents')) {
                        $vDocData = [
                            'file_url'    => $data[$pathKey],
                            'is_verified' => false,
                            'state'       => \App\Models\Driver\VehicleDocument::STATE_PENDING,
                        ];
                        if ($docExpiry) {
                            $vDocData['expiry_date'] = $docExpiry;
                        }

                        \App\Models\Driver\VehicleDocument::updateOrCreate(
                            ['vehicle_id' => $vehicle->id, 'doc_type' => $targetVehicleDocType],
                            $vDocData
                        );
                    }
                }
            }

            // 5. تحديث تواريخ الانتهاء المستقلة للوثائق
            foreach ($expiryFieldMap as $docType => $map) {
                $pathKey = array_search($docType, $docMap, true);
                if (array_key_exists($map['input'], $data) && empty($data[$pathKey])) {
                    if ($hasDriverDocs) {
                        $docUpdatePayload = ['status' => $driverDocPendingStatus];
                        if (\Illuminate\Support\Facades\Schema::hasColumn('driver_documents', $map['column'])) {
                            $docUpdatePayload[$map['column']] = $data[$map['input']];
                        }
                        if (\Illuminate\Support\Facades\Schema::hasColumn('driver_documents', 'expires_at')) {
                            $docUpdatePayload['expires_at'] = $data[$map['input']];
                        }
                        if (\Illuminate\Support\Facades\Schema::hasColumn('driver_documents', 'expiry_notified_milestone')) {
                            $docUpdatePayload['expiry_notified_milestone'] = null;
                        }

                        $actualDocType = ($docTypeCol === 'document_type') ? ($v2DocTypeMap[$docType] ?? 'vehicle_license') : $docType;
                        DriverDocument::where('driver_id', $driver->id)
                            ->where($docTypeCol, $actualDocType)
                            ->update($docUpdatePayload);
                    }

                    $targetVehicleDocType = $vehicleDocTypeMap[$docType] ?? null;
                    if ($vehicle && $targetVehicleDocType && \Illuminate\Support\Facades\Schema::hasTable('vehicle_documents')) {
                        \App\Models\Driver\VehicleDocument::where('vehicle_id', $vehicle->id)
                            ->where('doc_type', $targetVehicleDocType)
                            ->update([
                                'expiry_date' => $data[$map['input']],
                                'is_verified' => false,
                                'state'       => \App\Models\Driver\VehicleDocument::STATE_PENDING,
                            ]);
                    }
                }
            }

            // 6. تنظيف البيانات وتجهيز السجل للمراجعة الإدارية
            $cleanNewValues = array_filter($data, fn ($val) => !($val instanceof \Illuminate\Http\UploadedFile));

            if (empty($cleanNewValues)) {
                return [
                    'change_id' => null,
                    'message'   => 'لم يتم إرسال أي بيانات أو وثائق للتحديث.'
                ];
            }

            $changeId = DB::table('driver_profile_changes')->insertGetId([
                'driver_id'  => $driver->id,
                'old_values' => json_encode($filteredOldValues, JSON_UNESCAPED_UNICODE),
                'new_values' => json_encode($cleanNewValues, JSON_UNESCAPED_UNICODE),
                'status'     => 'Pending',
                'created_at' => now()
            ]);

            if (!$changeId) {
                throw new \Exception("فشل في تسجيل طلب التعديل في النظام.", 500);
            }

            // 7. إرسال الإشعار للإدارة
            try {
                $admins = User::whereIn('role_id', [1, 2])->get();
                if ($admins->isNotEmpty()) {
                    app(NotificationService::class)->sendToUsers($admins, 'driver_documents_updated', [
                        'title'       => 'تحديث وثائق رسمية للسائق 📄',
                        'message'     => "قام السائق ({$user->full_name}) بتحديث وثائقه الرسمية وبانتظار المراجعة.",
                        'driver_name' => $user->full_name,
                        'entity_id'   => (string) $changeId,
                    ], withPush: true);
                }
            } catch (\Throwable $e) {
                Log::warning("فشل إرسال إشعار تحديث الوثائق للأدمن: " . $e->getMessage());
            }

            return [
                'change_id'         => $changeId,
                'status'            => 'pending',
                'state'             => 'pending',
                'state_label'       => 'معلقة',
                'document_status'   => 'pending',
                'requires_approval' => true,
                'message'           => 'تم تحديث البيانات القانونية والوثائق بنجاح، وهي قيد المراجعة (معلقة) من قبل الإدارة.'
            ];
        });
    }
    

    /**
     * تحديث بيانات مركبة محددة مع التحقق من ملكيتها للسائق
     */
    public function updateVehicleDetails(int $userId, int $vehicleId, array $data): Vehicle
    {
        return DB::transaction(function () use ($userId, $vehicleId, $data) {
            $user = User::findOrFail($userId); // يعتمد على علاقة $user->driver لا على role_id الثابت
            $driver = $user->driver;

            if (!$driver) {
                throw new Exception("لم يتم العثور على ملف السائق.");
            }

            $vehicle = null;
            if ($vehicleId > 0) {
                $vehicle = Vehicle::where('driver_id', $driver->id)->where('id', $vehicleId)->first();
            }
            if (!$vehicle) {
                $vehicle = $driver->vehicles()->first();
            }

            if (!$vehicle) {
                $vehicle = Vehicle::create([
                    'driver_id' => $driver->id,
                    'status'    => 'Pending',
                ]);
            }

            $cleanNewValues = array_filter($data, fn ($val) => !($val instanceof \Illuminate\Http\UploadedFile));
            $cleanNewValues['vehicle_id'] = $vehicle->id;

            $vehicleFieldsMap = [
                'plate_number'      => $vehicle->plate_number,
                'brand'             => $vehicle->brand,
                'model'             => $vehicle->model,
                'year'              => $vehicle->year,
                'color'             => $vehicle->color,
                'type'              => $vehicle->type,
                'capacity_manual'   => $vehicle->capacity_manual,
                'has_ac'            => (bool) $vehicle->has_ac,
                'vehicle_image_url' => $vehicle->vehicle_image_url,
                'vehicle_image_path'=> $vehicle->vehicle_image_url,
            ];

            // فلترة القيم القديمة لتقتصر حصراً على الحقول التي تم تعديلها فعلياً في الطلب
            $oldValues = [];
            foreach ($cleanNewValues as $key => $val) {
                if ($key === 'vehicle_id') {
                    continue;
                }
                if (array_key_exists($key, $vehicleFieldsMap)) {
                    $oldValues[$key] = $vehicleFieldsMap[$key];
                }
            }

            // في حال تعديل صورة المركبة بأي مسمى، نضمن توفير القيمة القديمة للصورة لمقارنتها
            if (array_key_exists('vehicle_image_path', $cleanNewValues) || array_key_exists('vehicle_image_url', $cleanNewValues)) {
                $oldValues['vehicle_image_url'] = $vehicle->vehicle_image_url;
                if (array_key_exists('vehicle_image_path', $cleanNewValues)) {
                    $oldValues['vehicle_image_path'] = $vehicle->vehicle_image_url;
                }
            }

            // 🚗 تحويل حالة المركبة في قاعدة البيانات إلى Pending لحين اعتماد الإدارة
            $vehicle->update(['status' => 'Pending']);

            // 🚗 طلب تعديل المركبة وصورتها يسجل بحالة معلقة Pending لمراجعة الإدارة
            $changeId = DB::table('driver_profile_changes')->insertGetId([
                'driver_id'  => $driver->id,
                'old_values' => json_encode($oldValues, JSON_UNESCAPED_UNICODE),
                'new_values' => json_encode($cleanNewValues, JSON_UNESCAPED_UNICODE),
                'status'     => 'Pending',
                'created_at' => now(),
            ]);

            // إرسال إشعار للمشرفين
            try {
                $admins = User::whereIn('role_id', [1, 2])->get();
                app(NotificationService::class)->sendToUsers($admins, 'driver_vehicle_updated', [
                    'title'       => 'طلب تعديل بيانات مركبة 🚗',
                    'message'     => "قام السائق ({$user->full_name}) بطلب تعديل بيانات مركبته وبانتظار مراجعة الإدارة.",
                    'driver_name' => $user->full_name,
                    'entity_id'   => (string) $changeId,
                ], withPush: true);
            } catch (\Throwable $e) {
                Log::warning("Failed to notify admins of vehicle update: " . $e->getMessage());
            }

            return $vehicle;
        });
    }

   /**
     * إرجاع قائمة مختصرة لجميع سيارات السائق
     */
   public function getDriverVehiclesSummary(int $userId)
   {
       // 1. جلب سجل السائق المرتبط بهذا المستخدم
       $driver = Driver::where('user_id', $userId)->first();

       // في حال لم يتم العثور على ملف سائق لهذا المستخدم
       if (!$driver) {
           return [];
       }

       // 2. البحث برقم السائق ($driver->id) الصحيح بدلاً من ($userId)
       return Vehicle::where('driver_id', $driver->id)
           ->select(['id', 'brand', 'model', 'vehicle_image_url'])
           ->get()
           ->map(function ($vehicle) {
               return [
                   'id'        => $vehicle->id,
                   'name'      => $vehicle->brand,
                   'model'     => $vehicle->model,
                   'image_url' => $vehicle->vehicle_image_url ? asset($vehicle->vehicle_image_url) : null,
               ];
           });
   }

   /**
     * جلب تفاصيل سيارة محددة مع التأكد من ملكيتها للسائق الحالي
     */
   public function getVehicleDetails(int $userId, int $vehicleId)
   {
       // 1. جلب ملف السائق أولاً للحصول على driver_id الصحيح
       $driver = Driver::where('user_id', $userId)->first();

       if (!$driver) {
           return null;
       }

       // 2. البحث برقم السائق ($driver->id) بدلاً من رقم المستخدم ($userId)
       $vehicle = Vehicle::where('driver_id', $driver->id)
           ->where('id', $vehicleId)
           ->first();

       if (!$vehicle) {
           return null;
       }

       // 3. تحويل مسار الصورة إلى URL كامل
       $vehicle->vehicle_image_url = $vehicle->vehicle_image_url ? asset($vehicle->vehicle_image_url) : null;

       return $vehicle;
   }
}