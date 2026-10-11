# 🛡️ دليل ربط واجهات الآدمن بالخلفية (Frontend Admin API Integration Guide)

يقدم هذا الدليل التوثيق الكامل والشامل لجميع مسارات واجهة البرمجة (API Endpoints) الخاصة بـ **تسجيل الدخول، استعادة كلمة المرور، ولوحة تحكم الآدمن (Admin Dashboard)** لمنصة نقل الطلاب (Darby)، موضحاً الهيدرز المطلوبة، طرق الطلب (HTTP Methods)، مدخلات ومخرجات كل دالة بالتفصيل لضمان الربط البرمجي السلس.

---

## 📌 1. الإعدادات العامة للطلب (Base Request Configuration)

* **Base URL:** `https://your-domain.com/api` (أو `http://localhost:8000/api` محلياً).
* **Headers المطلوبة في جميع الطلبات (المحمية):**
  ```http
  Authorization: Bearer <ADMIN_SANCTUM_TOKEN>
  Accept: application/json
  Accept-Language: ar
  ```

---

## 🔐 2. وحدة المصادقة واستعادة كلمة المرور (Authentication & Password Reset)

### 2.1 تسجيل الدخول (Login)
* **Method:** `POST`
* **URL:** `/api/auth/login`
* **وصف:** تسجيل دخول المشرف أو أي مستخدم ورجوع توكن Sanctum والصلاحيات.
* **Body (JSON):**
```json
{
  "phone_number": "0910000000",
  "password": "password123",
  "device_name": "admin_web_browser",
  "fcm_token": "fcm_token_sample_string",
  "platform": "web"
}
```
* **Success Response (200 OK):**
```json
{
  "status": true,
  "message": "مرحباً الآدمن الرئيسي، تم تسجيل الدخول بنجاح!",
  "access_token": "1|abcdef1234567890qwertyuiop...",
  "token_type": "Bearer",
  "role_name": "مدير النظام",
  "user": {
    "id": 1,
    "full_name": "الآدمن الرئيسي",
    "email": "admin@darby.ly",
    "phone_number": "0910000000",
    "avatar_url": "http://domain.com/storage/avatars/admin.png",
    "role_id": 1,
    "is_active": true,
    "last_login_at": "2026-08-04 10:00:00"
  }
}
```
* **Error Responses:**
  * **404 Not Found:** `{"status": false, "message": "رقم الهاتف غير مسجل في النظام."}`
  * **401 Unauthorized:** `{"status": false, "message": "كلمة المرور غير صحيحة."}`
  * **403 Forbidden:** `{"status": false, "message": "حسابك غير مفعل."}`
  * **422 Unprocessable Entity:** validation errors.

---

### 2.2 تسجيل الخروج (Logout)
* **Method:** `POST`
* **URL:** `/api/auth/logout`
* **Header Required:** `Authorization: Bearer <TOKEN>`
* **Body:** لا يوجد.
* **Success Response (200 OK):**
```json
{
  "status": true,
  "message": "تم تسجيل الخروج بنجاح."
}
```

---

### 2.3 إرسال كود استعادة كلمة المرور (Send Reset OTP)
* **Method:** `POST`
* **URL:** `/api/auth/password/send-otp`
* **وصف:** الخطوة الأولى لاستعادة كلمة المرور المنسية عبر إرسال كود OTP مؤلف من 6 أرقام إلى الإيميل.
* **Body (JSON):**
```json
{
  "email": "admin@darby.ly"
}
```
* **Success Response (200 OK):**
```json
{
  "status": true,
  "message": "تم إرسال رمز التحقق إلى بريدك الإلكتروني بنجاح. يرجى التحقق من صندوق الوارد."
}
```
* **Error Responses:**
  * **404 Not Found:** `{"status": false, "message": "البريد الإلكتروني المدخل غير مسجل لدينا."}`
  * **422 Unprocessable Entity:** `{"status": false, "message": "البريد الإلكتروني المدخل غير صالح.", "errors": {...}}`

---

### 2.4 التحقق من كود الـ OTP (Verify Reset OTP)
* **Method:** `POST`
* **URL:** `/api/auth/password/verify-otp`
* **وصف:** الخطوة الثانية للتحقق من صحة كود OTP المدخل من قبل المستخدم قبل التغيير.
* **Body (JSON):**
```json
{
  "email": "admin@darby.ly",
  "code": "123456"
}
```
* **Success Response (200 OK):**
```json
{
  "status": true,
  "message": "تم التحقق من رمز التأكيد بنجاح، يمكنك الآن تعيين كلمة مرور جديدة."
}
```
* **Error Responses:**
  * **400 Bad Request:** `{"status": false, "error_code": "WRONG_CODE_OR_EXPIRED", "message": "رمز التحقق غير صحيح أو انتهت صلاحيته."}`
  * **422 Unprocessable Entity:** `{"status": false, "message": "البيانات المدخلة غير صحيحة.", "errors": {...}}`

---

### 2.5 تعيين كلمة المرور الجديدة (Reset Password)
* **Method:** `POST`
* **URL:** `/api/auth/password/reset`
* **وصف:** الخطوة الثالثة لتغيير كلمة المرور القديمة بالجديدة بعد نجاح الـ OTP.
* **Body (JSON):**
```json
{
  "email": "admin@darby.ly",
  "password": "newpassword123",
  "password_confirmation": "newpassword123"
}
```
* **Success Response (200 OK):**
```json
{
  "status": true,
  "message": "تم تحديث كلمة المرور بنجاح، يمكنك الآن الانتقال لتسجيل الدخول بالحساب."
}
```
* **Error Responses:**
  * **404 Not Found:** `{"status": false, "message": "فشلت العملية، الحساب المرتبط بهذا البريد غير موجود."}`
  * **422 Unprocessable Entity:** `{"status": false, "message": "كلمة المرور الجديدة غير مطابقة للشروط المعتمدة.", "errors": {...}}`

---

### 2.6 عرض الملف الشخصي الحالي للمستخدم (User Profile)
* **Method:** `GET`
* **URL:** `/api/user/profile`
* **Header Required:** `Authorization: Bearer <TOKEN>`
* **Success Response (200 OK):**
```json
{
  "status": true,
  "user": {
    "id": 1,
    "full_name": "الآدمن الرئيسي",
    "email": "admin@darby.ly",
    "phone_number": "0910000000",
    "avatar_url": "http://domain.com/storage/avatars/admin.png",
    "role_id": 1,
    "is_active": true
  }
}
```

---

## 📊 3. وحدة لوحة التحكم والرادار الحي (Dashboard & Radar Module)

### 3.1 إحصائيات الداشبورد الرئيسية (Dashboard Statistics)
* **Method:** `GET`
* **URL:** `/api/admin/dashboard/stats`
* **وصف:** يُرجع الإحصائيات السبعة الرئيسية في المنصة.
* **Request Body:** لا يوجد.
* **Success Response (200 OK):**
```json
{
  "success": true,
  "message": "تم جلب الإحصائيات الشاملة بنجاح.",
  "data": {
    "total_users": {
      "label": "إجمالي المستخدمين",
      "value": "150",
      "raw": 150,
      "change": "+10% الأسبوع الماضي",
      "trend": "up"
    },
    "active_drivers": {
      "label": "إجمالي السائقين المفعلين",
      "value": "25",
      "raw": 25,
      "change": "+3 سائقين جدد",
      "trend": "up"
    },
    "total_parents": {
      "label": "إجمالي أولياء الأمور",
      "value": "80",
      "raw": 80,
      "change": "مسجلين في المنصة",
      "trend": "info"
    },
    "subscribed_children": {
      "label": "إجمالي الأطفال المشتركين في الرحلات",
      "value": "110",
      "raw": 110,
      "change": "اشتراكات نشطة",
      "trend": "info"
    },
    "daily_subscriptions": {
      "label": "إجمالي الاشتراكات اليومية",
      "value": "15",
      "raw": 15,
      "change": "اشتراكات يومية نشطة",
      "trend": "info"
    },
    "monthly_subscriptions": {
      "label": "إجمالي الاشتراكات الشهرية",
      "value": "95",
      "raw": 95,
      "change": "اشتراكات شهرية نشطة",
      "trend": "info"
    },
    "drivers_with_active_trips": {
      "label": "إجمالي السائقين الذين لديهم رحلات حالياً",
      "value": "8",
      "raw": 8,
      "change": "رحلات حية جارية",
      "trend": "live"
    }
  },
  "generated_at": "2026-08-04T10:16:24.000000Z"
}
```

---

### 3.2 الرادار الحي للرحلات الجارية (Live Active Trips Radar - Hybrid Firebase & Fallback)
* **Method:** `GET`
* **URL:** `/api/admin/dashboard/active-trips`
* **وصف:** يُرجع قائمة الرحلات النشطة حالياً في طرابلس مع الموقع الجغرافي اللحظي، وتعيينات الخريطة، وميتا-داتا الفايربيز.
* **الاستراتيجية الهجينة (Hybrid Architecture):**
  1. **الأساس (Realtime):** الفرونت يطلب هذا المسار أولاً لجلب تفاصيل السائقين والوجهات، ثم يفتح استماعاً حياً (onSnapshot) على Firestore في المجموعة `trips_tracking` حيث تُحدّث إحداثيات وسرعة واتجاه الحافلات فوراً.
  2. **البديل (Fallback):** في حال تعذر الاتصال بـ Firebase، يتحول الفرونت تلقائياً للـ Polling الدوري (كل 3-5 ثوانٍ) عبر هذا المسار، حيث يقرأ الباك إند كاش التيليماتري اللحظي لضمان أدق حركة حتى بدون Firebase.
* **Request Body:** لا يوجد.
* **Success Response (200 OK):**
```json
{
  "success": true,
  "count": 2,
  "firebase": {
    "collection": "trips_tracking",
    "strategy": "realtime_with_backend_fallback"
  },
  "data": [
    {
      "id": 24,
      "trip_id": 24,
      "driver_id": 3,
      "name": "عبد السلام المصراتي",
      "phone": "0913456789",
      "avatar": "https://domain.com/storage/avatars/driver3.png",
      "children": "علي ومروة",
      "destination": "مدرسة الجيل الجديد الدولية",
      "duration": "12 دقيقة",
      "status": "في الطريق للاستلام",
      "lat": 32.91,
      "lng": 13.17,
      "heading": 135.0,
      "speed": "55 كم/س",
      "is_online": true,
      "firestore_doc": "trips_tracking/24",
      "map_x": 28.0,
      "map_y": 45.0,
      "region": "حي الأندلس",
      "last_update": "منذ دقيقتين"
    }
  ],
  "is_demo": false
}
```

#### 💡 كود مقترح لفرونت الأدمن (React / Vue / Flutter):
```javascript
// 1. جلب البيانات الأولية من الباك إند
const res = await axios.get('/api/admin/dashboard/active-trips');
renderTripsOnMap(res.data.data);

// 2. الاستماع اللحظي عبر Firebase Firestore
const unsubscribe = db.collection('trips_tracking')
  .where('status', '==', 'active')
  .onSnapshot(
    (snapshot) => {
      snapshot.docChanges().forEach((change) => {
        const trip = change.doc.data();
        updateMarkerPosition(trip.trip_id, trip.driver_lat, trip.driver_lng, trip.heading, trip.speed);
      });
    },
    (error) => {
      // 3. Fallback: عند فشل اتصال Firebase، الانتقال للـ Polling كل 4 ثوانٍ
      console.warn('Firebase error, falling back to Backend polling:', error);
      startBackendPollingFallback(4000);
    }
  );
```

> 📘 **الدليل الكامل المفصّل لربط رادار الأدمن بالفايربيز مع كود TypeScript/React كامل:**  
> يُرجى مراجعة الملف المستقل: [`FRONTEND_ADMIN_RADAR_FIREBASE_GUIDE.md`](FRONTEND_ADMIN_RADAR_FIREBASE_GUIDE.md)

---

## 👨‍💼 4. وحدة إدارة المشرفين (Admins Management Module)

> 📘 **الدليل الكامل والمفصّل لهذه الوحدة في ملف مستقل:** `FRONTEND_SUPERVISORS_API_GUIDE.md`
> (يشمل كل رسائل التحقق، وأمثلة Axios جاهزة، والبيانات الوهمية للاختبار).
> ما يلي ملخّص سريع بنماذج حقيقية ملتقطة من السيرفر.

**كائن المشرف الموحّد** (يرجع بنفس الشكل في كل العمليات):
```json
{
  "id": 9,
  "user_id": 22,
  "full_name": "سارة توفيق العجيلي",
  "email": "sara.supervisor@derbi.ly",
  "phone_number": "0928669900",
  "avatar_url": null,
  "is_active": true,
  "role_id": 2,
  "role_name": "مشرف",
  "created_by": 1,
  "creator_name": "أحمد المدير",
  "created_at": "2026-08-09 22:17:51",
  "last_login_at": null
}
```

### 4.1 جلب قائمة المشرفين
* **Method:** `GET`
* **URL:** `/api/admin/admins`
* **Query (اختياري):** `per_page` (افتراضي 10) — `page` — `search` (بحث في الاسم/البريد/الهاتف)
* **Success Response (200 OK):**
```json
{
  "status": true,
  "message": "تم جلب قائمة المشرفين بنجاح.",
  "data": {
    "data": [ { "...": "كائن المشرف الموحّد" } ],
    "links": {
      "first": "http://localhost/api/admin/admins?page=1",
      "last": "http://localhost/api/admin/admins?page=3",
      "prev": null,
      "next": "http://localhost/api/admin/admins?page=2"
    },
    "meta": {
      "current_page": 1,
      "from": 1,
      "last_page": 3,
      "per_page": 3,
      "to": 3,
      "total": 9
    }
  }
}
```
> 🔴 القائمة في `data.data` والترقيم في `data.meta`. الترتيب تنازلي (الأحدث أولاً).

### 4.2 إضافة مشرف جديد
* **Method:** `POST`
* **URL:** `/api/admin/admins`
* **Body (Multipart / JSON):**
```json
{
  "full_name": "أحمد محمود المبروك",
  "email": "ahmed.admin@darby.ly",
  "phone_number": "0911234567",
  "password": "password123"
}
```
* **قواعد الإدخال:** `full_name` اسم ثلاثي على الأقل — `phone_number` عشرة أرقام تبدأ بـ `09` —
  `password` اختياري (إن تُرك فارغاً يولّده النظام ويرسله للمشرف على بريده) —
  `avatar` اختياري (صورة حتى 2MB).
* **لا ترسل** `password_confirmation` ولا `role_id` ولا `is_active` ولا `created_by` — السيرفر يضبطها تلقائياً.
* **Success Response (201 Created):**
```json
{
  "status": true,
  "message": "تم إضافة المشرف بنجاح.",
  "data": { "...": "كائن المشرف الموحّد" }
}
```

### 4.3 عرض بيانات مشرف محدد
* **Method:** `GET`
* **URL:** `/api/admin/admins/{id}`
* **Success Response (200 OK):**
```json
{
  "status": true,
  "message": "تم جلب بيانات المشرف.",
  "data": { "...": "كائن المشرف الموحّد" }
}
```
* **Not Found (404):** `{ "status": false, "message": "عذراً، المشرف غير موجود." }`

### 4.4 تعديل بيانات مشرف
* **Method:** `POST`
* **URL:** `/api/admin/admins/{id}`
* **كل الحقول اختيارية** (تحديث جزئي): `full_name`, `email`, `phone_number`, `password`, `is_active`, `avatar`
* **Body (JSON / FormData):**
```json
{
  "full_name": "أحمد محمود المبروك",
  "is_active": false
}
```
* **Success Response (200 OK):**
```json
{
  "status": true,
  "message": "تم تحديث بيانات المشرف بنجاح.",
  "data": { "...": "كائن المشرف الموحّد" }
}
```
* **📧 عند تغيير البريد:** لا يتغير فوراً — يُرسل رابط تأكيد صالح 30 دقيقة، وتكون الرسالة:
  `"تم تحديث البيانات المرفقة بنجاح. أرسلنا رابط تأكيد لبريدك الجديد، يرجى مراجعته لتفعيله بكبسة زر."`

### 4.5 حذف مشرف
* **Method:** `DELETE`
* **URL:** `/api/admin/admins/{id}`
* **Body:** لا يوجد
* **Success Response (200 OK):**
```json
{
  "status": true,
  "message": "تم حذف المشرف (سارة توفيق العجيلي) نهائياً بنجاح."
}
```
> ⚠️ الحذف **نهائي وغير قابل للتراجع**، ويلغي جلسات دخول المشرف ويحذف صورته.
* **حالات المنع (403):**
```json
{ "status": false, "message": "لا يمكنك حذف حسابك الشخصي من هنا." }
```
```json
{ "status": false, "message": "لا يمكن حذف حساب مدير النظام الأساسي." }
```
> 💡 أخفِ زر الحذف عن الصفوف التي `role_id === 1` أو `user_id === currentUser.id`.

---

## 🚘 5. وحدة إدارة السائقين والمراجعة (Drivers Management & Approvals)

### 5.1 جلب قائمة السائقين والطلبات (مع الفلترة والبحث)
* **Method:** `GET`
* **URL:** `/api/admin/drivers?status=Pending&search=091&page=1`
* **Query Parameters:**
  * `status`: `Pending`, `Approved`, `Suspended`, `Rejected` (اختياري)
  * `search`: بحث بالاسم أو رقم الهاتف أو الرقم الوطني (اختياري)
  * `page`: رقم الصفحة (افتراضي 1)
* **Success Response (200 OK):**
```json
{
  "status": true,
  "message": "تم جلب قائمة السائقين والطلبات بنجاح.",
  "data": [
    {
      "id": 5,
      "full_name": "سالم الفيتوري",
      "phone_number": "0915554433",
      "email": "salem@darby.ly",
      "status": "Pending",
      "created_at": "2026-08-01 14:20:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

### 5.2 عرض تفاصيل ووثائق السائق للمراجعة
* **Method:** `GET`
* **URL:** `/api/admin/drivers/{id}`
* **Success Response (200 OK):**
```json
{
  "status": true,
  "message": "تم جلب تفاصيل السائق والوثائق بنجاح.",
  "data": {
    "id": 5,
    "full_name": "سالم الفيتوري",
    "phone_number": "0915554433",
    "national_id": "119900112233",
    "license_number": "LIC-98765",
    "status": "Pending",
    "documents": [
      {
        "type": "license",
        "file_url": "http://domain.com/storage/docs/lic_5.pdf"
      }
    ],
    "vehicles": [
      {
        "brand": "تويوتا",
        "model": "هايس",
        "color": "أبيض",
        "plate_number": "12345-طرابلس"
      }
    ]
  }
}
```

### 5.3 قرار مراجعة تسجيل السائق (قبول مفعل / رفض)
* **Method:** `POST`
* **URL:** `/api/admin/drivers/{id}/review`
* **Body (JSON):**
```json
{
  "status": "Approved", 
  "rejection_reason": null
}
```
*(في حال الرفض: `"status": "Rejected"`, `"rejection_reason": "رخصة القيادة منتهية الصلاحية"`)*
* **Success Response (200 OK):**
```json
{
  "status": true,
  "message": "تمت مراجعة طلب السائق وتحديث حالته بنجاح وعمل التفعيل.",
  "data": {
    "id": 5,
    "status": "Approved",
    "full_name": "سالم الفيتوري",
    "is_active": true,
    "updated_at": "2026-08-04 10:30:00"
  }
}
```

### 5.4 جلب طلبات تعديل بيانات/مركبات السائقين المعلقة
* **Method:** `GET`
* **URL:** `/api/admin/drivers/pending-changes`
* **Success Response (200 OK):**
```json
{
  "status": true,
  "message": "تم جلب كافة التعديلات المعلقة للسائقين بنجاح.",
  "data": [
    {
      "id": 3,
      "driver_id": 5,
      "driver_name": "سالم الفيتوري",
      "change_type": "vehicle_update",
      "status": "pending",
      "created_at": "2026-08-03 16:00:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

### 5.5 عرض التعديل المعلق مقارنة بالبيانات الحالية (Comparative Diff)
* **Method:** `GET`
* **URL:** `/api/admin/drivers/pending-changes/{id}`
* **Success Response (200 OK):**
```json
{
  "status": true,
  "message": "تم جلب تفاصيل طلب التعديل بنجاح للمقارنة الإدارية.",
  "data": {
    "id": 3,
    "driver_id": 5,
    "old_data": {
      "color": "أبيض",
      "plate_number": "12345-طرابلس"
    },
    "new_data": {
      "color": "أسود",
      "plate_number": "99999-طرابلس"
    }
  }
}
```

### 5.6 قبول أو رفض طلب التعديل المعلق
* **Method:** `POST`
* **URL:** `/api/admin/drivers/pending-changes/{id}/review`
* **Body (JSON):**
```json
{
  "decision": "Approved",
  "rejection_reason": null
}
```
* **Success Response (200 OK):**
```json
{
  "status": true,
  "message": "تمت الموافقة على التعديلات وتطبيقها على حساب المركبة والسائق فوراً بنجاح."
}
```

---

## 🏫 6. وحدة إدارة المدارس والمناطق (Schools & Zones Module)

### 6.1 جلب المدارس
* **Method:** `GET`
* **URL:** `/api/admin/schools`
* **Success Response (200 OK):**
```json
{
  "success": true,
  "message": "تم جلب كافة المدارس بنجاح.",
  "data": [
    {
      "id": 1,
      "name": "مدرسة الفلاح",
      "address": "حي الأندلس، طرابلس",
      "latitude": 32.8872,
      "longitude": 13.1856,
      "status": "approved"
    }
  ]
}
```

### 6.2 إضافة مدرسة معتمدة
* **Method:** `POST`
* **URL:** `/api/admin/schools`
* **Body (JSON):**
```json
{
  "name": "مدرسة المستقبل الدولية",
  "address": "بن عاشور، طرابلس",
  "zone_id": 2,
  "latitude": 32.8900,
  "longitude": 13.2000
}
```
* **Success Response (201 Created):**
```json
{
  "success": true,
  "message": "تم إضافة المدرسة كعنوان معتمد ومربوط جغرافياً بنجاح.",
  "data": {
    "id": 2,
    "name": "مدرسة المستقبل الدولية",
    "status": "approved"
  }
}
```

### 6.3 عرض / تعديل / حذف مدرسة
* **عرض:** `GET /api/admin/schools/{id}`
* **تعديل:** `POST /api/admin/schools/{id}` (Body يحتوي البيانات المعدلة)
* **حذف:** `DELETE /api/admin/schools/{id}`
* **إجابة الحذف (200 OK):**
```json
{
  "success": true,
  "message": "تم حذف المدرسة من النظام بنجاح."
}
```

---

## 🗺️ 7. وحدة المناطق والجغرافيا (Zones Module)

* **عرض المناطق:** `GET /api/admin/zones` (أو `/api/admin/zones-tree` لشجرة البلديات والمناطق)
* **إضافة منطقة:** `POST /api/admin/zones`
  ```json
  {
    "name": "حي الأندلس",
    "sub_municipality_id": 1
  }
  ```
* **تعديل منطقة:** `PUT /api/admin/zones/{id}`
* **حذف منطقة:** `DELETE /api/admin/zones/{id}`

---

## 📋 8. وحدة الشكاوى وتقييمات السائقين (Complaints & Reviews Module)

### 8.1 جلب الشكاوى
* **Method:** `GET`
* **URL:** `/api/admin/complaints?status=pending`
* **Success Response (200 OK):**
```json
{
  "status": true,
  "data": [
    {
      "id": 10,
      "parent_name": "طه سالم",
      "driver_name": "محمد علي",
      "reason": "تأخر في الموعد المحدد",
      "status": "pending",
      "created_at": "2026-08-02 08:00:00"
    }
  ],
  "pagination": {
    "current_page": 1,
    "last_page": 1,
    "total": 1,
    "per_page": 15
  }
}
```

### 8.2 معالجة الشكوى واتخاذ إجراء ضد السائق
* **Method:** `POST`
* **URL:** `/api/admin/complaints/{id}/review`
* **Body (JSON):**
```json
{
  "action": "warning", 
  "action_details": "تم توجيه إنذار كتابي للسائق بسبب التأخير."
}
```
*(خيارات الـ `action`: `"warning"` (إنذار), `"suspension"` (إيقاف), `"dismiss"` (تجاهل))*
* **Success Response (200 OK):**
```json
{
  "status": true,
  "message": "تم إرسال إنذار للسائق وحفظ القرار.",
  "data": {
    "id": 10,
    "status": "resolved"
  }
}
```

### 8.3 عرض حذف تقييمات السائقين
* **عرض جميع التقييمات:** `GET /api/admin/driver-reviews/all`
* **تقييمات سائق معين:** `GET /api/admin/driver-reviews/driver/{driverId}`
* **حذف تقييم مسيء:** `DELETE /api/admin/driver-reviews/{id}`

---

## 💰 9. وحدة الإدارة المالية، السحب والشحن (Financial Module)

### 9.1 طلبات سحب رصيد السائقين (Withdrawals)
* **عرض الطلبات:** `GET /api/admin/financial/withdrawals?status=pending`
* **معالجة طلب السحب:** `POST /api/admin/financial/withdrawals/{id}/process`
* **Body (JSON):**
```json
{
  "action": "approve" 
}
```
*(أو `"action": "reject"`, `"rejection_reason": "بيانات الحساب البنكي خاطئة"`)*
* **Success Response (200 OK):**
```json
{
  "status": true,
  "message": "تمت الموافقة على طلب السحب بنجاح."
}
```

### 9.2 طلبات شحن محفظة ولي الأمر (Recharges)
* **عرض الطلبات:** `GET /api/admin/financial/recharges?status=pending`
* **معالجة طلب الشحن:** `POST /api/admin/financial/recharges/{id}/process`
* **Query/Body:** `action=complete` (تأكيد الشحن) أو `action=reject` (رفض)
* **Success Response (200 OK):**
```json
{
  "status": true,
  "message": "تم تأكيد عملية الشحن وإضافة الرصيد للمحفظة."
}
```

---

## 📌 10. ملخص أكواد حالات الـ HTTP في الاستجابة (HTTP Status Codes)

* `200 OK`: تمت العملية بنجاح.
* `201 Created`: تم إنشاء عنصر جديد (مشرف، مدرسة، منطقة) بنجاح.
* `400 Bad Request`: الطلب غير صالح أو منتهي الصلاحية.
* `401 Unauthenticated`: التوكن غير موجود أو غير صالح.
* `403 Forbidden`: ليس لديك صلاحيات آدمن للقيام بهذا الإجراء.
* `404 Not Found`: العنصر المطلوب غير موجود.
* `422 Unprocessable Entity`: أخطاء في فحص البيانات (Validation Errors).
* `500 Internal Server Error`: خطأ في خادم النظام.
