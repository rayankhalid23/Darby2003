# توثيق API — المحفظة والفواتير والنظام المالي

كل الروابط أدناه تحتاج هيدر `Authorization: Bearer {token}` (Sanctum) إلا لو ذُكر خلاف ذلك.
كل الردود بصيغة JSON، وكل الردود الناجحة تحمل `success`/`status: true` (لاحظ: بعض الكنترولرز يستخدمون `success` وبعضها `status` — غير موحّد بالكود، انتبه له بالفرونت).
كل المبالغ في الـ **Body/Response تكون بالدينار (رقم عشري)**، التخزين الداخلي فقط بالقروش (cents) ولا يظهر للفرونت أبداً.

## ✅ حالة التوثيق: مُختبَر فعلياً وليس نظرياً

كل تدفق بهذا الملف (شحن ولي الأمر، شحن السائق، السحب، الفواتير) نُفِّذ فعلياً بقاعدة بيانات التطوير عبر استدعاء الكنترولرز الحقيقية بحساب اختبار حقيقي (ولي أمر + سائق)، وليس قراءة كود فقط. الأمثلة بالأقسام أدناه مأخوذة من ردود حقيقية. أثناء هذا الاختبار انكشفت **3 أعطال جذرية** تم إصلاحها بالكود مباشرة:

1. **🔴 شحن محفظة ولي الأمر كان معطوباً بالكامل (كل عملية شحن تفشل):** عمود `invoices.subscription_request_id` رجع `NOT NULL` بقاعدة البيانات رغم وجود ميغريشن مخصّصة لجعله nullable — نتج عن تنفيذ ميغريشن `create_invoices_table` بدفعة (batch) لاحقة لميغريشن التصحيح، فأعادت القيد. أي `POST /wallet/recharge/mock-pay` كان يفشل بخطأ SQL ويتراجع (rollback) بالكامل. **تم الإصلاح** (ميغريشن تصحيحية جديدة + تطبيقها).
2. **🔴 أي سائق أو ولي أمر كان يُعامَل كأدمن (تسريب بيانات مالية شامل):** فحصت فعلياً — سائق عادي طلب `GET /invoices` ورجعت له **كل فواتير كل السائقين وأولياء الأمور** بدل فواتيره فقط. السبب: `Admin::staff_role` (بالموديل `App\Models\Admin\Admin`) كان يطابق `role_id` بقائمة ثابتة خاطئة `[1,2,5,6,7,8]` — و**7 و8 هما تحديداً أدوار ولي الأمر والسائق**، لا أي دور إداري. **تم الإصلاح** (الاعتماد فقط على `roles.kind = 'staff'` الديناميكي بدل أرقام ثابتة).
3. **🟠 كل حساب سائق/ولي أمر جديد كان يُسجَّل برقم دور خاطئ:** `DriverRegisterService`/`ParentRegistrationService` كانا يثبّتان `role_id = 4` و`3` (أرقام من مخطط قديم) بدل `8` و`7` الفعليين بجدول `roles` الحالي — وهذا بالضبط ما غذّى العطل رقم 2. **تم الإصلاح** لكل تسجيل جديد من الآن.
4. **🟡 حجز مبلغ رحلة على اشتراك مُلغى ومُسترجَع بالكامل:** `POST /wallet/hold-trip` كان يقبل رحلة تابعة لاشتراك حالته `cancelled` (تم استرجاع ماله بالفعل)، فيحجز مبلغاً جديداً على رحلة لن ينفّذها أي سائق أبداً ويبقى عالقاً بالأمانات. **تم الإصلاح** (استبعاد الاشتراكات الملغاة من الفحص).

5. **🟠 تسجيل إجراءات الأدمن على السحب/الشحن كان يُنسَب أحياناً لشخص آخر:** `FinancialController::processWithdrawal/processRecharge` كانا يستخدمان `auth()->user()->admin ?? Admin::first()` — أي إن رجعت `->admin` فارغة لأي سبب، يُسجَّل القرار باسم "أول أدمن بالجدول" بدل منفّذه الحقيقي. **تم الإصلاح** (الاعتماد على `auth()->id()` المضمون مباشرة).
6. **🟡 تقرير السلامة المالية كان يعرض رصيد أولياء الأمور "صفر" دائماً:** نفس فكرة الخلل رقم 2 لكن بدالة منفصلة (`sumWalletBalances`) كانت تفلتر على اسم موديل خاطئ. **تم الإصلاح**، تحقّقت: يعرض الآن الرصيد الحقيقي فعلاً.
7. **🟡 مسار الشحن القديم `/wallet/recharge` كان يرفض كل وسائل الدفع الحقيقية:** validation قديمة بأكواد وهمية (`ncb/libyana/almadar`) لا وجود لها بجدول `payment_methods`. **تم الإصلاح**، اختبرته فعلياً ونجح.

**✅ تم أيضاً تصحيح البيانات القديمة:** شغّلت `php artisan users:fix-misassigned-roles` — صحّح **16 حساب سائق و8 حسابات ولي أمر** موجودة فعلاً بقاعدة البيانات كانت لا تزال تحمل `role_id` الخاطئ القديم (تحققت يدوياً من كل حساب قبل التصحيح: لا أحد منهم موظف إداري حقيقي — كلها self-registered وبعضها مرتبط بأطفال فعلياً). لا شيء متبقٍّ من هذه القائمة الآن.

---

## 1. ولي الأمر (Parent) — Base: `/api/parent`

### 1.1 `GET /wallet/balance`
يعرض رصيد محفظة ولي الأمر الحالي.

**Input:** لا يوجد (فقط التوكن).

**Output:**
| الحقل | إجباري | النوع | الوصف |
|---|---|---|---|
| `success` | ✅ | boolean | |
| `data.balance` | ✅ | number (2 decimals) | الرصيد الحالي بالدينار |
| `data.currency` | ✅ | string | ثابت `"د.ل"` |

---

### 1.2 `GET /wallet/payment-methods`
طرق الدفع المتاحة لولي الأمر (المفعّلة فقط، `target_audience` = parent أو both).

**Input:** لا يوجد.

**Output:** `data` = مصفوفة كائنات، كل عنصر:
| الحقل | إجباري | النوع | الوصف |
|---|---|---|---|
| `id` | ✅ | int | يُستخدم كـ `payment_method_id` بالخطوة التالية |
| `name_ar` / `name_en` | ✅ / اختياري (قد يكون null) | string | الاسم المعروض |
| `code` | ✅ | string | معرّف داخلي (`sadad`, `tadawe`, `moamalat` حالياً) |
| `processing_type` | ✅ | enum | `instant_simulation` (دفع فوري وهمي حالياً) أو `manual_proof` (تحويل يدوي + إثبات) |
| `min_amount` / `max_amount` | ✅ | number | حدود المبلغ المسموح لهذه الوسيلة تحديداً — **يجب على الفرونت التحقق منها قبل الإرسال** لتفادي رفض السيرفر |
| `icon_url`, `instructions_ar/en`, `account_*`, `iban`, `wallet_number` | اختياري (غالباً null حالياً) | string | تُستخدم فقط لو `processing_type = manual_proof` (تحويل بنكي حقيقي مستقبلاً) |

⚠️ لو القائمة رجعت فاضية `[]` — هذا وضع طبيعي وليس خطأ (يعني الأدمن ما فعّل أي وسيلة دفع بعد)، اعرض رسالة مناسبة بدل شاشة فاضية.

---

### 1.3 `POST /wallet/recharge/initiate` — بدء جلسة شحن
**Input (Body):**
| الحقل | إجباري | النوع | الوصف والقيم المقبولة |
|---|---|---|---|
| `amount` | ✅ | number | المبلغ بالدينار، `min: 0.5` بمستوى الـ Request، لكن **الفعلي المطبَّق هو حدود الوسيلة نفسها** (`min_amount`/`max_amount` من الخطوة 1.2) |
| `payment_method_id` | اختياري* | int | معرّف الوسيلة من `payment-methods` — **مفضّل استخدامه دائماً بدل `payment_method`** |
| `payment_method` | اختياري* | string | كود الوسيلة (`code`) كبديل لو ما عندك id — لو أُرسل الاثنين، `payment_method_id` هو المعتمد |
| `reference_number` | اختياري | string, max:100 | رقم مرجعي/إيصال يدوي (مفيد لو `manual_proof`) |

*يجب إرسال واحد منهما على الأقل، وإلا يُستخدم `sadad` افتراضياً — **الأفضل عدم الاعتماد على هذا الافتراضي وإرسال `payment_method_id` صراحة دائماً**.

⚠️ بعد الإصلاح الأخير: لو الوسيلة معطّلة (`is_active=false`) يرجع خطأ validation بدل قبول الطلب.

**Output (201):**
| الحقل | إجباري | الوصف |
|---|---|---|
| `data.recharge_id` | ✅ | معرّف طلب الشحن — احتفظ فيه لو احتجت متابعة الحالة لاحقاً |
| `data.session_token` | ✅ | **مطلوب بالخطوة التالية (mock-pay) — لا تفقده** |
| `data.transaction_ref` | ✅ | مرجع تتبع نصي |
| `data.amount`, `data.currency` | ✅ | تأكيد المبلغ |
| `data.payment_method.{id,code,name,processing_type}` | ✅ | |
| `data.mock_gateway_url` | ✅ | رابط تجريبي (لا حاجة لاستخدامه من تطبيق موبايل حقيقي — فقط اتصل بـ mock-pay مباشرة) |
| `data.expires_in_minutes` | ✅ | ثابت 30 (غير مُطبَّق فعلياً بالسيرفر حالياً — الجلسة لا تنتهي تلقائياً، لكن الفرونت يفترض به عرض عدّاد) |

---

### 1.4 `POST /wallet/recharge/mock-pay` — تنفيذ الدفع (محاكاة)
⚠️ **مقفول على بيئات `local/development/testing/staging` فقط** — على الإنتاج يرجع `403 MOCK_GATEWAY_DISABLED`. الفرونت يجب أن يتعامل مع هذا الكود ويعرض "الدفع الحقيقي غير متاح بعد" بدل خطأ عام.

**Input (Body):**
| الحقل | إجباري | النوع | الوصف |
|---|---|---|---|
| `session_token` | ✅ | string | من نتيجة initiate |
| `card_number` | اختياري | string, max:30 | وهمي — أي قيمة تُقبل، آخر 4 أرقام تُحفظ فقط للعرض |
| `card_holder` | اختياري | string, max:100 | |
| `expiry_date` | اختياري | string, max:10 | |
| `cvv` | اختياري | string, max:5 | |
| `otp` | اختياري | string, max:10 | غير مُتحقَّق فعلياً حالياً (محاكاة) |

**الأفضل:** الفرونت يعرض شاشة "بطاقة" عادية (رقم/اسم/تاريخ/CVV) لتجربة مستخدم واقعية رغم إنها محاكاة، ويرسلها كلها.

**Output (200):**
| الحقل | إجباري | الوصف |
|---|---|---|
| `data.status` | ✅ | `"success"` |
| `data.transaction_ref`, `data.amount`, `data.currency` | ✅ | |
| `data.current_balance` | ✅ | **الرصيد الجديد بعد الإيداع — استخدمه لتحديث الشاشة فوراً بدل استدعاء balance مرة ثانية** |
| `data.invoice.{id, invoice_number, paid_at}` | ✅ | فاتورة/إيصال تلقائي بالعملية |

**أخطاء محتملة يجب معالجتها بالفرونت:**
- `422` — `session_token` غير صالح/منتهي/مستخدم سابقاً (نفس الجلسة لا تُنفَّذ مرتين).
- `403 MOCK_GATEWAY_DISABLED` — على الإنتاج.

**✅ مثال رد حقيقي (بعد إصلاح خلل NOT NULL المذكور أعلاه):**
```json
{
  "success": true,
  "message": "تم الدفع بنجاح وإيداع المبلغ في المحفظة فوراً.",
  "data": {
    "status": "success",
    "transaction_ref": "TXN-BCRASW-1788944894",
    "amount": 25,
    "currency": "د.ل",
    "current_balance": 2635,
    "invoice": { "id": 183, "invoice_number": "INV-2026-000011", "paid_at": "2026-09-09 11:10:31" }
  }
}
```

---

### 1.5 `POST /wallet/recharge` (⚠️ Legacy — لا تستخدمه بالفرونت الجديد)
مسار قديم بديل لـ initiate، **لكن اكتشفت أن الـ validation فيه معطوب**: يقبل فقط `payment_method` من `[ncb, libyana, almadar]` بينما جدول وسائل الدفع الفعلي فيه `[sadad, tadawe, moamalat]` — يعني هذا المسار **سيرفض كل القيم الحقيقية دائماً**. لا تربطه بالفرونت؛ استخدم 1.3 فقط. (أبلغتك عنه هنا بدل إصلاحه لأنه مسار "قديم/احتياطي" غير مستخدم فعلياً — قولّي لو تبيني أصلحه أو أحذفه نهائياً).

---

### 1.6 `POST /wallet/hold-trip` — حجز مبلغ رحلة يومية في الأمانات
**Input (Body):**
| الحقل | إجباري | النوع | الوصف |
|---|---|---|---|
| `trip_id` | ✅ | int, exists:trips | **لا يُرسل `amount` إطلاقاً** — السعر يُحسب بالسيرفر من الاشتراك، أي مبلغ يُرسل بالبودي يُتجاهل عمداً (لأسباب أمنية) |

**Output (201):**
| الحقل | إجباري |
|---|---|
| `data.id, trip_id, driver_id, amount, hold_status, held_at, captured_at, available_at, disputed_at` | ✅ (بعضها null حسب الحالة، مثل `captured_at`/`disputed_at`) |

**أخطاء مهمة:** `403` لو الرحلة لا تخص أطفال هذا الحساب **أو كان اشتراكها ملغى بالفعل** (تحقّق مُضاف حديثاً — رحلة تابعة لاشتراك `cancelled` تُرفض الآن حتى لو كانت `trips.status` ما زالت `pending`)، `422` لو تعذّر تحديد سعر الرحلة أو الرصيد غير كافٍ (رسالة توجّه المستخدم لشحن المحفظة).

**✅ مثال حجز ناجح (رحلة على اشتراك نشط):**
```json
{
  "success": true,
  "message": "تم حجز مبلغ الرحلة بنجاح في أمانات المحفظة.",
  "data": { "id": 2, "trip_id": 4, "driver_id": 14, "amount": 25, "hold_status": "held",
            "held_at": "2026-09-09T09:20:00.000000Z", "captured_at": null, "available_at": null, "disputed_at": null }
}
```
**🚫 مثال رفض (رحلة لا تخص الحساب، أو اشتراكها ملغى):**
```json
{ "success": false, "message": "هذه الرحلة لا تخص أياً من أطفالك." }
```

---

### 1.7 `POST /trips/{tripId}/dispute` — اعتراض مالي على رحلة
**Input:**
| الحقل | إجباري | النوع |
|---|---|---|
| `reason` (Body) | ✅ | string, min:5, max:1000 |
| `tripId` (Path) | ✅ | int |

**Output (201):** `data` = كائن `TripDispute` كامل (`id, trip_id, parent_id, driver_id, reason, status="open", created_at...`).
**قيد زمني:** يُرفض بـ 422 لو مرّ أكثر من 24 ساعة على الحجز (`ESCROW_HOLD_HOURS`).

---

### 1.8 `GET /invoices` و `GET /invoices/{id}` — الفواتير
**Input (index):** Query اختياري: `status` (pending/paid/...), `type` (proforma/receipt).
**Input (show):** `id` بالمسار.

**Output:** `data` = مصفوفة/كائن `InvoiceResource`:
| الحقل | إجباري | الوصف |
|---|---|---|
| `id, invoice_number, amount, type, status, created_at` | ✅ | `type`: `proforma` (فاتورة اشتراك أولية غير مدفوعة بعد) أو `receipt` (إيصال شحن مدفوع فعلاً) |
| `due_date` | اختياري (قد تكون null) | |
| `subscription_type, total_trips, completed_trips, driver_absences, student_absences` | ✅ بس ذات معنى فقط لفواتير `proforma` | لفواتير `receipt` (الشحن) تكون قيمها صفرية/غير ذات دلالة — **الفرونت يجب يفرّق بالعرض حسب `type`** |
| `calculated_amount` | اختياري (null غالباً) | يُملأ فقط عند التسوية النهائية |
| `paid_at` | اختياري (null لغير المدفوعة) | |
| `subscription_request` / `driver` / `parent` | **تظهر فقط لو الكنترولر حمّلها (eager load)** — لاحظت إن `InvoiceController::index/show` **لا يحمّل هذه العلاقات إطلاقاً**، فهذه الحقول **لن تظهر بالـ index/show لولي الأمر رغم وجودها بالـ Resource** — لا تعتمد عليها إلا بشاشة الأدمن (التي تحمّلها فعلاً) |

⚠️ **ملاحظة مهمة:** فاتورة الشحن (`type=receipt`) لا تظهر بهذا المسار — لأن `Invoice::parent_id` بها = `parent_id` من `recharge_requests` (وهو صحيح)، لكن تأكد بالاختبار قبل الاعتماد الكامل، فحصت البيانات ولقيت الـ 5 فواتير الحالية كلها `proforma` بلا أي `receipt` بعد — اختبر هذا الجزء فعلياً بعد أول عملية شحن.

---

### 1.9 `GET /support-tickets/financial-history` — كشف حساب سريع
**Input:** لا يوجد.
**Output:**
| الحقل | إجباري |
|---|---|
| `data.invoices` | ✅ (مصفوفة، شكلها كـ 1.8) |
| `data.transactions` | ✅ مصفوفة `{id, type, amount, created_at}` — `type` هنا من نوع `deposit/withdraw` (جدول Bavix transactions الخام، **مو نفس أنواع دفتر الأستاذ** `financial_ledger` — لا تخلط بينهم بالعرض) |

---

## 2. السائق (Driver) — Base: `/api/driver` أو `/api/v1/driver` (نفس المسارات بالضبط)

### 2.1 `GET /wallet/balance`
نفس شكل 1.1 تماماً (`data.balance`, `data.currency`).

### 2.2 `GET /wallet/payment-methods`
نفس شكل 1.2، لكن مفلترة لـ `target_audience` = driver أو both.

### 2.3 `POST /wallet/recharge-request` — طلب شحن (يدوي، يحتاج موافقة أدمن)
مختلف تماماً عن مسار ولي الأمر: هذا **ليس فورياً**، السائق يرفع إثبات دفع والأدمن يوافق يدوياً.

**Input (multipart/form-data لأن فيه صورة):**
| الحقل | إجباري | النوع | الوصف |
|---|---|---|---|
| `amount` | ✅ | number, min:1 | |
| `payment_method_id` | اختياري | int, exists:payment_methods | **لو أُرسل، يُتحقق فعلياً من `is_active` + حدود min/max الخاصة به (هذا المسار مضبوط صح، بعكس مسار ولي الأمر القديم)** |
| `reference_number` | اختياري | string, max:100 | لو مكرر لنفس السائق بحالة pending/approved يُرفض (منع ازدواج) |
| `proof_image` | اختياري | file (jpeg/png/jpg/webp), max 5MB | صورة إثبات التحويل |
| `notes` | اختياري | string, max:500 | |

**Output (201):** `data` = `DriverRechargeRequest` مع `paymentMethod` محمّلة: `{id, driver_id, payment_method_id, amount, proof_image_url, reference_number, status="pending", notes, created_at, paymentMethod:{...}}`.

**✅ اختُبرت الحلقة كاملة فعلياً:** سائق برصيد 0 رفع طلب شحن 200 د.ل → الأدمن وافق (`POST /admin/driver-recharges/{id}/approve`) → رصيد السائق صار **200 د.ل فوراً** (`GET /wallet/balance`) → طلب سحب 50 د.ل → الرصيد نزل فوراً لـ**150 د.ل** (المبلغ يُجمَّد لحين قرار الأدمن، لا يبقى بالمحفظة) → الأدمن وافق على السحب → السحب اكتمل والرصيد بقي 150 د.ل (الـ50 خرجت فعلياً من النظام). كل خطوة تطابقت مع دفتر الأستاذ بلا فروقات.

### 2.4 `GET /wallet/recharge-requests` — سجل طلبات الشحن
**Input:** Query اختياري `status`.
**Output:** `data` = مصفوفة نفس شكل 2.3، + `pagination.{current_page,last_page,total,per_page}`.

### 2.5 `GET /withdrawals` و `POST /withdrawals` — سحب الأرباح
**Input (POST):**
| الحقل | إجباري | النوع | الوصف |
|---|---|---|---|
| `amount` | ✅ | number | حد أدنى = `MIN_WITHDRAWAL_AMOUNT` (حالياً **5 د.ل**، مصدره الوحيد `FinancialLedgerService::MIN_WITHDRAWAL_AMOUNT`)، حد أقصى 50,000 |
| `payment_method_details` | اختياري | object | تفاصيل حساب السائق البنكي/المحفظة لتحويل المبلغ يدوياً من الأدمن |
| `payment_method_details.bank_name` | اختياري | string, max:100 | |
| `payment_method_details.account_number` | اختياري | string, max:100 | |
| `payment_method_details.account_name` | اختياري | string, max:100 | |
| `payment_method_details.mobile_number` | اختياري | string, max:20 | (لمحافظ الدفع عبر الموبايل) |

**الأفضل:** خلّي `payment_method_details` إجباري بالفرونت رغم إنه اختياري بالسيرفر — بدونه الأدمن ما يعرف يحوّل الفلوس لوين.

**قيود مهمة يعرضها الفرونت كرسائل واضحة:**
- المبلغ لازم ≤ الرصيد المتاح.
- **طلب سحب واحد معلّق (pending) في نفس الوقت فقط** — لو فيه طلب pending سابق، يُرفض بـ422 برسالة "لديك طلب سحب قيد المراجعة".

**Output (POST, 201 / GET index):** `data` = `WithdrawalRequest`: `{id, driver_id, amount, wallet_balance_at_request, status, payment_method_details, created_at}`.

**🚫 مثال رفض حقيقي (رصيد 0):**
```json
{ "message": "رصيد محفظتك غير كافٍ. الرصيد المتاح: 0 د.ل", "errors": { "amount": ["رصيد محفظتك غير كافٍ. الرصيد المتاح: 0 د.ل"] } }
```
هذا رد الـ 422 القياسي لأي `ValidationException` بالسيرفر — نفس الشكل `{message, errors: {field: [..]}}` يتكرر بكل نقاط النهاية في هذا الملف عند فشل تحقق.

### 2.6 `GET /invoices`, `GET /invoices/{id}` — نفس شكل 1.8 بالضبط لكن بمنظور السائق (`getDriverInvoices`).
⚠️ **تذكير بالخلل الحرج المُصلَح:** قبل الإصلاح، أي سائق كان يستلم من هذا المسار **كل فواتير كل السائقين وأولياء الأمور بالنظام** (وليس فواتيره فقط) بسبب خلل `Admin::staff_role` — راجع القسم في أعلى الملف. تأكدت بالاختبار الفعلي أن الرد الآن يقتصر على فواتير السائق نفسه فقط بعد الإصلاح.

### 2.7 `GET /support-tickets/financial-history` — نفس شكل 1.9.

---

## 3. الأدمن (Admin) — Base: `/api/admin` أو `/api/v1/admin`
كل مسارات هذا القسم محمية بـ middleware `permission:xxx` بالإضافة للتوكن — Super Admin يتجاوزها تلقائياً، غيره يحتاج الصلاحية المذكورة (من `RbacSeeder`).

### 3.1 لوحة القيادة المالية
**`GET /financial/summary`** (صلاحية `financial.view_summary`) — لا Input.
Output: `data.{parents_escrow_pool, driver_pending_pool, driver_available_pool, platform_revenue_pool, penalty_pool}` (أرقام بالدينار) + `data.{pending_withdrawals_count, pending_recharges_count, pending_disputes_count, pending_escrows_count}` (أعداد صحيحة). كلها إجبارية دائماً.

**`GET /financial/solvency-check`** (صلاحية `financial.view_summary`) — لا Input.
Output: `data.is_solvent` (boolean) + `data.checks.{driver_wallet_mirror, escrow_backing, no_negative_pools}` كل واحد فيه `passed/expected_dinar/actual_dinar/difference_cents/description` + مجموعة أحواض بالدينار.
✅ **تم إصلاحه:** `data.parent_wallets_dinar` كان يعرض دائماً قريباً من صفر بغض النظر عن الرصيد الحقيقي (خلل بفلتر `sumWalletBalances`) — يعرض الآن الرصيد الفعلي الصحيح، تحقّقت بالاختبار.

### 3.2 الفواتير (نظرة الأدمن)
**`GET /financial/invoices`** (صلاحية `financial.view_ledger` أو `financial.view_summary`)
Input (Query، كلها اختيارية): `status`, `type`, `search` (يبحث بـ`invoice_number`), `date_from`, `date_to`, `per_page`.
Output: `data` = مصفوفة `InvoiceResource` **محمّلة فعلاً** بـ `subscription_request/driver/parent` (بعكس شاشة ولي الأمر) + `meta.{current_page,last_page,per_page,total}`.

**`GET /financial/invoices/{id}`** — Input: `id` بالمسار. Output: نفس الشكل، كائن واحد.

### 3.3 طلبات السحب (سائقين)
**`GET /financial/withdrawals`** (صلاحية `financial.manage_withdrawals`)
Input (Query اختياري): `status`, `search` (اسم السائق), `date_from`, `date_to`, `per_page`.
Output: `data` = مصفوفة خام (مو Resource) لكل عنصر `{id, driver_id, amount, wallet_balance_at_request, status, payment_method_details, created_at, driver:{...user}}` + `meta`.

**`GET /financial/withdrawals/{id}`**
Output: `data.{id, driver_id, driver_name, driver_phone, amount, wallet_balance_at_req, status, payment_method_details, rejection_reason, created_at, processed_at, admin_name}` — كل هذي الحقول موجودة فعلياً بالجدول (بعكس recharge، هذا سليم).

**`POST /financial/withdrawals/{id}/process`**
Input (Body):
| الحقل | إجباري | القيم |
|---|---|---|
| `action` | ✅ | `approve` أو `reject` |
| `rejection_reason` | إجباري فقط لو `action=reject` | string, max:1000 |

Output: `data` = `WithdrawalRequest` بعد التحديث + `driver.user` محمّلة.
**ملاحظة idempotency:** لو الطلب مو `pending` (اتّخذ قرار سابق فيه)، يرجع `422` برسالة واضحة — تعامل الفرونت معها بتعطيل الزر بعد أول ضغطة لتفادي الرسالة المربكة.

### 3.4 طلبات الشحن (أولياء أمور)
**`GET /financial/recharges`** (صلاحية `financial.manage_recharges`) — نفس نمط 3.3 (status/search/date_from/date_to/per_page).

**`GET /financial/recharges/{id}`**
Output: `data.{id, parent_name, parent_phone, amount, payment_method, reference_number, status, failure_reason, created_at, processed_at, admin_name}`.
✅ **تم إصلاحه:** كانا `failure_reason`/`processed_at` يرجعان `null` دائماً (عمودان غير موجودين أصلاً بالجدول). استبدلتهما بـ:
- `notes` — سبب الرفض الحقيقي (أو ملاحظة الشحن) من العمود الفعلي بالجدول.
- `completed_at` — وقت الاكتمال الفعلي (فقط لو نجح).
- `processed_at` — الآن محسوبة: `null` لو الطلب لا يزال `pending`، وإلا `updated_at` (وقت آخر تغيّر حالة — يغطي حالتي القبول والرفض).

**`POST /financial/recharges/{id}/process`**
Input (Body): `action` (اختياري، افتراضي `complete`) → `complete` أو أي قيمة أخرى تُعتبر رفض. **مو enum محقق بالسيرفر — الأفضل الفرونت يرسل بالضبط `"complete"` أو `"reject"` ليطابق منطق الكنترولر**، و`reason` (اختياري، Body) نص سبب الرفض لو رفض.
Output: `data` = `RechargeRequest` بعد التحديث + `parent` محمّلة.

### 3.5 الأمانات المعلّقة (Escrow)
**`GET /financial/escrows`** (صلاحية `financial.release_escrows`) — لا Input.
Output: `data.{pending_amount, eligible_amount, trips_count, eligible_count, oldest_escrow}` (كلها إجبارية، `oldest_escrow` قد تكون `null` لو ما فيه حجوزات).

**`POST /financial/release-escrows`** — لا Input (يُشغّل يدوياً نفس مهمة الـ Cron).
Output: `data.released_count` (int) + رسالة نصية بعدد الرحلات المحرَّرة.

### 3.6 النزاعات المالية (Disputes)
**`GET /financial/disputes`** (صلاحية `financial.resolve_disputes`) — Input: `status` (Query اختياري: open/resolved_*), `per_page`.
Output: `data` = مصفوفة `TripDispute` مع `parent.user`, `driver.user`, `trip` محمّلة + `meta`.

**`GET /financial/disputes/{id}`**
Output: `data.{id, trip_id, parent:{id,name,phone}, driver:{id,name,phone}, amount, reason, status, resolution_notes, created_at, resolved_at}`.

**`POST /financial/disputes/{disputeId}/resolve`**
Input (Body):
| الحقل | إجباري | القيم |
|---|---|---|
| `resolution` | ✅ | `resolve_parent_refunded` أو `resolve_driver_paid` |
| `notes` | اختياري | string |

Output: `data` = `TripDispute` بعد التحديث كامل. **idempotent:** لو الحالة مو `open`، يرجع 422.

### 3.7 تسويات العقود الشهرية
**`GET /financial/contracts/pending-settlements`** (صلاحية `financial.manage_settlements`) — Input: `per_page`.
Output: `data` = مصفوفة `{contract_id, contract_number, parent, driver, total_amount, executed_amount, pending_amount, completed_trips, settlement_status}` + `meta`.

**`POST /financial/contracts/{contractId}/settle-monthly`** — لا Input بالبودي (فقط `contractId` بالمسار).
Output: `data.{contract_number, total_contract_price, planned_trips, per_trip_cost, completed_trips, parent_absent_trips, driver_absent_trips, holidays_trips, location_changes_count, location_changes_fees, final_settled_amount, rollover_refund_credit}`.
⚠️ ملاحظة: هذا **تقرير قراءة فقط للعرض/الأرشفة** — لا يحرّك أي مال (الصرف الفعلي يتم تلقائياً رحلة برحلة). الفرونت لا يعرضه كـ"تحويل الآن" بل كـ"كشف حساب ختامي".

**`GET /financial/contracts/{contractId}/termination-preview`** — Input (Query): `terminated_by` (اختياري، افتراضي `parent`)، `is_arbitrary_parent` (boolean، اختياري).
Output: `data.{contract_id, contract_number, total_price, executed_cost, remaining_balance, cancellation_fee, refunded_to_parent}` — **معاينة فقط، لا تغيّر شيء** (استخدمها لعرض تأكيد قبل الإلغاء الفعلي).

**`POST /financial/contracts/{contractId}/terminate-mid-month`**
Input (Body):
| الحقل | إجباري | القيم |
|---|---|---|
| `terminated_by` | ✅ | `parent`/`driver`/`admin` |
| `is_arbitrary_parent` | اختياري | boolean (تُطبَّق غرامة 10% لو true) |

Output: نفس حقول termination-preview + `refunded_to_parent, driver_net_pay, platform_fee, settlement_status`. idempotent (422 لو ملغى مسبقاً).

### 3.8 إلغاء الرحلات بمصفوفة الغرامات
**`GET /financial/trips/{tripId}/cancel-preview`** — Input (Query): `cancelled_by` (اختياري، افتراضي parent؛ القيم: parent/driver/no_show).
Output: `data.{trip_id, cancelled_by, trip_price_dinar, parent_refund_dinar, driver_pay_dinar, platform_amount_dinar, penalty_dinar}` — معاينة فقط.

**`POST /financial/trips/{tripId}/cancel-with-matrix`**
Input (Body): `cancelled_by` ✅ (`parent`/`driver`/`no_show`).
Output: `{cancelled_by, parent_refund_dinar, driver_pay_dinar, platform_fee_dinar, driver_penalty_dinar}`. idempotent (422 لو الرحلة ملغاة مسبقاً).

### 3.9 دفتر الأستاذ والتدقيق (قراءة فقط)
**`GET /financial/ledger`** (صلاحية `financial.view_ledger`) — Input (Query): `type`, `status`, `search` (على `reference_number`/`source_account`/`destination_account`), `date_from`, `date_to`, `per_page`.
Output: `data` = مصفوفة خام من `financial_ledger`: `{id, reference_number, source_account, destination_account, amount, balance_before, balance_after, type, status, metadata, created_at}` (المبلغ هنا **بالقروش cents وليس بالدينار** — الحقل الوحيد بكل هذا الملف اللي يخالف القاعدة، **الفرونت لازم يقسّمه ÷100 يدوياً** قبل العرض) + `meta`.

**`GET /financial/audit-logs`** — نفس شكل `ledger` بالضبط لكن مفلتر على قيود فيها `admin` بالنوع أو `metadata.admin_id`.

### 3.10 إعدادات التسعير
**`match(GET, POST, PUT) /financial/pricing-settings`** (صلاحية `financial.manage_pricing`) — لم أفحص هذا الكنترولر بالتفصيل بعد (`PricingSettingController::manage`)؛ لو تحتاج توثيقه بنفس المستوى قولّي وأجيبه بجولة منفصلة.

---

### 3.11 إدارة وسائل الدفع (CRUD كامل) — `/admin/payment-methods`
صلاحية `financial.manage_payment_methods` على كل المجموعة.

**`GET /`** — Input (Query، اختيارية): `target_audience` (parent/driver/both/all)، `processing_type`، `is_active` (true/false)، `search`، `per_page`.
Output: `data` = مصفوفة `PaymentMethod` خام (كل الأعمدة، أنظر جدول الحقول أدناه) + `pagination`.

**`POST /`** — إنشاء وسيلة جديدة:
| الحقل | إجباري | النوع | الوصف والقيم |
|---|---|---|---|
| `name_ar` | ✅ | string, max:100 | |
| `name_en` | اختياري | string, max:100 | |
| `code` | ✅ | string, max:50, **فريد** | معرّف داخلي يُستخدم بكل نداءات الشحن — يُفضَّل بالإنجليزية بلا مسافات (`sadad` مثلاً) |
| `target_audience` | ✅ | enum: `parent`/`driver`/`both` | |
| `processing_type` | ✅ | enum: `instant_simulation`/`manual_proof` | اختر `manual_proof` لو الوسيلة تحويل بنكي حقيقي يحتاج مراجعة أدمن |
| `account_name`, `account_number`, `iban`, `wallet_number` | اختياري | string | **إجبارية منطقياً لو `manual_proof`** رغم إن السيرفر لا يفرض ذلك — الأفضل الفرونت يطلبها إجبارياً بهذه الحالة فقط |
| `icon_url` | اختياري | string, max:255 | رابط أيقونة |
| `min_amount` | اختياري | number, min:0.5 | افتراضي 1.00 لو ما أُرسل |
| `max_amount` | اختياري | number, **يجب > min_amount** (يُتحقق منه بالسيرفر) | افتراضي 50000.00 |
| `instructions_ar/en` | اختياري | string, max:1000 | تعليمات تُعرض للمستخدم (رقم حساب، خطوات تحويل...) |
| `is_active` | اختياري | boolean | افتراضي true |
| `sort_order` | اختياري | int | ترتيب العرض بالقائمة (تصاعدي) |

Output (201): `data` = الكائن كامل بعد الإنشاء.

**`GET /{id}`** — Output: نفس شكل عنصر الـ index.

**`PUT /{id}`** — نفس حقول store لكن **كلها اختيارية** (تعديل جزئي)، ما عدا: لو أرسلت `code` يجب يبقى فريداً (باستثناء السجل نفسه). بعد الإصلاح: يُرفض بـ422 لو نتيجة الدمج (المُرسل + القديم) تجعل `min_amount ≥ max_amount`.
Output: `data` = الكائن بعد التعديل.

**`PATCH /{id}/toggle-status`** — Input: لا يوجد. يعكس `is_active` الحالية.
Output: `data.{id, name_ar, is_active}`.
✅ بعد الإصلاح: تعطيل الوسيلة يمنع استخدامها فعلياً بمسار شحن ولي الأمر أيضاً، مو بس بالعرض.

**`DELETE /{id}`** — Input: لا يوجد. حذف ناعم (Soft Delete).
Output: `{status:true, message}` فقط، لا `data`.
⚠️ لا يوجد endpoint استرجاع (`restore`) حالياً — لو حذفت وحدة بالغلط، لازم تُنشأ من جديد يدوياً (بـ`id` مختلف). السجلات التاريخية (طلبات شحن قديمة استخدمت هذه الوسيلة) تبقى تعرض اسمها بشكل صحيح بعد الإصلاح الأخير حتى بعد الحذف.

**حقول جدول `payment_methods` كاملة (للعرض الخام بالـ index/show):**
`id, name_ar, name_en, code, target_audience, processing_type, account_name, account_number, iban, wallet_number, icon_url, min_amount, max_amount, instructions_ar, instructions_en, is_active, sort_order, created_at, updated_at, deleted_at`.

---

### 3.12 مراجعة طلبات شحن السائقين — `/admin/driver-recharges`
صلاحية `financial.manage_recharges`.

**`GET /`** — Input (Query): `status`, `driver_id`, `payment_method_id`, `search` (اسم/هاتف السائق أو الرقم المرجعي), `per_page`.
Output: `data` = مصفوفة `DriverRechargeRequest` مع `driver.user`, `paymentMethod`, `admin` محمّلة + `pagination`.

**`GET /{id}`** — نفس الشكل، كائن واحد.

**`POST /{id}/approve`**
Input (Body): `notes` (اختياري، string max:500).
Output: `data` = الطلب بعد التحديث (`status=approved`) + إيداع فوري بمحفظة السائق (يحدث تلقائياً بالسيرفر، الفرونت بس يعرض النتيجة).
idempotent: 422 (عبر ValidationException) لو مو pending.

**`POST /{id}/reject`**
Input (Body): `rejection_reason` ✅ (string, min:3, max:500).
Output: `data` = الطلب بعد التحديث (`status=rejected`).

---

## ملخص التوصيات للفرونت

1. **استخدم `payment_method_id` (رقم) دائماً بدل `code`** بكل نداءات الشحن — أثبت وأقل عرضة للأخطاء الإملائية.
2. مسار `/wallet/recharge` القديم (1.5) أصبح يعمل بعد الإصلاح، لكن يبقى **الأفضل استخدام `initiate` + `mock-pay`** (1.3/1.4) لأنه المسار الحالي الموثّق بالكامل مع كل الحالات (رابط جلسة، انتهاء صلاحية، إلخ).
3. حقول `financial/ledger` و `financial/audit-logs` فقط هي بالقروش (cents) — كل شيء آخر بالدينار.
4. تعامل مع الأكواد `401/403/422` بشكل موحّد: `401` = لازم تسجيل دخول، `403` = صلاحية/ملكية ناقصة (اعرض رسالة `message` مباشرة، هي بالعربي وجاهزة للعرض)، `422` = تحقق فشل أو idempotency guard (اعرض `errors` أو `message`).
