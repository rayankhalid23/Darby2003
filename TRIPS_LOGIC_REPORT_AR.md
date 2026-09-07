# تقرير منطق الرحلات — الباك إند وقاعدة البيانات

> نطاق التقرير: دورة حياة الرحلة كاملة (توليد ← بدء ← صعود/نزول ← استثناءات ← إنهاء) لتطبيق السائق، تطبيق ولي الأمر، لوحة الإدارة، والنظام المؤتمت.
> المصادر: `app/Services/Trip/*`، `app/Http/Controllers/Api/Trip/*`، `app/Http/Controllers/Api/Admin/*`، `routes/*`، `database/migrations/*`.

---

## الجزء الأول: جداول قاعدة البيانات المستخدمة

| الجدول | الدور | ملاحظات مهمة |
|---|---|---|
| `routes` | المسار الهيكلي المرجعي (Master Route) لكل سائق/وردية | `route_type`: Morning/Afternoon، `shift_slot`: morning_go / morning_return / afternoon_go / afternoon_return |
| `route_stops` | محطات المسار المرجعي الثابتة | `stop_type`: home / school |
| `trips` | نسخة الرحلة اليومية التشغيلية | **enum الحالة: `pending, in_progress, completed, suspended_breakdown` فقط** |
| `trip_stops` | محطات الرحلة اليومية — **مصدر الحقيقة لحالة كل طفل** | 9 حالات: pending, absent_pre, absent_late, boarded, dropped_off_school, delivered_home, skipped_unresponsive, dropoff_failed, direct_parent_handling |
| `trip_events` | سجل الأحداث الخام (صعود/نزول/غياب/تخطي) بموقع ووقت | `location_lat/lng`، `scanned_at`، `trip_cost` |
| `trip_tracking` | نقاط التتبع الجغرافي | `latitude, longitude, speed, accuracy, recorded_at` — **لا يوجد عمود `heading`** |
| `trip_student_attendance` | إحصائيات الحضور/الغياب | **لا يُكتب فيه أي سطر من كود التشغيل — البذور (Seeders) فقط** |
| `absence_logs` | غياب الطفل المسبق | `absence_type`: pickup / dropoff / both |
| `driver_absences` + `driver_absence_trips` | اعتذار السائق (يوم كامل أو رحلات محددة) | |
| `trip_manual_confirmations` | طلب التأكيد اليدوي من ولي الأمر | |
| `trip_breakdown_dispatches` | بلاغ عطل المركبة وطلب سائق بديل | |
| `platform_finance` + `platform_finance_trip_settlements` | تسوية حصة كل رحلة من الأمانة | قيد فريد يمنع الصرف المزدوج |

---

## الجزء الثاني: السيناريو بالتسلسل خطوة بخطوة

### أ) التوليد الآلي للرحلة اليومية (النظام)

1. `Schedule::command('trips:generate-daily')->everyMinute()` — يعمل كل دقيقة (`routes/console.php`).
2. `DailyTripGenerationService::generateDueTrips()` يجلب كل `routes` بحالة `Active` ولها `shift_slot` و `start_time`.
3. لكل مسار: هل توجد رحلة اليوم بنفس `route_id + trip_date`؟ إن وُجدت ← تخطٍ (العملية idempotent).
4. فحص التغطية `routeHasCoverageOn()`: هل يوجد اشتراك فعّال تغطي فترته تاريخ اليوم (`request_children.start_date/end_date`)؟ إن لا ← تخطٍ.
5. فحص نافذة الزمن: يُولَّد فقط في الفترة **T-30 دقيقة ← T** من `start_time`.
6. `generateForRoute()`: فحص غياب السائق (يوم كامل، أو غياب مربوط بهذا المسار تحديداً) ← إن غائب: `return null`.
7. `INSERT INTO trips` بحالة `pending` و `scheduled_start_time = route.start_time`.
8. `buildTripStops()`:
   - يحدد اتجاه الوردية عبر `DriverSeatSlot::isGoSlot()`.
   - يستبعد الأطفال من `absence_logs` حسب نوع الغياب المطابق للاتجاه (ذهاب ← pickup/both، عودة ← dropoff/both).
   - يطبّق `location_change_requests` المعتمدة لتاريخ اليوم (تغيير إحداثيات المحطة).
   - `INSERT INTO trip_stops`: الحاضرون بـ `sequence_order = 1..n` وحالة `pending`، والغائبون بـ `sequence_order = 0` وحالة `absent_pre`.
9. `notifyDriverAndParents()`: إشعار `trip_ready` للسائق + `trip_upcoming` لأولياء الأمور.
10. مسار بديل: عند فتح السائق شاشة `GET /trips/today` يُستدعى `generateForRoute()` لحظياً لكل مسار (سحب عند الطلب).
11. مسار إداري: `POST /api/admin/trips/generate-daily` ← `generateDueTrips()` يدوياً.

### ب) بدء الرحلة (السائق)

1. `POST /api/v1/driver/trips/{tripId}/start` مع `latitude/longitude`.
2. التحقق من ملكية الرحلة (`driver_id`).
3. فحص غياب السائق لهذا اليوم/هذه الرحلة ← 422 `DRIVER_ABSENT`.
4. رفض الرحلات `completed/cancelled` ← 409 `TRIP_NOT_STARTABLE`.
5. إن كانت `in_progress` ← إرجاع نجاح idempotent.
6. فحص وجود محطات فعّالة (`stop_type=home` و `sequence_order > 0`) ← إن صفر: 422 `NO_ACTIVE_CHILDREN`.
7. `UPDATE trips SET status='in_progress', actual_start_time=now(), started_at=now(), start_lat/lng`.
8. `TripLifecycleService::computeLiveEtas()`: يحسب الوصلة الأولى (Lead-In) من موقع السائق الحي إلى أول محطة، ثم ETA تراكمي لكل المحطات عبر `GeoEstimator::haversineKm`، ويحفظه في `trip_stops.eta / eta_minutes`.

### ج) التتبع الجغرافي (النظام)

1. `POST /trips/{tripId}/location` بـ `latitude, longitude, speed, heading`.
2. فحص ملكية الرحلة (يمنع حقن إحداثيات في رحلة سائق آخر).
3. `TripTrackingService::updateDriverLocation()`:
   - **الاستشعار التلقائي للبدء**: إن كانت `status = pending` و `speed > 10` ← `handleAutoStart()` يضبط `in_progress` ويُرجِع `started_at` لأول نقطة تتبع، ثم يرسل `trip_started` للأهالي الذين لم يُسجَّل لأطفالهم حدث بعد.
   - `UPDATE drivers SET current_lat/lng`.
   - **التخزين الذكي**: يُكتب سطر في `trip_tracking` فقط إذا تحرّك السائق أكثر من **15 متراً** عن آخر نقطة مخزَّنة في الكاش.
   - يُحدَّث الكاش `driver_last_loc_{driverId}` في كل نبضة (ليعكس `is_online`) بصلاحية 6 ساعات.
   - مزامنة اختيارية مع Firestore: `trips_tracking/{tripId}` بحقول `driver_lat, driver_lng, heading, is_online`.

### د) الصعود والنزول (السائق)

المسار الموحّد `updateChildTripStatus()` يقف خلف كل من: `/pickup`، `/dropoff`، `/absent`، `/skip/{childId}`، `/verify-qr/{childId}`، `/children/{id}/status`.

1. فحص ملكية الرحلة + وجوب أن تكون `in_progress` (وإلا 409 `TRIP_NOT_STARTED` / `TRIP_ALREADY_COMPLETED`).
2. استنتاج الإجراء من اسم الميثود أو من حقل `action`.
3. فحص ملكية الاشتراك (منع IDOR) ← 404 `TRIP_CHILD_NOT_FOUND`.
4. **مسار QR**: مطابقة `qr_code_token` (400 `QR_MISMATCH`) + فحص نطاق موسّع **1000 م** (422 `OUT_OF_RANGE`).
5. **المسار اليدوي**: `latitude/longitude` إلزامية (422 `LOCATION_REQUIRED`) + `GeofenceService`: **100 م** للمنازل و **200 م** للمدارس.
6. **pickup**: `DB::transaction` + `lockForUpdate` على صف المحطة ← إن لم تكن `pending`: 409 `ALREADY_PROCESSED`. ثم `INSERT trip_events(picked_up)` + `UPDATE trip_stops SET status='boarded'`.
7. **dropoff**: نفس القفل + منع النزول قبل الصعود (409 `NOT_BOARDED_YET`) ← `INSERT trip_events(dropped_off)` + الحالة `dropped_off_school` (ذهاب) أو `delivered_home` (عودة).
8. **الاستثناءات** (`absent / skip / dropoff_failed / direct_parent_handling`): مصفوفة حالات مصدر مسموحة — absent/skip من `pending` فقط، dropoff_failed من `boarded` فقط.
9. بعد كل إجراء: إشعار FCM لولي الأمر + إرجاع `next_stop` عبر `resolveNextStop()` (أول محطة بـ `sequence_order` أكبر وبحالة غير نهائية).

### هـ) الاستثناءات

- **التأكيد اليدوي**: السائق ← `POST /driver/trip-manual-confirmations` ← إنشاء `trip_manual_confirmations` بحالة `pending` + إشعار ولي الأمر ← ولي الأمر ← `POST /parent/trip-manual-confirmations/{id}/respond` ← عند الموافقة يُحدَّث `trip_stops.status = target_status` ويُشعَر السائق.
- **اعتذار السائق**: `GET /trips/upcoming-for-absence` (قائمة الرحلات القادمة) ← `POST /trips/register-absence` ← إنشاء `driver_absences` بحالة `approved` فوراً + ربط `driver_absence_trips` + **فصل السائق فوراً** (`driver_id = null, status = pending`) + إشعار السائق وأولياء أمور أطفال تلك الرحلات تحديداً.
- **عطل المركبة**: `POST /trips/{tripId}/report-breakdown` ← `trips.status = suspended_breakdown` + حصر الأطفال العالقين (`pending`/`boarded`) ← إنشاء `trip_breakdown_dispatches` بمهلة 10 دقائق ← بحث عن سائقين بدلاء (سعة المقاعد + جدوى المسار + المناطق المجاورة) ← بث الطلب ← عند القبول: دمج الأطفال العالقين في مسار البديل + إشعار الأهالي. عند عدم توفر بديل: `triggerFallbackParentPickup()` (إشعار الأهالي بموقع الأبناء لاستلامهم).
- **الاستئناف**: `POST /trips/{tripId}/resume` (من `suspended_breakdown` إلى `in_progress` فقط).

### و) إنهاء الرحلة

1. `POST /trips/{tripId}/complete`.
2. **صمام أمان الأطفال** `assertNoForgottenChildren()`: أي محطة `home` بحالة `pending` أو `boarded` ← 422 `FORGOTTEN_CHILDREN_ON_BUS` مع أسماء الأطفال.
3. `UPDATE trips SET status='completed', completed_at=now()`.
4. إغلاق قسري لمحطات المدارس المتبقية ← `dropped_off_school`.
5. تنظيف الكاش (`driver_last_loc_`، `trip_waiting_`، `proximity_alert_sent_`، `automatic_arrival_logged_`).
6. إشعار `trip_completed` لأولياء الأمور.
7. **التسوية المالية**: فحص أن الرحلة نقلت طفلاً فعلياً ← تحديد الاشتراكات التي تغطي تاريخ الرحلة ← صرف **حصة رحلة واحدة** = المبلغ ÷ عدد الرحلات المتوقعة، مع قيد فريد `(platform_finance_id, trip_id)` كحارس ضد الصرف المزدوج + تحديث `MasterEscrowVault`.
8. `captureTripOnCompletion()` لحجوزات الرحلات اليومية + تسوية مستحقات السائق البديل.
9. الرد يتضمن ملخصاً: المدة الفعلية والمسافة المحسوبة من نقاط `trip_tracking`.

---

## الجزء الثالث: الأخطاء المنطقية والمنطق الناقص

### 🔴 حرج — يكسر سيناريو تشغيلياً كاملاً

**1. رحلات العودة: فحص الـ Geofence عند الصعود يقارن بموقع المنزل بدل المدرسة**
`DriverTripController.php:1064-1067` — فرع `pickup` يحدد الهدف دائماً `$homeStop->lat/lng` بلا أي تفريع على اتجاه الرحلة (على عكس فرع `dropoff` الذي يفرّع بـ `isGoSlot`). في رحلة العودة يكون الصعود **في المدرسة**، فالسائق الواقف عند المدرسة يبعد كيلومترات عن منزل الطفل ← **كل عملية صعود يدوية في رحلات العودة تُرفض بـ 422 `OUT_OF_RANGE`**. مسار الـ QR مصاب بنفس الخلل، لكن نطاق الـ 1000 م يخفّفه جزئياً فقط.

**2. `trips.status` لا يحتوي القيمة `cancelled` أصلاً، والإلغاء المالي لا يغيّر حالة الرحلة**
- الهجرة `2026_08_06_000006_update_trips_status_enum.php`: القيم المسموحة `pending, in_progress, completed, suspended_breakdown`.
- `FinancialLedgerService::processTripCancellation()` ينفّذ كل الحركات المالية (استرجاع/غرامة/عمولة) لكنه **لا يكتب `status = 'cancelled'` إطلاقاً**.
- النتيجة: حارس الـ Idempotency في `FinancialLedgerController.php:428` (`if ($trip->status === 'cancelled')`) **لا يمكن أن يتحقق أبداً**، والرحلة «الملغاة» تبقى `pending` فيستطيع السائق تشغيلها وإنهاءها وقبض حصتها.
- كما أن نحو 10 مواضع تفلتر على `'cancelled'` (`DriverTripController:111,446`، `TripLifecycleService:304` …) وهي فروع ميتة.
- المتطلب الإداري «حالة ملغاة» **غير منفَّذ**.

**3. جدول `trip_student_attendance` لا يُكتب فيه شيء أثناء التشغيل**
لا يوجد أي `create/insert` عليه خارج الـ Seeders، بينما يُقرأ منه في:
- `FinancialService.php:141` و `FinancialLedgerService.php:585` (حساب أيام الغياب لتسوية الاشتراك).
- `ReportService.php:256` (تقارير الحضور).

النتيجة: **متطلب «تحديث إحصائيات الحضور والغياب» غير منفَّذ**، وتقارير الحضور والتسويات النهائية تحسب الغياب = 0 دائماً.

**4. الغياب المسجَّل بعد توليد الرحلة لا يؤثر على الرحلة إطلاقاً**
`TripLifecycleService::setChildAbsence()` يكتب في `absence_logs` ثم يستدعي `recalculateActiveTripsForChild()` الذي يستدعي `calculateInitialRoute()` — وهذه الدالة **تُرجع مصفوفة OSRM ولا تحفظ أي شيء في `trip_stops`**. وبما أن `buildTripStops()` لا يعمل إلا مرة واحدة (`if (TripStop::where(...)->doesntExist())`)، يبقى الطفل `pending` ويذهب السائق إلى منزله. والعكس صحيح: `removeChildAbsence()` لا يعيد الطفل المستبعد (`absent_pre`، `sequence_order = 0`) إلى المسار.

**5. `calculateInitialRoute()` تقارن `trip_type` بحروف صغيرة بينما القيمة المخزَّنة `Morning`**
`TripLifecycleService.php:180`: `if ($trip->trip_type === 'morning')` — القيمة الفعلية `'Morning'` (يفرضها `startTrip` و `generateForRoute`). **الشرط لا يتحقق أبداً** ← كل حسابات المسار تستخدم `dropoff_lat/lng` حتى في رحلة الذهاب.

**6. إشعار «بدء الرحلة» لا يُرسل عند البدء اليدوي**
`trip_started` يُرسل حصرياً من `TripTrackingService::handleAutoStart()` (البدء التلقائي بالسرعة > 10). أما المسار الفعلي الذي يستخدمه التطبيق — `DriverTripController::start()` و `TripLifecycleService::startTrip()` — فلا يرسل أي إشعار. **متطلب «إرسال إشعار عند بدء الرحلة» ناقص في المسار الرئيسي.**

### 🟠 مرتفع — منطق ناقص أو متناقض

**7. وقت الانتظار قبل تخطي المحطة غير مطبَّق إطلاقاً**
`TripStopService::startWaitingCounter()` و `TripStopService::skipChild()` **كلاهما كود ميت** — لا يوجد أي Route أو استدعاء لهما في المشروع كله. مسار التخطي الفعلي (`/trips/{tripId}/skip/{childId}` ← `updateChildTripStatus`) لا يفحص أي عدّاد انتظار ولا يقرأ `driver_waiting_minutes`. النتيجة: السائق يستطيع تخطي أي طفل **فوراً** دون انتظار، بينما ينص المتطلب على «بعد انتهاء وقت الانتظار المحدد».

**8. محطات المدارس لا تتحدث حالتها أثناء الرحلة**
`dropoff` يحدّث دائماً `$homeStop` (محطة المنزل) ويكتب فيها `dropped_off_school`. محطات `stop_type = school` تبقى `pending` طوال الرحلة، فيعيدها `resolveNextStop()` كـ«المحطة التالية» بلا نهاية، ولا تُغلق إلا قسرياً داخل `completeTrip()`. **متطلب «معرفة حالة كل محطة أثناء تنفيذ الرحلة» منفَّذ جزئياً (محطات المنازل فقط).**

**9. التأكيد اليدوي متاح للرحلات السابقة فقط — لا يغطي الحالة المطلوبة**
`TripManualConfirmationService::getIncompleteTrips()` يفلتر بـ `where('trip_date', '<', today)`. المتطلب هو «إرسال طلب تأكيد يدوي لولي الأمر **عند تعذر استخدام QR**» أي أثناء الرحلة الجارية — وهذا **غير ممكن** حالياً؛ الميزة تعمل كتصحيح لاحق (اليوم التالي) فقط.

**10. مساران متناقضان للتأكيد اليدوي، كلٌّ منهما يكتب نصف الحقيقة**
- `TripStopService::confirmManualPickup()` (خلف `POST /parent/trips/{tripId}/children/{childId}/manual-pickup`) يُنشئ `trip_events(picked_up)` لكنه **لا يحدّث `trip_stops.status`** ← المحطة تبقى `pending`، الشاشة الحية للسائق لا تتغير، وصمام الأمان يمنع إنهاء الرحلة.
- `TripManualConfirmationService::respondToConfirmation()` يفعل العكس: يحدّث `trip_stops` **دون كتابة أي `trip_events`** ← الحدث غائب عن الـ timeline وعن أي حساب مبني على الأحداث.

**11. رفض ولي الأمر للتأكيد اليدوي يقفل الرحلة نهائياً**
عند `denied` لا يتغير شيء في `trip_stops` — تبقى المحطة `pending`/`boarded` إلى الأبد، فيرفض `assertNoForgottenChildren()` إنهاء الرحلة دائماً، ولا يوجد أي مسار إداري لتجاوز الحالة (Admin Override) أو لإعادة الطلب.

**12. البدء التلقائي (Auto-Start) يتجاوز كل فحوصات البدء اليدوي**
`handleAutoStart()` يضبط `in_progress` مباشرة دون فحص: غياب السائق، وجود أطفال فعليين (`NO_ACTIVE_CHILDREN`)، أو مطابقة `trip_date`. كما أن `updateDriverLocation()` تقبل نقاط تتبع لرحلة `completed` وتكتبها في `trip_tracking` بلا حارس.

**13. `TripLifecycleService::startTrip()` ينشئ رحلة موازية مكرّرة**
المسار `POST /api/v1/driver/trips/start` (بلا `tripId`) لا يبحث عن رحلة اليوم المولَّدة، بل **ينشئ `Trip` جديدة دائماً** بـ `route_id = $route?->id ?? 0` (قيمة صفر وهمية)، ويختار المسار بـ `first()` على `route_type` وحده — فإن كان للسائق مساران في نفس الفترة (`morning_go` و `morning_return`) يُختار أحدهما عشوائياً. النتيجة: رحلتان لنفس اليوم، الثانية بلا `trip_stops`، ومسارات تسوية مالية مضطربة.

**14. `checkProximityAndArrival()` دالة فارغة (Stub)**
`TripTrackingService.php:152-157` تُرجع `['status' => 'tracking']` مع تعليق «تم الاحتفاظ بنفس المنطق الذكي». مفاتيح الكاش `proximity_alert_sent_` و `automatic_arrival_logged_` تُنظَّف في `completeTrip()` لكن **لا يكتبها أحد**. أي: **لا يوجد تنبيه اقتراب الحافلة ولا استشعار وصول تلقائي**.

**15. تتبع ولي الأمر لا يُرجع السرعة ولا الاتجاه ولا المحطات المتبقية**
`ParentTripService::getLiveTracking()` يُرجع `driver_location` + `destination` + `children` فقط:
- «عرض سرعة الحافلة واتجاه حركتها» — `speed` موجودة في `trip_tracking` لكنها غير مُعادة، و **`heading` لا يوجد له عمود أصلاً في `trip_tracking`** (يُمرَّر إلى Firestore فقط ثم يُفقد).
- «عرض خط سير الرحلة والمحطات المتبقية» — لا توجد مصفوفة `stops` في الرد إطلاقاً.

**16. ولي الأمر لا يرى موقع صعود/نزول طفله**
المتطلب: «معرفة وقت **وموقع** صعود الطفل / وقت **وموقع** نزوله». الإحداثيات محفوظة فعلاً في `trip_events.location_lat/lng`، لكن `getChildTripProgress()` و `getTripTimeline()` و `getChildTripStatus()` **تُرجع الوقت فقط بلا أي إحداثيات**.

**17. الـ Timeline يُسقط أحداث الاستثناءات ويُثبّت نص «وصل للمدرسة»**
`getTripTimeline()` يعالج `picked_up` و `dropped_off` فقط — أحداث `absent`، `skipped`، `dropoff_failed`، `direct_parent_handling` **تختفي تماماً من سجل ولي الأمر**. كما أن عنوان النزول ثابت `"وصل الطفل للمدرسة"` حتى في رحلة العودة إلى المنزل.

### 🟡 متوسط — دقة البيانات والصلاحيات

**18. لوحة الإدارة: `activeTrips()` تعرض بيانات ملفّقة**
`DashboardController.php:163-248`:
- إحداثيات عشوائية عند غياب التتبع: `32.9 + (rand(-50,50)/1000)`.
- سرعة ثابتة مزيفة `'45 كم/س'` وحالة ثابتة `'في الطريق للاستلام'` لكل الرحلات.
- `$trip->route?->name` — الحقل الفعلي اسمه `route_name`، فالنتيجة `null` دائماً ويُستبدل بنص ثابت `'مدرسة طرابلس المركزية...'`.
- الأطفال المعروضون مأخوذون من **كل اشتراكات السائق** لا من `trip_stops` الخاصة بالرحلة، ومحدودون بأول اسمين.
- `limit(20)` ثابت بلا Pagination، و **لا يوجد أي فلتر بالمدينة/المنطقة** رغم متطلب «متابعة حالة الرحلات في نطاق المدينة».
- عند غياب رحلات حية يُرجع `getDemoTrips()` بيانات وهمية.

**19. لا توجد شاشة إدارية لبلاغات الأعطال**
لا يوجد أي Endpoint في `routes/Admin.php` يقرأ `trip_breakdown_dispatches` أو الرحلات `suspended_breakdown`. المتطلبات: «استقبال بلاغات أعطال المركبات / معرفة موقع المركبة وقت العطل / متابعة الرحلة المتوقفة / توفير مركبة بديلة» — **كلها غير منفَّذة في لوحة الإدارة** (منفَّذة في تطبيق السائق فقط).

**20. ثغرة IDOR في تفاصيل مهمة الإنقاذ**
`DriverTripController::getBreakdownDispatchDetails()` (سطر 881) ينفّذ `findOrFail($dispatchId)` **بلا أي فحص** أن السائق ضمن `candidate_driver_ids`. أي سائق مسجَّل يستطيع قراءة تفاصيل أي بلاغ عطل بما فيه أسماء الأطفال وعناوين منازلهم ومدارسهم.

**21. لا يوجد فحص Geofence على إجراءات الاستثناء**
`absent`، `skip`، `dropoff_failed`، `direct_parent_handling` تُنفَّذ بلا أي إحداثيات ولا فحص موقع — ويُكتب في `trip_events.location_lat/lng` **موقع الاشتراك الثابت وليس موقع السائق الفعلي**، أي أن التوثيق الجغرافي لهذه الأحداث غير حقيقي.

**22. `resumeTrip` لا يعالج آثار العطل**
يعيد الحالة إلى `in_progress` فقط، دون: إغلاق `trip_breakdown_dispatches` المفتوح، أو إلغاء البث للسائقين المرشحين، أو إشعار أولياء الأمور بأن الرحلة استؤنفت. وإن كان بديل قد قَبِل بالفعل، تصبح رحلتان جاريتين لنفس الأطفال في آن واحد.

**23. ملخص الإنهاء وسجل الرحلات غير دقيقين**
- `complete()`: `children = ActiveSubscription::where('route_id', ...)->count()` يشمل الاشتراكات الملغاة ويتجاهل الغائبين.
- `history()`: يفلتر `status = 'completed'` فقط، فالرحلات المتوقفة بعطل أو غير المكتملة **لا تظهر في سجل السائق أبداً**.

**24. تناقض معماري في `children.parent_id`**
- الهجرة `2026_07_28_195307` تربط `children.parent_id` بـ **`parents.id`**.
- علاقة `Child::parent()` تشير إلى **`User::class`**.
- `active_subscriptions.parent_id` مربوط بـ **`users.id`**.

حالياً `ParentModel` هو Proxy فوق جدول `users` (لذا الأرقام متطابقة والنظام يعمل)، لكن أي فصل مستقبلي لجدول `parents` سيكسر فحوصات الملكية في `ParentChildController::checkChildBelongsToParent()` و `ParentTripService::resolveParentIds()` دفعةً واحدة.

**25. حالات غير موجودة في الـ enum مستخدمة في الاستعلامات**
`FinancialLedgerService` يستعلم عن `$trips->where('status', 'holiday')` — قيمة غير موجودة في enum جدول `trips` ← النتيجة صفر دائماً.

---

## أولويات المعالجة المقترحة

| # | الإصلاح | الأثر |
|---|---|---|
| 1 | تفريع الـ Geofence عند `pickup` حسب اتجاه الرحلة (بند 1) | رحلات العودة معطّلة كلياً |
| 2 | إضافة `cancelled` للـ enum + كتابتها في `processTripCancellation` (بند 2) | رحلات ملغاة تُنفَّذ وتُصرف أموالها |
| 3 | كتابة `trip_student_attendance` عند كل إجراء على المحطة (بند 3) | التسويات والتقارير المالية |
| 4 | إعادة بناء/مزامنة `trip_stops` عند تسجيل أو إلغاء الغياب (بند 4) | الغياب المتأخر بلا أثر |
| 5 | تصحيح مقارنة `trip_type` (بند 5) وإرسال `trip_started` في البدء اليدوي (بند 6) | مسار خاطئ + إشعار مفقود |
| 6 | ربط عدّاد الانتظار بمسار التخطي (بند 7) | شرط تعاقدي مع أولياء الأمور |
| 7 | توحيد مسارَي التأكيد اليدوي وإتاحته أثناء الرحلة + معالجة الرفض (بنود 9–11) | رحلات لا يمكن إغلاقها |
| 8 | إضافة `heading` إلى `trip_tracking` وإرجاع السرعة/الاتجاه/المحطات المتبقية لولي الأمر (بند 15) | متطلب صريح غير منفَّذ |
| 9 | بناء شاشات الإدارة للأعطال + تنظيف بيانات `activeTrips` الملفّقة (بندا 18–19) | متطلبات إدارية غائبة |
| 10 | إغلاق ثغرة IDOR في `getBreakdownDispatchDetails` (بند 20) | تسريب بيانات أطفال |
