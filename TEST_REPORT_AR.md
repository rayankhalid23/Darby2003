# تقرير اختبار شامل + الأخطاء والحلول — مشروع نقل الطلاب (Darby)

تاريخ الاختبار: 2026-09-06
البيئة: تشغيل حقيقي عبر سيرفر محلي (`php public/index.php`) مقابل قاعدة البيانات `school_transport_v2`، واختبار الطلبات فعلياً بالتوكنات كأدوار مختلفة (ولي أمر / سائق / إدارة).

---

## أولاً: ملخص النتائج

تم اختبار جميع مجموعات الوظائف المطلوبة فعلياً عبر الـ API. النتيجة العامة: **معظم الوظائف تعمل**، لكن توجد **ثغرات أمنية حرجة** و**أخطاء كسر (500)** يجب معالجتها قبل الإطلاق.

| الحالة | العدد |
|---|---|
| 🔴 حرِج (أمني/مالي) | 4 |
| 🟠 عالي (كسر وظيفة / منطق) | 5 |
| 🟡 متوسط/منخفض (جودة/رسائل) | 6 |

---

## ثانياً: الأخطاء المكتشفة + الحل المقترح

### 🔴 1) تجاوز OTP في إعادة تعيين كلمة المرور → استيلاء على الحساب (Account Takeover)
- **المسار:** `POST /api/auth/password/reset`
- **الوصف:** الدالة `resetPassword` في `app/Http/Controllers/Api/Auth/PasswordController.php` تغيّر كلمة مرور أي مستخدم بمجرد معرفة بريده، **دون التحقق من رمز OTP إطلاقاً**. أي شخص يعرف بريد الضحية يستولي على حسابها.
- **الإثبات:** أرسلنا `reset` مباشرة (بدون send-otp/verify-otp) → نجح تغيير كلمة المرور ثم سجّلنا الدخول بها.
- **الحل المقترح:** إلزام التحقق من نجاح `verify-otp` قبل السماح بالتغيير. مثلاً: عند نجاح `verifyOtp` أنشئ سجلاً/توكن مؤقت (`password_reset_token` أو علّم سجل الـ OTP `verified_at`)، وفي `resetPassword` تحقق من وجود OTP مُتحقَّق منه وغير منتهٍ لنفس البريد وبغرض `RESET_PASSWORD` قبل `update`.

```php
// داخل resetPassword قبل التحديث:
$otp = OtpCode::where('email',$request->email)
    ->where('purpose','RESET_PASSWORD')
    ->where('is_used',1)               // تم التحقق منه
    ->where('updated_at','>=',now()->subMinutes(10))
    ->latest()->first();
if (!$otp) {
    return response()->json(['status'=>false,'message'=>'يرجى التحقق من رمز OTP أولاً.'],403);
}
```

### 🔴 2) IDOR في العناوين → تعديل/حذف عناوين مستخدمين آخرين
- **المسار:** `PUT /api/parent/addresses/{id}` و `DELETE /api/parent/addresses/{id}`
- **الوصف:** `AddressController::update/destroy` يستخدمان Route-Model-Binding `Address $address` **بدون التحقق من الملكية**. الدالة `AddressService::updateAddress` تستقبل `$parentId` لكن تستخدمه فقط لفحص التكرار، لا للتحقق من الملكية.
- **الإثبات:** بتوكن ولي أمر (48) عدّلنا عنوان ولي أمر آخر (51) بنجاح، وظهر التعديل عند المالك الحقيقي.
- **الحل المقترح:** تقييد النطاق بالمالك في الكنترولر:

```php
public function update(UpdateAddressRequest $request, int $id){
    $address = Address::where('id',$id)->where('user_id',auth()->id())->firstOrFail();
    // ... نفس المنطق
}
public function destroy(int $id){
    $address = Address::where('id',$id)->where('user_id',auth()->id())->firstOrFail();
    $this->addressService->deleteAddress($address);
}
```
أو استخدام Policy: `$this->authorize('update',$address);`

### 🔴 3) تصعيد صلاحية: ولي الأمر يعيّن `is_trusted=true` لنفسه
- **المسار:** `POST /api/parent/profile/update`
- **الوصف:** `UpdateParentProfileRequest` يسمح بحقل `is_trusted` (`sometimes|boolean`)، فيستطيع ولي الأمر منح نفسه صفة "موثوق".
- **الإثبات:** أرسلنا `{"is_trusted":true}` → أصبح `users.is_trusted=1` في قاعدة البيانات (أُعيد للوضع الصحيح بعد الاختبار).
- **الحل المقترح:** إزالة `is_trusted` من قواعد التحقق ومن أي `update` مُجمّع في خدمة الملف الشخصي لولي الأمر؛ يُدار فقط من الإدارة.

### 🔴 4) بوابة دفع وهمية تمنح رصيداً مجانياً + بيانات بنكية وهمية في الكود
- **المسار:** `POST /api/parent/wallet/recharge/mock-pay`
- **الوصف:** أي مستخدم يشحن محفظته بأموال حقيقية عبر إرسال رقم بطاقة صوري. `processMockPayment` يودع المبلغ فوراً.
- **الإثبات:** الرصيد انتقل من 2100 إلى 2600 بإرسال بطاقة `4242...` دون دفع فعلي.
- **تم الإصلاح في هذه الجلسة (إزالة/تقييد البيانات الوهمية):**
  1. تقييد `mock-pay` على بيئات التطوير فقط (`local/development/testing/staging`) — في الإنتاج تُعيد 403. (`WalletController::mockPay`)
  2. إزالة بيانات الحساب البنكي/الـ IBAN الوهمية من `getFallbackPaymentMethods` (كانت `020-1234567-001` / `LY98NCBL...`) واستبدالها بقائمة فارغة. (`WalletRechargeService`)
- **يتبقى:** ربط بوابة دفع حقيقية قبل الإطلاق، وإزالة مسار الـ mock نهائياً في الإنتاج.

---

### 🟠 5) عدم اتساق `role_id` في كامل النظام
- **الوصف:** جدول `roles` يعرّف: `parent=7`, `driver=8`, بينما الكود يعتمد ثابتاً `parent=3`, `driver=4` في عدة أماكن:
  - `DriverProfileService` (5 مواضع: 34, 150, 221, 258, 469)
  - `LoginController` (92)، `ParentModel` (24)، `ReportService` (40)، `DashboardController` (46)، `ParentRegistrationService` (142)
- **الأثر:** `GET /api/v1/driver/status` يرجع **404** للسائق (user 52, driver 14) لأن `role_id=2` لا يطابق الثابت 4. كما يظهر سائقون ضمن قائمة المشرفين. أي سائق يُنشأ بـ `role_id` من جدول الأدوار (8) سيتعطل.
- **الحل المقترح:** توحيد مصدر الحقيقة. الأفضل استبدال الفلترة بـ `role_id` بالعلاقة الفعلية (`$user->driver`, `$user->parent`) أو ثابت مركزي (Enum `RoleId`) يطابق جدول `roles`، ثم تصحيح `role_id` لبيانات المستخدمين. مثال في `getDriverStatus`:

```php
// بدل: User::where('id',$userId)->where('role_id',4)->firstOrFail();
$user = User::findOrFail($userId);
$driver = $user->driver;   // الاعتماد على العلاقة لا على role_id
if (!$driver) throw new Exception('لم يتم العثور على ملف السائق.');
```

### 🟠 6) جدول `complaints` غير موجود → تعطّل الشكاوى بالكامل (500)
- **المسارات:** `GET /api/admin/complaints`, `GET /api/parent/complaints`
- **الوصف:** الخدمة تستعلم من جدول `complaints` غير الموجود. توجد فقط Migrations من نوع `alter/add`/`fix` للجدول، ولا يوجد Migration لإنشائه (`create_complaints_table` مفقود).
- **الإثبات:** `SQLSTATE[42S02]: ... Table 'school_transport_v2.complaints' doesn't exist`.
- **الحل المقترح:** إضافة Migration لإنشاء جدول `complaints` بالأعمدة التي تعتمدها الـ Migrations اللاحقة (`submitted_by`, `status`, أعمدة الـ AI ...) ثم `migrate`.

### 🟠 7) جدول `driver_documents` غير موجود → تعطّل إحصائيات السائق (500)
- **المسار:** `GET /api/v1/driver/statistics` (و `dashboard/stats`)
- **الوصف:** الكود يستعلم من `driver_documents` بينما الجدول الفعلي اسمه `vehicle_documents`.
- **الإثبات:** `SQLSTATE[42S02]: ... Table 'school_transport_v2.driver_documents' doesn't exist`.
- **الحل المقترح:** تصحيح الاستعلام/الموديل ليستخدم `vehicle_documents` (والعمود `doc_type`/`state` الموجود فعلاً)، أو إنشاء الجدول إن كان مقصوداً منفصلاً.

### 🟠 8) تسريب أخطاء SQL الخام للعميل
- **الأمثلة:** إنشاء مشرف بـ `role_id` غير موجود يعيد رسالة `SQLSTATE[23000] ... foreign key constraint fails`؛ وإحصائيات السائق تعيد `"error":"SQLSTATE..."`.
- **الحل المقترح:** عدم إظهار `getMessage()` الخام للعميل، والاعتماد على مُعالج الاستثناءات الموحّد. وفي `StoreAdminRequest` أضف `role_id => ['required','integer','exists:roles,id']` بدل الاعتماد على قيد قاعدة البيانات.

### 🟠 9) `created_by` يُستقبل من العميل عند إنشاء مشرف
- **المسار:** `POST /api/admin/admins`
- **الوصف:** `StoreAdminRequest` يشترط `created_by` كمُدخل، فيمكن انتحال منشئ الحساب.
- **الحل المقترح:** إزالة `created_by` من المُدخلات وأخذه من `auth()->id()` داخل الخدمة.

---

### 🟡 أخطاء متوسطة/منخفضة (جودة)
1. **رسالة تحقق غير مترجمة** عند رفض طلب الاشتراك من السائق: `validation.required_if` تظهر كمفتاح خام. أضف رسالة مخصّصة/مدخل `rejection_reason` في `messages()`.
2. **رسالة إنجليزية داخل تطبيق عربي:** `POST /api/parent/profile/update` يعيد "Profile updated successfully." — يُفضّل توحيدها للعربية.
3. **`child_id: null`** في مخرجات `GET /api/parent/children/{id}/subscription` وتواريخ فارغة — مراجعة ربط بيانات النقل بالطفل.
4. **كشف رقم هاتف السائق كاملاً** في نتائج البحث لولي الأمر — يُفضّل إخفاؤه قبل الاشتراك.
5. **تعداد المستخدمين (User Enumeration):** `send-otp`/`login` تفرّق بين "بريد غير مسجل" (404) وكلمة مرور خاطئة (401) — يسهّل حصر الحسابات.
6. **`APP_DEBUG=true`** في `.env` — يجب أن يكون `false` في الإنتاج لمنع تسريب المسارات والـ Stack traces.

---

## ثالثاً: البيانات الوهمية التي أُزيلت من الكود (منفّذ فعلياً)
- `app/Services/Parent/WalletRechargeService.php` → `getFallbackPaymentMethods()`: حُذفت الحسابات البنكية والـ IBAN الوهمية (`020-1234567-001` / `LY98NCBL0200001234567001`) وتعيد الآن قائمة فارغة (مصدر الحقيقة جدول `payment_methods`).
- `app/Http/Controllers/Api/Parent/WalletController.php` → `mockPay()`: بوابة الدفع التجريبية أصبحت مقيّدة ببيئات غير الإنتاج (تعيد 403 في الإنتاج).

> ملاحظة: مسار الـ mock بأكمله (`WalletRechargeService::initiateRecharge/processMockPayment`، توكنات `MOCK_SESS_`، البطاقة الافتراضية `4242`) هو محاكاة يجب استبدالها ببوابة دفع حقيقية قبل الإطلاق. لم أحذفه بالكامل حتى لا أُعطّل مسار الشحن الوحيد الحالي دون بديل — القرار النهائي لك.

---

## رابعاً: دليل الاختبار على Postman
- الملف الجاهز للاستيراد: `postman/Darby_API_Full.postman_collection.json`
- الاستيراد: Postman → Import → اختر الملف. ثم من Variables اضبط:
  - `base_url` (مثال: `http://127.0.0.1:8000` أو رابط الـ tunnel)
  - `parent_token` / `driver_token` / `admin_token` (من استجابة تسجيل الدخول → `access_token`)
- الترتيب المقترح للاختبار: (1) عام → سجّل الدخول وانسخ التوكن، (2) ولي الأمر، (3) السائق، (4) الإدارة، (5) تطبيق ولي الأمر، (6) تطبيق السائق.
- بادئات المسارات: ولي الأمر `api/parent`، السائق `api/v1/driver`، الإدارة `api/admin`.

قائمة كل دالة (Endpoint + المدخلات الإجبارية/الاختيارية + المخرجات) موثّقة داخل حقل **description** لكل طلب في المجموعة.

---

## خامساً: الإصلاحات المطبّقة والمُتحقَّق منها (تحديث)

تم إصلاح جميع الأخطاء المطلوبة (عدا #6 جدول complaints و #7 جدول driver_documents — بقيت كما طلبت) واختبارها فعلياً على السيرفر:

| # | الإصلاح | الملف | نتيجة الاختبار |
|---|---------|-------|----------------|
| 1 | إلزام التحقق من OTP قبل تغيير كلمة المرور (إذن مؤقت أحادي الاستخدام عبر Cache) | `PasswordController.php` | reset بدون تحقق → 403 ✓ / بعد التحقق → 200 ✓ / محاولة ثانية → 403 ✓ |
| 2 | إغلاق IDOR في العناوين (تقييد بالمالك) | `Parent/AddressController.php` | تعديل/حذف عنوان مستخدم آخر → 404 ✓ / المالك نفسه → 200 ✓ |
| 3 | منع تصعيد صلاحية is_trusted (أُزيل من مدخلات ولي الأمر) | `UpdateParentProfileRequest.php` | إرسال is_trusted=true → يُتجاهل، يبقى 0 ✓ |
| 4 | تقييد بوابة الدفع الوهمية ببيئة التطوير + إزالة الحساب البنكي الوهمي | `WalletController.php`, `WalletRechargeService.php` | mock-pay في الإنتاج → 403 (منفّذ) |
| 5 | توحيد تحديد السائق/ولي الأمر عبر العلاقة بدل role_id الثابت | `DriverProfileService.php` (5)، `ParentRegistrationService.php` | `/driver/status` للسائق 14 → 200 ✓ |
| 8 | التحقق من `role_id` بـ exists + عدم تسريب خطأ SQL الخام | `StoreAdminRequest.php`, `AdminController.php` | role_id=999 → 422 دون تسريب SQL ✓ |
| 9 | `created_by` يُفرض من المستخدم المصادَق (مؤكَّد عبر prepareForValidation) | `StoreAdminRequest.php`, `AdminController.php` | إنشاء صحيح → created_by=المصادَق ✓ |
| 🟡 | ترجمة رسالة رفض طلب الاشتراك للسائق | `Driver/DriverSubscriptionController.php` | رسالة عربية واضحة ✓ |
| 🟡 | ترجمة رسالة تحديث ملف ولي الأمر للعربية | `Parent/ParentAuthController.php` | تم ✓ |

ملاحظة على #9: تبيّن أن `created_by` كان محمياً أصلاً عبر `prepareForValidation` (يستبدل قيمة العميل بمعرّف المصادَق)، فتم فقط تعزيزه وإضافة `exists`.

### ملاحظات لم تُعدّل عمداً
- **#6 و #7** (جدولا `complaints` و `driver_documents`): تُركا كما طلبت.
- **`APP_DEBUG=true`**: إعداد بيئة إنتاج وليس خطأ برمجياً — يُضبط `false` عند النشر فقط (تركته `true` لأنك على بيئة تطوير محلية).
- **مسار الـ mock-pay بالكامل**: يحتاج استبدالاً ببوابة دفع حقيقية قبل الإطلاق (خارج نطاق إصلاح خطأ).
