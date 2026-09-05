<?php

namespace App\Services\Driver;

use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Driver\Vehicle;
use App\Models\Driver\DriverDocument;
use App\Jobs\SendDriverOtpEmailJob;
use App\Services\Shared\EmailService;
use App\Services\Shared\OtpService;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Exception;

class DriverRegisterService
{
    protected EmailService $emailService;
    protected OtpService $otpService;
    protected NotificationService $notificationService;

    public function __construct(EmailService $emailService, OtpService $otpService, NotificationService $notificationService)
    {
        $this->emailService = $emailService;
        $this->otpService = $otpService;
        $this->notificationService = $notificationService;
    }

    /**
     * الخطوة 1: إرسال الـ OTP قبل إنشاء الحساب لحماية قاعدة البيانات من السجلات الوهمية
     */
    /**
     * الخطوة 1: إرسال الـ OTP قبل إنشاء الحساب لحماية قاعدة البيانات من السجلات الوهمية
     */
    public function sendVerificationOtp(array $data): bool
    {
        // توليد الرمز وربطه بالبريد الإلكتروني في جدول الـ OTP المؤقت
        $otpResult = $this->otpService->generate($data['email'], 'REGISTER');
        if (!$otpResult['success']) {
            throw new \Exception($otpResult['message']);
        }
        $otpCode = $otpResult['code'];

        // 🚀 الحل الأسرع: استخدام dispatchSync لتنفيذ الإرسال فوراً وبشكل لحظي بدلاً من الانتظار في الـ Queue
        SendDriverOtpEmailJob::dispatchSync(
            $data['email'], 
            $data['full_name'], 
            $otpCode, 
            4, // role_id الخاص بالسائق
            $data['gender'] ?? null,
            'REGISTER'
        );

        return true;
    }

    /**
     * الخطوة 2: يتم استدعاء هذه الدالة فقط "بعد" نجاح التحقق من الـ OTP في الـ Controller
     */
    public function registerAccountAfterOtp(array $data): User
    {
        return DB::transaction(function () use ($data) {
            // 1. إنشاء المستخدم وتفعيله مباشرة لأن الـ OTP تم التحقق منه
            $user = User::create([
                'full_name'         => $data['full_name'],
                'email'             => $data['email'],
                'phone_number'      => $data['phone_number'],
                'alternative_phone' => $data['alternative_phone'] ?? null,
                'password_hash'     => Hash::make($data['password']),
                'gender'            => $data['gender'] ?? null,
                'role_id'           => 4,
                'is_active'         => 0, // معلّق — يتفعل فقط عند موافقة الأدمن
            ]);

            // 2. إنشاء ملف السائق الأساسي
            Driver::create([
                'user_id' => $user->id,
                'status'  => 'Offline',
            ]);

            // 3. تسجيل جهاز السائق فقط إذا تم إرسال fcm_token حقيقي (fcm_token فريد عالمياً في الجدول،
            //    ولا معنى لإدراج توكن وهمي؛ التسجيل الرسمي للجهاز يتم عبر POST /api/user/device-token بعد تسجيل الدخول)
            if (!empty($data['fcm_token'])) {
                $deviceData = [
                    'user_id'     => $user->id,
                    'device_name' => $data['device_name'] ?? 'mobile_device',
                ];
                if (\Illuminate\Support\Facades\Schema::hasColumn('user_devices', 'device_id')) {
                    $deviceData['device_id'] = $data['device_id'] ?? null;
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('user_devices', 'platform')) {
                    $deviceData['platform'] = in_array(strtolower($data['platform'] ?? ''), ['ios', 'android', 'web']) ? strtolower($data['platform']) : 'web';
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('user_devices', 'is_active')) {
                    $deviceData['is_active'] = true;
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('user_devices', 'last_active_at')) {
                    $deviceData['last_active_at'] = Carbon::now();
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('user_devices', 'created_at')) {
                    $deviceData['created_at'] = Carbon::now();
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('user_devices', 'updated_at')) {
                    $deviceData['updated_at'] = Carbon::now();
                }

                DB::table('user_devices')->updateOrInsert(
                    ['fcm_token' => $data['fcm_token']],
                    $deviceData
                );
            }

            return $user;
        });
    }

    /**
     * المرحلة الثانية: إكمال بيانات السائق (المركبة والوثائق) كما هي
     */
    public function completeProfile(int $userId, array $data): Driver
    {
        $user = User::with('driver')->findOrFail($userId);
        $driver = $user->driver;

        if (!$driver) {
            throw new Exception("لم يتم العثور على ملف السائق.");
        }

        // المعاملة محصورة في تحديث الجداول فقط لتستغرق بضع ميلي ثوانٍ
        DB::transaction(function () use ($driver, $data) {
            // 1. تحديث بيانات السائق ورخصته
            $driver->update([
                'national_id'       => $data['national_id'],
                'license_number'    => $data['license_number'],
                'license_expiry'    => $data['license_expiry'],
                'license_image_url' => $data['doc_license_path'] ?? null,
                'status'            => 'Pending',
            ]);

            // 2. إنشاء المركبة
            $vehicle = Vehicle::create([
                'driver_id'         => $driver->id,
                'plate_number'      => $data['plate_number'],
                'brand'             => $data['brand'],
                'model'             => $data['model'],
                'year'              => $data['year'],
                'color'             => $data['color'],
                'type'              => $data['type'],
                'capacity_manual'   => $data['capacity_manual'],
                'vehicle_image_url' => $data['vehicle_image_path'],
                'has_ac'            => $data['has_ac'] ?? true,
                'status'            => 'Active',
            ]);

            // 3. إدخال وثائق المركبة (في جدول vehicle_documents المطبع الجديد)
            $vehicleDocs = [
                'LOGBOOK' => [
                    'file_url'    => $data['doc_logbook_path'] ?? null,
                    'expiry_date' => null,
                ],
                'INSURANCE' => [
                    'file_url'    => $data['doc_insurance_path'] ?? null,
                    'expiry_date' => $data['insurance_expiry'] ?? null,
                ],
                'INSPECTION' => [
                    'file_url'    => $data['doc_technical_inspection_path'] ?? null,
                    'expiry_date' => $data['technical_inspection_expiry'] ?? null,
                ],
                'OPERATING_PERMIT' => [
                    'file_url'    => $data['doc_booklet_page_path'] ?? $data['doc_stamp_path'] ?? null,
                    'expiry_date' => $data['stamp_expiry'] ?? null,
                ],
            ];

            foreach ($vehicleDocs as $docType => $docFields) {
                if (!empty($docFields['file_url'])) {
                    \App\Models\Driver\VehicleDocument::create([
                        'vehicle_id'  => $vehicle->id,
                        'doc_type'    => $docType,
                        'file_url'    => $docFields['file_url'],
                        'expiry_date' => $docFields['expiry_date'],
                        'is_verified' => false,
                    ]);
                }
            }

            // 4. إنشاء سجل طلب الاعتماد في جدول driver_approvals الموحد
            \App\Models\Driver\DriverApproval::create([
                'driver_id'    => $driver->id,
                'request_type' => 'Registration',
                'status'       => 'Pending',
                'new_values'   => [
                    'vehicle_id'     => $vehicle->id,
                    'license_number' => $data['license_number'],
                    'plate_number'   => $data['plate_number'],
                ],
            ]);
        });

        // إرسال إشعار الإدارة خارج المعاملة لحماية قاعدة البيانات من أي بطء في الإشعارات
        try {
            $admins = \App\Models\User::whereIn('role_id', [1, 2])->get();
            // withPush: false — إشعارات الأدمن عبر DB + polling فقط، بلا Firebase Push.
            $this->notificationService->sendToUsers($admins, 'new_driver_registered', [
                'title'       => 'تسجيل سائق جديد 🚐',
                'message'     => "قام السائق ({$user->full_name}) بإكمال بياناته وبانتظار المراجعة.",
                'driver_name' => $user->full_name,
                'entity_id'   => (string) $driver->id,
            ], withPush: false);
        } catch (\Throwable $e) {
            Log::warning("فشل إرسال إشعار الأدمن عن تسجيل السائق الجديد #{$driver->id}: " . $e->getMessage());
        }

        return $driver->load([
            'user',
            'vehicles' => function($query) {
                $query->withoutGlobalScopes(); // 🚀 تخطي أي شروط حذف ناعم مبرمجة تلقائياً
            },
            'documents'
        ]);
    }

    /**
     * إلغاء وحذف الحساب غير المكتمل بعد التحقق من OTP
     */
    public function abandonRegistration(User $user): bool
    {
        return DB::transaction(function () use ($user) {
            $driver = $user->driver;

            if ($driver) {
                // حماية: منع حذف الحسابات النشطة العاملة التي لديها رحلات أو اشتراكات نشطة
                $hasActiveTrips = $driver->trips()->whereIn('status', ['SCHEDULED', 'IN_PROGRESS', 'STARTED'])->exists();
                $hasActiveContracts = $driver->activeSubscriptions()->exists();

                if ($hasActiveTrips || $hasActiveContracts) {
                    throw new Exception("لا يمكن إلغاء الحساب لوجود رحلات أو اشتراكات نشطة مرتبطة به.");
                }

                // 1. حذف وثائق ومستندات السائق وملفاتها من التخزين
                if ($driver->documents()->exists()) {
                    foreach ($driver->documents as $doc) {
                        if (!empty($doc->file_url)) {
                            $relative = str_replace('storage/', '', $doc->file_url);
                            Storage::disk('public')->delete($relative);
                        }
                        $doc->delete();
                    }
                }

                // 2. حذف مركبات السائق وصورها من التخزين
                if ($driver->vehicles()->withoutGlobalScopes()->exists()) {
                    foreach ($driver->vehicles()->withoutGlobalScopes()->get() as $vehicle) {
                        if (!empty($vehicle->vehicle_image_url)) {
                            $relative = str_replace('storage/', '', $vehicle->vehicle_image_url);
                            Storage::disk('public')->delete($relative);
                        }
                        $vehicle->forceDelete();
                    }
                }

                // 3. حذف علاقات المناطق والمقاعد والعناوين إن وجدت
                $driver->zones()->detach();
                $driver->seatSlots()->delete();
                $driver->addresses()->forceDelete();

                // 4. حذف سجل السائق
                $driver->delete();
            }

            // 5. حذف أجهزة المستخدم وتوكنات الدخول
            DB::table('user_devices')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();

            Log::info("تم إلغاء طلب تسجيل السائق بنجاح وحذف حسابه غير المكتمل #{$user->id}", [
                'user_id' => $user->id,
                'email'   => $user->email,
            ]);

            // 6. حذف المستخدم نهائياً لتحرير البريد الإلكتروني ورقم الهاتف
            $user->forceDelete();

            return true;
        });
    }
}