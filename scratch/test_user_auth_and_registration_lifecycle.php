<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Shared\OtpCode;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\PasswordController;
use App\Http\Controllers\Api\Parent\ParentAuthController;
use App\Http\Controllers\Api\Driver\DriverRegisterController;
use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Shared\SendOtpRequest;
use App\Http\Requests\Api\Parent\ParentRegisterRequest;
use App\Http\Requests\Api\Driver\RegisterAccountRequest;
use App\Http\Requests\Api\Driver\CompleteProfileRequest;
use App\Http\Requests\Api\Driver\AbandonRegistrationRequest;
use App\Http\Requests\Api\Admin\StoreAdminRequest;
use App\Http\Requests\Api\Shared\OtpRequest;

echo "=================================================================\n";
echo "  اختبار شامل ومحاكاة حقيقية لدورة حياة إنشاء الحسابات والمصادقة (V2)\n";
echo "=================================================================\n\n";

// تنظيف الجداول قبل الاختبار لضمان دقة النتائج
DB::table('personal_access_tokens')->truncate();
DB::table('otp_codes')->truncate();
DB::table('user_devices')->truncate();

// -----------------------------------------------------------------
// 1. دورة حياة ولي الأمر (Parent Lifecycle: Send OTP -> Register -> Login -> Logout)
// -----------------------------------------------------------------
echo "--- [1] اختبار دورة حياة ولي الأمر (Parent Lifecycle) ---\n";
$parentEmail = 'parent_user_' . uniqid() . '@example.com';
$parentPhone = '091' . rand(1000000, 9999999);
$parentPassword = 'ParentPassword123';

// أ. إرسال رمز التحقق
$parentAuthController = app(ParentAuthController::class);
$sendOtpRequest = new SendOtpRequest();
$sendOtpRequest->merge(['email' => $parentEmail]);
$sendOtpResponse = $parentAuthController->sendOtp($sendOtpRequest);
$sendOtpData = json_decode($sendOtpResponse->getContent(), true);

if (!($sendOtpData['status'] ?? false)) {
    throw new Exception("فشل إرسال كود التحقق لولي الأمر: " . json_encode($sendOtpData, JSON_UNESCAPED_UNICODE));
}
echo "✓ [Parent] تم طلب رمز التحقق بنجاح.\n";

// جلب الرمز الذي تم توليده
$otpRecord = OtpCode::where('email', $parentEmail)->where('purpose', 'REGISTER')->latest()->first();
if (!$otpRecord) {
    throw new Exception("لم يتم حفظ رمز التحقق لولي الأمر في جدول otp_codes!");
}

// استخراج الرمز الحقيقي من الـ OtpService أو فحصه (الهاش محفوظ)
// لمحاكاة الـ OTP الحقيقي، نولد كوداً معروفاً ونختبر التحقق
$otpPlain = '123456';
$otpRecord->update(['code_hash' => Hash::make($otpPlain), 'expires_at' => now()->addMinutes(10)]);

// ب. إنشاء حساب ولي الأمر
$registerRequest = new ParentRegisterRequest();
$registerRequest->merge([
    'full_name'             => 'محمد صالح الزوي',
    'email'                 => $parentEmail,
    'phone_number'          => $parentPhone,
    'password'              => $parentPassword,
    'password_confirmation' => $parentPassword,
    'otp'                   => $otpPlain,
    'platform'              => 'android',
    'device_name'           => 'Samsung S23',
]);
$registerResponse = $parentAuthController->register($registerRequest);
$registerData = json_decode($registerResponse->getContent(), true);

if (!($registerData['status'] ?? false) || empty($registerData['token'])) {
    throw new Exception("فشل إنشاء حساب ولي الأمر: " . json_encode($registerData, JSON_UNESCAPED_UNICODE));
}
echo "✓ [Parent] تم إنشاء حساب ولي الأمر بنجاح (ID: {$registerData['data']['id_user']}).\n";

// ج. تسجيل الدخول
$loginController = app(LoginController::class);
$loginReq = new LoginRequest();
$loginReq->merge([
    'email'       => $parentEmail,
    'password'    => $parentPassword,
    'platform'    => 'android',
    'device_name' => 'Samsung S23',
]);
$loginResp = $loginController->login($loginReq);
$loginData = json_decode($loginResp->getContent(), true);

if (!($loginData['status'] ?? false) || empty($loginData['access_token'])) {
    throw new Exception("فشل تسجيل دخول ولي الأمر: " . json_encode($loginData, JSON_UNESCAPED_UNICODE));
}
if ($loginData['role_name'] !== 'ولي أمر') {
    throw new Exception("اسم الدور لولي الأمر غير صحيح: " . $loginData['role_name']);
}
echo "✓ [Parent] تم تسجيل الدخول بنجاح! التوكن تم إنشاؤه والدور: {$loginData['role_name']}.\n";

// د. تسجيل الخروج
$parentUser = User::where('email', $parentEmail)->first();
// محاكاة طلب تسجيل الخروج مع التوكن
$tokenModel = $parentUser->tokens()->latest()->first();
$logoutReq = Request::create('/api/auth/logout', 'POST');
$logoutReq->setUserResolver(fn() => $parentUser);
$parentUser->withAccessToken($tokenModel);

$logoutResp = $loginController->logout($logoutReq);
$logoutData = json_decode($logoutResp->getContent(), true);

if (!($logoutData['status'] ?? false)) {
    throw new Exception("فشل تسجيل خروج ولي الأمر: " . json_encode($logoutData, JSON_UNESCAPED_UNICODE));
}
echo "✓ [Parent] تم تسجيل الخروج بنجاح وإبطال التوكن.\n\n";

// -----------------------------------------------------------------
// 2. دورة حياة السائق (Driver Lifecycle: Step 1 -> Step 2 OTP -> Step 3 Complete -> Login -> Logout)
// -----------------------------------------------------------------
echo "--- [2] اختبار دورة حياة السائق (Driver Lifecycle) ---\n";
$driverEmail = 'driver_user_' . uniqid() . '@example.com';
$driverPhone = '092' . rand(1000000, 9999999);
$driverPassword = 'DriverPassword123';

$driverRegisterController = app(DriverRegisterController::class);

// أ. المرحلة 1: طلب التسجيل وإرسال OTP
$driverStep1Req = new RegisterAccountRequest();
$driverStep1Req->merge([
    'full_name'    => 'عبد الله فرج الورفلي',
    'email'        => $driverEmail,
    'phone_number' => $driverPhone,
    'gender'       => 'male',
    'password'     => $driverPassword,
    'platform'     => 'android',
]);
$driverStep1Resp = $driverRegisterController->registerAccount($driverStep1Req);
$driverStep1Data = json_decode($driverStep1Resp->getContent(), true);

if (!($driverStep1Data['status'] ?? false)) {
    throw new Exception("فشل الخطوة 1 لتسجيل السائق: " . json_encode($driverStep1Data, JSON_UNESCAPED_UNICODE));
}
echo "✓ [Driver] الخطوة 1: تم طلب تسجيل السائق وتوليد الـ OTP بنجاح.\n";

// جلب الـ OTP وتعيين كود معروف للاختبار
$driverOtpRecord = OtpCode::where('email', $driverEmail)->where('purpose', 'REGISTER')->latest()->first();
$driverOtpPlain = '654321';
$driverOtpRecord->update(['code_hash' => Hash::make($driverOtpPlain), 'expires_at' => now()->addMinutes(10)]);

// ب. المرحلة 2: التحقق من OTP وإنشاء الحساب
$driverVerifyReq = new OtpRequest();
$driverVerifyReq->merge([
    'email'             => $driverEmail,
    'otp'               => $driverOtpPlain,
    'full_name'         => 'عبد الله فرج الورفلي',
    'phone_number'      => $driverPhone,
    'gender'            => 'male',
    'password'          => $driverPassword,
    'alternative_phone' => null,
]);
$driverVerifyResp = $driverRegisterController->verifyOtp($driverVerifyReq);
$driverVerifyData = json_decode($driverVerifyResp->getContent(), true);

if (!($driverVerifyData['status'] ?? false) || empty($driverVerifyData['token'])) {
    throw new Exception("فشل التحقق من OTP وإنشاء حساب السائق: " . json_encode($driverVerifyData, JSON_UNESCAPED_UNICODE));
}
$driverUserId = $driverVerifyData['user_id'];
$driverId = $driverVerifyData['driver_id'];
echo "✓ [Driver] الخطوة 2: تم التحقق من OTP وإنشاء حساب السائق الأولي (User #{$driverUserId}, Driver #{$driverId}).\n";

// ج. المرحلة 3: إكمال الملف الشخصي والمركبة والوثائق (Complete Profile)
$driverUser = User::find($driverUserId);
$completeProfileReq = new CompleteProfileRequest();
$completeProfileReq->setUserResolver(fn() => $driverUser);
$completeProfileReq->merge([
    'national_id'                    => '119900' . rand(100000, 999999),
    'license_number'                 => 'LIC-TRIPOLI-' . rand(10000, 99999),
    'license_expiry'                 => '2028-12-31',
    'doc_license_path'               => 'uploads/drivers/license_' . uniqid() . '.jpg',
    'plate_number'                   => 'TRP-' . rand(1000, 9999),
    'brand'                          => 'Toyota',
    'model'                          => 'HiAce Commuter',
    'year'                           => 2023,
    'color'                          => 'White',
    'type'                           => 'Van',
    'capacity_manual'                => 14,
    'vehicle_image_path'             => 'uploads/vehicles/car_' . uniqid() . '.jpg',
    'has_ac'                         => true,
    'doc_logbook_path'               => 'uploads/vehicles/logbook_' . uniqid() . '.jpg',
    'doc_insurance_path'             => 'uploads/vehicles/insurance_' . uniqid() . '.jpg',
    'insurance_expiry'               => '2027-06-30',
    'doc_technical_inspection_path'  => 'uploads/vehicles/inspection_' . uniqid() . '.jpg',
    'technical_inspection_expiry'    => '2027-12-31',
    'doc_stamp_path'                 => 'uploads/vehicles/stamp_' . uniqid() . '.jpg',
    'stamp_expiry'                   => '2028-01-01',
]);

$completeProfileResp = $driverRegisterController->completeProfile($completeProfileReq, $driverUserId);
$completeProfileData = json_decode($completeProfileResp->getContent(), true);

if (!($completeProfileData['status'] ?? false)) {
    throw new Exception("فشل إكمال ملف السائق: " . json_encode($completeProfileData, JSON_UNESCAPED_UNICODE));
}
echo "✓ [Driver] الخطوة 3: تم إكمال ملف السائق، إنشاء المركبة، حفظ وثائق المركبة في vehicle_documents، وتسجيل طلب الاعتماد في driver_approvals.\n";

// تفعيل حساب السائق لمسار تسجيل الدخول (محاكاة موافقة الأدمن)
$driverUser->update(['is_active' => 1]);
$driverUser->driver->update(['status' => 'Approved']);

// د. تسجيل دخول السائق
$driverLoginReq = new LoginRequest();
$driverLoginReq->merge([
    'email'       => $driverEmail,
    'password'    => $driverPassword,
    'platform'    => 'android',
    'device_name' => 'Driver Phone',
]);
$driverLoginResp = $loginController->login($driverLoginReq);
$driverLoginData = json_decode($driverLoginResp->getContent(), true);

if (!($driverLoginData['status'] ?? false) || $driverLoginData['role_name'] !== 'سائق') {
    throw new Exception("فشل تسجيل دخول السائق: " . json_encode($driverLoginData, JSON_UNESCAPED_UNICODE));
}
echo "✓ [Driver] تم تسجيل دخول السائق بنجاح! الدور: {$driverLoginData['role_name']}, ومعرف السائق في الـ Resource: {$driverLoginData['user']['driver_id']}.\n";

// هـ. تسجيل خروج السائق
$driverTokenModel = $driverUser->tokens()->latest()->first();
$driverLogoutReq = Request::create('/api/auth/logout', 'POST');
$driverLogoutReq->setUserResolver(fn() => $driverUser);
$driverUser->withAccessToken($driverTokenModel);
$driverLogoutResp = $loginController->logout($driverLogoutReq);
$driverLogoutData = json_decode($driverLogoutResp->getContent(), true);

if (!($driverLogoutData['status'] ?? false)) {
    throw new Exception("فشل تسجيل خروج السائق: " . json_encode($driverLogoutData, JSON_UNESCAPED_UNICODE));
}
echo "✓ [Driver] تم تسجيل خروج السائق بنجاح.\n\n";

// -----------------------------------------------------------------
// 3. دورة حياة المشرف (Supervisor Creation by Super Admin & Login)
// -----------------------------------------------------------------
echo "--- [3] اختبار دورة حياة المشرف (Supervisor Creation by Admin & Login) ---\n";
// إنشاء الأدمن الرئيسي (Super Admin)
$superAdmin = User::create([
    'full_name'    => 'مدير النظام العام',
    'email'        => 'superadmin_' . uniqid() . '@darby.ly',
    'phone_number' => '091' . rand(1000000, 9999999),
    'password'     => Hash::make('AdminSecret123'),
    'role_id'      => 1, // مدير النظام
    'is_active'    => 1,
]);

// تسجيل دخول الأدمن الرئيسي
$adminLoginReq = new LoginRequest();
$adminLoginReq->merge([
    'email'       => $superAdmin->email,
    'password'    => 'AdminSecret123',
    'platform'    => 'web',
    'device_name' => 'Admin Dashboard',
]);
$adminLoginResp = $loginController->login($adminLoginReq);
$adminLoginData = json_decode($adminLoginResp->getContent(), true);

if (!($adminLoginData['status'] ?? false) || $adminLoginData['role_name'] !== 'مدير النظام') {
    throw new Exception("فشل تسجيل دخول الأدمن الرئيسي: " . json_encode($adminLoginData, JSON_UNESCAPED_UNICODE));
}
echo "✓ [Admin] تم تسجيل دخول مدير النظام بنجاح واستلام AdminResource مع الصلاحيات.\n";

// إنشاء مشرف جديد من قبل الأدمن
$adminController = app(AdminController::class);
$supervisorEmail = 'supervisor_' . uniqid() . '@darby.ly';
$supervisorPhone = '092' . rand(1000000, 9999999);
$supervisorPassword = 'SupervisorPass123';

$createSupervisorReq = new StoreAdminRequest();
$createSupervisorReq->setUserResolver(fn() => $superAdmin);
auth()->login($superAdmin);

$createSupervisorReq->merge([
    'full_name'    => 'عمر عبد السلام القمودي',
    'email'        => $supervisorEmail,
    'phone_number' => $supervisorPhone,
    'password'     => $supervisorPassword,
    'role_id'      => 2, // مشرف
    'is_active'    => 1,
    'created_by'   => $superAdmin->id,
]);
$createSupervisorResp = $adminController->store($createSupervisorReq);
$createSupervisorData = json_decode($createSupervisorResp->getContent(), true);

if (!($createSupervisorData['status'] ?? false)) {
    throw new Exception("فشل إنشاء حساب المشرف: " . json_encode($createSupervisorData, JSON_UNESCAPED_UNICODE));
}
echo "✓ [Admin] قام مدير النظام بإنشاء حساب المشرف الجديد بنجاح (معرف المنشئ: {$superAdmin->id}).\n";

// تسجيل دخول المشرف الجديد
$supervisorLoginReq = new LoginRequest();
$supervisorLoginReq->merge([
    'email'       => $supervisorEmail,
    'password'    => $supervisorPassword,
    'platform'    => 'web',
    'device_name' => 'Supervisor Mac',
]);
$supervisorLoginResp = $loginController->login($supervisorLoginReq);
$supervisorLoginData = json_decode($supervisorLoginResp->getContent(), true);

if (!($supervisorLoginData['status'] ?? false) || $supervisorLoginData['role_name'] !== 'مشرف') {
    throw new Exception("فشل تسجيل دخول المشرف: " . json_encode($supervisorLoginData, JSON_UNESCAPED_UNICODE));
}
if (!isset($supervisorLoginData['user']['permissions'])) {
    throw new Exception("لم يتم تضمين الصلاحيات في استجابة تسجيل دخول المشرف!");
}
echo "✓ [Supervisor] تم تسجيل دخول المشرف بنجاح! الدور: {$supervisorLoginData['role_name']}, عدد الصلاحيات الممنوحة: " . count($supervisorLoginData['user']['permissions']) . ".\n";

// تسجيل خروج المشرف
$supervisorUser = User::where('email', $supervisorEmail)->first();
$supervisorTokenModel = $supervisorUser->tokens()->latest()->first();
$supervisorLogoutReq = Request::create('/api/auth/logout', 'POST');
$supervisorLogoutReq->setUserResolver(fn() => $supervisorUser);
$supervisorUser->withAccessToken($supervisorTokenModel);
$supervisorLogoutResp = $loginController->logout($supervisorLogoutReq);
$supervisorLogoutData = json_decode($supervisorLogoutResp->getContent(), true);

if (!($supervisorLogoutData['status'] ?? false)) {
    throw new Exception("فشل تسجيل خروج المشرف: " . json_encode($supervisorLogoutData, JSON_UNESCAPED_UNICODE));
}
echo "✓ [Supervisor] تم تسجيل خروج المشرف بنجاح.\n\n";

// -----------------------------------------------------------------
// 4. دورة استعادة كلمة المرور الموحدة (Password Reset for Any Role)
// -----------------------------------------------------------------
echo "--- [4] اختبار دورة استعادة كلمة المرور الموحدة (Password Reset Lifecycle) ---\n";
$passwordController = app(PasswordController::class);

// أ. طلب كود استعادة كلمة المرور
$pwdSendReq = Request::create('/api/auth/password/send-otp', 'POST', ['email' => $parentEmail]);
$pwdSendResp = $passwordController->sendResetOtp($pwdSendReq);
$pwdSendData = json_decode($pwdSendResp->getContent(), true);

if (!($pwdSendData['status'] ?? false)) {
    throw new Exception("فشل إرسال كود استعادة كلمة المرور: " . json_encode($pwdSendData, JSON_UNESCAPED_UNICODE));
}
echo "✓ تم إرسال رمز استعادة كلمة المرور إلى البريد.\n";

// جلب وتحديث الرمز
$resetOtpRecord = OtpCode::where('email', $parentEmail)->where('purpose', 'RESET_PASSWORD')->latest()->first();
$resetOtpPlain = '789123';
$resetOtpRecord->update(['code_hash' => Hash::make($resetOtpPlain), 'expires_at' => now()->addMinutes(10)]);

// ب. التحقق من كود الـ OTP
$pwdVerifyReq = Request::create('/api/auth/password/verify-otp', 'POST', [
    'email' => $parentEmail,
    'code'  => $resetOtpPlain,
]);
$pwdVerifyResp = $passwordController->verifyOtp($pwdVerifyReq);
$pwdVerifyData = json_decode($pwdVerifyResp->getContent(), true);

if (!($pwdVerifyData['status'] ?? false)) {
    throw new Exception("فشل التحقق من كود استعادة كلمة المرور: " . json_encode($pwdVerifyData, JSON_UNESCAPED_UNICODE));
}
echo "✓ تم التحقق من رمز استعادة كلمة المرور بنجاح.\n";

// ج. تغيير كلمة المرور
$newPassword = 'NewSecretPassword999';
$pwdResetReq = Request::create('/api/auth/password/reset', 'POST', [
    'email'                 => $parentEmail,
    'password'              => $newPassword,
    'password_confirmation' => $newPassword,
]);
$pwdResetResp = $passwordController->resetPassword($pwdResetReq);
$pwdResetData = json_decode($pwdResetResp->getContent(), true);

if (!($pwdResetData['status'] ?? false)) {
    throw new Exception("فشل إعادة تعيين كلمة المرور: " . json_encode($pwdResetData, JSON_UNESCAPED_UNICODE));
}
echo "✓ تم تحديث كلمة المرور الجديدة بنجاح.\n";

// د. اختبار تسجيل الدخول بكلمة المرور القديمة (يجب أن يفشل 401)
$oldLoginReq = new LoginRequest();
$oldLoginReq->merge([
    'email'       => $parentEmail,
    'password'    => $parentPassword,
    'platform'    => 'android',
    'device_name' => 'Test Phone',
]);
$oldLoginResp = $loginController->login($oldLoginReq);
if ($oldLoginResp->getStatusCode() !== 401) {
    throw new Exception("خطأ أمني: النظام سمح بتسجيل الدخول بكلمة المرور القديمة الملغاة!");
}
echo "✓ [Security Pass] تم رفض كلمة المرور القديمة بنجاح (401 Unauthorized).\n";

// هـ. تسجيل الدخول بكلمة المرور الجديدة (يجب أن ينجح 200)
$newLoginReq = new LoginRequest();
$newLoginReq->merge([
    'email'       => $parentEmail,
    'password'    => $newPassword,
    'platform'    => 'android',
    'device_name' => 'Test Phone',
]);
$newLoginResp = $loginController->login($newLoginReq);
$newLoginData = json_decode($newLoginResp->getContent(), true);

if (!($newLoginData['status'] ?? false)) {
    throw new Exception("فشل تسجيل الدخول بكلمة المرور الجديدة!");
}
echo "✓ [Success] تم تسجيل الدخول بنجاح تام بكلمة المرور الجديدة!\n";

echo "\n=================================================================\n";
echo ">>> النتيجة النهائية: كافة دورات حياة الحسابات والمصادقة (أولياء الأمور، السائقين، المشرفين، والمدراء)\n";
echo "    تعمل بنسبة 100% بنجاح وتوافق تام مع قاعدة البيانات V2! <<<\n";
echo "=================================================================\n";
