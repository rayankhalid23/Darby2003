# 🚚 دليل توثيق اندبوينتس السائق (Driver API Documentation Guide)

هذا الملف مخصص لفريق الـ Frontend (Flutter / Mobile / Web) لربط كافة الاندبوينتس الخاصة بـ **تطبيق السائق (Driver App)** في منصة **Darby**.

---

## 🔒 الهيدرز العامة والتوثيق (Headers & Auth)

جميع المسارات (باستثناء التسجيل الأولي ورسائل الـ OTP) محمية عبر **Sanctum Middleware** وتتطلب إرسال التوكن:

```http
Authorization: Bearer {sanctum_token}
Accept: application/json
Content-Type: application/json
```

*ملاحظة: Base URL للمشروع:* `http://localhost:8000/api`

---

## 📋 الفهرس السريع للاندبوينتس (API Quick Index)

| القسم | اسم الوظيفة | HTTP Method | URL Path |
|---|---|---|---|
| **المصادقة والتسجيل** | 1. إرسال طلب تسجيل السائق (OTP) | `POST` | `/api/driver/register` |
| | 2. التحقق من كود الـ OTP والتسجيل | `POST` | `/api/driver/verify-otp` |
| | 3. استكمال البيانات والوثائق والمركبة | `POST` | `/api/driver/complete-profile/{userId}` |
| **الملف الشخصي والمركبات** | 4. عرض الملف الشخصي للسائق | `GET` | `/api/driver/profile` |
| | 5. تحديث البيانات الشخصية والصورة | `POST` | `/api/driver/profile/update` |
| | 6. عرض الوثائق الرسمية والمستندات | `GET` | `/api/driver/profile/legal-data` |
| | 7. تحديث الوثائق والمستندات | `POST` | `/api/driver/profile/legal-data` |
| | 8. عرض بيانات المركبة | `GET` | `/api/driver/profile/vehicle` |
| | 9. تحديث بيانات المركبة | `POST` | `/api/driver/profile/vehicle/{vehicle}` |
| | 10. قائمة مركبات السائق | `GET` | `/api/driver/vehicles` |
| | 11. تفاصيل مركبة معينة | `GET` | `/api/driver/vehicles/{vehicle}` |
| **التفضيلات والمناطق** | 12. عرض تفضيلات ورغبات العمل | `GET` | `/api/driver/preferences` |
| | 13. تحديث تفضيلات السائق والوردية | `POST` | `/api/driver/preferences` |
| | 14. إضافة منطقة نفوذ/تغطية | `POST` | `/api/driver/preferences/zones/add` |
| | 15. إزالة منطقة تغطية | `POST` | `/api/driver/preferences/zones/remove` |
| | 16. جلب التفضيلات المرجعية | `GET` | `/api/driver/preferences/defaults` |
| | 17. جلب جميع المناطق المتاحة بالنظام | `GET` | `/api/driver/zones` |
| **العناوين الجغرافية** | 18. عرض عناوين السائق | `GET` | `/api/driver/addresses` |
| | 19. إضافة عنوان جديد للسائق | `POST` | `/api/driver/addresses` |
| | 20. تعديل عنوان | `PUT` | `/api/driver/addresses/{address}` |
| | 21. حذف عنوان | `DELETE` | `/api/driver/addresses/{address}` |
| **الطلبات والمحادثات** | 22. جلب طلبات الاشتراك الواردة بالسائق | `GET` | `/api/driver/requests` |
| | 23. عرض تفاصيل طلب اشتراك محدد | `GET` | `/api/driver/requests/{id}` |
| | 24. قبول أو رفض طلب الاشتراك | `PUT` | `/api/driver/{id}/status` |
| | 25. جلب الاشتراكات النشطة والمثبتة | `GET` | `/api/driver/active-subscriptions` |
| | 26. تفاصيل اشتراك نشط محدد | `GET` | `/api/driver/active-subscriptions/{id}` |
| | 27. عرض قائمة محادثات السائق | `GET` | `/api/driver/chats` |
| **المسارات والرحلات** | 28. عرض مسارات السائق التشغيلية | `GET` | `/api/driver/routes` |
| | 29. تحديث مسار تشغيلي | `PUT` | `/api/driver/routes/{route}` |
| | 30. بدء رحلة حية (صباحية/مسائية) | `POST` | `/api/driver/trips/start` |
| | 31. بث وتحديث موقع الـ GPS المباشر | `POST` | `/api/driver/trips/{tripId}/location` |
| | 32. تأكيد صعود/نزول الطفل يدوياً (زر) | `POST` | `/api/driver/trips/{tripId}/pickup` أو `/dropoff` |
| | 33. تخطي محطة طفل وتحديث المسار | `POST` | `/api/driver/trips/{tripId}/skip/{childId}` |
| | 34أ. عرض الرحلات القادمة لاختيار الغياب منها | `GET` | `/api/driver/trips/upcoming-for-absence` |
| | 34. تسجيل غياب مجدول للسائق عن رحلات محددة | `POST` | `/api/driver/trips/register-absence` |
| | 35. إنهاء وإغلاق الرحلة الحية | `POST` | `/api/driver/trips/{tripId}/complete` |
| **المالية والسحب** | 36. عرض رصيد محفظة السائق | `GET` | `/api/driver/wallet/balance` |
| | 37. عرض طلبات سحب الأرباح | `GET` | `/api/driver/withdrawals` |
| | 38. تقديم طلب سحب أرباح جديد | `POST` | `/api/driver/withdrawals` |
| | 39. عرض فواتير السائق | `GET` | `/api/driver/invoices` |
| | 40. عرض تفاصيل فاتورة معينة | `GET` | `/api/driver/invoices/{id}` |
| **لوحة التحكم والإحصائيات** | 41. عرض إحصائيات ولوحة تحكم السائق | `GET` | `/api/driver/statistics` |

---

## 📑 البيانات التفصيلية لكل إيندبوينت (JSON Request & Response Body)

---

### 🔑 1. المصادقة والتسجيل (Authentication & Onboarding)

#### 1.1 طلب إرسال كود OTP لإنشاء الحساب
* **Method:** `POST`
* **URL:** `/api/driver/register`
* **Body (JSON):**
```json
{
  "full_name": "عبد السلام المصراتي",
  "email": "driver1@darby.com",
  "phone_number": "0921111111"
}
```
* **Success Response (200 OK):**
```json
{
  "status": "success",
  "message": "تم إرسال كود التحقق OTP بنجاح إلى البريد الإلكتروني"
}
```

#### 1.2 التحقق من OTP وتوليد التوكن
* **Method:** `POST`
* **URL:** `/api/v1/driver/verify-otp`
* **Body (JSON):**
```json
{
  "email": "driver1@darby.com",
  "otp": "123456",
  "full_name": "عبد السلام المصراتي",
  "phone_number": "0921111111",
  "gender": "male",
  "password": "Password123"
}
```
* **Success Response (201 Created):**
```json
{
  "status": true,
  "message": "تم تفعيل الحساب وإنشاؤه بنجاح.",
  "user_id": 95,
  "driver_id": 36,
  "token": "1|sanctum_token_string_here"
}
```

#### 1.3 استكمال بيانات السائق والوثائق والمركبة (Complete Profile)
* **Method:** `POST`
* **URL:** `/api/driver/complete-profile/{userId}`
* **Headers:** `Content-Type: multipart/form-data`
* **Body (FormData):**
  - `national_id`: `"119900112233"`
  - `license_number`: `"DL-998877"`
  - `license_expiry`: `"2028-12-31"`
  - `shift`: `3` *(1: صباحي, 2: مسائي, 3: الفترتين)*
  - `subscription_type`: `"both"` *(monthly, daily, both)*
  - `accepted_gender`: `"both"` *(male, female, both)*
  - `gender`: `"male"`
  - `brand`: `"تويوتا"`
  - `model`: `"كوستر"`
  - `year`: `2022`
  - `color`: `"أبيض"`
  - `plate_number`: `"5-12345"`
  - `capacity_manual`: `14`
  - `has_ac`: `1`
  - `vehicle_type`: `"Bus"` *(Bus, Van, Sedan)*
  - `license_photo` (file)
  - `vehicle_photo` (file)
* **Success Response (200 OK):**
```json
{
  "status": "success",
  "message": "تم إرسال ملف البيانات والوثائق بنجاح وهو قيد المراجعة من الإدارة"
}
```

---

### 👤 2. الملف الشخصي والبيانات الرسمية والمركبة

#### 2.1 عرض الملف الشخصي للسائق
* **Method:** `GET`
* **URL:** `/api/driver/profile`
* **Success Response (200 OK):**
```json
{
  "status": "success",
  "data": {
    "user_id": 95,
    "driver_id": 36,
    "full_name": "عبد السلام المصراتي",
    "email": "driver1@darby.com",
    "phone_number": "0921111111",
    "status": "Approved",
    "national_id": "119900112233",
    "license_number": "DL-998877",
    "avatar_url": "http://localhost:8000/storage/avatars/driver.jpg"
  }
}
```

#### 2.2 تحديث البيانات الشخصية والصورة
* **Method:** `POST`
* **URL:** `/api/driver/profile/update`
* **Body (JSON / FormData):**
```json
{
  "full_name": "عبد السلام المصراتي",
  "alternative_phone": "0920000000"
}
```

#### 2.3 عرض تحديث الوثائق الرسمية المركبة
* **Method:** `GET` `/api/driver/profile/legal-data` | `POST` `/api/driver/profile/legal-data`
* **Body:** `national_id`, `license_number`, `license_expiry`, `license_photo`

#### 2.4 عرض وتحديث بيانات المركبة
* **Method:** `GET` `/api/driver/profile/vehicle` | `POST` `/api/driver/profile/vehicle/{vehicleId}`
* **Body:** `brand`, `model`, `year`, `color`, `plate_number`, `capacity_manual`, `has_ac`

---

### ⚙️ 3. التفضيلات والمناطق الجغرافية (Preferences & Zones)

#### 3.1 عرض تفضيلات السائق
* **Method:** `GET`
* **URL:** `/api/driver/preferences`
* **Success Response (200 OK):**
```json
{
  "status": "success",
  "data": {
    "shift": 3,
    "subscription_type": "both",
    "accepted_gender": "both",
    "zones": [
      { "id": 1, "name": "منطقة السياحية" }
    ]
  }
}
```

#### 3.2 تحديث التفضيلات ورغبات العمل
* **Method:** `POST`
* **URL:** `/api/driver/preferences`
* **Body (JSON):**
```json
{
  "shift": 3,
  "subscription_type": "both",
  "accepted_gender": "both"
}
```

#### 3.3 إضافة منطقة تغطية جديدة للسائق
* **Method:** `POST`
* **URL:** `/api/driver/preferences/zones/add`
* **Body (JSON):**
```json
{
  "zone_id": 1
}
```
* **Success Response (200 OK):**
```json
{
  "status": "success",
  "message": "تم إضافة المنطقة لتغطيتك بنجاح"
}
```

---

### 📋 4. طلبات الاشتراكات والمحادثات (Subscription Requests & Chats)

#### 4.1 جلب طلبات الاشتراك الواردة للسائق
* **Method:** `GET`
* **URL:** `/api/driver/requests?filter=pending` *(فلاتر اختيارية: `pending`, `accepted`, `rejected`)*
* **Success Response (200 OK):**
```json
{
  "success": true,
  "count": 1,
  "data": [
    {
      "id": 15,
      "parent_name": "محمود علي الورفلي",
      "parent_phone": "0912222222",
      "school_name": "مدرسة الشروق الأهلية",
      "subscription_type": "monthly",
      "direction": "two_way",
      "timing": "morning",
      "days_count": 22,
      "total_price": "350.00",
      "children_count": 1,
      "status": "pending",
      "created_at": "2026-07-27 10:41:46"
    }
  ]
}
```

#### 4.2 قبول أو رفض طلب اشتراك
* **Method:** `PUT`
* **URL:** `/api/driver/{id}/status`
* **Body (JSON):**
```json
{
  "status": "accepted"
}
```
*(أو `"rejected"` مع كود الرفض في حال تم رغبة السائق بذلك)*

* **Success Response (200 OK):**
```json
{
  "status": "success",
  "message": "تم قبول طلب الاشتراك وتوليد العقد بنجاح"
}
```

#### 4.3 جلب الاشتراكات النشطة والمثبتة
* **Method:** `GET`
* **URL:** `/api/driver/active-subscriptions?filter=current_active`
* **Success Response (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "subscription_id": 10,
      "contract_id": 5,
      "parent_name": "طه سالم القمودي",
      "child_name": "سند طه القمودي",
      "pickup_label": "منزل الولي طه",
      "dropoff_label": "مدرسة الجيل الجديد",
      "status": "active"
    }
  ]
}
```

#### 4.4 عرض قائمة المحادثات (Driver Chats)
* **Method:** `GET`
* **URL:** `/api/driver/chats`
* **Success Response (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "room_id": "parent_93_driver_36",
      "parent_id": 93,
      "parent_name": "طه سالم القمودي",
      "last_message": "شكراً جزيلاً لك على التوصيل السريع",
      "unread_count": 0
    }
  ]
}
```

---

### 🚦 5. المسارات والرحلات الحية (Routes & Trips Lifecycle)

#### 5.1 بدء رحلة حية جديدة (Start Trip)
* **Method:** `POST`
* **URL:** `/api/driver/trips/start`  
  *(يدعم أيضاً `/api/driver/trips/start`)*
* **Body (JSON):**
```json
{
  "trip_type": "Morning"
}
```
*(أو `"Afternoon"` للرحلة المسائية)*

* **Success Response (200 OK):**
```json
{
  "status": "success",
  "message": "تم بدء الرحلة بنجاح",
  "data": {
    "id": 24,
    "driver_id": 36,
    "route_id": 1,
    "trip_type": "Morning",
    "status": "started",
    "trip_date": "2026-07-27",
    "started_at": "2026-07-27 07:05:00"
  }
}
```

#### 5.2 بث وتحديث موقع الـ GPS المباشر للسائق
* **Method:** `POST`
* **URL:** `/api/driver/trips/{tripId}/location`
* **Path Parameter:** `tripId` (int) - مثال: `24`
* **Body (JSON):**
```json
{
  "latitude": 32.89200000,
  "longitude": 13.17500000,
  "speed": 42.5
}
```
* **Success Response (200 OK):**
```json
{
  "status": "success",
  "message": "تم تحديث موقع الـ GPS بنجاح"
}
```

#### 5.3 تأكيد صعود/نزول الطفل يدوياً (زر "تم الصعود" / "تم النزول")
* **Method:** `POST`
* **URL:** `/api/driver/trips/{tripId}/pickup` (صعود) أو `/api/driver/trips/{tripId}/dropoff` (نزول)
* **Path Parameters:** `tripId` (int)
* **Body (JSON):**
```json
{
  "trip_child_id": 101,
  "latitude": 32.89200000,
  "longitude": 13.17500000
}
```
* **ملاحظة:** `latitude`/`longitude` إلزاميان دائماً — يُرفض الطلب بموقع السائق الفعلي إذا كان بعيداً عن محطة الطفل (≤100م للمنزل، ≤200م للمدرسة).
* **Success Response (200 OK):**
```json
{
  "status": "success",
  "message": "تم تأكيد الصعود وإرسال الإشعار لولي الأمر.",
  "next_stop": { }
}
```

#### 5.4 تخطي محطة طفل وتحديث المسار (Skip Stop)
* **Method:** `POST`
* **URL:** `/api/driver/trips/{tripId}/skip/{childId}`
* **Path Parameters:** `tripId` (int), `childId` (int)
* **Body (JSON):** لا يوجد (None)
* **Success Response (200 OK):**
```json
{
  "status": "success",
  "message": "تم تخطي المحطة وتنبيه ولي الأمر وإعادة إرشادات المسار"
}
```

#### 5.5 تسجيل غياب مجدول للسائق (Driver Absence)

**الخطوة 1: عرض الرحلات القادمة ليختار السائق منها**
* **Method:** `GET`
* **URL:** `/api/driver/trips/upcoming-for-absence`
* **Success Response (200 OK):**
```json
{
  "status": "success",
  "message": "تم جلب رحلاتك القادمة بنجاح.",
  "data": [
    {
      "id": 501,
      "trip_type": "Morning",
      "shift_slot": "morning_go",
      "trip_date": "2026-09-02",
      "scheduled_start_time": "07:00:00",
      "status": "pending",
      "route_name": "مسار الصباح حي الأندلس"
    }
  ]
}
```

**الخطوة 2: تسجيل الغياب عن رحلة/رحلات محددة يختارها السائق**
* **Method:** `POST`
* **URL:** `/api/driver/trips/register-absence`
* **Body (JSON):**
```json
{
  "date": "2026-09-02",
  "trip_ids": [501, 502],
  "reason": "ظرف صحي طارئ"
}
```
* **ملاحظة:** يُطبَّق الغياب فوراً دون انتظار موافقة إدارية. يُفصل السائق فوراً عن الرحلات
  المحددة فقط (بقية رحلاته في نفس اليوم تبقى كما هي)، ويُرسَل إشعار فوري لجميع أولياء أمور
  الأطفال الموجودين في تلك الرحلات تحديداً بأن سائق رحلة طفلهم غائب وأن النظام يعمل على
  تدبير سائق بديل. مولّد الرحلات اليومية أيضاً لن يُنشئ لاحقاً رحلة جديدة لنفس المسار في نفس
  اليوم بالسائق الغائب نفسه.
* **Success Response (200 OK):**
```json
{
  "status": "success",
  "message": "تم تسجيل غيابك عن الرحلات المحددة فوراً، وفصلك عنها، وتنبيه أولياء أمور أطفالها.",
  "data": {
    "absence_id": 12,
    "driver_id": 7,
    "absence_date": "2026-09-02",
    "reason": "ظرف صحي طارئ",
    "status": "approved",
    "trip_ids": [501, 502],
    "trips": [ { "id": 501, "trip_type": "Morning", "shift_slot": "morning_go", "trip_date": "2026-09-02", "status": "pending", "scheduled_start_time": "07:00:00" } ]
  }
}
```

#### 5.6 إنهاء وإغلاق الرحلة الحية (Complete Trip)
* **Method:** `POST`
* **URL:** `/api/driver/trips/{tripId}/complete`
* **Path Parameter:** `tripId` (int)
* **Body (JSON):** لا يوجد (None)
* **Success Response (200 OK):**
```json
{
  "status": "success",
  "message": "تم إنهاء الرحلة بنجاح وإرسال تقرير الإغلاق"
}
```

---

### 💰 6. البيانات المالية وسحب الأرباح (Wallet, Withdrawals & Invoices)

#### 6.1 عرض رصيد محفظة السائق (Wallet Balance)
* **Method:** `GET`
* **URL:** `/api/driver/wallet/balance`
* **Success Response (200 OK):**
```json
{
  "status": "success",
  "data": {
    "balance": 1250.00,
    "currency": "LYD",
    "pending_withdrawals": 450.00
  }
}
```

#### 6.2 عرض طلبات سحب الأرباح (Withdrawal Requests)
* **Method:** `GET`
* **URL:** `/api/driver/withdrawals`
* **Success Response (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "id": 8,
      "amount": "450.00",
      "wallet_balance_at_request": "1250.00",
      "status": "pending",
      "payment_method_details": {
        "bank": "مصرف الجمهورية",
        "account": "LY3300100200300400"
      },
      "created_at": "2026-07-27 10:41:46"
    }
  ]
}
```

#### 6.3 تقديم طلب سحب أرباح جديد (Request Withdrawal)
* **Method:** `POST`
* **URL:** `/api/driver/withdrawals`
* **Body (JSON):**
```json
{
  "amount": 300.00,
  "payment_method_details": {
    "bank": "مصرف التجاري الوطني",
    "account_number": "LY1100223344"
  }
}
```
* **Success Response (200 OK):**
```json
{
  "status": "success",
  "message": "تم تقديم طلب سحب الأرباح بنجاح وهو قيد المراجعة"
}
```

#### 6.4 عرض فواتير السائق (Driver Invoices)
* **Method:** `GET`
* **URL:** `/api/driver/invoices`
* **Success Response (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "id": 14,
      "invoice_number": "INV-2026-001",
      "amount": "600.00",
      "status": "paid",
      "type": "monthly",
      "due_date": "2026-08-01",
      "paid_at": "2026-07-27 11:07:47"
    }
  ]
}
```

---

### 📊 7. لوحة التحكم والإحصائيات الشاملة (Driver Statistics & Dashboard)

#### 7.1 عرض إحصائيات السائق ولوحة التحكم
تُرجع هذه النقطة كافة المؤشرات المالية، وحالة الركاب وسعة المركبة، ومعدلات التشغيل والالتزام بالرحلات، والويدجت والتنبيهات السريعة.

* **Method:** `GET`
* **URL:** `/api/driver/statistics` (أو المسار المرادف: `/api/driver/dashboard/stats`)
* **Headers:**
  ```http
  Authorization: Bearer {sanctum_token}
  Accept: application/json
  ```
* **Query Parameters (اختيارية):**
  * `month` *(integer, 1-12)*: رقم الشهر المراد حساب أرباحه (افتراضياً: الشهر الحالي).
  * `year` *(integer, YYYY)*: السنة المراد حساب أرباحها (افتراضياً: السنة الحالية).

* **Success Response (200 OK):**
```json
{
  "status": true,
  "success": true,
  "message": "تم جلب إحصائيات السائق ولوحة التحكم بنجاح.",
  "data": {
    "financial_stats": {
      "net_earnings": {
        "total": 4500.00,
        "current_month": 1250.00,
        "previous_month": 1100.00,
        "growth_percentage": 13.64,
        "growth_trend": "up"
      },
      "expected_active_earnings": 1800.00,
      "pending_and_due": {
        "wallet_balance": 650.00,
        "escrow_pending_balance": 200.00,
        "cash_dues_to_platform": 0.00,
        "currency": "د.ل"
      }
    },
    "subscription_and_passenger_stats": {
      "active_students_count": 12,
      "vehicle_capacity": {
        "vehicle_model": "تويوتا كوستر Coaster",
        "plate_number": "5-112233",
        "total_capacity": 14,
        "occupied_seats": 12,
        "available_seats": 2,
        "occupancy_rate": 85.71
      },
      "history": {
        "completed_subscriptions_count": 28,
        "cancelled_subscriptions_count": 2,
        "total_historical_subscriptions": 42
      }
    },
    "trip_operations_stats": {
      "completed_trips": {
        "total": 240,
        "morning_trips": 120,
        "evening_trips": 120,
        "today_completed": 2
      },
      "punctuality_rate": 96.50,
      "absences_and_breakdowns": {
        "absence_days_count": 3,
        "vehicle_breakdowns_count": 1,
        "total_downtime_days": 4
      }
    },
    "quick_widgets": {
      "expiring_soon_subscriptions": {
        "count": 2,
        "days_threshold": 5,
        "items": [
          {
            "subscription_id": 1,
            "child_id": 1,
            "child_name": "سند طه القموضي",
            "school_name": "مدرسة الجيل الجديد الدولية",
            "end_date": "2026-08-31",
            "days_remaining": 2,
            "direction": "two_way"
          }
        ]
      },
      "documents_status": {
        "overall_indicator": "green",
        "overall_status_label": "كافة الوثائق الرسمية سارية ومفعلة",
        "license": {
          "label": "رخصة القيادة",
          "license_number": "DL-998877",
          "expiry_date": "2028-12-31",
          "days_remaining": 855,
          "is_expired": false,
          "indicator": "green",
          "status_label": "سارية ومفعلة"
        },
        "insurance": {
          "label": "تأمين المركبة",
          "expiry_date": "2027-12-31",
          "days_remaining": 489,
          "is_expired": false,
          "indicator": "green",
          "status_label": "ساري ومفعل"
        }
      }
    }
  }
}
```

* **شرح الحقول (Field Descriptions):**
  * `financial_stats.net_earnings`: إجمالي الأرباح الصافية بعد خصم نسبة المنصة (12%)، مع تفصيل الشهر الحالي والشهر السابق ونسبة التغير واتجاه النمو (`up` / `down` / `neutral`).
  * `financial_stats.expected_active_earnings`: مجموع قيمة الاشتراكات الفعّالة والجارية حالياً التي يستحقها السائق بنهاية فترة الاشتراك.
  * `financial_stats.pending_and_due`: الرصيد المتاح حالياً في محفظة السائق، والمبالغ المعلقة في الضمان بانتظار الإفراج، وأي مستحقات نقدية للمنصة.
  * `subscription_and_passenger_stats.active_students_count`: إجمالي عدد الأطفال الذين يتولى توصيلهم حالياً.
  * `subscription_and_passenger_stats.vehicle_capacity`: السعة الكلية للمركبة، المقاعد المشغولة، المقاعد المتبقية المتاحة لقبول طلبات جديدة، ونسبة الإشغال.
  * `subscription_and_passenger_stats.history`: إحصائيات تاريخية تشمل الاشتراكات المكتملة، والاشتراكات الملغاة، وإجمالي النشاط.
  * `trip_operations_stats.completed_trips`: إجمالي الرحلات المنجزة بنجاح وتفصيلها (صباحي / مسائي / منجز اليوم).
  * `trip_operations_stats.punctuality_rate`: معدل الالتزام بالمواعيد المحددة لبدء وإتمام الرحلات كنسبة مئوية.
  * `trip_operations_stats.absences_and_breakdowns`: عدد أيام الإجازة/الغياب المسجلة للسائق وعدد بلاغات الأعطال.
  * `quick_widgets.expiring_soon_subscriptions`: ويدجت يعرض عدد وقائمة الاشتراكات التي ستنتهي خلال 5 أيام قادمة لتنبيه السائق.
  * `quick_widgets.documents_status`: مؤشر لوني لحالة رخصة القيادة ووثيقة التأمين (`green` = سارية، `yellow` = تنتهي قريباً، `red` = منتهية الصلاحية) مع حساب الأيام المتبقية.

