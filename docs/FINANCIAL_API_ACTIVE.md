# توثيق API — النظام المالي (الدوال المفعّلة فقط)

نسخة مُنقّحة من `FINANCIAL_API.md` تحتوي **فقط على المسارات المستخدمة فعلياً بالتدفق الحالي**. استُبعدت منها:
- `POST /wallet/recharge` (Legacy) — نفس وظيفة `initiate` بالضبط، بلا داعٍ لربط اثنين لنفس الشيء.
- `POST /admin/financial/recharges/{id}/process` + الدالتان `completeRecharge`/`failRecharge` — أداة يدوية لسيناريو "تحويل بنكي يدوي بإثبات" (`processing_type = manual_proof`) **غير مستخدم حالياً** (كل وسائل الدفع الثلاث الحالية فورية `instant_simulation`). شحن ولي الأمر فوري ذاتي الخدمة بالكامل ولا يحتاج موافقة أدمن — راجع الشرح إذا احتجته لاحقاً.

كل الروابط تحتاج هيدر `Authorization: Bearer {token}` (Sanctum). كل الردود JSON. **كل المبالغ بالـ Body/Response بالدينار (رقم عشري)** ما عدا `financial/ledger` و`financial/audit-logs` (بالقروش/cents). التخزين الداخلي فقط بالقروش ولا يظهر للفرونت أبداً.

---

## 1. ولي الأمر (Parent) — Base: `/api/parent`

### 1.1 `GET /wallet/balance`
**Input:** لا يوجد.
**Output:**
| الحقل | إجباري | النوع | الوصف |
|---|---|---|---|
| `success` | ✅ | boolean | |
| `data.balance` | ✅ | number | الرصيد الحالي بالدينار |
| `data.currency` | ✅ | string | ثابت `"د.ل"` |

---

### 1.2 `GET /wallet/payment-methods`
**Input:** لا يوجد.
**Output:** `data` = مصفوفة، كل عنصر:
| الحقل | إجباري | النوع | الوصف |
|---|---|---|---|
| `id` | ✅ | int | يُستخدم كـ `payment_method_id` بالخطوة التالية |
| `name_ar` | ✅ | string | الاسم المعروض |
| `name_en` | اختياري (قد null) | string | |
| `code` | ✅ | string | معرّف داخلي (`sadad`, `tadawe`, `moamalat` حالياً) |
| `processing_type` | ✅ | string | حالياً دائماً `instant_simulation` |
| `min_amount` / `max_amount` | ✅ | number | حدود المبلغ لهذه الوسيلة — تحقق منها قبل الإرسال |
| `icon_url` | اختياري (null حالياً) | string | |
| `instructions_ar/en`, `account_*`, `iban`, `wallet_number` | اختياري (null حالياً) | string | غير مستخدمة حالياً (خاصة بوسائل `manual_proof` غير المفعّلة) |

⚠️ لو رجعت `[]` — طبيعي (لا وسيلة مفعّلة)، اعرض رسالة مناسبة بدل شاشة فاضية.

---

### 1.3 `POST /wallet/recharge/initiate` — بدء جلسة شحن
**Input (Body):**
| الحقل | إجباري | النوع | الوصف |
|---|---|---|---|
| `amount` | ✅ | number | بالدينار، الحد الفعلي = حدود الوسيلة المختارة (من 1.2) |
| `payment_method_id` | ✅ (مُستحسن) | int | معرّف الوسيلة — **استخدمه دائماً بدل الكود النصي** |
| `payment_method` | بديل اختياري | string | كود الوسيلة (`code`) لو ما عندك id |
| `reference_number` | اختياري | string, max:100 | رقم مرجعي حر |

يُرفض بـ422 لو الوسيلة معطّلة (`is_active=false`) أو المبلغ خارج حدودها.

**Output (201):**
| الحقل | إجباري | الوصف |
|---|---|---|
| `data.recharge_id` | ✅ | معرّف الطلب |
| `data.session_token` | ✅ | **مطلوب بالخطوة التالية — لا تفقده** |
| `data.transaction_ref` | ✅ | |
| `data.amount`, `data.currency` | ✅ | |
| `data.payment_method.{id,code,name,processing_type}` | ✅ | |
| `data.mock_gateway_url` | ✅ | غير مطلوب استخدامه من تطبيق حقيقي |
| `data.expires_in_minutes` | ✅ | ثابت 30 (غير مُطبَّق فعلياً بالسيرفر — الجلسة لا تنتهي تلقائياً) |

---

### 1.4 `POST /wallet/recharge/mock-pay` — تنفيذ الدفع وإيداع المبلغ فوراً
⚠️ **مقفول على بيئات `local/development/testing/staging`** — على الإنتاج `403 MOCK_GATEWAY_DISABLED`. هذا هو مكان "بوابة الدفع الحقيقية" لاحقاً — حالياً محاكاة كاملة.

**Input (Body):**
| الحقل | إجباري | النوع | الوصف |
|---|---|---|---|
| `session_token` | ✅ | string | من نتيجة 1.3 |
| `card_number` | اختياري | string, max:30 | وهمي — أي قيمة تُقبل |
| `card_holder` | اختياري | string, max:100 | |
| `expiry_date` | اختياري | string, max:10 | |
| `cvv` | اختياري | string, max:5 | |
| `otp` | اختياري | string, max:10 | غير مُتحقَّق فعلياً حالياً |

**Output (200):**
| الحقل | إجباري | الوصف |
|---|---|---|
| `data.status` | ✅ | `"success"` |
| `data.transaction_ref`, `data.amount`, `data.currency` | ✅ | |
| `data.current_balance` | ✅ | **الرصيد الجديد — حدّث الشاشة به فوراً بدل استدعاء 1.1 مرة ثانية** |
| `data.invoice.{id, invoice_number, paid_at}` | ✅ | إيصال تلقائي |

**✅ مثال رد حقيقي (مُختبَر):**
```json
{ "success": true, "message": "تم الدفع بنجاح وإيداع المبلغ في المحفظة فوراً.",
  "data": { "status": "success", "transaction_ref": "TXN-BCRASW-1788944894", "amount": 25,
            "currency": "د.ل", "current_balance": 2635,
            "invoice": { "id": 183, "invoice_number": "INV-2026-000011", "paid_at": "2026-09-09 11:10:31" } } }
```
**أخطاء يجب معالجتها:** `422` (جلسة غير صالحة/منتهية/مستخدمة)، `403 MOCK_GATEWAY_DISABLED` (إنتاج).

---

### 1.5 `POST /wallet/hold-trip` — حجز مبلغ رحلة يومية
**Input (Body):** `trip_id` ✅ (int, exists:trips) فقط — **لا تُرسل `amount`**، السعر يُحسب بالسيرفر.
**Output (201):** `data.{id, trip_id, driver_id, amount, hold_status, held_at, captured_at, available_at, disputed_at}`.
**أخطاء:** `403` لو الرحلة لا تخص أطفال الحساب **أو كان اشتراكها ملغى**، `422` لو الرصيد غير كافٍ أو تعذّر تحديد السعر.

---

### 1.6 `POST /trips/{tripId}/dispute` — اعتراض مالي
**Input:** `reason` ✅ (Body, string, min:5, max:1000)، `tripId` بالمسار.
**Output (201):** كائن `TripDispute` كامل. قيد زمني: يُرفض بعد 24 ساعة من الحجز.

---

### 1.7 `GET /invoices` و `GET /invoices/{id}`
**Input (index):** Query اختياري `status`, `type`.
**Output:** مصفوفة/كائن:
| الحقل | إجباري | الوصف |
|---|---|---|
| `id, invoice_number, amount, type, status, created_at` | ✅ | `type`: `proforma` (اشتراك غير مدفوع بعد) أو `receipt` (شحن محفظة مدفوع) |
| `due_date` | اختياري | |
| `subscription_type, total_trips, completed_trips, driver_absences, student_absences` | ✅ لكن بلا دلالة لفواتير `receipt` | |
| `calculated_amount` | اختياري (null غالباً) | يُملأ فقط عند التسوية النهائية |
| `paid_at` | اختياري (null لغير المدفوعة) | |

---

### 1.8 `GET /support-tickets/financial-history`
**Input:** لا يوجد.
**Output:** `data.invoices` (كشكل 1.7) + `data.transactions` (مصفوفة `{id, type: deposit/withdraw, amount, created_at}` من جدول المحفظة الخام).

---

## 2. السائق (Driver) — Base: `/api/driver` أو `/api/v1/driver`

### 2.1 `GET /wallet/balance` — نفس شكل 1.1.

### 2.2 `GET /wallet/payment-methods` — نفس شكل 1.2، مفلترة لـ driver/both.

### 2.3 `POST /wallet/recharge-request` — طلب شحن (**هذا مسار حي فعلياً**: يدوي + موافقة أدمن)
**Input (multipart/form-data):**
| الحقل | إجباري | النوع | الوصف |
|---|---|---|---|
| `amount` | ✅ | number, min:1 | |
| `payment_method_id` | اختياري | int | يُتحقق من `is_active` وحدود min/max فعلياً |
| `reference_number` | اختياري | string, max:100 | مكرر لنفس السائق بحالة pending/approved يُرفض |
| `proof_image` | اختياري | file (jpeg/png/jpg/webp), max 5MB | صورة إثبات التحويل |
| `notes` | اختياري | string, max:500 | |

**Output (201):** `data` = `DriverRechargeRequest` مع `paymentMethod` محمّلة، `status="pending"`.

### 2.4 `GET /wallet/recharge-requests` — سجل الطلبات
**Input:** Query اختياري `status`. **Output:** مصفوفة + `pagination`.

### 2.5 `GET /withdrawals` و `POST /withdrawals` — سحب الأرباح
**Input (POST):**
| الحقل | إجباري | النوع | الوصف |
|---|---|---|---|
| `amount` | ✅ | number | حد أدنى 5 د.ل، حد أقصى 50,000 |
| `payment_method_details` | اختياري (مُستحسن إجباري بالفرونت) | object | `bank_name`, `account_number`, `account_name`, `mobile_number` — كلها اختيارية فردياً |

**قيود:** المبلغ ≤ الرصيد المتاح، وطلب سحب pending واحد فقط بنفس الوقت.
**Output:** `data.{id, driver_id, amount, wallet_balance_at_request, status, payment_method_details, created_at}`.

**✅ الحلقة كاملة مُختبَرة فعلياً:** شحن 200 د.ل → موافقة أدمن → رصيد فوري 200 → طلب سحب 50 → الرصيد ينزل فوراً لـ150 (يُجمَّد لحين قرار الأدمن) → موافقة أدمن → السحب يكتمل.

### 2.6 `GET /invoices`, `GET /invoices/{id}` — نفس شكل 1.7 بمنظور السائق.

### 2.7 `GET /support-tickets/financial-history` — نفس شكل 1.8.

---

## 3. الأدمن (Admin) — Base: `/api/admin`
كل مسار محمي بـ `permission:xxx` (Super Admin يتجاوزها تلقائياً).

### 3.1 لوحة القيادة
**`GET /financial/summary`** (`financial.view_summary`): `data.{parents_escrow_pool, driver_pending_pool, driver_available_pool, platform_revenue_pool, penalty_pool}` (دينار) + `data.{pending_withdrawals_count, pending_recharges_count, pending_disputes_count, pending_escrows_count}`.

**`GET /financial/solvency-check`** (`financial.view_summary`): `data.is_solvent` (boolean) + `data.checks.{driver_wallet_mirror, escrow_backing, no_negative_pools}` + أرصدة الأحواض + `parent_wallets_dinar`/`driver_wallets_dinar` (موثوقة الآن بعد الإصلاح).

### 3.2 الفواتير (نظرة شاملة، محمّلة العلاقات)
**`GET /financial/invoices`** (`financial.view_ledger`): Query اختياري `status, type, search, date_from, date_to, per_page`.
**`GET /financial/invoices/{id}`**: نفس الشكل، كائن واحد.

### 3.3 طلبات السحب (سائقين) — هذا هو المسار الحي لإتمام السحب
**`GET /financial/withdrawals`** (`financial.manage_withdrawals`): Query `status, search, date_from, date_to, per_page`.
**`GET /financial/withdrawals/{id}`**: `data.{id, driver_id, driver_name, driver_phone, amount, wallet_balance_at_req, status, payment_method_details, rejection_reason, created_at, processed_at, admin_name}` — كل الحقول حقيقية وموجودة.
**`POST /financial/withdrawals/{id}/process`**: Input Body: `action` ✅ (`approve`/`reject`), `rejection_reason` (إجباري فقط لو reject). idempotent (422 لو مو pending).

### 3.4 مراجعة طلبات شحن السائقين — المسار الحي لإتمام شحن السائق
**`GET /admin/driver-recharges`** (`financial.manage_recharges`): Query `status, driver_id, payment_method_id, search, per_page`.
**`GET /admin/driver-recharges/{id}`**: كائن واحد.
**`POST /admin/driver-recharges/{id}/approve`**: Input Body: `notes` اختياري. يودع المبلغ فوراً بمحفظة السائق.
**`POST /admin/driver-recharges/{id}/reject`**: Input Body: `rejection_reason` ✅ (min:3, max:500).

### 3.5 الأمانات المعلّقة
**`GET /financial/escrows`** (`financial.release_escrows`): `data.{pending_amount, eligible_amount, trips_count, eligible_count, oldest_escrow}`.
**`POST /financial/release-escrows`**: لا Input. `data.released_count`.

### 3.6 النزاعات المالية
**`GET /financial/disputes`** (`financial.resolve_disputes`): Query `status, per_page`.
**`GET /financial/disputes/{id}`**: `data.{id, trip_id, parent:{id,name,phone}, driver:{id,name,phone}, amount, reason, status, resolution_notes, created_at, resolved_at}`.
**`POST /financial/disputes/{disputeId}/resolve`**: Input Body: `resolution` ✅ (`resolve_parent_refunded`/`resolve_driver_paid`), `notes` اختياري. idempotent.

### 3.7 تسويات العقود
**`GET /financial/contracts/pending-settlements`** (`financial.manage_settlements`): Query `per_page`.
**`POST /financial/contracts/{contractId}/settle-monthly`**: لا Input. تقرير قراءة فقط للأرشفة، لا يحرّك مالاً (الصرف تلقائي رحلة برحلة).
**`GET /financial/contracts/{contractId}/termination-preview`**: Query `terminated_by` (اختياري)، `is_arbitrary_parent` (boolean). معاينة فقط.
**`POST /financial/contracts/{contractId}/terminate-mid-month`**: Input Body: `terminated_by` ✅ (`parent`/`driver`/`admin`), `is_arbitrary_parent` اختياري.

### 3.8 إلغاء الرحلات بمصفوفة الغرامات
**`GET /financial/trips/{tripId}/cancel-preview`**: Query `cancelled_by` (اختياري).
**`POST /financial/trips/{tripId}/cancel-with-matrix`**: Input Body: `cancelled_by` ✅ (`parent`/`driver`/`no_show`). idempotent.

### 3.9 دفتر الأستاذ والتدقيق (قراءة فقط، بالقروش cents وليس الدينار)
**`GET /financial/ledger`** (`financial.view_ledger`): Query `type, status, search, date_from, date_to, per_page`.
**`GET /financial/audit-logs`**: نفس الشكل، مفلتر على قيود إدارية.

### 3.10 إدارة وسائل الدفع (CRUD كامل) — `/admin/payment-methods`
صلاحية `financial.manage_payment_methods`.
- **`GET /`**: Query `target_audience, processing_type, is_active, search, per_page`.
- **`POST /`**: Input إجباري: `name_ar, code (فريد), target_audience (parent/driver/both), processing_type (instant_simulation/manual_proof)`. اختياري: `name_en, account_name, account_number, iban, wallet_number, icon_url, min_amount (افتراضي 1), max_amount (افتراضي 50000, يجب > min_amount), instructions_ar/en, is_active (افتراضي true), sort_order`.
- **`GET /{id}`**: عنصر واحد.
- **`PUT /{id}`**: كل الحقول اختيارية (تعديل جزئي)؛ يُرفض 422 لو min ≥ max بعد الدمج مع القيم القديمة.
- **`PATCH /{id}/toggle-status`**: لا Input. يعكس `is_active` — **يمنع الاستخدام فعلياً الآن، مو بس العرض**.
- **`DELETE /{id}`**: حذف ناعم — السجلات التاريخية تبقى تعرض اسم الوسيلة صحيحاً حتى بعد الحذف.

---

## ملخص التوصيات
1. **استخدم `payment_method_id` (رقم) دائماً** بكل نداءات الشحن.
2. حقول `financial/ledger` و`financial/audit-logs` فقط بالقروش — كل شيء آخر بالدينار.
3. الأكواد: `401` = لازم تسجيل دخول، `403` = صلاحية/ملكية ناقصة (اعرض `message` مباشرة، جاهزة عربي)، `422` = تحقق فشل أو idempotency guard (اعرض `errors` أو `message`).
4. شحن ولي الأمر **فوري بالكامل** — لا تصمم شاشة "بانتظار موافقة الإدارة" لشحن ولي الأمر (هذي فقط لشحن السائق وسحب أرباحه).
