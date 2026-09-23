# دليل اختبار دورة حياة الرحلة — Endpoints كاملة للربط مع الفرونت

> مبني من قراءة الكود الفعلي في المشروع بتاريخ 2026-08-30. كل حقل هنا موجود بالفعل في الكنترولر/السيرفس المذكور بجانبه.

## ⚠️ ملاحظة حرجة قبل البدء: `trip_child_id` وليس `child_id`

كل نقاط تحديث حالة الطفل داخل الرحلة (صعود/نزول/غياب) تتوقع **معرّف الاشتراك النشط** (`active_subscriptions.id`)، وليس معرّف الطفل نفسه (`children.id`) — حتى لو كان اسم الـ route parameter مكتوب `{childId}`.

هذا القيمة تُسمّى `trip_child_id` في استجابة [`GET /driver/trips/{tripId}`](app/Http/Controllers/Api/Trip/DriverTripController.php:266) — لازم الفرونت يخزّنها عند عرض قائمة الأطفال ويرسلها هي (مش `child_id`) في كل نداءات pickup/dropoff/absent/skip.

```php
// DriverTripController::updateChildTripStatus
$subId = $tripChildId ?? $request->trip_child_id;
$sub = ActiveSubscription::where('id', $subId)->where('driver_id', $driverId)->firstOrFail(); // ⚠️ id مش child_id
```

---

## 1) إنشاء مسار سائق (Route)

**Endpoint:** `POST /api/v1/driver/routes`
Auth: `driver` (Sanctum)
المصدر: [DriverRouteController::store](app/Http/Controllers/Api/Driver/DriverRouteController.php:125)

### Input
| الحقل | إجباري | النوع | ملاحظات |
|---|---|---|---|
| `name` | ✅ | string, max:50 | اسم المسار |
| `trip_type` | ✅ | `morning` \| `evening` \| `afternoon` | `evening` و `afternoon` يُخزَّنان كليهما كـ `Afternoon` داخلياً |

```json
{ "name": "مسار الأندلس الصباحي", "trip_type": "morning" }
```

### Output — نجاح (201)
```json
{
  "status": "success",
  "message": "تم إنشاء المسار.",
  "route": { "id": 42 }
}
```

### Output — فشل (422)
```json
{ "status": "error", "code": "CREATE_ROUTE_FAILED", "message": "..." }
```
فشل الفاليديشن (اسم مفقود / trip_type خاطئ) يرجع رسالة Laravel القياسية (422) قبل الوصول للكنترولر.

---

## 2) عرض مسارات السائق — كل الحالات

### 2.أ) قائمة كل المسارات
**Endpoint:** `GET /api/v1/driver/routes`
المصدر: [DriverRouteController::index](app/Http/Controllers/Api/Driver/DriverRouteController.php:92) + [RouteRecommendationService::calculateRouteMetrics](app/Services/Trip/RouteRecommendationService.php:33)

```json
{
  "status": "success",
  "routes": [
    {
      "id": 42,
      "name": "مسار الأندلس الصباحي",
      "trip_type": "morning",
      "status": "ready",
      "is_locked": false,
      "lock_reason": null,
      "children_count": 3,
      "schools_count": 2,
      "vehicle_capacity": 12,
      "available_seats": 9,
      "first_school_time": "07:30",
      "last_school_time": "08:00",
      "recommended_departure": "07:00",
      "estimated_duration": 35,
      "health_score": 100,
      "needs_review": false,
      "review_reason": null
    }
  ]
}
```

**حالات `status` الممكنة:**
| القيمة | متى تظهر |
|---|---|
| `draft` | لا يوجد أي طفل مسند للمسار (`children_count == 0`) |
| `ready` | فيه أطفال ولا توجد رحلة نشطة اليوم عليه |
| `in_trip` | فيه رحلة `pending`/`in_progress` بتاريخ اليوم على هذا المسار — و`is_locked = true`, `lock_reason = "Trip Started"` |
| `archived` | حالة المسار نفسه `inactive`/`archived` في قاعدة البيانات |

**`needs_review = true`** يعني أحد الأطفال المسندين حالته `needs_review` (تغيّرت مدرسته/عنوانه بعد الإسناد) — يجب على الفرونت تنبيه السائق لمراجعة `review_reason`.

**`is_locked = true`** يمنع التعديل (`update`/`reorder`/`assign`/`unassign`) — أي محاولة تعديل السائق سترجع 422 مع `error_code` مخصص (انظر تحت).

### 2.ب) تفاصيل مسار واحد (بالإحداثيات والوقفات)
**Endpoint:** `GET /api/v1/driver/routes/{routeId}`
المصدر: [DriverRouteController::show](app/Http/Controllers/Api/Driver/DriverRouteController.php:172)

```json
{
  "status": "success",
  "route": {
    "id": 42, "name": "...", "trip_type": "morning", "status": "ready",
    "is_locked": false, "lock_reason": null,
    "children_count": 2, "schools_count": 2, "vehicle_capacity": 12,
    "available_seats": 10, "first_school_time": "07:30", "last_school_time": "08:00",
    "recommended_departure": "07:00", "estimated_duration": 35, "health_score": 100,
    "needs_review": false, "review_reason": null,
    "shift_slot": null,
    "subscriptions": [
      {
        "id": 101, "subscription_id": 101, "child_id": 10, "child_name": "سند طه",
        "route_status": "active", "pickup_order": 1, "estimated_pickup": "07:28",
        "needs_review": false, "review_reason": null,
        "pickup": { "label": "حي الأندلس", "latitude": 32.8872, "longitude": 13.1913 },
        "school": { "id": 1, "name": "مدرسة أ", "latitude": 32.901, "longitude": 13.205 }
      },
      {
        "id": 102, "subscription_id": 102, "child_id": 11, "child_name": "مروة طه",
        "route_status": "active", "pickup_order": 2, "estimated_pickup": "07:28",
        "needs_review": false, "review_reason": null,
        "pickup": { "label": "حي الأندلس", "latitude": 32.8872, "longitude": 13.1913 },
        "school": { "id": 2, "name": "مدرسة ب", "latitude": 32.910, "longitude": 13.210 }
      }
    ],
    "stops": [
      { "id": 5, "stop_type": "home", "child_id": 10, "school_id": null, "label": "حي الأندلس", "latitude": 32.887, "longitude": 13.191, "sequence_order": 1 }
    ]
  }
}
```

**حالات مهمة تظهر هنا (تغطي سؤالك عن أكثر من طفل / أكثر من مدرسة):**
- طفل واحد فقط → `subscriptions` عنصر واحد، `schools_count = 1`.
- طفلان بنفس المدرسة → `subscriptions` عنصران، كل عنصر بنفس `school.id`، `schools_count = 1`.
- طفلان بمدرستين مختلفتين → `subscriptions` عنصران بـ `school` مختلف لكل واحد، `schools_count = 2`. **لا يوجد** حقل "مدرسة واحدة عامة للمسار" هنا — كل طفل يحمل مدرسته بشكل صريح ومستقل (على عكس الخلل الذي صححناه في جانب ولي الأمر).
- `needs_review: true` على مستوى الاشتراك الفردي → السائق لازم يراجع هذا الطفل تحديداً قبل بدء الرحلة (لن يمنع البدء لكن يستحق تنبيه UI).

### Output — فشل (404)
```json
{ "status": "error", "code": "ROUTE_NOT_FOUND", "message": "المسار غير موجود أو لا تملك صلاحية الوصول إليه." }
```

### أكواد أخطاء شائعة عند التعديل/الإسناد على مسار (كلها 422 إلا ما هو محدد)
| `error_code` | السبب |
|---|---|
| `ROUTE_HAS_CHILDREN` | محاولة تغيير `trip_type` أو حذف مسار فيه أطفال مسندون (409 عند الحذف) |
| `SUBSCRIPTION_EXPIRED` / `SUBSCRIPTION_SUSPENDED` | الاشتراك غير صالح للإسناد |
| `SUBSCRIPTION_NOT_STARTED` | تاريخ بداية الاشتراك لم يحن بعد |
| `SUBSCRIPTION_ALREADY_ASSIGNED` | الطفل مسند بالفعل لمسار آخر بنفس الفترة (صباح/مساء) |
| `ROUTE_FULL` | تجاوز سعة المركبة |
| مسار به رحلة نشطة اليوم | يمنع أي تعديل/إسناد/حذف — رسالة من `validateRouteNotRunning` |

---

## 3) بدء رحلة (Start Trip)

**Endpoint:** `POST /api/v1/driver/trips/{tripId}/start` (أو `POST /api/v1/driver/trips/start` بدون tripId في الرابط)
Auth: `driver`
المصدر: [DriverTripController::start](app/Http/Controllers/Api/Trip/DriverTripController.php:317)

### Input
| الحقل | إجباري | ملاحظات |
|---|---|---|
| `trip_id` | اختياري | فقط إذا استخدمت المسار بدون `{tripId}` في الـ URL |
| `latitude` / `lat` | اختياري | أي من الاسمين يُقبل |
| `longitude` / `lng` | اختياري | أي من الاسمين يُقبل |
| `trip_type` | اختياري | `Morning` (افتراضي) — يُستخدم فقط في المسار البديل (بدون tripId ولا trip موجود لليوم أصلاً) |

لا تحقق (validation) صارم هنا — كل الحقول اختيارية فعلياً؛ لو أرسلت `lat`/`lng` يحدّث موقع السائق الحالي ويحسب ETAs حية تلقائياً.

```json
{ "latitude": 32.8872, "longitude": 13.1913 }
```

### Output — نجاح (200)
```json
{
  "status": "success",
  "message": "تم بدء الرحلة.",
  "data": { "trip_id": 55, "status": "in_progress", "started_at": "07:15" }
}
```

### Output — فشل (400)
```json
{ "status": "error", "message": "<رسالة الاستثناء>" }
```
(مثلاً: لا يوجد مسار نشط لليوم، أو الرحلة تخص سائق آخر)

---

## 4) عرض تفاصيل الرحلة للسائق (كل حالات الأطفال/المدارس)

**Endpoint:** `GET /api/v1/driver/trips/{tripId}`
المصدر: [DriverTripController::show](app/Http/Controllers/Api/Trip/DriverTripController.php:174)

```json
{
  "status": "success",
  "data": {
    "trip_id": 55, "trip_type": "Morning", "route_name": "...", "status": "in_progress",
    "suspension_reason": null, "trip_date": "2026-08-30",
    "recommended_departure": "07:00", "estimated_duration": 35,
    "vehicle": { "plate": "5-12345", "capacity": 14 },
    "statistics": { "children": 3, "schools": 2 },
    "schools": [
      { "school_id": 1, "name": "مدرسة أ", "children_count": 2 },
      { "school_id": 2, "name": "مدرسة ب", "children_count": 1 }
    ],
    "children": [
      {
        "trip_child_id": 101, "child_id": 10, "name": "سند طه",
        "school": "مدرسة أ", "school_name": "مدرسة أ",
        "pickup_address": "حي الأندلس", "dropoff_address": "مدرسة أ",
        "status": "pending", "pickup_status": "pending", "dropoff_status": "pending",
        "eta": "07:28", "sequence_order": 1
      }
    ]
  }
}
```

هذا الإندبوينت يرجع **كل** الأطفال المسندين لهذا الخط/العقد مع السائق (بغض النظر عن أهاليهم) — راجع `statistics.children` و`schools[]` المجمعة تلقائياً بحسب `school_id`. لا حاجة لأي تعديل هنا لتغطية حالة تعدد المدارس، فهي مغطاة أصلاً كل طفل بمدرسته.

### Output — فشل (404)
```json
{ "status": "error", "message": "الرحلة غير موجودة." }
```

---

## 5) تأكيد الصعود/النزول اليدوي من السائق (زر "تم الصعود" / "تم النزول")

⚠️ **لم يعد هناك مسح QR.** التأكيد الوحيد المتاح للسائق هو الزر اليدوي، ويُقيَّد دائماً بموقع GPS الفعلي (لا يوجد أي مسار يتجاوز هذا الفحص).

**Endpoint:** `POST /api/v1/driver/trips/{tripId}/pickup` (صعود) أو `POST /api/v1/driver/trips/{tripId}/dropoff` (نزول)
⚠️ رغم اسم الـ param بالراوت القديم، القيمة المطلوبة في `trip_child_id` هي `active_subscriptions.id` وليس `children.id`.
المصدر: [DriverTripController::updateChildTripStatus](app/Http/Controllers/Api/Trip/DriverTripController.php:941)

### Input
| الحقل | إجباري | ملاحظات |
|---|---|---|
| `trip_child_id` | ✅ | معرّف الاشتراك النشط |
| `latitude` | ✅ | موقع السائق الحالي — مطلوب لفحص الـ Geofence |
| `longitude` | ✅ | نفس الشيء |

```json
{ "trip_child_id": 101, "latitude": 32.8871, "longitude": 13.1912 }
```

### Output — نجاح صعود (200)
```json
{
  "status": "success",
  "message": "تم تأكيد الصعود وإرسال الإشعار لولي الأمر.",
  "next_stop": { "trip_child_id": 102, "name": "مروة طه" }
}
```
`next_stop` قد يكون `null` إذا كان هذا آخر طفل بالمسار.

### Output — نجاح نزول (200)
```json
{ "status": "success", "message": "تم تأكيد النزول وإرسال الإشعار لولي الأمر." }
```

### Output — فشل خارج النطاق الجغرافي (422)
```json
{
  "status": "error",
  "error_code": "OUT_OF_RANGE",
  "message": "أنت بعيد عن موقع المحطة (250 م)، الحد المسموح 100 م. يرجى الاقتراب من الموقع."
}
```
نصف قطر السماح: **100م للمنزل**، **200م للمدرسة**.

### Output — فشل عدم إرسال موقع (422)
```json
{ "status": "error", "error_code": "LOCATION_REQUIRED", "message": "يجب إرسال الموقع الجغرافي الحالي (latitude, longitude) للتأكيد." }
```

### Output — فشل تعارض/تكرار (409)
```json
{ "status": "error", "error_code": "ALREADY_PROCESSED", "message": "تم تسجيل صعود هذا الطفل مسبقاً." }
```
أو عند محاولة تسجيل نزول قبل صعود:
```json
{ "status": "error", "error_code": "NOT_BOARDED_YET", "message": "لا يمكن تأكيد النزول قبل تأكيد صعود الطفل أولاً." }
```

---

> ⚠️ **البنود 7–9 التالية غير محدّثة**: الكنترولرات والسيرفس المذكورة فيها (`TripManualConfirmationController`, `TripManualConfirmationService`, والـ Requests/Resource المرتبطة بها) **حُذفت من الكود حالياً**. لا تعتمد عليها في الفرونت قبل التأكد من السيرفر — هذه ميزة منفصلة تماماً عن تأكيد الصعود/النزول اللحظي في البند 5 أعلاه.

## 7) طلب تأكيد يدوي من ولي الأمر (السائق ينسى توثيق رحلة سابقة)

هذا مسار منفصل تماماً عن Pickup/Dropoff اللحظي — يُستخدم فقط عندما تفوّت رحلة **سابقة** (تاريخها مضى) بدون توثيق صحيح.

### 7.أ) جلب أولياء الأمور والأطفال المشتركين مع السائق (لاختيار الطفل)
**Endpoint:** `GET /api/v1/driver/trip-manual-confirmations/parents-and-children`
المصدر: [TripManualConfirmationService::getSubscribedParentsAndChildren](app/Services/Trip/TripManualConfirmationService.php:35)
```json
{
  "success": true, "message": "...",
  "data": [
    {
      "active_subscription_id": 101,
      "child": { "id": 10, "name": "سند طه", "photo_url": null },
      "parent": { "user_id": 5, "name": "طه القمودي", "phone": "0912345678" }
    }
  ]
}
```

### 7.ب) الرحلات السابقة غير الموثّقة بالكامل
**Endpoint:** `GET /api/v1/driver/trip-manual-confirmations/incomplete-trips`
```json
{
  "success": true, "message": "...",
  "data": [
    { "trip_id": 40, "trip_date": "2026-08-27", "shift_slot": null, "route_name": "...", "status": "in_progress", "unconfirmed_count": 2 }
  ]
}
```

### 7.ج) أطفال رحلة معيّنة قابلين لطلب تأكيد
**Endpoint:** `GET /api/v1/driver/trip-manual-confirmations/trips/{tripId}/children`
```json
{
  "success": true, "message": "...",
  "data": [
    { "trip_stop_id": 9, "child": { "id": 10, "name": "سند طه" }, "current_status": "pending", "has_pending_confirmation": false }
  ]
}
```

### 7.د) إرسال طلب التأكيد فعلياً (إشعار لولي الأمر)
**Endpoint:** `POST /api/v1/driver/trip-manual-confirmations`
المصدر: [TripManualConfirmationController::store](app/Http/Controllers/Api/Driver/TripManualConfirmationController.php:76)

#### Input
| الحقل | إجباري | ملاحظات |
|---|---|---|
| `trip_id` | ✅ integer, exists:trips,id | |
| `child_ids` | ✅ array, min:1 | |
| `child_ids.*` | ✅ integer, exists:children,id | هنا فعلاً `children.id` الحقيقي (مختلف عن بند 1) |

```json
{ "trip_id": 40, "child_ids": [10, 11] }
```

#### Output — نجاح (201)
```json
{
  "success": true,
  "message": "تم إرسال طلبات التأكيد لأولياء الأمور المعنيين.",
  "data": [
    {
      "id": 7, "trip_id": 40, "trip_date": "2026-08-27",
      "question_type": "pickup", "target_status": "delivered_home", "status": "pending",
      "child": { "id": 10, "name": "سند طه" },
      "responded_at": null, "created_at": "2026-08-30T10:00:00+00:00"
    }
  ]
}
```
- `question_type`: `pickup` (هل صعد الطفل من المنزل؟) أو `dropoff` (هل تم تسليمه؟) — يُحدَّد تلقائياً حسب الحالة الحالية للمحطة.
- الطفل يُتخطى بصمت (لا يظهر في `data`) لو حالته موثّقة فعلاً أو لا يوجد اشتراك نشط له.

#### Output — فشل (422)
```json
{ "success": false, "message": "هذا الحساب غير مسجل كسائق في النظام." }
```

---

## 8) رد ولي الأمر على طلب التأكيد اليدوي (من السائق)

### 8.أ) عرض طلبات التأكيد المعلّقة لولي الأمر
**Endpoint:** `GET /api/parent/trip-manual-confirmations`
نفس شكل استجابة بند 7.د (`data` array من نفس الـ Resource) لكن فقط الحالة `pending`.

### 8.ب) الرد (تأكيد أو نفي)
**Endpoint:** `POST /api/parent/trip-manual-confirmations/{id}/respond`
المصدر: [TripManualConfirmationController::respond](app/Http/Controllers/Api/Parent/TripManualConfirmationController.php:33)

#### Input
| الحقل | إجباري | ملاحظات |
|---|---|---|
| `confirmed` | ✅ boolean | `true` = نعم فعلاً حصل، `false` = لا لم يحصل |

```json
{ "confirmed": true }
```

#### Output — نجاح تأكيد (200)
```json
{
  "success": true,
  "message": "شكراً لتأكيدك، تم تحديث حالة الرحلة.",
  "data": { "id": 7, "trip_id": 40, "status": "confirmed", "responded_at": "2026-08-30T10:05:00+00:00", "...": "..." }
}
```
عند `confirmed: true` يتحدّث `trip_stops.status` فعلياً إلى `target_status` (مثلاً `delivered_home`).

#### Output — نجاح نفي (200)
```json
{ "success": true, "message": "تم تسجيل ردك، وتم إشعار السائق.", "data": { "status": "denied", "...": "..." } }
```
عند `confirmed: false` **لا يتغيّر** `trip_stops.status` — فقط يُشعَر السائق ليتابع يدوياً.

#### Output — فشل (422)
```json
{ "success": false, "message": "تم الرد على هذا الطلب مسبقاً." }
```
أو `"طلب التأكيد غير موجود أو لا يخص طفلك."` لو الـ `id` لا يخص هذا الوالد.

---

## 9) تأكيد يدوي مباشر من ولي الأمر (بدون طلب مسبق من السائق)

مسار مختلف تماماً عن بند 8 — يُستخدم كبديل فوري عندما لا يستطيع السائق تأكيد الصعود بنفسه (تعطل جهازه مثلاً) أثناء الرحلة الجارية (وليس رحلة قديمة).

**Endpoint:** `POST /api/parent/children/{childId}/confirm-pickup/{tripId}`
المصدر: [ParentChildController::confirmManualPickup](app/Http/Controllers/Api/Trip/ParentChildController.php:247) → [TripStopService::confirmManualPickup](app/Services/Trip/TripStopService.php:232)

### Input
لا body مطلوب — كل شيء عبر مسار الـ URL.

| Path param | إجباري | ملاحظات |
|---|---|---|
| `childId` | ✅ | `children.id` الحقيقي — يُتحقق أنه يتبع ولي الأمر المُصادَق عليه |
| `tripId` | ✅ | |

### Output — نجاح (200)
```json
{ "status": "success", "message": "قمتِ بتأكيد ركوب طفلك يدوياً، تم إخطار السائق" }
```

### Output — فشل (400)
```json
{ "status": "error", "message": "عذراً، هذا الطفل غير موجود أو لا يتبع لحسابك." }
```
أو
```json
{ "status": "error", "message": "عذراً، قام السائق بتجاوز هذه المحطة بالفعل." }
```
(لو كان السائق سجّل `skipped` مسبقاً لهذا الطفل بهذه الرحلة)

⚠️ **تنبيه معماري يستحق الانتباه عند اختبار هذا المسار تحديداً:** هذه الدالة تكتب فقط في `trip_events` (action_type=`picked_up`) ولا تُحدّث `trip_stops.status` إلى `boarded` كما يفعل مسار pickup العادي (بند 5). يعني بعدها لو السائق فتح شاشة الرحلة، حالة الطفل في `trip_stops` (مصدر الحقيقة الأساسي في [`show`](app/Http/Controllers/Api/Trip/DriverTripController.php:255) و[`live`](app/Http/Controllers/Api/Trip/DriverTripController.php:388)) قد تبقى `pending` رغم إن ولي الأمر أكّد. إذا كنت تختبر هذا التدفق وتتوقع تحديث فوري لحالة السائق، هذه نقطة يجب فحصها/تصحيحها في السيرفر أولاً — أخبرني إذا أردت أن أصلحها بنفس منطق pickup العادي (تحديث `trip_stops` + قفل صف/معاملة).

---

## 10) عرض أطفال ولي الأمر ضمن رحلة نشطة + حالتهم

### 10.أ) كل الرحلات النشطة اليوم لأطفال ولي الأمر
**Endpoint:** `GET /api/parent/trips/active`
المصدر: [ParentTripService::getActiveTripsForParent](app/Services/Trip/ParentTripService.php:25) — **بعد التصحيح** الذي طبّقناه لحقل الوجهة عند تعدد المدارس.

```json
{
  "status": "success",
  "data": [
    {
      "trip_id": 55, "trip_type": "Morning", "direction": "to_school", "status": "in_progress",
      "started_at": "2026-08-30T07:15:00+00:00",
      "driver": { "id": 3, "name": "...", "phone": "...", "photo": "..." },
      "vehicle": { "info": "Toyota Hiace 2022" },
      "children": [
        { "child_id": 10, "child_name": "سند طه", "child_photo": "...", "child_status": "boarded",
          "destination": { "name": "مدرسة أ", "type": "school", "lat": 32.89, "lng": 13.18 } },
        { "child_id": 11, "child_name": "مروة طه", "child_photo": "...", "child_status": "pending",
          "destination": { "name": "مدرسة ب", "type": "school", "lat": 32.91, "lng": 13.20 } }
      ],
      "destination": { "name": "مدرسة أ", "type": "school", "lat": 32.89, "lng": 13.18 },
      "destinations": [
        { "name": "مدرسة أ", "type": "school", "lat": 32.89, "lng": 13.18 },
        { "name": "مدرسة ب", "type": "school", "lat": 32.91, "lng": 13.20 }
      ],
      "is_multi_school": true
    }
  ]
}
```
`children[].child_status` قيم ممكنة: `pending`, `boarded`, `absent_pre`, `absent_late`, `dropped_off_school`, `delivered_home`, `skipped_unresponsive`, `dropoff_failed`, `direct_parent_handling` (من `trip_stops.status`)، أو fallback قديم `waiting`/`absent`/اسم `action_type` لو لا توجد `trip_stops` بعد.

### 10.ب) تفاصيل رحلة واحدة (بيانات السائق/المركبة/الأطفال)
**Endpoint:** `GET /api/parent/trips/{tripId}`
نفس البنية تقريباً مع `destinations`/`is_multi_school` الجديدة، لكن `children[]` هنا يحمل `school_name` + `destination` بدل `child_status` القادم من `trip_events` فقط (وليس `trip_stops`) — أقل دقة زمنياً من بند 10.أ.

### 10.ج) حالة طفل معيّن داخل رحلة معيّنة (دقيقة)
**Endpoint:** `GET /api/parent/trips/{tripId}/children/{childId}/status`
```json
{ "status": "success", "data": { "child_id": 10, "status": "boarded", "time": "07:20" } }
```

### 10.د) خطوات تقدّم الطفل (لعرض Progress Bar)
**Endpoint:** `GET /api/parent/trips/{tripId}/children/{childId}/progress`
```json
{
  "status": "success",
  "data": {
    "trip_id": 55, "child_id": 10, "child_name": "سند طه", "current_step": 2,
    "steps": [
      { "key": "started", "title": "انطلقت", "completed": true, "timestamp": "2026-08-30 07:15:00" },
      { "key": "picked_up", "title": "في الطريق", "completed": true, "timestamp": "2026-08-30 07:20:00" },
      { "key": "arrived_school", "title": "الاستلام", "completed": false, "timestamp": null },
      { "key": "completed", "title": "وصلت للمدرسة", "completed": false, "timestamp": null }
    ]
  }
}
```

### 10.هـ) التتبع الجغرافي اللحظي لكل الرحلات النشطة دفعة واحدة (للخريطة)
**Endpoint:** `GET /api/parent/trips/active/tracking`
```json
{
  "status": "success",
  "data": [
    { "trip_id": 55, "driver_location": { "lat": 32.887, "lng": 13.191 }, "destination": { "lat": 32.89, "lng": 13.18 } }
  ]
}
```
⚠️ نفس ملاحظة تعدد المدارس تنطبق هنا أيضاً: `destination` مبني من أول اشتراك فقط ولم يُصحَّح بعد (لم يُطلب صراحة، أخبرني لو تريد نفس المعالجة هنا).

### 10.و) تتبع رحلة واحدة
**Endpoint:** `GET /api/parent/trips/{tripId}/track`
```json
{
  "status": "success",
  "data": {
    "trip_id": 55, "status": "in_progress",
    "driver_location": { "lat": 32.887, "lng": 13.191 },
    "destination": { "name": "مدرسة أ", "type": "school", "lat": 32.89, "lng": 13.18 },
    "last_updated": "2026-08-30T07:20:00+00:00", "is_online": true
  }
}
```
نفس الملاحظة: مبني من أول اشتراك للسائق (وليس بالضرورة أطفال هذا الولي تحديداً) — أضعف من بقية الـ endpoints دقة.

---

## 11) حالة الرحلة نفسها (للسائق) — شاشة Live

**Endpoint:** `GET /api/v1/driver/trips/{tripId}/live`
المصدر: [DriverTripController::live](app/Http/Controllers/Api/Trip/DriverTripController.php:373)

```json
{
  "status": "success",
  "data": {
    "trip_status": "in_progress",
    "current_child": {
      "trip_child_id": 102, "child_id": 11, "name": "مروة طه", "school": "مدرسة ب",
      "pickup_address": "حي الأندلس", "latitude": 32.887, "longitude": 13.191,
      "status": "pending", "pickup_status": "pending", "dropoff_status": "pending", "eta": "07:30"
    },
    "progress": { "total": 3, "completed": 1, "remaining": 2 }
  }
}
```
`current_child` يكون `null` إذا انتهت كل المحطات (كل الأطفال بحالة نهائية).

---

## 12) إنهاء الرحلة (Complete)

**Endpoint:** `POST /api/v1/driver/trips/{tripId}/complete`
المصدر: [DriverTripController::complete](app/Http/Controllers/Api/Trip/DriverTripController.php:1049) + [TripLifecycleService::completeTrip](app/Services/Trip/TripLifecycleService.php:378)

### Input
لا حقول مطلوبة في الـ body.

### Output — نجاح (200)
```json
{
  "status": "success",
  "message": "تم إنهاء الرحلة وتصفير سجلات الكاش المؤقتة بنجاح.",
  "summary": { "children": 3, "picked_up": 3, "dropped_off": 3, "absent": 0, "duration": 48, "distance": 19.3 }
}
```
`duration`/`distance` قيمتان ثابتتان حالياً في الكود (48 دقيقة / 19.3 كم) — ليستا محسوبتين فعلياً من بيانات GPS، لا تعتمد عليهما لعرض إحصاءات دقيقة للمستخدم.

### Output — فشل: يوجد أطفال لم تُحسم حالتهم (422) ⚠️ الحالة الأهم للاختبار
```json
{
  "status": "error",
  "error_code": "FORGOTTEN_CHILDREN_ON_BUS",
  "message": "لا يمكن إنهاء الرحلة: يوجد أطفال لم تُحسم حالتهم بعد (سند طه، مروة طه). يجب تأكيد نزولهم أو تسجيل غيابهم أولاً."
}
```
يُرمى إذا كان أي `trip_stops.status` لا يزال `pending` أو `boarded` (أي غير نهائي). لازم الفرونت يمنع زر "إنهاء الرحلة" أو يعرض هذه الرسالة بوضوح، ويوجّه السائق لإكمال حالة كل طفل أولاً (pickup/dropoff/absent/skip).

### Output — الرحلة مغلقة بالفعل (200، ليست خطأ)
```json
{
  "status": "success",
  "message": "الرحلة مغلقة بالفعل.",
  "summary": { "children": 0, "picked_up": 0, "dropped_off": 0, "absent": 0, "duration": 48, "distance": 19.3 }
}
```
ملاحظة: رسالة `already_completed` من `completeTrip()` لا تُستخدم فعلياً في نص `message` المُرجَع للفرونت (الكنترولر يستخدم `$result['message']` فتظهر "الرحلة مغلقة بالفعل." بشكل صحيح)، لكن `summary` هنا مبني من استعلامات لاحقة قد ترجع صفراً على غير المتوقع — يفضّل الفرونت يتعامل مع `status: success` + هذه الرسالة كحالة "لا شيء للفعل" بدل الاعتماد على `summary`.
