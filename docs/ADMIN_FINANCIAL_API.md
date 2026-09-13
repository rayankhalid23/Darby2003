# توثيق API — الإدارة المالية الشاملة (لوحة الأدمن)

Base: `/api/admin` (أو `/api/v1/admin`)
كل المسارات تحتاج `Authorization: Bearer {token}` + الصلاحية المذكورة بكل قسم (المدير العام يتجاوز كل الصلاحيات تلقائياً).
كل المبالغ بالـ Body/Response **بالدينار** إلا قسم "دفتر الأستاذ" (رقم 9) — استثناء موثّق بالقرش (cents).

**وسائل الدفع (إضافة/تعديل/حذف) موثّقة بملف منفصل:** [`PAYMENT_METHODS_ADMIN_API.md`](PAYMENT_METHODS_ADMIN_API.md).

---

## 1. لوحة القيادة المالية

### 1.1 `GET /financial/summary` — الملخص اليومي
صلاحية: `financial.view_summary`. **Input:** لا يوجد.

**Output (200):**
```json
{
  "success": true,
  "data": {
    "parents_escrow_pool": 0,
    "driver_pending_pool": 0,
    "driver_available_pool": 150,
    "platform_revenue_pool": 0,
    "penalty_pool": 0,
    "pending_withdrawals_count": 0,
    "pending_recharges_count": 5,
    "pending_disputes_count": 0,
    "pending_escrows_count": 0
  }
}
```
كل الحقول إجبارية دائماً. الأحواض (`_pool`) بالدينار، الأعداد (`_count`) صحيحة.

### 1.2 `GET /financial/solvency-check` — فحص السلامة المالية
صلاحية: `financial.view_summary`. **Input:** لا يوجد.

**Output (200):**
```json
{
  "success": true,
  "message": "النظام متسق مالياً بنسبة 100%.",
  "data": {
    "is_solvent": true,
    "checks": {
      "driver_wallet_mirror": { "passed": true, "expected_dinar": 150, "actual_dinar": 150, "difference_cents": 0, "description": "..." },
      "escrow_backing": { "passed": true, "expected_dinar": 0, "actual_dinar": 0, "difference_cents": 0, "description": "..." },
      "no_negative_pools": { "passed": true, "negative_pools": [], "description": "..." }
    },
    "parents_escrow_pool": 0,
    "driver_pending_pool": 0,
    "driver_available_pool": 150,
    "pending_withdrawal_pool": 0,
    "platform_revenue_pool": 0,
    "penalty_pool": 0,
    "parent_wallets_dinar": 2635,
    "driver_wallets_dinar": 150,
    "total_custody_dinar": 150
  }
}
```
لو `is_solvent: false`، `message` يتغيّر لتحذير — راقب هذا الحقل بلوحة تنبيهات.

### 1.3 `GET /financial/ledger` — دفتر الأستاذ (سجل غير قابل للمسح)
صلاحية: `financial.view_ledger`.

**Input (Query، كلها اختيارية):** `type`, `status`, `search` (على `reference_number`/`source_account`/`destination_account`), `date_from`, `date_to`, `per_page` (افتراضي 20).

**Output (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 197,
      "reference_number": "WITHDRAW-PAID-1",
      "source_account": "pending_withdrawal_pool",
      "destination_account": "external_bank_payout",
      "amount": 5000,
      "balance_before": 5000,
      "balance_after": 0,
      "type": "withdrawal_paid",
      "status": "completed",
      "metadata": { "admin_id": 15, "withdrawal_id": 1 },
      "created_at": "2026-09-09T09:17:42.000000Z",
      "updated_at": "2026-09-09T09:17:42.000000Z"
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 20 }
}
```
⚠️ **`amount`/`balance_before`/`balance_after` بالقروش (cents)** هنا فقط — قسِمها ÷100 قبل العرض.

### 1.4 `GET /financial/audit-logs` — سجل تدقيق إجراءات الأدمن
صلاحية: `financial.view_ledger`. **Input:** `search`, `per_page`. نفس شكل 1.3 تماماً، لكن مفلتر على قيود فيها `admin` بالنوع أو `metadata.admin_id`.

---

## 2. الفواتير (نظرة الأدمن — كل الفواتير، بلا فلترة حسب المالك)

### 2.1 `GET /financial/invoices`
صلاحية: `financial.view_ledger` أو `financial.view_summary`.
**Input (Query، اختيارية):** `status`, `type`, `search` (على `invoice_number`), `date_from`, `date_to`, `per_page`.

**Output (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 183, "invoice_number": "INV-2026-000011", "amount": 25, "type": "receipt", "status": "paid",
      "due_date": "2026-09-09", "subscription_type": null, "total_trips": 0, "completed_trips": 0,
      "driver_absences": 0, "student_absences": 0, "calculated_amount": null, "action_taken": "none",
      "paid_at": "2026-09-09 11:10:31", "created_at": "2026-09-09 11:10:31",
      "driver": { "id": null, "name": "غير معروف" },
      "parent": { "id": 51, "name": "ولي أمر تجريبي للاختبار" }
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 6 }
}
```
بعكس شاشة ولي الأمر/السائق، هنا `subscription_request`/`driver`/`parent` **محمّلة دائماً**.

### 2.2 `GET /financial/invoices/{id}` — نفس الشكل، كائن واحد. **404** لو غير موجود.

---

## 3. طلبات السحب (سائقين)
صلاحية: `financial.manage_withdrawals`.

### 3.1 `GET /financial/withdrawals`
**Input (Query):** `status`, `search` (اسم السائق), `date_from`, `date_to`, `per_page`.
**Output:** `data` = مصفوفة `{id, driver_id, amount, wallet_balance_at_request, status, payment_method_details, created_at, driver:{...user}}` + `meta`.

### 3.2 `GET /financial/withdrawals/{id}`
**Output (200):**
```json
{
  "success": true,
  "data": {
    "id": 1, "driver_id": 13, "driver_name": "عمر عبد السلام القماطي", "driver_phone": "0918899001",
    "amount": 50, "wallet_balance_at_req": 200, "status": "approved",
    "payment_method_details": { "bank_name": "مصرف الوحدة", "account_name": "...", "account_number": "987654321" },
    "rejection_reason": null, "created_at": "2026-09-09T09:17:11.000000Z", "processed_at": "2026-09-09T09:17:42.000000Z",
    "admin_name": "المدير العام للاختبار"
  }
}
```

### 3.3 `POST /financial/withdrawals/{id}/process` — موافقة/رفض
**Input (Body):**
| الحقل | إجباري | القيم |
|---|---|---|
| `action` | ✅ | `approve` أو `reject` |
| `rejection_reason` | إجباري فقط لو `action=reject` | نص، أقصى 1000 حرف |

**Output (200):** `{success, message, data: WithdrawalRequest بعد التحديث + driver.user}`.
**idempotent:** 422 لو الطلب مو `pending` (تم البت فيه مسبقاً):
```json
{ "success": false, "message": "لا يمكن تنفيذ العملية.", "errors": { "action": ["طلب السحب تم معالجته مسبقاً وغير معلق حالياً."] } }
```
✅ **مُصلَح:** القرار يُسجَّل الآن باسم الأدمن الحقيقي المسجّل دخوله دائماً (كان سابقاً يمكن أن يُنسَب أحياناً لأدمن آخر عشوائي).

---

## 4. طلبات الشحن (أولياء أمور)
صلاحية: `financial.manage_recharges`.

### 4.1 `GET /financial/recharges` — نفس نمط 3.1.

### 4.2 `GET /financial/recharges/{id}`
**Output (200):**
```json
{
  "success": true,
  "data": {
    "id": 11, "parent_name": "ولي أمر تجريبي للاختبار", "parent_phone": "0919999001",
    "amount": 25, "payment_method": "sadad", "reference_number": null, "status": "completed",
    "notes": "طلب شحن عبر خدمة سداد (Sadad)", "created_at": "2026-09-09T09:08:14.000000Z",
    "completed_at": "2026-09-09T09:10:31.000000Z", "processed_at": "2026-09-09T09:10:31.000000Z",
    "admin_name": null
  }
}
```
✅ **مُصلَح:** `notes` (سبب الرفض/ملاحظة الشحن الحقيقية)، `completed_at`، و`processed_at` (محسوبة: `null` لو `pending`، وإلا `updated_at`) — كانت `failure_reason`/`processed_at` القديمتان ترجعان `null` دائماً (عمودان غير موجودين أصلاً بالجدول). `admin_name` يبقى `null` لو الشحن تمّ ذاتياً عبر المحاكاة الفورية (لا أدمن نفّذه).

### 4.3 `POST /financial/recharges/{id}/process` — موافقة/رفض
**Input (Body):**
| الحقل | إجباري | القيم |
|---|---|---|
| `action` | اختياري (افتراضي `complete`) | `complete` أو أي قيمة أخرى (تُعتبر رفضاً) — **الأفضل إرسال `complete` أو `reject` صراحة** |
| `reason` | اختياري (يُستخدم فقط عند الرفض) | نص، افتراضي "تم رفض طلب الشحن." لو تُرك فارغاً |

**Output:** `{success, message, data: RechargeRequest بعد التحديث + parent}`. idempotent (422 لو مو `pending`).

---

## 5. طلبات شحن السائقين — `/admin/driver-recharges` (كنترولر منفصل تماماً)
صلاحية: `financial.manage_recharges`.

### 5.1 `GET /driver-recharges`
**Input (Query):** `status`, `driver_id`, `payment_method_id`, `search` (اسم/هاتف السائق أو الرقم المرجعي), `per_page`.
**Output:** `data` = مصفوفة `DriverRechargeRequest` مع `driver.user`, `paymentMethod`, `admin` محمّلة + `pagination`.

### 5.2 `GET /driver-recharges/{id}` — نفس الشكل، كائن واحد.

### 5.3 `POST /driver-recharges/{id}/approve`
**Input (Body):** `notes` (اختياري، نص، أقصى 500 حرف).
**Output (200):**
```json
{
  "status": true,
  "message": "تمت الموافقة على طلب الشحن وإيداع الرصيد في محفظة السائق فوراً.",
  "data": { "id": 1, "driver_id": 13, "amount": "200.00", "status": "approved", "admin_id": 15, "approved_at": "...", "driver": {...}, "paymentMethod": null, "admin": {...} }
}
```
الإيداع بمحفظة السائق **فوري** لحظة الموافقة (لا انتظار). idempotent: 422 لو مو `pending`.

### 5.4 `POST /driver-recharges/{id}/reject`
**Input (Body):** `rejection_reason` ✅ (نص، 3-500 حرف).
**Output:** نفس شكل 5.3 لكن `status:"rejected"`.

---

## 6. الأمانات المعلّقة (Escrow)
صلاحية: `financial.release_escrows`.

### 6.1 `GET /financial/escrows` — نظرة عامة
**Output:**
```json
{ "success": true, "data": { "pending_amount": 0, "eligible_amount": 0, "trips_count": 0, "eligible_count": 0, "oldest_escrow": null } }
```

### 6.2 `POST /financial/release-escrows` — تحرير يدوي (نفس مهمة الـ Cron)
**Input:** لا يوجد. **Output:** `{success, message, data: {released_count}}`.

---

## 7. النزاعات المالية (Disputes)
صلاحية: `financial.resolve_disputes`.

### 7.1 `GET /financial/disputes`
**Input (Query):** `status` (`open`/`resolved_parent_refunded`/`resolved_driver_paid`), `per_page`.
**Output:** `data` = مصفوفة `TripDispute` مع `parent.user`, `driver.user`, `trip` + `meta`.

### 7.2 `GET /financial/disputes/{id}`
**Output:**
```json
{
  "success": true,
  "data": {
    "id": 1, "trip_id": 4,
    "parent": { "id": 51, "name": "...", "phone": "..." },
    "driver": { "id": 13, "name": "...", "phone": "..." },
    "amount": 25, "reason": "...", "status": "open", "resolution_notes": null,
    "created_at": "...", "resolved_at": null
  }
}
```

### 7.3 `POST /financial/disputes/{disputeId}/resolve`
**Input (Body):**
| الحقل | إجباري | القيم |
|---|---|---|
| `resolution` | ✅ | `resolve_parent_refunded` (استرجاع لولي الأمر) أو `resolve_driver_paid` (صرف للسائق) |
| `notes` | اختياري | نص حر |

**Output:** `{success, message, data: TripDispute بعد الحل}`. **قيد زمني:** فتح النزاع نفسه محدود بـ24 ساعة من الرحلة (يُفحص عند الفتح لا الحل). idempotent: 422 لو مو `open`.

---

## 8. تسويات العقود الشهرية
صلاحية: `financial.manage_settlements`.

### 8.1 `GET /financial/contracts/pending-settlements`
**Input:** `per_page`. **Output:**
```json
{
  "success": true,
  "data": [
    { "contract_id": 13, "contract_number": "REQ-13", "parent": "...", "driver": "...", "total_amount": 1160, "executed_amount": 0, "pending_amount": 1160, "completed_trips": 0, "settlement_status": "pending_settlement" }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 5 }
}
```

### 8.2 `POST /financial/contracts/{contractId}/settle-monthly` — كشف مقاصة نهائي (قراءة فقط، لا يحرّك مالاً)
**Input:** لا يوجد. **Output:** `{success, message, data: {contract_number, total_contract_price, planned_trips, per_trip_cost, completed_trips, parent_absent_trips, driver_absent_trips, holidays_trips, location_changes_count, location_changes_fees, final_settled_amount, rollover_refund_credit}}`.

### 8.3 `GET /financial/contracts/{contractId}/termination-preview` — معاينة إلغاء مبكر
**Input (Query):** `terminated_by` (اختياري، افتراضي `parent`)، `is_arbitrary_parent` (boolean).
**Output:**
```json
{ "success": true, "data": { "contract_id": 6, "contract_number": "REQ-6", "total_price": 360, "executed_cost": 0, "remaining_balance": 360, "cancellation_fee": 0, "refunded_to_parent": 360 } }
```

### 8.4 `POST /financial/contracts/{contractId}/terminate-mid-month` — تنفيذ فعلي
**Input (Body):**
| الحقل | إجباري | القيم |
|---|---|---|
| `terminated_by` | ✅ | `parent` / `driver` / `admin` |
| `is_arbitrary_parent` | اختياري | boolean (تُطبَّق غرامة 10% لو true) |

**Output:** نفس حقول 8.3 + `refunded_to_parent, driver_net_pay, platform_fee, settlement_status`. idempotent (422 لو ملغى مسبقاً).

---

## 9. إلغاء الرحلات بمصفوفة الغرامات
صلاحية: `trips.emergency_cancel` أو `financial.manage_settlements`.

### 9.1 `GET /financial/trips/{tripId}/cancel-preview`
**Input (Query):** `cancelled_by` (اختياري، افتراضي `parent`؛ القيم: `parent`/`driver`/`no_show`).
**Output:** `{success, data: {trip_id, cancelled_by, trip_price_dinar, parent_refund_dinar, driver_pay_dinar, platform_amount_dinar, penalty_dinar}}`.

### 9.2 `POST /financial/trips/{tripId}/cancel-with-matrix`
**Input (Body):** `cancelled_by` ✅ (`parent`/`driver`/`no_show`).
**Output:** `{success, message, data: {cancelled_by, parent_refund_dinar, driver_pay_dinar, platform_fee_dinar, driver_penalty_dinar}}`. idempotent (422 لو الرحلة ملغاة مسبقاً).

---

## 10. إعدادات التسعير
صلاحية: `financial.manage_pricing`. **رابط واحد لكل من العرض والتعديل** (نفس الرابط، الطريقة HTTP تحدد السلوك).

### 10.1 `GET /financial/pricing-settings` — عرض الإعدادات الحالية
**Input:** لا يوجد.

### 10.2 `POST` أو `PUT /financial/pricing-settings` — تعديل الإعدادات (تُنشئ السجل تلقائياً لو غير موجود)
**Input (Body — كلها إجبارية ما عدا رسوم تغيير الموقع):**
| الحقل | إجباري | القيود |
|---|---|---|
| `discount_one_child` | ✅ | رقم 0-100 (نسبة خصم %) |
| `discount_two_children` | ✅ | رقم 0-100 |
| `discount_three_plus_children` | ✅ | رقم 0-100 |
| `platform_commission_rate` | ✅ | رقم 0-100 (نسبة عمولة المنصة — **هذا هو المصدر الوحيد للعمولة بكل النظام المالي**) |
| `price_per_km_ac` | ✅ | رقم 0.1-1000 (سعر الكم للمركبة المكيّفة) |
| `price_per_km_non_ac` | ✅ | رقم 0.1-1000 |
| `location_change_fee` | اختياري | رقم 0-10000 (رسم عام قديم، احتياطي) |
| `location_change_fee_under_2km` | اختياري | رقم 0-10000 |
| `location_change_fee_2_to_6km` | اختياري | رقم 0-10000 |
| `location_change_fee_6_to_10km` | اختياري | رقم 0-10000 |

**Output (كلا الطريقتين — نفس الشكل):**
```json
{
  "success": true,
  "message": "تم جلب إعدادات التسعير بنجاح",
  "data": {
    "id": 1,
    "discount_one_child": 0, "discount_two_children": 10, "discount_three_plus_children": 15,
    "platform_commission_rate": 8, "price_per_km_ac": 2.5, "price_per_km_non_ac": 2,
    "location_change_fee": 5, "location_change_fee_under_2km": 5,
    "location_change_fee_2_to_6km": 10, "location_change_fee_6_to_10km": 15,
    "location_change_fee_tiers": [
      { "tier": "under_2km", "label": "أقل من 2 كم", "min_km": 0, "max_km": 2, "max_inclusive": false, "fee": 5 },
      { "tier": "2_to_6km", "label": "من 2 كم إلى 6 كم", "min_km": 2, "max_km": 6, "max_inclusive": true, "fee": 10 },
      { "tier": "6_to_10km", "label": "أكثر من 6 كم إلى 10 كم", "min_km": 6, "max_km": 10, "max_inclusive": true, "fee": 15 }
    ],
    "max_location_change_distance_km": 10,
    "currency": "د.ل",
    "updated_at": "2026-09-04 21:36:03"
  }
}
```
⚠️ `location_change_fee_tiers` و`max_location_change_distance_km` **حقول محسوبة للعرض فقط** — لا تُرسَل بالإدخال، تُبنى تلقائياً من الحقول الفردية أعلاها.

---

## 11. إدارة وسائل الدفع
موثّقة بالكامل (6 إندبوينتات: عرض، إضافة برفع أيقونة، عرض واحد، تعديل، تفعيل/تعطيل، حذف) بملف منفصل: **[`PAYMENT_METHODS_ADMIN_API.md`](PAYMENT_METHODS_ADMIN_API.md)**.

---

## ملخص التوصيات للفرونت

1. **`financial/ledger` و`financial/audit-logs` فقط بالقروش (cents)** — كل شيء آخر بهذا الملف بالدينار.
2. تعامل مع الأكواد بشكل موحّد: `401` = تسجيل دخول، `403` = صلاحية ناقصة، `404` = سجل غير موجود، `422` = فشل تحقق أو idempotency guard (العملية تمّت مسبقاً) — كلها رسائلها عربية جاهزة للعرض (`message`/`errors`).
3. كل نداءات "المعاينة" (`*-preview`) **لا تُغيّر أي بيانات** — استخدمها لعرض تأكيد للأدمن قبل تنفيذ الإجراء الفعلي المقابل.
4. `POST /financial/recharges/{id}/process` — أرسل `action` صراحة (`complete`/`reject`) دائماً، لا تعتمد على الافتراضي.
5. طلبات شحن السائقين (قسم 5) **كنترولر ومسار منفصلان تماماً** عن طلبات شحن أولياء الأمور (قسم 4) — لا تخلط بينهما بالفرونت رغم تشابه الاسم.
