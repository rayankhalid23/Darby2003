<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Driver\ProfileUpdateRequest;
use App\Http\Requests\Api\Driver\UpdateLegalDocumentsRequest;
use App\Services\Driver\DriverProfileService;
use App\Http\Resources\Api\Driver\DriverResource;
use App\Http\Resources\Api\Driver\DriverProfileResource;
use App\Http\Controllers\Api\Shared\MediaController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class ProfileController extends Controller
{
    protected DriverProfileService $profileService;

    public function __construct(DriverProfileService $profileService)
    {
        $this->profileService = $profileService;
    }

    /**
     * 1. تحديث بيانات الملف الشخصي للسائق (PATCH/POST/PUT)
     */
    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $uploadedPaths = [];
        try {
            $user = auth()->user();
            $validatedData = $request->validated();

            if ($request->hasFile('avatar')) {
                if ($user->avatar_url) {
                    $oldPath = str_replace('storage/', '', $user->avatar_url);
                    Storage::disk('public')->delete($oldPath);
                }

                $avatarPath = $request->file('avatar')->store('drivers/avatars', 'public');
                $validatedData['avatar_url'] = 'storage/' . $avatarPath; 
                $uploadedPaths[] = $avatarPath;
            }

            $result = $this->profileService->updateDriverProfile($user->id, $validatedData);

            $isEmailChanged = !empty($result['is_email_changed']);

            $message = $isEmailChanged 
                ? 'Profile updated successfully. Please verify your new email.' 
                : 'Profile updated successfully.';

            $emailVerificationData = $isEmailChanged ? [
                'status'    => 'pending',
                'new_email' => $result['pending_email'] ?? null,
            ] : null;

            return response()->json([
                'success' => true,
                'message' => $message,
                'data'    => [
                    'profile'            => new DriverResource($result['driver']),
                    'email_verification' => $emailVerificationData,
                ]
            ], 200);

        } catch (Exception $e) {
            foreach ($uploadedPaths as $path) {
                Storage::disk('public')->delete($path);
            }

            Log::error("Driver Profile Update Error: " . $e->getMessage(), ['user_id' => auth()->id()]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'تعذر تحديث البيانات بسبب مشكلة تقنية.',
            ], 500);
        }
    }

    /**
     * دالة فحص حالة تغيير البريد الإلكتروني للسائق
     * GET /api/v1/driver/profile/email-change/status
     */
    public function checkEmailChangeStatus(Request $request): JsonResponse
    {
        try {
            $userId = $request->user()->id;
            $statusKey = "driver_email_change_status_{$userId}";
            $pendingKey = "driver_email_change_{$userId}";

            $status = \Illuminate\Support\Facades\Cache::get($statusKey);

            if (!$status) {
                if (\Illuminate\Support\Facades\Cache::has($pendingKey)) {
                    $status = 'pending';
                } else {
                    $status = 'expired';
                }
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'status' => $status
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error("Driver Check Email Status Error: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * دالة إلغاء تغيير البريد الإلكتروني للسائق
     * POST /api/v1/driver/profile/email-change/cancel
     */
    public function cancelEmailChange(Request $request): JsonResponse
    {
        try {
            $this->profileService->cancelEmailChange($request->user()->id);

            return response()->json([
                'success' => true,
                'message' => 'Email change cancelled.'
            ], 200);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * دالة إعادة إرسال رسالة التحقق للبريد الجديد للسائق
     * POST /api/v1/driver/profile/email-change/resend
     */
    public function resendEmailChange(Request $request): JsonResponse
    {
        try {
            $this->profileService->resendEmailChange($request->user()->id);

            return response()->json([
                'success' => true,
                'message' => 'Verification email resent successfully.'
            ], 200);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * دالة عرض حالة اعتماد حساب السائق فقط (خفيفة، مخصصة لشاشة الانتظار بعد التسجيل)
     * GET /api/v1/driver/status
     */
    public function status(Request $request): JsonResponse
    {
        try {
            $data = $this->profileService->getDriverStatus($request->user()->id);

            return response()->json(array_merge(['status' => true], $data), 200);

        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage() ?: 'تعذر جلب حالة الحساب.'
            ], 404);
        }
    }

    /**
     * 2. عرض بيانات الملف الشخصي للسائق بالكامل مع ملحقاته
     */
    public function show(): JsonResponse
    {
        try {
            $user = auth()->user();
            
            $driver = $user->driver()->with(['vehicles', 'documents'])->first();

            if (!$driver) {
                return response()->json([
                    'success' => false,
                    'message' => 'عذراً، هذا الحساب غير مرتبط بملف سائق نشط على النظام.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'تم جلب البيانات بنجاح.',
                'data'    => new DriverProfileResource($driver)
            ], 200);

        } catch (Exception $e) {
            Log::error("Driver Profile Fetch Error: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'حدث خطأ أثناء جلب الملف الشخصي.'], 500);
        }
    }

    /**
     * 3. تفعيل واعتماد البريد الإلكتروني الجديد من الرابط الموقّع
     */
    public function approveEmailChange(int $id): JsonResponse
    {
        try {
            $this->profileService->approveEmailChange($id);
            
            return response()->json([
                'success'       => true,
                'email_changed' => true,
                'message'       => 'تم تأكيد وتحديث البريد الإلكتروني بنجاح في النظام.'
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'رابط التأكيد غير صالح أو منتهي الصلاحية.'
            ], 400);
        }
    }

    /**
     * 4. إلغاء طلب تعديل البريد الإلكتروني القادم من الرابط الموقّع
     */
    public function rejectEmailChange(int $id): JsonResponse
    {
        try {
            $this->profileService->rejectEmailChange($id);
            
            return response()->json([
                'success'       => true,
                'email_changed' => false,
                'message'       => 'تم إلغاء طلب تغيير البريد الإلكتروني بنجاح.'
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * 5. تحديث وتجديد المستندات والوثائق الرسمية للسائق
     */
    public function updateLegalData(UpdateLegalDocumentsRequest $request): JsonResponse
    {
        $uploadedPaths = [];
        try {
            $data = $request->validated();

            $docFileMap = [
                'doc_license'              => 'doc_license_path',
                'doc_logbook'              => 'doc_logbook_path',
                'doc_insurance'            => 'doc_insurance_path',
                'doc_booklet_page'         => 'doc_booklet_page_path',
                'doc_stamp'                => 'doc_stamp_path',
                'doc_technical_inspection' => 'doc_technical_inspection_path',
            ];

            foreach ($docFileMap as $fileField => $pathKey) {
                if ($request->hasFile($fileField)) {
                    $path = $request->file($fileField)->store('drivers/documents', 'public');
                    $data[$pathKey] = 'storage/' . $path;
                    $uploadedPaths[] = $path;
                }
            }

            $user = auth()->user();
            $result = $this->profileService->updateLegalDocuments($user->id, $data);

            return response()->json([
                'status'  => true,
                'message' => $result['message']
            ], 200);

        } catch (Exception $e) {
            foreach ($uploadedPaths as $path) {
                Storage::disk('public')->delete($path);
            }
            Log::error("Driver Legal Data Update Error: " . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage() ?: 'حدث خطأ أثناء تحديث البيانات القانونية.'
            ], 500);
        }
    }

    /**
     * 6. تحديث بيانات وتفاصيل المركبة وتجميدها لحين مراجعة الأدمن
     */
    public function updateVehicle(Request $request, $vehicleId = null): JsonResponse
    {
        $uploadedPaths = [];
        try {
            if ($request->hasFile('vehicle_photo') && !$request->hasFile('vehicle_image')) {
                $request->files->set('vehicle_image', $request->file('vehicle_photo'));
            }
            if ($request->filled('vehicle_type') && !$request->filled('type')) {
                $request->merge(['type' => $request->input('vehicle_type')]);
            }

            $validatedData = $request->validate([
                'has_ac'          => 'sometimes|nullable|boolean',
                'plate_number'    => 'sometimes|nullable|string|max:20',
                'brand'           => 'sometimes|nullable|string|max:50',
                'model'           => 'sometimes|nullable|string|max:50',
                'year'            => 'sometimes|nullable|integer|min:2000|max:' . (date('Y') + 1),
                'color'           => 'sometimes|nullable|string|max:30',
                'type'            => 'sometimes|nullable|string|max:30',
                'capacity_manual' => 'sometimes|nullable|integer|min:1|max:60',
                'vehicle_image'   => 'sometimes|nullable|file|mimes:jpeg,png,jpg,webp,heic,heif|max:10240',
            ]);

            $vehicleFile = $request->file('vehicle_image') ?? $request->file('vehicle_photo');
            if ($vehicleFile) {
                $path = $vehicleFile->store('drivers/vehicles', 'public');
                $validatedData['vehicle_image_path'] = 'storage/' . $path;
                $uploadedPaths[] = $path;
            }

            $user = auth()->user();
            $targetVehicleId = $vehicleId ? (int)$vehicleId : ($user->driver?->vehicles()->first()?->id ?? 0);
            $vehicle = $this->profileService->updateVehicleDetails($user->id, $targetVehicleId, $validatedData);

            $vehicleImageUrl = MediaController::urlFor($vehicle->vehicle_image_url);

            return response()->json([
                'status'  => true,
                'message' => 'تم تحديث تفاصيل المركبة بنجاح، وهي قيد المراجعة والتدقيق الآن من قبل الإدارة.',
                'data'    => [
                    'id'                => $vehicle->id,
                    'plate_number'      => $vehicle->plate_number,
                    'brand'             => $vehicle->brand,
                    'model'             => $vehicle->model,
                    'year'              => $vehicle->year,
                    'color'             => $vehicle->color,
                    'type'              => $vehicle->type,
                    'capacity_manual'   => $vehicle->capacity_manual,
                    'vehicle_image_url' => $vehicleImageUrl,
                    'has_ac'            => (bool) $vehicle->has_ac,
                    'status'            => $vehicle->status,
                    'is_verified'       => (bool) $vehicle->is_verified,
                ]
            ], 200);

        } catch (Exception $e) {
            foreach ($uploadedPaths as $path) {
                Storage::disk('public')->delete($path);
            }
            Log::error("Driver Vehicle Update Error: " . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage() ?: 'حدث خطأ أثناء تحديث بيانات المركبة.'
            ], 500);
        }
    }
/**
 * عرض المستندات الحالية للسائق مع ملفات الوثائق المرفوعة
 * بناءً على جداول النظام الموضحة في image_075cae.png و image_075cae.png
 */
public function showLegalData(Request $request)
{
    // جلب المستخدم وتحميل بيانات السائق والوثائق المرتبطة به دفعة واحدة
    $user = $request->user()->load(['driver.documents']);

    // التحقق من وجود حساب سائق
    if (!$user->driver) {
        return response()->json([
            'status'  => false,
            'message' => 'لم يتم العثور على بيانات رسمية لهذا الحساب.'
        ], 404);
    }

    $documents = $user->driver->documents ?? collect();

    // بناء روابط ومصفوفات مخصصة وسهلة للواجهة الأمامية
    $documentsMap = [];
    $uploadedFiles = $documents->map(function ($document) use (&$documentsMap) {
        $fileUrl = MediaController::urlFor($document->file_url);

        if ($document->doc_type && $fileUrl) {
            $documentsMap[$document->doc_type] = $fileUrl;
        }

        return [
            'id'                               => $document->id,
            'vehicle_id'                       => $document->vehicle_id,
            'doc_type'                         => $document->doc_type,
            'file_url'                         => $fileUrl,
            'expiry_date'                      => $document->expiry_date ? \Carbon\Carbon::parse($document->expiry_date)->format('Y-m-d') : null,
            'is_verified'                      => (bool) $document->is_verified,
            'state'                            => $document->state ?? 'pending',
            'state_label'                      => $document->state_label ?? 'معلقة',
            'license_expiry_date'              => $document->license_expiry_date ?? ($document->expiry_date ? \Carbon\Carbon::parse($document->expiry_date)->format('Y-m-d') : null),
            'insurance_expiry_date'            => $document->insurance_expiry_date ?? ($document->expiry_date ? \Carbon\Carbon::parse($document->expiry_date)->format('Y-m-d') : null),
            'stamp_expiry_date'                => $document->stamp_expiry_date,
            'technical_inspection_expiry_date' => $document->technical_inspection_expiry_date,
            'document_status'                  => $document->state ?? ($document->status ?? 'pending'),
            'feedback'                         => $document->feedback,
            'uploaded_at'                      => $document->uploaded_at 
                ? \Carbon\Carbon::parse($document->uploaded_at)->toDateTimeString() 
                : ($document->created_at ? $document->created_at->toDateTimeString() : null),
        ];
    });

    return response()->json([
        'status'  => true,
        'message' => 'تم استرجاع الوثائق الرسمية بنجاح.',
        'data'    => [
            // 1. البيانات الأساسية من جدول drivers
            'national_id'    => $user->driver->national_id,
            'license_number' => $user->driver->license_number,
            'license_expiry' => $user->driver->license_expiry,
            'driver_status'  => $user->driver->status, // حالة السائق العامة

            // 2. قائمة الوثائق الكاملة
            'uploaded_files' => $uploadedFiles,

            // 3. خريطة مباشرة للوصول للوثائق حسب نوعها لدعم تطبيقات الموبايل
            'documents_map'  => $documentsMap,

            // 4. اختصارات مباشرة للروابط الأكثر استخداماً في الفرونت
            'doc_license_url'   => $documentsMap['LICENSE'] ?? null,
            'doc_logbook_url'   => $documentsMap['VEHICLE_LOGBOOK'] ?? null,
            'doc_insurance_url' => $documentsMap['INSURANCE'] ?? null,
        ]
    ], 200);
}

/**
 * عرض بيانات وتفاصيل المركبة الحالية للسائق
 * بناءً علىโครงสร้าง جدول vehicles في image_074e49.png
 */
public function showVehicle(Request $request)
{
    // جلب المستخدم وتحميل السائق مع مركبته (Nested Eager Loading)
    $user = $request->user()->load('driver.vehicle');

    // التحقق من وجود السائق ووجود مركبة مرتبطة به
    if (!$user->driver || !$user->driver->vehicle) {
        return response()->json([
            'status'     => false,
            'error_code' => 'VEHICLE_NOT_FOUND',
            'message'    => 'لم يتم تسجيل أي مركبة لهذا السائق حتى الآن.'
        ], 404);
    }

    $vehicle = $user->driver->vehicle;

    return response()->json([
        'status'  => true,
        'message' => 'تم استرجاع بيانات المركبة بنجاح.',
        'data'    => [
            'id'                => $vehicle->id,
            'plate_number'      => $vehicle->plate_number,
            'brand'             => $vehicle->brand,
            'model'             => $vehicle->model,
            'year'              => $vehicle->year,
            'color'             => $vehicle->color,
            'type'              => $vehicle->type,
            'capacity_manual'   => $vehicle->capacity_manual,
            'capacity_ai'       => $vehicle->capacity_ai,
            'is_verified'       => (bool) $vehicle->is_verified,
            'vehicle_image_url' => MediaController::urlFor($vehicle->vehicle_image_url),
            'has_ac'            => (bool) $vehicle->has_ac,
            'status'            => $vehicle->status,
        ]
    ], 200);
}
    
}