# توثيق API — النظام المالي (محفظة، فواتير، سحب)

آخر تحديث: 2026-09-12 — كل نداء بهذا الملف **مُختبَر فعلياً** على قاعدة بيانات التطوير (لا قراءة كود فقط)، والأمثلة مأخوذة من ردود حقيقية.

كل الروابط تحتاج هيدر `Authorization: Bearer {token}` (Sanctum). كل الردود JSON، وتحمل `success` أو `status: true` عند النجاح (غير موحّد بالكود — بعض الكنترولرز تستخدم `success` وبعضها `status`، انتبه له بالفرونت لكل نداء على حدة كما هو موضّح تحت). **كل المبالغ بالـ Body/Response بالدينار (رقم عشري)** — التخزين الداخلي فقط بالقروش ولا يظهر للفرونت، ما عدا `financial/ledger` و`financial/audit-logs` بلوحة الأدمن (استثناء موثّق بقسمهما).

## 📌 آخر التعديلات المؤثّرة على هذا الملف

- **وسائل الدفع صارت لولي الأمر حصراً ودفعاً فورياً فقط** (`target_audience=parent`, `processing_type=instant_simulation` دائماً) — لا خيار "تحويل يدوي" ولا وسيلة مخصّصة للسائق بعد الآن. القائمة الحالية: `sadad`, `tadawul`, `moamalat`.
- **مخرجات `GET /wallet/payment-methods`** (لولي الأمر وللسائق) **نُظّفت من الحقول القديمة غير المستخدمة** (`name_en`, `account_name`, `account_number`, `iban`, `wallet_number`, `instructions_ar`, `instructions_en`, `target_audience`, `processing_type`, `deleted_at`, `created_at`, `updated_at`) — ترجع الآن: `id`, `name_ar`, `code`, `icon_url`, `min_amount`, `max_amount`.
- **الأدمن يرفع أيقونة/صورة فعلية لكل وسيلة دفع الآن** (حقل ملف `icon` بشاشة الإضافة/التعديل، موثّق بالكامل بملف [`PAYMENT_METHODS_ADMIN_API.md`](PAYMENT_METHODS_ADMIN_API.md)) — الرابط الناتج `icon_url` يظهر لولي الأمر بالقائمة تحت (اختبرته فعلياً: رفعت أيقونة، ظهرت مباشرة بـ`GET /wallet/payment-methods`).
- **رسائل التحقق (validation) صارت عربية فعلاً** بدل مفاتيح خام مثل `"validation.exists"` — أُضيف `lang/ar/validation.php` (كان مفقوداً من المشروع بالكامل).
- إدارة وسائل الدفع من لوحة الأدمن (إضافة/تعديل/حذف) موثّقة بالكامل بملف منفصل: [`PAYMENT_METHODS_ADMIN_API.md`](PAYMENT_METHODS_ADMIN_API.md).

---

## 1. ولي الأمر (Parent) — Base: `/api/parent`

### 1.1 `GET /wallet/balance` — رصيد المحفظة
**Input:** لا يوجد.

**Output (200):**
```json
{ "success": true, "data": { "balance": 76.5, "currency": "د.ل" } }
```
| الحقل | إجباري |
|---|---|
| `success` | ✅ |
| `data.balance` | ✅ رقم عشري بالدينار |
| `data.currency` | ✅ ثابت `"د.ل"` |

---

### 1.2 `GET /wallet/payment-methods` — طرق الدفع المتاحة
**Input:** لا يوجد.

**✅ رد حقيقي (بعد التنظيف + إضافة الأيقونة):**
```json
{
  "success": true,
  "data": [
    { "id": 31, "name_ar": "خدمة سداد (Sadad)", "code": "sadad", "icon_url": "https://.../storage/payment_methods/icons/xxx.png", "min_amount": 1, "max_amount": 5000 },
    { "id": 32, "name_ar": "تداول / بطاقة مصرفية (Tadawul)", "code": "tadawul", "icon_url": null, "min_amount": 5, "max_amount": 10000 },
    { "id": 33, "name_ar": "شبكة معاملات (Moamalat)", "code": "moamalat", "icon_url": null, "min_amount": 5, "max_amount": 10000 }
  ]
}
```
| الحقل | إجباري | الوصف |
|---|---|---|
| `id` | ✅ | استخدمه كـ `payment_method_id` بالخطوة التالية (initiate) |
| `name_ar` | ✅ | الاسم المعروض |
| `code` | ✅ | معرّف نصي بديل عن `id` (نادراً ما يُحتاج) |
| `icon_url` | ✅ (قد تكون `null`) | رابط أيقونة الوسيلة الذي رفعه الأدمن — اعرض أيقونة افتراضية لو `null` |
| `min_amount` / `max_amount` | ✅ | حدود المبلغ **الخاصة بهذه الوسيلة تحديداً** — تحقّق منها بالفرونت قبل الإرسال |

⚠️ لو رجعت `data: []` — وضع طبيعي (لا وسيلة مفعّلة حالياً)، اعرض رسالة مناسبة بدل شاشة فاضية. (اختبرت هذه الحالة فعلياً: الكنترولر لا يكراش).

---

### 1.3 `POST /wallet/recharge/initiate` — بدء جلسة شحن
**Input (Body):**
| الحقل | إجباري | النوع | الوصف |
|---|---|---|---|
| `amount` | ✅ | number | المبلغ بالدينار — **يُتحقق فعلياً من حدود الوسيلة المختارة** (`min_amount`/`max_amount` من 1.2)، مو رقم ثابت عام |
| `payment_method_id` | اختياري* | int | من قائمة 1.2 — **الأفضل استخدامه دائماً** |
| `payment_method` | اختياري* | string | كود الوسيلة (`code`) كبديل — لو أُرسل الاثنان يُعتمد `payment_method_id` |
| `reference_number` | اختياري | string, max:100 | رقم مرجعي حر |

*أرسل واحداً منهما على الأقل، وإلا يُفترض `sadad` افتراضياً — **لا تعتمد على هذا الافتراضي، أرسل `payment_method_id` صراحة دائماً**.

**✅ مثال حقيقي (amount=40, payment_method_id=31):**
```json
{
  "success": true,
  "message": "تم إنشاء جلسة الشحن وجاهزة لتنفيذ عملية الدفع.",
  "data": {
    "recharge_id": 506,
    "transaction_ref": "TXN-Z8NFWK-1789231078",
    "session_token": "MOCK_SESS_d3VbLYSh0gi8hjlan9ZkFmWu3TKmV825",
    "amount": 40,
    "currency": "د.ل",
    "payment_method": { "id": 31, "code": "sadad", "name": "خدمة سداد (Sadad)", "processing_type": "instant_simulation" },
    "mock_gateway_url": "https://.../api/parent/wallet/recharge/mock-pay?token=MOCK_SESS_...",
    "expires_in_minutes": 30
  }
}
```
| الحقل | إجباري |
|---|---|
| `data.recharge_id` | ✅ |
| `data.session_token` | ✅ **مطلوب بالخطوة التالية — احتفظ به بالـ state، لا يُعرض للمستخدم** |
| `data.transaction_ref`, `amount`, `currency` | ✅ |
| `data.payment_method.{id,code,name,processing_type}` | ✅ |
| `data.mock_gateway_url` | ✅ (لا حاجة لاستخدامه من تطبيق موبايل — نادِ mock-pay مباشرة) |
| `data.expires_in_minutes` | ✅ ثابت 30 (غير مُطبَّق فعلياً بالسيرفر — الجلسة لا تنتهي تلقائياً) |

**🚫 أخطاء 422 حقيقية اختُبرت:**
```json
{ "amount": ["الحد الأدنى للشحن هو 5 د.ل."] }
```
```json
{ "payment_method_id": ["القيمة المختارة لـ طريقة الدفع غير موجودة."] }
```

---

### 1.4 `POST /wallet/recharge/mock-pay` — تنفيذ الدفع (محاكاة)
⚠️ مقفول على `local/development/testing/staging` فقط — على الإنتاج يرجع `403 MOCK_GATEWAY_DISABLED`.

**Input (Body):**
| الحقل | إجباري | النوع | الوصف |
|---|---|---|---|
| `session_token` | ✅ | string | **نفس القيمة** من initiate، بلا تعديل |
| `card_number` | اختياري | string, max:30 | أي قيمة تُقبل (وهمي) — آخر 4 أرقام تُحفظ للعرض فقط |
| `card_holder` | اختياري | string, max:100 | نصي حر |
| `expiry_date` | اختياري | string, max:10 | أي صيغة نصية |
| `cvv` | اختياري | string, max:5 | أي قيمة |
| `otp` | اختياري | string, max:10 | غير مُتحقَّق فعلياً حالياً |

**الأفضل:** اعرض فورم بطاقة كامل (رقم/اسم/تاريخ/CVV) لتجربة واقعية رغم إنها محاكاة.

**✅ رد حقيقي:**
```json
{
  "success": true,
  "message": "تم الدفع بنجاح وإيداع المبلغ في المحفظة فوراً.",
  "data": {
    "status": "success",
    "transaction_ref": "TXN-Z8NFWK-1789231078",
    "amount": 40,
    "currency": "د.ل",
    "current_balance": 76.5,
    "invoice": { "id": 510, "invoice_number": "INV-2026-000506", "paid_at": "2026-09-12 18:37:58" }
  }
}
```
| الحقل | إجباري |
|---|---|
| `data.status` | ✅ `"success"` |
| `data.current_balance` | ✅ **الرصيد الجديد — حدّث الشاشة به مباشرة، لا تنادِ balance مرة ثانية** |
| `data.invoice.{id, invoice_number, paid_at}` | ✅ |

**🚫 خطأ حقيقي (إعادة استخدام نفس الجلسة):**
```json
{ "session_token": ["جلسة الدفع غير صالحة أو منتهية الصلاحية أو تم سدادها مسبقاً."] }
```

---

### 1.5 `POST /wallet/recharge` (Legacy — نفس منطق initiate)
نفس حقول initiate بالضبط (`amount`, `payment_method`, `reference_number`) — مسار بديل قديم، صار يعمل صح بعد إصلاح validation قديمة. **يُفضَّل استخدام initiate + mock-pay** لأنه الموثّق بالكامل مع كل الحالات.

---

### 1.6 `POST /wallet/hold-trip` — حجز مبلغ رحلة يومية
**Input (Body):**
| الحقل | إجباري | النوع | الوصف |
|---|---|---|---|
| `trip_id` | ✅ | int, exists:trips | **لا يُرسل `amount` إطلاقاً** — يُحسب بالسيرفر من الاشتراك |

**Output (201):** `data.{id, trip_id, driver_id, amount, hold_status, held_at, captured_at, available_at, disputed_at}`.

**أخطاء:** `403` لو الرحلة لا تخص أطفال الحساب **أو كان اشتراكها ملغى** (يُستبعد الآن)، `422` لو الرصيد غير كافٍ أو تعذّر تحديد السعر.

---

### 1.7 `POST /trips/{tripId}/dispute` — اعتراض مالي على رحلة
**Input:** `reason` (Body) ✅ string, min:5, max:1000. `tripId` بالمسار.
**Output (201):** كائن `TripDispute` كامل. **قيد زمني:** يُرفض بـ422 بعد 24 ساعة من الحجز.

---

### 1.8 `GET /invoices` و `GET /invoices/{id}` — الفواتير
**Input (index):** Query اختياري `status`, `type`.
**Output:** `data` = مصفوفة/كائن:
| الحقل | إجباري | ملاحظة |
|---|---|---|
| `id, invoice_number, amount, type, status, created_at` | ✅ | `type`: `proforma` (اشتراك غير مدفوع بعد) أو `receipt` (إيصال شحن مدفوع) |
| `due_date, paid_at` | اختياري (null حسب الحالة) | |
| `subscription_type, total_trips, completed_trips, driver_absences, student_absences` | ✅ لكن ذات معنى فقط لـ `proforma` | لفواتير `receipt` قيمها صفرية — فرّق بالعرض حسب `type` |
| `calculated_amount` | اختياري | يُملأ فقط عند التسوية النهائية |

---

### 1.9 `GET /support-tickets/financial-history` — كشف حساب سريع
**Output:** `data.invoices` (مصفوفة كـ1.8) + `data.transactions` (مصفوفة `{id, type, amount, created_at}` — `type` هنا `deposit`/`withdraw` من جدول Bavix الخام، لا تخلطه بأنواع دفتر الأستاذ).

---

## 2. السائق (Driver) — Base: `/api/driver` أو `/api/v1/driver` (نفس المسارات)

### 2.1 `GET /wallet/balance`
نفس شكل 1.1 تماماً.

### 2.2 `GET /wallet/payment-methods`
نفس شكل 1.2 بعد التنظيف — لكن **ترجع دائماً `data: []` حالياً** (لا وجود لوسيلة دفع مستهدِفة للسائق بعد قرار "ولي الأمر فقط"). اختبرت: لا كراش، رد نظيف `{"status":true,"data":[]}`.

### 2.3 `POST /wallet/recharge-request` — طلب شحن يدوي (يحتاج موافقة أدمن)
مختلف عن مسار ولي الأمر: **ليس فورياً**، السائق يرفع إثبات دفع والأدمن يوافق يدوياً.

**Input (multipart/form-data):**
| الحقل | إجباري | النوع | الوصف |
|---|---|---|---|
| `amount` | ✅ | number, min:1 | |
| `payment_method_id` | اختياري | int, exists:payment_methods | **بما إن القائمة فارغة الآن دائماً، اترك هذا الحقل فارغاً بالفرونت** — لو أُرسل رقم غير موجود/معطّل يُرفض 422 |
| `reference_number` | اختياري | string, max:100 | يُمنع التكرار لنفس السائق بحالة pending/approved |
| `proof_image` | اختياري | file (jpeg/png/jpg/webp), أقصى 5MB | صورة إثبات التحويل |
| `notes` | اختياري | string, max:500 | |

**Output (201):** `data` = `DriverRechargeRequest`: `{id, driver_id, payment_method_id: null, amount, proof_image_url, reference_number, status:"pending", notes, created_at, paymentMethod: null}`.

### 2.4 `GET /wallet/recharge-requests` — سجل طلبات الشحن
**Input:** Query اختياري `status`.
**Output:** `data` = مصفوفة نفس شكل 2.3 + `pagination.{current_page,last_page,total,per_page}`.

### 2.5 `GET /withdrawals` و `POST /withdrawals` — سحب الأرباح
**Input (POST):**
| الحقل | إجباري | النوع | الوصف |
|---|---|---|---|
| `amount` | ✅ | number | حد أدنى **5 د.ل** (`FinancialLedgerService::MIN_WITHDRAWAL_AMOUNT`)، حد أقصى 50,000 |
| `payment_method_details` | اختياري (الأفضل جعله إجبارياً بالفرونت) | object | تفاصيل تحويل السائق يدوياً |
| `payment_method_details.bank_name` | اختياري | string, max:100 | |
| `payment_method_details.account_number` | اختياري | string, max:100 | |
| `payment_method_details.account_name` | اختياري | string, max:100 | |
| `payment_method_details.mobile_number` | اختياري | string, max:20 | لمحافظ الدفع بالموبايل |

**قيود:** المبلغ ≤ الرصيد المتاح، **طلب سحب واحد معلّق فقط بنفس الوقت** (422 لو فيه pending سابق).

**Output:** `data` = `WithdrawalRequest`: `{id, driver_id, amount, wallet_balance_at_request, status, payment_method_details, created_at}`.

**🚫 مثال رفض حقيقي (رصيد غير كافٍ):**
```json
{ "message": "رصيد محفظتك غير كافٍ. الرصيد المتاح: 0 د.ل", "errors": { "amount": ["رصيد محفظتك غير كافٍ. الرصيد المتاح: 0 د.ل"] } }
```

### 2.6 `GET /invoices`, `GET /invoices/{id}` — نفس شكل 1.8 بمنظور السائق (فواتيره فقط).

### 2.7 `GET /support-tickets/financial-history` — نفس شكل 1.9.

---

## ✅ سيناريو الشحن الكامل — مثال حقيقي مُنفَّذ فعلياً

| # | النداء | المدخل | النتيجة |
|---|---|---|---|
| 1 | `GET /wallet/balance` | — | `36.5` د.ل |
| 2 | `GET /wallet/payment-methods` | — | 3 وسائل، اختار المستخدم `id=31` (sadad) |
| 3 | `POST /wallet/recharge/initiate` | `{"amount":40,"payment_method_id":31}` | `session_token=MOCK_SESS_...` |
| 4 | `POST /wallet/recharge/mock-pay` | `{"session_token":"MOCK_SESS_...","card_number":"4111...","card_holder":"...","expiry_date":"12/29","cvv":"321"}` | `current_balance=76.5` |
| 5 | `GET /wallet/balance` | — | `76.5` د.ل ✅ متطابق |

---

## ملخص التوصيات للفرونت

1. **استخدم `payment_method_id` (رقم) دائماً بدل `code`.**
2. تعامل مع الأكواد بشكل موحّد: `401` = تسجيل دخول مطلوب، `403` = صلاحية/ملكية ناقصة (اعرض `message` مباشرة، عربي جاهز للعرض)، `422` = فشل تحقق أو idempotency (اعرض `errors` أو `message`، كلها عربية الآن بعد إصلاح ملف الترجمة).
3. بعد `mock-pay` استخدم `current_balance` من نفس الرد لتحديث الشاشة — لا داعٍ لنداء إضافي.
4. قائمة وسائل الدفع للسائق فارغة حالياً بتصميم — اعرض حالة "لا توجد وسيلة دفع متاحة، أدخل تفاصيل التحويل يدوياً" بدل شاشة معطوبة.
