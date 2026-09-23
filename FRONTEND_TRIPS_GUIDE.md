# 🗺️ دليل الربط والتكامل لرحلات السائقين وأولياء الأمور (Trips Frontend Integration Guide)

يقدم هذا الدليل المرجعي لمهندسي تطبيقات الجوال (Flutter / React Native) والويب التوثيق الكامل لجميع واجهات برمجة الرحلات (APIs)، مع كافة بيانات الإدخال (Request) والمخرجات (Response) للسائق وأولياء الأمور.

---

## 🔐 التوثيق القياسي (Authentication)
جميع المسارات تطلب توكن Sanctum في الهيدر:
`Authorization: Bearer <SANCTUM_TOKEN>`
`Accept: application/json`

---

## 🚘 أولاً: واجهات السائق (Driver Trip Endpoints)

### 1. بدء رحلة جديدة (Start Trip)
- **Endpoint**: `POST /api/v1/driver/trips/start`
- **Request Body**:
  ```json
  {
    "trip_type": "morning" // أو "afternoon" أو "صباحية"
  }
  ```
- **Response Success (200 OK)**:
  ```json
  {
    "status": "success",
    "message": "تم بدء الرحلة بنجاح",
    "data": {
      "id": 101,
      "driver_id": 12,
      "trip_type": "Morning",
      "status": "started",
      "scheduled_start_time": "2026-07-26T07:00:00.000000Z",
      "actual_start_time": "2026-07-26T07:05:12.000000Z"
    }
  }
  ```

---

### 2. تحديث وتتبع موقع السائق GPS (Update Location)
- **Endpoint**: `POST /api/v1/driver/trips/{tripId}/location`
- **Request Body**:
  ```json
  {
    "latitude": 32.8872,
    "longitude": 13.1913,
    "speed": 45.5 // اختياري
  }
  ```
- **Response Success (200 OK)**:
  ```json
  {
    "status": "success",
    "message": "تم تحديث موقع السائق بنجاح",
    "data": {
      "trip_id": 101,
      "current_lat": 32.8872,
      "current_lng": 13.1913,
      "speed": 45.5
    }
  }
  ```

---

### 3. تأكيد صعود/نزول الطفل يدوياً (زر "تم الصعود" / "تم النزول")
- **Endpoint**: `POST /api/v1/driver/trips/{tripId}/pickup` (صعود) أو `POST /api/v1/driver/trips/{tripId}/dropoff` (نزول)
- **Request Body**:
  ```json
  {
    "trip_child_id": 101,
    "latitude": 32.8872,
    "longitude": 13.1913
  }
  ```
  `latitude`/`longitude` إلزاميان — يُرفض الطلب إن كان موقع السائق الفعلي بعيداً عن محطة الطفل (≤100م للمنزل، ≤200م للمدرسة).
- **Response Success (200 OK)**:
  ```json
  {
    "status": "success",
    "message": "تم تأكيد الصعود وإرسال الإشعار لولي الأمر."
  }
  ```

---

### 4. تخطي محطة طفل غائب/متأخر (Skip Station)
- **Endpoint**: `POST /api/v1/driver/trips/{tripId}/skip/{childId}`
- **Request Body**: *(بدون Body)*
- **Response Success (200 OK)**:
  ```json
  {
    "status": "success",
    "message": "تم تخطي المحطة بنجاح وإعادة حساب المسار"
  }
  ```

---

### 5. تسجيل غياب السائق (Register Driver Absence)
- **Endpoint**: `POST /api/v1/driver/trips/register-absence`
- **Request Body**:
  ```json
  {
    "dates": ["2026-07-28", "2026-07-29"]
  }
  ```
- **Response Success (200 OK)**:
  ```json
  {
    "status": "success",
    "message": "تم تسجيل أيام غيابك وإشعار أولياء الأمور"
  }
  ```

---

### 6. إنهاء وإغلاق الرحلة (Complete Trip)
- **Endpoint**: `POST /api/v1/driver/trips/{tripId}/complete`
- **Request Body**: *(بدون Body)*
- **Response Success (200 OK)**:
  ```json
  {
    "status": "success",
    "message": "تم إنهاء الرحلة وإكمالها بنجاح"
  }
  ```

---

## 👨‍👩‍👧‍👦 ثانياً: واجهات ولي الأمر (Parent Trip Endpoints)

### 1. جلب الرحلات النشطة حالياً للأبناء (Get Active Trips)
- **Endpoint**: `GET /api/parent/trips/active`
- **Response Success (200 OK)**:
  ```json
  {
    "status": "success",
    "data": [
      {
        "trip_id": 101,
        "trip_type": "Morning",
        "driver_name": "عبد الله السائق",
        "vehicle_info": "تويوتا هايس - ط ن 4421",
        "children": [
          {
            "child_id": 5,
            "child_name": "سارة محمد",
            "status": "picked_up" // "pending", "picked_up", "dropped_off", "skipped"
          }
        ]
      }
    ]
  }
  ```

---

### 2. التتبع المباشر للرحلة والموقع الحركي (Get Live Tracking)
- **Endpoint**: `GET /api/parent/trips/{tripId}/track`
- **Response Success (200 OK)**:
  ```json
  {
    "status": "success",
    "data": {
      "trip_id": 101,
      "driver": {
        "name": "عبد الله السائق",
        "phone": "0912345678",
        "current_location": {
          "lat": 32.8872,
          "lng": 13.1913
        }
      },
      "eta_minutes": 8,
      "trip_status": "started"
    }
  }
  ```

---

### 3. جدولة غياب طفل (Set Child Absence)
- **Endpoint**: `POST /api/parent/children/{childId}/absence`
- **Request Body**:
  ```json
  {
    "dates": ["2026-07-27", "2026-07-28"]
  }
  ```
- **Response Success (200 OK)**:
  ```json
  {
    "status": "success",
    "message": "تم جدولة غياب الطفل بنجاح وتحديث المسارات الجارية إن وجدت"
  }
  ```

---

### 4. إلغاء غياب طفل مجدول (Cancel Child Absence)
- **Endpoint**: `DELETE /api/parent/children/{childId}/absence`
- **Request Body**:
  ```json
  {
    "dates": ["2026-07-27"]
  }
  ```
- **Response Success (200 OK)**:
  ```json
  {
    "status": "success",
    "message": "تم إلغاء الغياب وإعادة الطفل للمسارات التشغيلية"
  }
  ```

---

### 5. التأكيد اليدوي لصعود الطفل (Manual Pickup Confirmation)
- **Endpoint**: `POST /api/parent/trips/{tripId}/children/{childId}/manual-pickup`
- **Response Success (200 OK)**:
  ```json
  {
    "status": "success",
    "message": "قمتِ بتأكيد ركوب طفلك يدوياً، تم إخطار السائق"
  }
  ```
