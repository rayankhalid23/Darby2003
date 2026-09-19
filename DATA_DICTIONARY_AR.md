# قاموس البيانات (Data Dictionary)

**تاريخ الإصدار:** 2026-09-19
**المصدر:** استعلام مباشر على قاعدة البيانات الفعلية (`school_transport_v2`, MySQL) + قراءة الموديلات/الخدمات المرتبطة. الأعمدة والأنواع والعلاقات هنا مطابقة لما هو موجود فعليًا في الـ DB وقت كتابة هذا الملف، وليست مُستنتجة من الـ migrations فقط (كان فيه أكثر من migration تعدّل نفس الجدول بمرور الوقت).

> **رمز الحالة لكل جدول:**
> 🟢 موجود في قاعدة البيانات كما هو موصوف تمامًا
> 🟡 موجود لكن بشكل مختلف عمّا هو متوقّع (تفاصيل تحت كل جدول)
> 🔴 غير موجود إطلاقًا في قاعدة البيانات

---

## 1. الفترات التشغيلية لمقاعد السائق

### 🟢 `driver_seat_slots` — Driver_Seat_Slot

يمثّل **"خانة" واحدة (سائق × فترة × تاريخ)** ويخزّن عدد المقاعد المحجوزة فيها فقط. الطاقة الكاملة (capacity) غير مخزّنة هنا — هي تُقرأ من `vehicles.capacity_manual` وقت الحساب. **صف غير موجود = صفر حجوزات** (الجدول لا يخزّن الفراغ، فقط الإشغال)، ويُحذف الصف تلقائيًا عند رجوع `booked` إلى 0 (تنظيف ذاتي، انظر [`DriverSeatSlot::decrementBooked`](app/Models/Driver/DriverSeatSlot.php:228)).

| العمود | النوع | Null | افتراضي | الوصف |
|---|---|---|---|---|
| `id` | bigint unsigned PK | ✗ | — | معرّف تلقائي |
| `driver_id` | bigint unsigned FK → `drivers.id` | ✗ | — | السائق صاحب الخانة |
| `slot` | enum(`morning_go`,`morning_return`,`afternoon_go`,`afternoon_return`) | ✗ | — | الفترة + الاتجاه (صباحي/مسائي × ذهاب/إياب) |
| `date` | date | ✗ | — | اليوم المحدد للخانة |
| `booked` | tinyint unsigned | ✓ | 0 | عدد المقاعد المحجوزة في هذه الخانة |
| `created_at` / `updated_at` | timestamp | ✓ | NULL | — |

**فهارس:** `UNIQUE(driver_id, slot, date)` — يضمن خانة واحدة فريدة لكل (سائق، فترة، تاريخ)، وهو ما يمنّع الحجز المزدوج.

**منطق العمل (وليس أعمدة، لكنه ضروري لفهم الجدول):**
- **المتاح لخانة واحدة** = إذا كان السائق غائب في `driver_absences` لذلك اليوم → 0، وإلا → `capacity - booked`.
- **القبول عبر فترة اشتراك كاملة** يعتمد على `minAvailableOverPeriod()`: أدنى قيمة متاحة عبر كل الأيام/الفترات المطلوبة، ويستثني الجمعة والسبت من الحساب.
- **`incrementBooked` / `decrementBooked`**: هما الوحيدان المصرّح لهما بتعديل `booked` (يُستدعيان عند قبول/إلغاء اشتراك على `active_subscriptions`).

---

## 2. الطلبات والاشتراكات (Requests & Subscriptions)

### 🟢 `requests` — Request (الطلب الأساسي)

الموديل: [`SubscriptionRequest`](app/Models/Shared/SubscriptionRequest.php) (اسم الكلاس مختلف عن اسم الجدول عمدًا). يمثّل طلب اشتراك وليّ أمر لسائق معيّن — بعد القبول يتفرّع منه واحد أو أكثر من `active_subscriptions` (واحد لكل طفل عبر `request_children`).

| العمود | النوع | Null | افتراضي | الوصف |
|---|---|---|---|---|
| `id` | bigint unsigned PK | ✗ | — | |
| `parent_id` | bigint unsigned FK → `users.id` | ✗ | — | وليّ الأمر مُقدّم الطلب |
| `driver_id` | bigint unsigned FK → `drivers.id` | ✗ | — | السائق المطلوب |
| `status` | enum(`pending`,`acquired`,`accepted`,`rejected`,`contract_offered`,`cancelled`) | ✗ | `pending` | حالة الطلب |
| `total_price` | decimal(10,2) | ✗ | 0.00 | السعر الإجمالي قبل الخصم |
| `discount_amount` | decimal(10,2) | ✗ | 0.00 | قيمة الخصم (حسب عدد الأطفال، انظر `pricing_settings`) |
| `total_amount_after_discount` | decimal(10,2) | ✗ | 0.00 | الصافي بعد الخصم |
| `platform_commission_amount` | decimal(10,2) | ✓ | NULL | قيمة عمولة المنصة |
| `driver_net_amount` | decimal(10,2) | ✓ | NULL | صافي حصة السائق بعد العمولة |
| `children_count` | int | ✗ | 1 | عدد الأطفال في الطلب |
| `pickup_time` / `dropoff_time` | time | ✓ | NULL | وقت الاستلام/التسليم |
| `max_waiting_time` | int | ✗ | 15 | أقصى وقت انتظار (دقائق) |
| `notes` | text | ✓ | NULL | ملاحظات وليّ الأمر |
| `subscription_type` | varchar(50) | ✓ | NULL | نوع الاشتراك (شهري/فصلي...) |
| `trip_direction` | varchar(50) | ✓ | NULL | اتجاه الرحلة |
| `start_date` / `end_date` | date | ✓ | NULL | فترة الاشتراك |
| `working_days_count` | smallint unsigned | ✓ | NULL | عدد أيام العمل بالفترة |
| `home_label` / `home_lat` / `home_lng` | varchar / decimal(10,8) / decimal(11,8) | ✓ | NULL | موقع المنزل (نسخة مستقلة وقت تقديم الطلب) |
| `home_address_id` | bigint unsigned FK → `addresses.id` | ✓ | NULL | مرجع لعنوان محفوظ |
| `pricing_setting_id` | bigint unsigned FK → `pricing_settings.id` | ✓ | NULL | **لقطة (snapshot)** من إعدادات التسعير وقت إنشاء الطلب |
| `price_per_km` | decimal(8,2) | ✓ | NULL | سعر الكم المُطبّق فعليًا (نسخة، وليس مرجع حي) |
| `vehicle_has_ac` | tinyint(1) | ✓ | NULL | هل المركبة مكيّفة (يؤثر على السعر) |
| `discount_percent` | decimal(5,2) | ✓ | NULL | نسبة الخصم المُطبّقة (نسخة) |
| `platform_commission_rate` | decimal(5,2) | ✓ | NULL | نسبة العمولة المُطبّقة (نسخة) |
| `rejection_reason` | text | ✓ | NULL | سبب الرفض إن وُجد |
| `responded_at` | timestamp | ✓ | NULL | لحظة قبول/رفض السائق للطلب |
| `created_at` / `updated_at` | timestamp | ✓ | NULL | |

**ملاحظة مهمة:** أعمدة مثل `price_per_km`, `discount_percent`, `platform_commission_rate` هي **لقطات (snapshots)** من `pricing_settings` وقت إنشاء الطلب، وليست مراجع حيّة — تغيير `pricing_settings` لاحقًا لا يغيّر طلبات قديمة. هذا مقصود لحفظ السعر التعاقدي وقت الاتفاق.

**فهارس:** `(parent_id, status)`, `(driver_id, status)` — للاستعلامات المتكررة "طلبات هذا الولي/السائق حسب الحالة".

---

### 🟢 `pricing_settings` — Pricing_Setting (إعدادات التسعير)

جدول إعدادات عام (غالبًا صف واحد أو قليل، تُديره لوحة الأدمن) يحدد قواعد التسعير والخصومات الافتراضية التي تُنسخ (snapshot) داخل `requests` عند إنشاء كل طلب.

| العمود | النوع | افتراضي | الوصف |
|---|---|---|---|
| `id` | bigint unsigned PK | — | |
| `discount_one_child` | decimal(5,2) | 0.00 | نسبة خصم طفل واحد (٪) |
| `discount_two_children` | decimal(5,2) | 10.00 | نسبة خصم طفلين (٪) |
| `discount_three_plus_children` | decimal(5,2) | 15.00 | نسبة خصم 3 أطفال فأكثر (٪) |
| `platform_commission_rate` | decimal(5,2) | 8.00 | نسبة عمولة المنصة (٪) |
| `price_per_km_ac` | decimal(8,2) | 2.50 | سعر الكم لمركبة مكيّفة |
| `price_per_km_non_ac` | decimal(8,2) | 2.00 | سعر الكم لمركبة غير مكيّفة |
| `location_change_fee` | decimal(8,2) | 5.00 | رسوم تغيير الموقع الافتراضية |
| `location_change_fee_under_2km` | decimal(8,2) | 5.00 | رسوم تغيير الموقع (< 2 كم) |
| `location_change_fee_2_to_6km` | decimal(8,2) | 10.00 | رسوم تغيير الموقع (2–6 كم) |
| `location_change_fee_6_to_10km` | decimal(8,2) | 15.00 | رسوم تغيير الموقع (6–10 كم) |
| `created_at` / `updated_at` | timestamp | NULL | |

لا يوجد أي مفتاح أجنبي وارد إليه ما عدا `requests.pricing_setting_id` (علاقة واحد-إلى-كثير من هذا الجدول نحو `requests`).

---

### 🟢 `request_children` — Request_Children (تفاصيل الأطفال داخل الطلب)

سطر واحد لكل (طلب × طفل) — يحمل التسعير والموقع المدرسي الخاص بذلك الطفل تحديدًا داخل الطلب، ولاحقًا يُربط بـ `active_subscriptions.request_child_id`.

| العمود | النوع | Null | افتراضي | الوصف |
|---|---|---|---|---|
| `id` | bigint unsigned PK | ✗ | — | |
| `request_id` | bigint unsigned FK → `requests.id` | ✗ | — | الطلب الأب |
| `child_id` | bigint unsigned FK → `children.id` | ✗ | — | الطفل |
| `school_id` | bigint unsigned FK → `schools.id` | ✓ | NULL | مدرسة الطفل |
| `timing` | varchar(50) | ✓ | NULL | التوقيت (صباحي/مسائي/الاثنين) |
| `distance_km` | decimal(8,2) | ✓ | NULL | المسافة الفعلية (كم) |
| `billable_distance_km` | decimal(8,2) | ✓ | NULL | المسافة المحتسبة للتسعير (قد تختلف عن الفعلية بحد أدنى/أقصى) |
| `school_label` / `school_lat` / `school_lng` | varchar / decimal(10,8) / decimal(11,8) | ✓ | NULL | موقع المدرسة (نسخة) |
| `price_per_child` | decimal(10,2) | ✗ | 0.00 | سعر هذا الطفل (بعد ضرب المسافة بسعر الكم) |
| `trip_price` | decimal(10,2) | ✗ | 0.00 | سعر الرحلة (قبل الخصم) |
| `trip_price_after_discount` | decimal(10,2) | ✓ | NULL | بعد الخصم |
| `daily_price` | decimal(10,2) | ✓ | NULL | السعر اليومي |
| `discount_amount` | decimal(10,2) | ✗ | 0.00 | قيمة الخصم لهذا الطفل |
| `total_amount_after_discount` | decimal(10,2) | ✗ | 0.00 | الصافي بعد الخصم |
| `driver_net_price` | decimal(10,2) | ✗ | 0.00 | صافي حصة السائق بعد خصم عمولة المنصة |
| `notes` | text | ✓ | NULL | |
| `created_at` / `updated_at` | timestamp | ✓ | NULL | |

**فهارس:** `UNIQUE(request_id, child_id)` — لا يمكن تكرار نفس الطفل في نفس الطلب.

---

### 🟢 `active_subscriptions` — Subscription (الاشتراكات النشطة)

**تنويه على التسمية:** رغم أن اسم الملف/الموديل قد يوحي بأنه "خطة اشتراك عامة"، الجدول فعليًا يمثّل **الاشتراك التنفيذي لطفل واحد ضمن خط سير سائق واحد** — أي أنه حلقة الوصل بين `request_children` (من هو الطفل وسعره) و`routes` (في أي خط سير هو ضمن رحلات السائق اليومية). لا يوجد جدول "خطط اشتراك" منفصل (باقات/أسعار عامة) — تلك المسؤولية موزّعة بين `requests` و`pricing_settings`.

| العمود | النوع | Null | افتراضي | الوصف |
|---|---|---|---|---|
| `id` | bigint unsigned PK | ✗ | — | |
| `subscription_request_id` | bigint unsigned FK → `requests.id` | ✗ | — | الطلب الأصلي الذي انبثق منه الاشتراك |
| `request_child_id` | bigint unsigned FK → `request_children.id` | ✓ | NULL | تفاصيل الطفل/السعر لهذا الاشتراك |
| `route_id` | bigint unsigned FK → `routes.id` | ✓ | NULL | خط السير المُسنَد له هذا الطفل |
| `pickup_lat` / `pickup_lng` / `pickup_label` | decimal / varchar | ✓ | NULL | نقطة الاستلام الفعلية |
| `dropoff_lat` / `dropoff_lng` / `dropoff_label` | decimal / varchar | ✓ | NULL | نقطة التسليم الفعلية |
| `pickup_time` / `dropoff_time` | time | ✓ | NULL | وقت الاستلام/التسليم المُجدوَل |
| `sort_order` | int | ✗ | 0 | ترتيب صعود الطفل في خط سير السائق |
| `status` | enum(`active`,`paused`,`completed`,`cancelled`,`suspended_unpaid`,`terminated`) | ✗ | `active` | حالة الاشتراك |
| `cancelled_at` | timestamp | ✓ | NULL | |
| `cancelled_by` | varchar(20) | ✓ | NULL | `parent` \| `driver` \| `admin` \| `system` |
| `cancellation_reason` | varchar(255) | ✓ | NULL | |
| `created_at` / `updated_at` | timestamp | ✓ | NULL | |

**فهارس:** `status` مفهرس ثلاث مرّات تحت أسماء مختلفة (`_driver_id_status_index`, `_parent_id_status_index`, `_child_id_status_index`) لكنها كلها **نفس العمود `status` فقط** — بقايا من migrations سابقة كانت تحتوي أعمدة `driver_id`/`parent_id`/`child_id` مباشرة ثم أُزيلت (انظر `2026_09_07_215107_drop_child_driver_parent_id_from_active_subscriptions_table.php` و`2026_09_07_183714_drop_dead_columns...`)، فبقيت الفهارس بأسمائها القديمة دون تنظيف. **الوصول لمعرّف السائق/الولي/الطفل الآن يمرّ عبر العلاقات**: `route.driver_id`، `subscription_request.parent_id`، `request_child.child_id`.

---

### 🟢 `routes` — Routes (خطوط السير / المسارات)

خط سير يومي متكرر لسائق واحد ضمن فترة محددة (`shift_slot`)، ويحوي نقاط توقف مرتّبة عبر `route_stops`.

| العمود | النوع | Null | افتراضي | الوصف |
|---|---|---|---|---|
| `id` | bigint unsigned PK | ✗ | — | |
| `subscription_request_id` | bigint unsigned FK → `requests.id` | ✓ | NULL | الطلب الذي أنشأ هذا المسار (المسار الأساسي "Master Route") |
| `driver_id` | bigint unsigned FK → `drivers.id` | ✗ | — | السائق |
| `vehicle_id` | bigint unsigned FK → `vehicles.id` | ✓ | NULL | المركبة المستخدمة |
| `route_name` | varchar(150) | ✗ | — | اسم وصفي |
| `route_type` | enum(`Morning`,`Afternoon`) | ✗ | — | نوع المسار |
| `shift_slot` | enum(`morning_go`,`morning_return`,`afternoon_go`,`afternoon_return`) | ✓ | NULL | الفترة والاتجاه الدقيق للمسار (يُطابق `driver_seat_slots.slot`) |
| `start_time` | time | ✓ | NULL | وقت انطلاق المسار |
| `optimized_points` | json | ✓ | NULL | نقاط المسار بعد تحسين الترتيب (خوارزمية التوجيه) |
| `total_distance` | decimal(8,2) | ✓ | NULL | إجمالي المسافة (كم) |
| `estimated_duration` | int | ✓ | NULL | المدة التقديرية بالدقائق |
| `status` | enum(`Active`,`Inactive`) | ✗ | `Active` | |
| `created_at` / `updated_at` | timestamp | ✓ | NULL | |

**فهارس:** `(driver_id, shift_slot, status)` — للبحث السريع عن مسار سائق نشط لفترة معينة.

---

### 🟢 `route_stops` — Route_Stop (محطات التوقف في المسار)

محطة واحدة (منزل طفل أو مدرسة) ضمن ترتيب مسار معيّن.

| العمود | النوع | Null | افتراضي | الوصف |
|---|---|---|---|---|
| `id` | bigint unsigned PK | ✗ | — | |
| `route_id` | bigint unsigned FK → `routes.id` | ✗ | — | المسار الأب |
| `stop_type` | enum(`home`,`school`) | ✗ | — | نوع المحطة |
| `child_id` | bigint unsigned FK → `children.id` | ✓ | NULL | مُعبّأ عند `stop_type = home` |
| `school_id` | bigint unsigned FK → `schools.id` | ✓ | NULL | مُعبّأ عند `stop_type = school` |
| `lat` / `lng` | decimal(10,8) / decimal(11,8) | ✓ | NULL | إحداثيات المحطة |
| `label` | varchar(255) | ✓ | NULL | تسمية نصية للمحطة |
| `sequence_order` | int unsigned | ✗ | 0 | ترتيب المحطة ضمن المسار |
| `created_at` / `updated_at` | timestamp | ✓ | NULL | |

**فهارس:** `(route_id, sequence_order)` — لسحب محطات مسار بترتيبها مباشرة.

---

## 3. المحفظة والمالية (Wallet & Finance)

### 🟡 `wallets` — Wallet (المحفظة الرقمية)

**ليست جدولاً مخصّصًا للمشروع** — هذا جدول حزمة [`bavix/laravel-wallet`](https://github.com/bavix/laravel-wallet) الجاهزة (polymorphic wallet). حاليًا trait `HasWallet` مُفعّل فقط على موديل [`Driver`](app/Models/Driver/Driver.php:19) — أي أن السائقين هم من يملكون صفوفًا في هذا الجدول عمليًا وقت كتابة هذا التقرير (وليّ الأمر/الأدمن لا يستعملون `wallets` حسب الكود الحالي، رصيدهم يُدار عبر منطق آخر في `FinancialLedgerService`/`WalletRechargeService`).

| العمود | النوع | Null | افتراضي | الوصف |
|---|---|---|---|---|
| `id` | bigint unsigned PK | ✗ | — | |
| `holder_type` | varchar(255) | ✗ | — | اسم كلاس المالك (polymorphic)، عمليًا `App\Models\Driver\Driver` فقط حاليًا |
| `holder_id` | bigint unsigned | ✗ | — | معرّف المالك |
| `name` | varchar(255) | ✗ | — | اسم المحفظة (افتراضيًا "Default Wallet") |
| `slug` | varchar(255) | ✗ | — | معرّف نصي فريد لكل (holder, slug) |
| `uuid` | char(36) | ✗ | — | UUID فريد عالميًا |
| `description` | varchar(255) | ✓ | NULL | |
| `meta` | json | ✓ | NULL | بيانات إضافية حرة |
| `balance` | decimal(64,0) | ✗ | 0 | **الرصيد بأصغر وحدة عملة (سنتات/فلوس)، وليس بوحدة العملة الكاملة** — يُقسَّم على `10^decimal_places` عند العرض |
| `decimal_places` | smallint unsigned | ✗ | 2 | عدد الخانات العشرية للعملة |
| `deleted_at` | timestamp | ✓ | NULL | Soft delete |

**⚠️ نقص مهم:** جداول الحزمة المكمّلة `wallet_transactions` و`wallet_transfers` **غير موجودة في قاعدة البيانات فعليًا** رغم أن الحزمة مثبّتة ووجود `wallets` — أي أن `balance` يتغيّر لكن **لا يوجد سجل حركات (transactions) خاص بالحزمة نفسها**. سجل الحركة الفعلي والمُعتمَد في هذا المشروع هو `financial_ledger` أدناه (نظام قيد مزدوج منفصل مبني يدويًا، وليس ما توفره الحزمة).

---

### 🟢 `financial_ledger` — Financial_Ledger (السجل المالي / القيود)

سجل **قيد مزدوج (double-entry)** مركزي لكل حركة مالية في المنصة (شحن محفظة، سحب، عمولة، تسوية شكوى...). يُنشأ حصريًا عبر [`FinancialLedgerService::recordLedgerEntry()`](app/Services/Shared/FinancialLedgerService.php:119).

| العمود | النوع | Null | افتراضي | الوصف |
|---|---|---|---|---|
| `id` | bigint unsigned PK | ✗ | — | |
| `transaction_id` | char(36) UNIQUE | ✗ | — | UUID للمعاملة |
| `reference_number` | varchar(255) | ✓ | NULL | مرجع خارجي (رقم إيصال، معرّف سحب...) |
| `source_account` | varchar(255) | ✗ | — | الحساب المصدر (نص حر، مثال: `pending_withdrawal_pool`, `payment_gateway_clearing`, `manual_admin_clearing`) |
| `destination_account` | varchar(255) | ✗ | — | الحساب الوجهة (مثال: `external_bank_payout`, `platform_revenue_pool`, محفظة سائق/ولي) |
| `amount` | bigint | ✗ | — | **بأصغر وحدة عملة (سنتات)، وليس بالدينار/الريال الكامل** — نفس منطق `wallets.balance` |
| `balance_before` / `balance_after` | bigint | ✗ | 0 | رصيد الحساب الوجهة قبل/بعد الحركة (سنتات) |
| `type` | varchar(255) | ✗ | — | نوع الحركة (مثال: `withdrawal_requested`, `withdrawal_paid`, `withdrawal_rejected`, `parent_deposit`) |
| `status` | varchar(255) | ✗ | `completed` | حالة القيد |
| `metadata` | json | ✓ | NULL | تفاصيل إضافية حرة عن الحركة |
| `created_at` / `updated_at` | timestamp | ✓ | NULL | |

**ملاحظة:** `source_account` و`destination_account` و`type` هي **نصوص حرة (varchar) وليست enum** — قيمها الفعلية مُعرّفة كسلاسل نصية متناثرة داخل الخدمات (`WithdrawalService`, `WalletRechargeService`, `SubscriptionRequestService`, `TripLifecycleService`، إلخ) وليست موحّدة في مكان واحد (لا يوجد enum/const مركزي لأسماء الحسابات).

---

### 🟢 `withdrawal_requests` — Withdrawal_Request (طلبات السحب)

طلب سحب أرباح من محفظة سائق إلى حساب بنكي خارجي.

| العمود | النوع | Null | افتراضي | الوصف |
|---|---|---|---|---|
| `id` | bigint unsigned PK | ✗ | — | |
| `driver_id` | bigint unsigned FK → `drivers.id` | ✗ | — | السائق الطالب |
| `amount` | decimal(10,2) | ✗ | — | المبلغ المطلوب سحبه (بوحدة العملة الكاملة، **على عكس** `financial_ledger.amount`) |
| `wallet_balance_at_request` | decimal(10,2) | ✗ | 0.00 | رصيد المحفظة وقت تقديم الطلب (لقطة) |
| `status` | varchar(20) | ✗ | `pending` | حالة الطلب (نصّي حر، قيم مستعملة في الكود: `pending`) |
| `payment_method_details` | json | ✓ | NULL | تفاصيل الحساب البنكي/وسيلة الدفع |
| `admin_id` | bigint unsigned | ✓ | NULL | الأدمن الذي عالج الطلب (بدون FK صريح في القاعدة) |
| `rejection_reason` | text | ✓ | NULL | |
| `processed_at` | timestamp | ✓ | NULL | لحظة معالجة الطلب (قبول/رفض) |
| `created_at` / `updated_at` | timestamp | ✓ | NULL | |

**⚠️ ملاحظة اتساق وحدات:** `withdrawal_requests.amount` مخزّن بوحدة العملة الكاملة (decimal 10,2) بينما `financial_ledger.amount` و`wallets.balance` مخزّنان بأصغر وحدة (سنتات/bigint) — أي تحويل بين الجدولين يتطلب ضرب/قسمة على `10^decimal_places` يدويًا في الكود، وهذا مصدر شائع لأخطاء التقريب إذا لم يُطبَّق بانتباه.

---

## 4. التواصل - الشات (Chat)

### 🔴 `Chat_Room` و `Messages` — **غير موجودين في قاعدة البيانات**

لا يوجد أي جدول باسم يحتوي `chat` أو `message` في قاعدة البيانات الفعلية (تأكّدت بـ `SHOW TABLES LIKE '%chat%'` و`'%message%'` → نتيجة فارغة)، ولا يوجد موديل Eloquent مطابق.

ما هو موجود فعليًا: [`ChatController`](app/Http/Controllers/Api/Shared/ChatController.php) يعرض **فقط قائمة المحادثات المشتقّة من الاشتراكات** (`getParentChatList` / `getDriverChatList`)، عبر [`SubscriptionRequestService::getParentChats()` / `getDriverChats()`](app/Services/Shared/SubscriptionRequestService.php:1957). كل "غرفة" هي معرّف نصي **مُركَّب في الكود وليس مخزّنًا**:

```
chat_room_id = "parent_{parent_id}_driver_{driver_id}"
```

أي أن غرفة المحادثة ليست صفًا في جدول — هي فقط مفتاح مُشتقّ من (وليّ أمر × سائق) يُستخدم على الأغلب مع خدمة خارجية للرسائل الفورية (المشروع يحمل إعداد `FIREBASE_CREDENTIALS` في `.env`، مما يرجّح أن الرسائل الفعلية تُخزَّن وتُبَث عبر Firebase وليس MySQL — لكن هذا لم يُتحقّق منه مباشرة في هذا المسح، فقط استُدلّ عليه من وجود الإعداد).

**الخلاصة:** إذا كان المطلوب قاموس بيانات لجداول MySQL فعلية لهذين الكيانين — **لا توجد بعد**. إذا كنتم تخططون لإضافتهما كجداول حقيقية (بدل الاعتماد الكامل على Firebase)، هذا يحتاج تصميمًا جديدًا (migration + موديل)، وليس توثيقًا لما هو موجود. أخبرني إذا تريد أساعدك تصمم هذا الجزء.

---

## ملحق: خريطة العلاقات المختصرة

```
requests (Request)
 ├─ pricing_settings (snapshot عبر pricing_setting_id)
 ├─ request_children (1 → N: طفل واحد لكل سطر)
 │   └─ active_subscriptions (1 → 1 غالبًا، عبر request_child_id)
 └─ routes (1 → N: قد يُنشئ الطلب مسارًا أساسيًا "Master Route")
     └─ route_stops (1 → N، مرتّبة بـ sequence_order)

active_subscriptions
 ├─ subscription_request_id → requests.id
 ├─ request_child_id → request_children.id
 └─ route_id → routes.id

driver_seat_slots  ⋯ منفصل بنيويًا، مرتبط منطقيًا (لا FK) بـ routes.shift_slot ودرايفر route/active_subscriptions عبر منطق التطبيق فقط

wallets (Driver فقط حاليًا) ⋯ financial_ledger (سجل الحركات الفعلي) ⋯ withdrawal_requests (سحب من wallets إلى خارج المنصة)
```

---

## أسئلة مفتوحة لو تحب نكمّل التوثيق

1. **Chat_Room/Messages**: نصمم جداول MySQL حقيقية، ولا نوثّق بنية Firebase كما هي (يحتاج فحص كود الفرونت/الموبايل اللي يستعمل Firebase مباشرة، مش موجود في هذا الـ backend)؟
2. **wallets لوليّ الأمر/الأدمن**: حاليًا غير مفعّل (`HasWallet` على `Driver` فقط) — هل هذا مقصود ورصيد الولي يُدار بالكامل عبر `financial_ledger` بدون محفظة Eloquent؟
3. تحب نوسّع القاموس ليشمل جداول مرتبطة لم يطلبها السؤال مباشرة لكنها متصلة بقوة (`trips`, `invoices`, `master_escrow_vault`, `trip_escrow_holds`)؟
