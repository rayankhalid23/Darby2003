# قاموس البيانات حسب الدورات (Data Dictionary — Rounds 1→4)

**تاريخ الإصدار:** 2026-09-19
**المصدر:** استعلام مباشر على قاعدة البيانات الفعلية (`school_transport_v2`, MySQL 8) عبر `information_schema` + قراءة الموديلات/الخدمات/الكنترولرز المرتبطة. كل عمود ونوع وقيد هنا مطابق لما هو موجود فعلياً في القاعدة وقت كتابة هذا الملف، وليس مُستنتجاً من الـ migrations فقط.

**ملاحظة على الأنواع:** بناءً على طلبكم، كل عمود نوعه الفعلي `bigint` أو `bigint unsigned` مكتوب هنا باسم **`int`** لتبسيط القراءة (النوع الحقيقي في القاعدة أكبر سعة، لكن هذا لا يغيّر طريقة التعامل معه).

**كيفية التعامل مع الجداول المشتركة بين الدورات:** أول دورة يظهر فيها الجدول تحصل على تعريفه الكامل (كل الأعمدة). أي دورة لاحقة تستخدم نفس الجدول تُعطى سطراً واحداً فقط (`id`) مع إشارة "انظر تعريفه الكامل في الدورة رقم كذا" — حتى لا يتكرر نفس الجدول بالكامل أكثر من مرة.

> 🟢 يُستخدم فعلياً في الكود الحالي وبنفس الغرض الموصوف.
> 🟡 موجود لكنه يحتاج تنويهاً (عمود/جدول غير مُستخدم، أو تكرار، أو تسمية مضلّلة).
> 🔴 غير مفعّل / كود ميت / لا مسار حقيقي يستدعيه.

---

# الدورة الأولى — الحسابات والبيانات التأسيسية

## وظائف هذه الدورة

**أولاً: وظائف المستخدم العام** — 1) تسجيل الدخول 2) تسجيل الخروج 3) إعادة تعيين كلمة المرور 4) تأكيد الحساب عبر OTP 5) عرض الملف الشخصي 6) تعديل الملف الشخصي.

**ثانياً: وظائف ولي الأمر** — 7) إنشاء حساب 8) إضافة طفل 9) عرض بيانات الطفل 10) تعديل بيانات الطفل 11) حذف طفل 12) عرض كل الأطفال 13) إضافة عنوان 14) تعديل عنوان.

**ثالثاً: وظائف السائق** — 15) إنشاء حساب 16) استكمال بيانات المركبة ووثائقها 17) عرض الوثائق 18) تحديث الوثائق 19) عرض بيانات المركبة 20) تعديل بيانات المركبة 21) اختيار إعدادات العمل 22) تعديل إعدادات العمل 23) عرض إعدادات العمل.

**رابعاً: وظائف الإدارة** — 24) عرض طلبات انضمام السائقين 25) البحث عن سائق 26) مراجعة بيانات السائق ووثائقه 27) اعتماد الطلب 28) رفضه مع السبب 29) عرض كل المدارس 30) البحث عن مدرسة 31) إضافة مدرسة 32) تعديلها 33) حذفها 34) إضافة بلدية 35) تعديلها 36) حذفها 37) عرض قائمة البلديات 38) إضافة فرع بلدي 39) تعديله 40) حذفه 41) إضافة منطقة 42) تعديلها 43) حذفها 44) إضافة مشرف وتحديد صلاحياته 45) عرض قائمة المشرفين 46) البحث عن مشرف 47) عرض تفاصيله 48) تعديل بياناته 49) حذف/تعطيل حسابه.

## الجداول

### 🟢 `roles` — Role (الأدوار)

يمثّل دور الحساب. `kind='account'` = وليّ أمر/سائق، `kind='staff'` = أدمن/مشرف. تحديد "أدمن" لا يتم بحقل منفصل بل ديناميكياً عبر `roles.kind='staff'` (انظر [`Admin`](app/Models/Admin/Admin.php:20) الذي يستخدم `users` نفسه بـ global scope على الدور).

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | معرّف الدور |
| `name` | varchar(50) | NOT NULL, UNIQUE | اسم الدور البرمجي (`parent`, `driver`, `admin`, `supervisor`...) |
| `display_name` | varchar(100) | NOT NULL | الاسم المعروض |
| `kind` | enum(`account`,`staff`) | NOT NULL | نوع الدور: حساب عادي أم طاقم إداري |
| `is_super` | tinyint(1) | NOT NULL, DEFAULT 0 | هل هو أدمن خارق (كل الصلاحيات تلقائياً) |
| `description` | text | NULL | وصف الدور |
| `created_at` / `updated_at` | timestamp | NULL | — |

---

### 🟢 `permissions` — Permission (الصلاحيات)

كتالوج ثابت بكل صلاحية ممكنة يُمنح منها المشرف مجموعة فرعية عبر `permission_role` أو `users.custom_permissions` (استثناء فردي).

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `key` | varchar(100) | NOT NULL, UNIQUE | مفتاح الصلاحية البرمجي |
| `group_key` | varchar(50) | NOT NULL | تجميع الصلاحيات في مجموعات بالواجهة |
| `name_ar` | varchar(150) | NOT NULL | الاسم المعروض بالعربية |
| `description_ar` | text | NULL | شرح الصلاحية |
| `created_at` / `updated_at` | timestamp | NULL | — |

---

### 🟢 `permission_role` — Permission_Role (جدول وسيط)

يربط دوراً (عادةً `supervisor`) بمجموعة الصلاحيات الافتراضية لذلك الدور. يُستخدم مع وظيفة "إضافة مشرف وتحديد صلاحياته" (#44).

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `role_id` | int | PK (مركّب), FK → `roles.id` | |
| `permission_id` | int | PK (مركّب), FK → `permissions.id` | |

---

### 🟢 `users` — User (المستخدم الموحّد لكل الأدوار)

**الجدول المركزي لكل شخص في المنصة** — وليّ أمر، سائق، أدمن، أو مشرف، جميعهم صفوف في هذا الجدول ويُميَّزون فقط عبر `role_id → roles.kind`. لا يوجد جدول `admins` منفصل (أُلغي في التطبيع V2؛ انظر تعليق [`Admin::booted()`](app/Models/Admin/Admin.php:20)).

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `role_id` | int | NOT NULL, FK → `roles.id` | دور الحساب (يحدد وليّ أمر/سائق/أدمن/مشرف) |
| `custom_permissions` | json | NULL | صلاحيات إضافية/استثناءات فردية للمشرف تتجاوز صلاحيات دوره العامة |
| `full_name` | varchar(150) | NOT NULL | الاسم الكامل |
| `email` | varchar(100) | NOT NULL, UNIQUE | البريد الإلكتروني (يُستخدم أيضاً كمعرّف OTP) |
| `phone_number` | varchar(20) | NOT NULL, UNIQUE | |
| `alternative_phone` | varchar(20) | NULL | |
| `password` | varchar(255) | NOT NULL | كلمة المرور (Hash) |
| `avatar_url` | varchar(500) | NULL | |
| `gender` | enum(`male`,`female`) | NULL | يُستخدم أيضاً في فلترة مطابقة السائق/الطفل |
| `is_active` | tinyint(1) | NOT NULL, DEFAULT 1 | تفعيل/تعطيل الحساب (يُستخدم في "حذف أو تعطيل حساب مشرف" #49) |
| `is_trusted` | tinyint(1) | NOT NULL, DEFAULT 1 | ثقة الحساب — يُطفأ تلقائياً عند مخالفة سلامة حرجة من محرك الذكاء الاصطناعي (الدورة الرابعة) |
| `created_by` | int | NULL, FK → `users.id` (ذاتي) | الأدمن الذي أنشأ هذا الحساب (مفيد لحسابات المشرفين) |
| `email_verified_at` | timestamp | NULL | |
| `last_login_at` | timestamp | NULL | |
| `remember_token` | varchar(100) | NULL | |
| `created_at` / `updated_at` | timestamp | NULL | |
| `deleted_at` | timestamp | NULL | Soft delete |

---

### 🟢 `otp_codes` — Otp_Code (رموز التحقق)

يخدم وظيفة "تأكيد الحساب عبر OTP" (#4) وكذلك إعادة تعيين كلمة المرور (#3).

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `email` | varchar(100) | NOT NULL | البريد المُرسَل إليه الرمز (لا FK صريح؛ يُطابَق نصياً وقت التحقق) |
| `code_hash` | varchar(255) | NOT NULL | الرمز مُشفَّر (Hash) وليس نصاً صريحاً |
| `purpose` | varchar(50) | NOT NULL | الغرض (`register`, `reset_password`...) |
| `expires_at` | timestamp | NOT NULL | وقت انتهاء صلاحية الرمز |
| `is_used` | tinyint(1) | NOT NULL, DEFAULT 0 | هل استُهلك الرمز |
| `attempts` | tinyint | NOT NULL, DEFAULT 0 | عدد محاولات إدخال خاطئة |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `addresses` — Address (العناوين)

عنوان محفوظ لأي مستخدم (وليّ أمر بشكل أساسي — وظائف #13/#14)، ويُستخدم لاحقاً كمنزل الطفل.

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `user_id` | int | NOT NULL, FK → `users.id` | صاحب العنوان |
| `zone_id` | int | NULL, FK → `zones.id` | المنطقة الجغرافية الإدارية |
| `label` | varchar(100) | NULL | تسمية ("المنزل", "بيت الجدة"...) |
| `lat` | decimal(10,8) | NOT NULL | خط العرض |
| `lng` | decimal(11,8) | NOT NULL | خط الطول |
| `is_default` | tinyint(1) | NOT NULL, DEFAULT 0 | العنوان الافتراضي لهذا المستخدم |
| `created_at` / `updated_at` | timestamp | NULL | |
| `deleted_at` | timestamp | NULL | Soft delete |

---

### 🟢 `children` — Child (الأطفال)

وظائف #8–#12 (إضافة/عرض/تعديل/حذف/عرض الكل).

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `parent_id` | int | NOT NULL, FK → `users.id` | وليّ الأمر |
| `school_id` | int | NULL, FK → `schools.id` | مدرسة الطفل |
| `address_id` | int | NULL, FK → `addresses.id` | منزل الطفل (من عناوين وليّ الأمر) |
| `full_name` | varchar(150) | NOT NULL | |
| `birth_date` | date | NOT NULL | |
| `gender` | enum(`male`,`female`) | NOT NULL | يدخل في فلترة "الجنس المقبول" لدى السائق |
| `grade` | tinyint unsigned | NOT NULL | الصف الدراسي |
| `school_stage` | varchar(255) | NULL | المرحلة الدراسية (يطابق `drivers.school_stages`) |
| `photo_url` | varchar(500) | NULL | |
| `medical_notes` | text | NULL | ملاحظات طبية |
| `notification_radius` | int | NOT NULL, DEFAULT 500 | نطاق تنبيه القرب من المنزل/المدرسة (متر) |
| `qr_code_token` | varchar(100) | NOT NULL, UNIQUE | رمز QR الفريد للطفل (يُستخدم في صعود/نزول الرحلة، الدورة الثالثة) |
| `preferred_time_slot` | enum(`morning`,`evening`) | NOT NULL | الفترة المفضّلة، تُشتق منها خانات الحجز في `driver_seat_slots` |
| `pickup_time` / `dropoff_time` | time | NULL | |
| `is_active` | tinyint(1) | NOT NULL, DEFAULT 1 | |
| `created_at` / `updated_at` | timestamp | NULL | |
| `deleted_at` | timestamp | NULL | Soft delete (تُستخدم كـ "حذف طفل" #11) |

---

### 🟢 `drivers` — Driver (ملف السائق)

وظائف #15 (إنشاء حساب سائق)، #19–#23 (بيانات المركبة الأساسية وإعدادات العمل — **مخزّنة كأعمدة مباشرة في هذا الجدول وليس بجدول منفصل**، لذا "اختيار/تعديل/عرض إعدادات العمل" كلها CRUD على نفس الصف).

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `user_id` | int | NOT NULL, UNIQUE, FK → `users.id` | حساب المستخدم المرتبط (1:1) |
| `national_id` | varchar(50) | UNIQUE | الرقم الوطني |
| `license_number` | varchar(50) | UNIQUE | رقم رخصة القيادة |
| `license_expiry` | date | NULL | تاريخ انتهاء الرخصة (يدخل في فلترة الأهلية للبحث، الدورة الرابعة) |
| `license_expiry_notified_milestone` | tinyint unsigned | NULL | آخر تنبيه اقتراب انتهاء تم إرساله |
| `driver_waiting_minutes` | int | NOT NULL, DEFAULT 5 | مدة انتظار الطفل غير المستجيب (تُستخدم بالدورة الثالثة) |
| `license_image_url` | varchar(500) | NULL | |
| `status` | enum(`Pending`,`Approved`,`Suspended`,`Rejected`,`Offline`,`ON_TRIP`) | NOT NULL, DEFAULT `Offline` | حالة اعتماد/تشغيل السائق (#27/#28) |
| `suspension_count` | int unsigned | NOT NULL, DEFAULT 0 | عدد مرات الإيقاف التاريخية |
| `suspended_until` | timestamp | NULL | حجب مؤقت نشط حالياً (يُضبط يدوياً من الأدمن أو آلياً من محرك القرار، الدورة الرابعة) |
| `active_warnings_count` | int unsigned | NOT NULL, DEFAULT 0 | عدد التحذيرات النشطة (محرك القرار، الدورة الرابعة) |
| `last_incident_at` | timestamp | NULL | آخر واقعة مسجَّلة |
| `is_searchable` | tinyint(1) | NOT NULL, DEFAULT 1 | |
| `hidden_from_search` | tinyint(1) | NOT NULL, DEFAULT 0 | إخفاء احترازي عن نتائج بحث أولياء الأمور |
| `reviewed_by` | int | NULL, FK → `users.id` | الأدمن الذي راجع الطلب (#26) |
| `rejection_reason` | text | NULL | سبب الرفض (#28) |
| `shift` | enum(`morning`,`evening`,`both`) | NOT NULL, DEFAULT `both` | الفترة العامة التي يعمل بها |
| `morning_go` / `morning_return` / `afternoon_go` / `afternoon_return` | tinyint(1) | NOT NULL, DEFAULT 0 لكل منها | أربعة أعلام بديلة تحدد الخانات الدقيقة التي يفعّلها السائق (تُطابق enum `driver_seat_slots.slot`) — هذه هي "إعدادات العمل" الفعلية (#21–#23) |
| `subscription_type` | enum(`single_day`,`multi_day`,`both`) | NOT NULL, DEFAULT `both` | نوع الاشتراكات المقبولة |
| `accepted_gender` | enum(`male`,`female`,`both`) | NOT NULL, DEFAULT `both` | جنس الأطفال المقبول |
| `school_stages` | json | NULL | المراحل الدراسية المقبولة |
| `current_lat` / `current_lng` | decimal | NULL | آخر موقع حي (الدورة الثالثة) |
| `last_ping_at` | timestamp | NULL | |
| `rating_avg` | decimal(3,2) | NOT NULL, DEFAULT 5.00 | متوسط التقييم — يُحدَّث آلياً من محرك القرار (الدورة الرابعة) |
| `ai_last_reset_at` | timestamp | NULL | آخر إعادة تأهيل يدوية من الأدمن (الدورة الرابعة) |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `vehicles` — Vehicle (المركبة)

وظائف #16 (إضافة عند التسجيل)، #19–#20 (عرض/تعديل).

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `driver_id` | int | NOT NULL, FK → `drivers.id` | مالك المركبة |
| `plate_number` | varchar(20) | NOT NULL, UNIQUE | رقم اللوحة |
| `brand` / `model` | varchar(50) | NOT NULL | |
| `year` | year | NOT NULL | |
| `color` | varchar(30) | NOT NULL | |
| `type` | enum(`Bus`,`Sedan`,`Van`) | NOT NULL, DEFAULT `Bus` | |
| `capacity_manual` | smallint unsigned | NOT NULL | السعة القصوى للمقاعد — أساس حساب التوفر في `driver_seat_slots` |
| `has_ac` | tinyint(1) | NOT NULL, DEFAULT 1 | تكييف — يدخل في التسعير (الدورة الثانية) وفلترة البحث (الدورة الرابعة) |
| `status` | enum(`Active`,`Maintenance`,`Retired`) | NOT NULL, DEFAULT `Active` | |
| `vehicle_image_url` | varchar(500) | NULL | |
| `created_at` / `updated_at` | timestamp | NULL | |
| `deleted_at` | timestamp | NULL | Soft delete |

---

### 🟢 `driver_documents` — Driver_Document (وثائق شخصية للسائق)

وظائف #17–#18.

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `driver_id` | int | NOT NULL, FK → `drivers.id` | |
| `document_type` | enum(`national_id`,`vehicle_license`,`insurance`,`technical_inspection`) | NOT NULL | نوع الوثيقة |
| `file_url` | varchar(500) | NOT NULL | |
| `status` | enum(`pending`,`approved`,`rejected`) | NOT NULL, DEFAULT `approved` | حالة مراجعة الوثيقة |
| `expires_at` | date | NULL | |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `vehicle_documents` — Vehicle_Document (وثائق المركبة)

جزء من وظيفتي #17–#18 لكن خاص بالمركبة تحديداً (وليس بالسائق شخصياً).

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `vehicle_id` | int | NOT NULL, FK → `vehicles.id` | |
| `doc_type` | enum(`LOGBOOK`,`INSURANCE`,`INSPECTION`,`OPERATING_PERMIT`) | NOT NULL | |
| `file_url` | varchar(500) | NOT NULL | |
| `expiry_date` | date | NULL | |
| `is_verified` | tinyint(1) | NOT NULL, DEFAULT 0 | |
| `state` | enum(`pending`,`active`,`expired`,`rejected`) | NOT NULL, DEFAULT `pending` | |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `driver_approvals` — Driver_Approval (سجل مراجعة الإدارة لطلبات السائق)

وظائف #24 (عرض الطلبات)، #26 (مراجعة)، #27/#28 (اعتماد/رفض). يُستخدم أيضاً عند أي تعديل لاحق على بيانات السائق/مركبته يتطلب موافقة إدارية (`ProfileChange`, `VehicleUpdate`).

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `driver_id` | int | NOT NULL, FK → `drivers.id` | |
| `request_type` | enum(`Registration`,`ProfileChange`,`VehicleUpdate`) | NOT NULL, DEFAULT `Registration` | نوع طلب المراجعة |
| `status` | enum(`Pending`,`Approved`,`Rejected`) | NOT NULL, DEFAULT `Pending` | |
| `old_values` | json | NULL | القيم قبل التعديل (لطلبات التعديل) |
| `new_values` | json | NULL | القيم الجديدة المطلوب اعتمادها |
| `admin_id` | int | NULL, FK → `users.id` | الأدمن المُراجِع |
| `rejection_reason` | text | NULL | |
| `reviewed_at` | timestamp | NULL | |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `driver_zone` — Driver_Zone (تغطية السائق الجغرافية)

جدول وسيط فعلي (سائق ↔ منطقة). يُستخدم في وظيفة "إعدادات العمل" (نطاق التغطية) وفي فلترة البحث بالدورة الرابعة. العلاقة النشطة فعلياً في الكود: [`Driver::zones()`](app/Models/Driver/Driver.php:174).

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `driver_id` | int | NOT NULL, FK → `drivers.id` | |
| `zone_id` | int | NOT NULL, FK → `zones.id` | |
| `created_at` / `updated_at` | timestamp | NULL | |

**فهرس:** `UNIQUE(driver_id, zone_id)`.

**🟡 تنويه:** يوجد جدول آخر شبه مطابق باسم `driver_zones` (بصيغة الجمع) بنفس الأعمدة (`driver_id`, `zone_id`) وبنفس القيد الفريد، لكنه **غير مُستخدم في أي علاقة Eloquent حالياً** — بقية من تصميم/migration سابق. لا يُنصح بالكتابة فيه؛ الجدول المعتمد هو `driver_zone` (المفرد) فقط.

---

### 🟢 `schools` — School (المدارس)

وظائف #29–#33.

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `name` | varchar(150) | NOT NULL | |
| `zone_id` | int | NULL, FK → `zones.id` | |
| `lat` / `lng` | decimal(10,8)/(11,8) | NOT NULL | إحداثيات المدرسة — أساس حساب المسافة والتسعير |
| `address` | varchar(255) | NULL | |
| `status` | enum(`Approved`,`Pending`,`Inactive`) | NOT NULL, DEFAULT `Approved` | |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `municipalities` — Municipality (البلديات)

وظائف #34–#37.

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `name` | varchar(100) | NOT NULL, UNIQUE | |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `sub_municipalities` — Sub_Municipality (الفروع البلدية)

وظائف #38–#40 ("فرع بلدي").

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `municipality_id` | int | NOT NULL, FK → `municipalities.id` | البلدية الأم |
| `name` | varchar(100) | NOT NULL | |
| `created_at` / `updated_at` | timestamp | NULL | |

**فهرس:** `UNIQUE(municipality_id, name)` — لا يتكرر اسم الفرع داخل نفس البلدية.

---

### 🟢 `zones` — Zone (المناطق)

وظائف #41–#43. أدق مستوى جغرافي، وهو ما يُستخدم فعلياً في مطابقة تغطية السائق (`driver_zone`) وعناوين وليّ الأمر (`addresses.zone_id`) والمدارس (`schools.zone_id`).

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `sub_municipality_id` | int | NOT NULL, FK → `sub_municipalities.id` | الفرع البلدي الأم |
| `name` | varchar(100) | NOT NULL | |
| `created_at` / `updated_at` | timestamp | NULL | |

**فهرس:** `UNIQUE(sub_municipality_id, name)`.

---

## ملحق الدورة الأولى: خريطة العلاقات

```
roles ──< users >── permission_role >── permissions        (وظائف #44–49)
users ──< addresses                                        (وظائف #13–14)
users(parent) ──< children >── addresses / schools          (وظائف #7–12)
users(driver) ── drivers ──< vehicles ──< vehicle_documents  (وظائف #15–20)
drivers ──< driver_documents                                 (وظائف #17–18)
drivers ──< driver_zone >── zones                             (إعدادات العمل / التغطية)
drivers ──< driver_approvals                                   (وظائف #24–28)
municipalities ──< sub_municipalities ──< zones ──< schools     (وظائف #29–43)
```

---
---

# الدورة الثانية — الاشتراكات والمعاملات المالية

## وظائف هذه الدورة

**أولاً: ولي الأمر** — 1) بحث مباشر عن سائق 2) بحث بناءً على بيانات الأطفال 3) تصفية النتائج 4) عرض الملف التعريفي للسائق 5) عرض التكلفة التقديرية 6) إرسال طلب اشتراك 7) عرض قائمة الطلبات 8) عرض تفاصيل طلب 9) إلغاء طلب 10) عرض الاشتراكات الحالية 11) عرض تفاصيل اشتراك 12) إلغاء اشتراك 13) عرض رصيد المحفظة 14) شحن المحفظة 15) عرض الفواتير 16) عرض تفاصيل فاتورة 17) التواصل عبر المحادثة الفورية.

**ثانياً: السائق** — 18) عرض طلبات الاشتراك الواردة 19) عرض تفاصيل طلب 20) عرض تكلفته 21) قبول الطلب 22) رفضه 23) عرض الاشتراكات الحالية 24) عرض تفاصيل اشتراك 25) عرض رصيد المحفظة 26) طلب سحب رصيد 27) عرض الفواتير 28) عرض تفاصيل فاتورة 29) التواصل عبر المحادثة الفورية.

**ثالثاً: المشرف** — 30) ضبط التسعير والعمولة 31) إضافة طرق الدفع 32) عرضها 33) تعديلها 34) حذفها 35) عرض الفواتير الصادرة 36) عرض تفاصيل فاتورة 37) عرض طلبات سحب السائقين 38) قبول طلب السحب 39) رفضه 40) عرض الأمانات المعلّقة 41) تحرير الأمانات المستحقة.

*(الترقيم أعلاه مُعاد فقط للتسلسل الداخلي داخل هذا الملف؛ يقابل ترقيم رقم 1–42 كما ورد في طلبكم الأصلي.)*

## ملاحظة مهمة: المحادثة الفورية (وظيفة "التواصل عبر الشات")

**🔴 لا يوجد جدول `Chat_Room` أو `Messages` في قاعدة البيانات فعلياً.** ما هو موجود: [`ChatController`](app/Http/Controllers/Api/Shared/ChatController.php) يبني فقط **قائمة** المحادثات المشتقة من الاشتراكات القائمة (`parent_{parent_id}_driver_{driver_id}` — معرّف مُركَّب في الكود وليس صفاً في جدول)، عبر [`SubscriptionRequestService::getParentChats()`/`getDriverChats()`](app/Services/Shared/SubscriptionRequestService.php:1957). الرسائل الفعلية على الأرجح تُخزَّن وتُبَث خارج MySQL (المشروع يحمل `FIREBASE_CREDENTIALS` في `.env`)، لكن هذا لم يُتحقَّق منه مباشرة في هذا المسح. **لا يوجد قاموس بيانات MySQL لهذا الجزء لأنه غير موجود.**

## الجداول

### 🟢 `pricing_settings` — Pricing_Setting (إعدادات التسعير) — وظيفة #30

جدول إعدادات عام (صف واحد عادةً) تُديره لوحة الأدمن، ويُنسخ (snapshot) داخل `requests` عند كل طلب.

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `discount_one_child` | decimal(5,2) | NOT NULL, DEFAULT 0.00 | نسبة خصم طفل واحد (٪) |
| `discount_two_children` | decimal(5,2) | NOT NULL, DEFAULT 10.00 | نسبة خصم طفلين (٪) |
| `discount_three_plus_children` | decimal(5,2) | NOT NULL, DEFAULT 15.00 | نسبة خصم 3 فأكثر (٪) |
| `platform_commission_rate` | decimal(5,2) | NOT NULL, DEFAULT 8.00 | نسبة عمولة المنصة (٪) |
| `price_per_km_ac` | decimal(8,2) | NOT NULL, DEFAULT 2.50 | سعر الكم لمركبة مكيّفة |
| `price_per_km_non_ac` | decimal(8,2) | NOT NULL, DEFAULT 2.00 | سعر الكم لمركبة غير مكيّفة |
| `location_change_fee` | decimal(8,2) | NOT NULL, DEFAULT 5.00 | رسوم تغيير موقع افتراضية |
| `location_change_fee_under_2km` | decimal(8,2) | NOT NULL, DEFAULT 5.00 | رسوم تغيير الموقع (< 2 كم) |
| `location_change_fee_2_to_6km` | decimal(8,2) | NOT NULL, DEFAULT 10.00 | رسوم تغيير الموقع (2–6 كم) |
| `location_change_fee_6_to_10km` | decimal(8,2) | NOT NULL, DEFAULT 15.00 | رسوم تغيير الموقع (6–10 كم) |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `requests` — Request (طلب الاشتراك) — وظائف #6–#9، #18–#22

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `parent_id` | int | NOT NULL, FK → `users.id` | |
| `driver_id` | int | NOT NULL, FK → `drivers.id` | |
| `status` | enum(`pending`,`acquired`,`accepted`,`rejected`,`contract_offered`,`cancelled`) | NOT NULL, DEFAULT `pending` | حالة الطلب (قبول/رفض/إلغاء) |
| `total_price` | decimal(10,2) | NOT NULL, DEFAULT 0.00 | السعر قبل الخصم |
| `discount_amount` | decimal(10,2) | NOT NULL, DEFAULT 0.00 | |
| `total_amount_after_discount` | decimal(10,2) | NOT NULL, DEFAULT 0.00 | الصافي بعد الخصم — يظهر في "عرض التكلفة التقديرية" (#5/#20) |
| `platform_commission_amount` | decimal(10,2) | NULL | |
| `driver_net_amount` | decimal(10,2) | NULL | |
| `children_count` | int | NOT NULL, DEFAULT 1 | |
| `pickup_time` / `dropoff_time` | time | NULL | |
| `max_waiting_time` | int | NOT NULL, DEFAULT 15 | |
| `notes` | text | NULL | |
| `subscription_type` | varchar(50) | NULL | |
| `trip_direction` | varchar(50) | NULL | |
| `start_date` / `end_date` | date | NULL | |
| `working_days_count` | smallint unsigned | NULL | |
| `home_label` / `home_lat` / `home_lng` | varchar/decimal | NULL | لقطة موقع المنزل وقت الطلب |
| `home_address_id` | int | NULL, FK → `addresses.id` | |
| `pricing_setting_id` | int | NULL, FK → `pricing_settings.id` | لقطة إعدادات التسعير وقت الطلب |
| `price_per_km` | decimal(8,2) | NULL | سعر الكم المُطبَّق فعلياً (نسخة) |
| `vehicle_has_ac` | tinyint(1) | NULL | |
| `discount_percent` | decimal(5,2) | NULL | |
| `platform_commission_rate` | decimal(5,2) | NULL | |
| `rejection_reason` | text | NULL | سبب رفض السائق (#22) |
| `responded_at` | timestamp | NULL | لحظة قبول/رفض السائق |
| `created_at` / `updated_at` | timestamp | NULL | |

**ملاحظة:** الأعمدة `price_per_km`, `discount_percent`, `platform_commission_rate` لقطات (snapshots) من `pricing_settings` وقت إنشاء الطلب، وليست مراجع حيّة.

---

### 🟢 `request_children` — Request_Child (تفاصيل كل طفل داخل الطلب)

سطر واحد لكل (طلب × طفل) — التسعير والموقع المدرسي الخاص بهذا الطفل داخل هذا الطلب تحديداً.

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `request_id` | int | NOT NULL, FK → `requests.id` | |
| `child_id` | int | NOT NULL, FK → `children.id` | |
| `school_id` | int | NULL, FK → `schools.id` | |
| `timing` | varchar(50) | NULL | |
| `distance_km` | decimal(8,2) | NULL | المسافة الفعلية |
| `billable_distance_km` | decimal(8,2) | NULL | المسافة المحتسبة للتسعير |
| `school_label` / `school_lat` / `school_lng` | varchar/decimal | NULL | لقطة موقع المدرسة |
| `price_per_child` | decimal(10,2) | NOT NULL, DEFAULT 0.00 | |
| `trip_price` | decimal(10,2) | NOT NULL, DEFAULT 0.00 | |
| `trip_price_after_discount` | decimal(10,2) | NULL | |
| `daily_price` | decimal(10,2) | NULL | |
| `discount_amount` | decimal(10,2) | NOT NULL, DEFAULT 0.00 | |
| `total_amount_after_discount` | decimal(10,2) | NOT NULL, DEFAULT 0.00 | |
| `driver_net_price` | decimal(10,2) | NOT NULL, DEFAULT 0.00 | |
| `notes` | text | NULL | |
| `created_at` / `updated_at` | timestamp | NULL | |

**فهرس:** `UNIQUE(request_id, child_id)`.

---

### 🟢 `active_subscriptions` — Active_Subscription (الاشتراك النشط) — وظائف #10–#12، #23–#24

يمثّل الاشتراك التنفيذي لطفل واحد ضمن خط سير سائق واحد.

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `subscription_request_id` | int | NOT NULL, FK → `requests.id` | الطلب الأصلي |
| `request_child_id` | int | NULL, FK → `request_children.id` | تفاصيل الطفل/السعر |
| `route_id` | int | NULL, FK → `routes.id` | خط السير المُسنَد |
| `pickup_lat` / `pickup_lng` / `pickup_label` | decimal/varchar | NULL | نقطة الاستلام الفعلية |
| `dropoff_lat` / `dropoff_lng` / `dropoff_label` | decimal/varchar | NULL | نقطة التسليم الفعلية |
| `pickup_time` / `dropoff_time` | time | NULL | |
| `sort_order` | int | NOT NULL, DEFAULT 0 | ترتيب صعود الطفل بخط السير |
| `status` | enum(`active`,`paused`,`completed`,`cancelled`,`suspended_unpaid`,`terminated`) | NOT NULL, DEFAULT `active` | |
| `cancelled_at` | timestamp | NULL | |
| `cancelled_by` | varchar(20) | NULL | `parent`\|`driver`\|`admin`\|`system` |
| `cancellation_reason` | varchar(255) | NULL | |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `routes` — Route (خط السير) و🟢 `route_stops` — Route_Stop (محطاته)

خط سير يومي متكرر لسائق واحد ضمن فترة (`shift_slot`)، يظهر ضمن "الملف التعريفي للسائق" و"تفاصيل الاشتراك".

**`routes`**

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `subscription_request_id` | int | NULL, FK → `requests.id` | الطلب الذي أنشأ هذا المسار |
| `driver_id` | int | NOT NULL, FK → `drivers.id` | |
| `vehicle_id` | int | NULL, FK → `vehicles.id` | |
| `route_name` | varchar(150) | NOT NULL | |
| `route_type` | enum(`Morning`,`Afternoon`) | NOT NULL | |
| `shift_slot` | enum(`morning_go`,`morning_return`,`afternoon_go`,`afternoon_return`) | NULL | |
| `start_time` | time | NULL | |
| `optimized_points` | json | NULL | نقاط المسار بعد تحسين الترتيب |
| `total_distance` | decimal(8,2) | NULL | |
| `estimated_duration` | int | NULL | بالدقائق |
| `status` | enum(`Active`,`Inactive`) | NOT NULL, DEFAULT `Active` | |
| `created_at` / `updated_at` | timestamp | NULL | |

**`route_stops`**

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `route_id` | int | NOT NULL, FK → `routes.id` | |
| `stop_type` | enum(`home`,`school`) | NOT NULL | |
| `child_id` | int | NULL, FK → `children.id` | عند `stop_type=home` |
| `school_id` | int | NULL, FK → `schools.id` | عند `stop_type=school` |
| `lat` / `lng` | decimal | NULL | |
| `label` | varchar(255) | NULL | |
| `sequence_order` | int unsigned | NOT NULL, DEFAULT 0 | |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `driver_seat_slots` — Driver_Seat_Slot (إشغال المقاعد)

يمثّل "خانة" واحدة (سائق × فترة × تاريخ) ويخزّن عدد المقاعد المحجوزة فقط؛ الطاقة الكاملة تُقرأ من `vehicles.capacity_manual` وقت الحساب. يُستخدم في قبول/رفض الطلب (#21) للتحقق من توفر مقعد.

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `driver_id` | int | NOT NULL, FK → `drivers.id` | |
| `slot` | enum(`morning_go`,`morning_return`,`afternoon_go`,`afternoon_return`) | NOT NULL | |
| `date` | date | NOT NULL | |
| `booked` | tinyint unsigned | NULL, DEFAULT 0 | عدد المقاعد المحجوزة في هذه الخانة |
| `created_at` / `updated_at` | timestamp | NULL | |

**فهرس:** `UNIQUE(driver_id, slot, date)`.

---

### 🟡 `wallets` — Wallet (المحفظة الرقمية) — وظائف #13، #25

جدول حزمة [`bavix/laravel-wallet`](https://github.com/bavix/laravel-wallet) الجاهزة (polymorphic). حالياً `HasWallet` مُفعَّل فقط على موديل [`Driver`](app/Models/Driver/Driver.php:19) — أي أن السائقين فقط من يملكون صفوفاً هنا فعلياً؛ رصيد وليّ الأمر يُدار عبر `financial_ledger` بدل محفظة Eloquent مستقلة.

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `holder_type` | varchar(255) | NOT NULL | اسم كلاس المالك (`App\Models\Driver\Driver` عملياً فقط حالياً) |
| `holder_id` | int | NOT NULL | |
| `name` | varchar(255) | NOT NULL | |
| `slug` | varchar(255) | NOT NULL | |
| `uuid` | char(36) | NOT NULL, UNIQUE | |
| `description` | varchar(255) | NULL | |
| `meta` | json | NULL | |
| `balance` | decimal(64,0) | NOT NULL, DEFAULT 0 | الرصيد بأصغر وحدة عملة (وليس الوحدة الكاملة) |
| `decimal_places` | smallint unsigned | NOT NULL, DEFAULT 2 | |
| `created_at` / `updated_at` | timestamp | NULL | |
| `deleted_at` | timestamp | NULL | |

**⚠️ نقص:** جداول الحزمة المكمّلة `wallet_transactions`/`wallet_transfers` غير مستخدمة فعلياً هنا؛ سجل الحركة الفعلي هو `financial_ledger` أدناه (نظام قيد مزدوج مبني يدوياً).

---

### 🟢 `financial_ledger` — Financial_Ledger (السجل المالي / القيود) — وظائف #13، #14، #25

سجل قيد مزدوج (double-entry) مركزي لكل حركة مالية (شحن، سحب، عمولة...). يُنشأ حصراً عبر [`FinancialLedgerService::recordLedgerEntry()`](app/Services/Shared/FinancialLedgerService.php:119).

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `transaction_id` | char(36) | NOT NULL, UNIQUE | |
| `reference_number` | varchar(255) | NULL | |
| `source_account` | varchar(255) | NOT NULL | حساب المصدر (نص حر) |
| `destination_account` | varchar(255) | NOT NULL | حساب الوجهة (نص حر) |
| `amount` | int | NOT NULL | بأصغر وحدة عملة |
| `balance_before` / `balance_after` | int | NOT NULL, DEFAULT 0 | |
| `type` | varchar(255) | NOT NULL | نوع الحركة (نص حر) |
| `status` | varchar(255) | NOT NULL, DEFAULT `completed` | |
| `metadata` | json | NULL | |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `recharge_requests` — Recharge_Request (طلبات شحن محفظة وليّ الأمر) — وظيفة #14

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `parent_id` | int | NOT NULL, FK → `users.id` | |
| `amount` | decimal(10,2) | NOT NULL | |
| `payment_method` | varchar(30) | NOT NULL | |
| `payment_method_id` | int | NULL, FK → `payment_methods.id` | |
| `reference_number` | varchar(100) | NULL | |
| `transaction_ref` | varchar(100) | NULL | |
| `session_token` | varchar(120) | NULL | |
| `gateway_payload` | json | NULL | استجابة بوابة الدفع |
| `status` | varchar(20) | NOT NULL, DEFAULT `pending` | |
| `notes` | text | NULL | |
| `admin_id` | int | NULL | بدون FK صريح بقاعدة البيانات |
| `completed_at` | timestamp | NULL | |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `withdrawal_requests` — Withdrawal_Request (طلبات سحب السائق) — وظائف #26، #38، #39

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `driver_id` | int | NOT NULL, FK → `drivers.id` | |
| `amount` | decimal(10,2) | NOT NULL | بوحدة العملة الكاملة (بعكس `financial_ledger.amount`) |
| `wallet_balance_at_request` | decimal(10,2) | NOT NULL, DEFAULT 0.00 | لقطة رصيد وقت الطلب |
| `status` | varchar(20) | NOT NULL, DEFAULT `pending` | |
| `payment_method_details` | json | NULL | |
| `admin_id` | int | NULL | الأدمن الذي عالج الطلب (بدون FK صريح) |
| `rejection_reason` | text | NULL | |
| `processed_at` | timestamp | NULL | |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `payment_methods` — Payment_Method (طرق الدفع) — وظائف #31–#34

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `name_ar` / `name_en` | varchar(255) | NOT NULL/NULL | |
| `code` | varchar(50) | NOT NULL, UNIQUE | |
| `target_audience` | enum(`parent`,`driver`,`both`) | NOT NULL, DEFAULT `both` | |
| `processing_type` | enum(`instant_simulation`,`manual_proof`) | NOT NULL, DEFAULT `instant_simulation` | |
| `account_name` / `account_number` / `iban` / `wallet_number` | varchar | NULL | تفاصيل الحساب المصرفي/المحفظة |
| `icon_url` | varchar(255) | NULL | |
| `min_amount` | decimal(10,2) | NOT NULL, DEFAULT 1.00 | |
| `max_amount` | decimal(10,2) | NOT NULL, DEFAULT 50000.00 | |
| `instructions_ar` / `instructions_en` | text | NULL | |
| `is_active` | tinyint(1) | NOT NULL, DEFAULT 1 | |
| `sort_order` | int | NOT NULL, DEFAULT 0 | |
| `created_at` / `updated_at` | timestamp | NULL | |
| `deleted_at` | timestamp | NULL | Soft delete |

---

### 🟢 `invoices` — Invoice (الفواتير) — وظائف #15–#16، #27–#28، #35–#36

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `subscription_request_id` | int | NULL, FK → `requests.id` | |
| `parent_id` | int | NOT NULL | بدون FK صريح بقاعدة البيانات |
| `driver_id` | int | NULL | بدون FK صريح بقاعدة البيانات |
| `invoice_number` | varchar(50) | NOT NULL | |
| `amount` | decimal(10,2) | NOT NULL | |
| `type` | varchar(20) | NOT NULL, DEFAULT `proforma` | |
| `status` | varchar(20) | NOT NULL, DEFAULT `pending` | |
| `due_date` | date | NOT NULL | |
| `subscription_type` | varchar(20) | NULL | |
| `total_trips` | decimal(5,0) | NOT NULL, DEFAULT 0 | |
| `completed_trips` | decimal(5,0) | NOT NULL, DEFAULT 0 | |
| `driver_absences` | decimal(5,0) | NOT NULL, DEFAULT 0 | |
| `student_absences` | decimal(5,0) | NOT NULL, DEFAULT 0 | |
| `calculated_amount` | decimal(10,2) | NULL | |
| `action_taken` | varchar(50) | NOT NULL, DEFAULT `none` | |
| `payment_method` | varchar(50) | NULL | |
| `details` | json | NULL | |
| `paid_at` | timestamp | NULL | |
| `resolved_at` | timestamp | NULL | |
| `created_at` / `updated_at` | timestamp | NULL | |

---

## ملحق الدورة الثانية: خريطة العلاقات

```
requests ─┬─ pricing_settings (snapshot)
          ├─ request_children ──< active_subscriptions ── routes ──< route_stops
          └─ invoices

driver_seat_slots  ⋯ (لا FK) مرتبط منطقياً بـ vehicles.capacity_manual وحقول drivers.{morning_go,...}

wallets(Driver فقط) ⋯ financial_ledger (سجل الحركات الفعلي) ⋯ withdrawal_requests / recharge_requests
payment_methods ──< recharge_requests
```

---
---

# الدورة الثالثة — العمليات التشغيلية والمالية المتقدمة

## وظائف هذه الدورة

من تطبيق السائق: (1) الإدارة التشغيلية للرحلات والتتبع الحي — عرض رحلات اليوم، تفاصيل رحلة، بدء رحلة مع الموقع الحي، عرض الرحلة الحية ومحطاتها، تحديث الموقع لحظياً، إنهاء الرحلة بعد صمّام "لا طفل منسي". (2) إدارة ركاب الرحلة وسلامة الأطفال — توثيق صعود/نزول عبر QR، تسجيل غياب طفل، تخطي طفل غير مستجيب، تحديث حالة الطفل يدوياً. (3) إدارة الطوارئ — الإبلاغ عن عطل وطلب بديل، استئناف الرحلة، عرض/قبول/رفض مهام الإنقاذ الطارئة. (4) السجلات والجدولة — سجل الرحلات السابقة، تسجيل غياب السائق. (5) المالية ولوحة الأداء — رصيد المحفظة والمستحقات، طلب سحب، حالة السحب، الفواتير، إحصائيات الأداء.

من تطبيق ولي الأمر: (1) التتبع الحي — الرحلات النشطة الآن، تتبع المركبة لحظياً، التتبع الجماعي، تفاصيل رحلة وخط زمني، حالة الطفل لحظة بلحظة. (2) الجدولة والسجلات — الرحلات القادمة، سجل الرحلات، نظرة عامة على رحلات طفل. (3) الحضور والغياب — الأيام المتاحة، تسجيل غياب الطفل (ذهاب/عودة/كلاهما)، عرض الغيابات، إلغاء غياب مجدول. (4) الشكاوى والتظلمات — استعراض رحلات السائق، تقديم شكوى (بشرط وجود اشتراك)، عرض/تصفية الشكاوى، عرض التفاصيل، تعديل شكوى مفتوحة، إلغاء شكوى قيد الانتظار. (5) المالية — رصيد المحفظة وطرق الدفع، الشحن، الفواتير.

من لوحة التحكم: (1) الرقابة الشاملة — إحصائيات، رادار حي، تقارير. (2) الإعدادات والتدقيق المالي — التسعير والعمولة، طرق الدفع، الملخص المالي. (3) الرقابة والبتّ في الشكاوى — مراقبة الشكاوى، فحص التفاصيل، السجل السلوكي للسائق، البتّ (إنذار/إيقاف/حفظ)، إشعارات آلية. (4) العمليات المالية والخزينة — الفواتير، طلبات سحب السائقين، الأمانات المعلّقة وتحريرها.

من المهام المجدولة: توليد الرحلات اليومية آلياً، إصدار الفواتير النهائية وتسوية الاشتراكات يومياً، فحص الطلبات المعلقة وإلغاء غير القابل للتنفيذ منها.

## الجداول

### 🟢 `trips` — Trip (الرحلة اليومية)

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `driver_id` | int | NULL, FK → `drivers.id` | |
| `route_id` | int | NULL, FK → `routes.id` | |
| `trip_type` | varchar(50) | NOT NULL, DEFAULT `morning` | |
| `shift_slot` | varchar(50) | NULL | |
| `status` | varchar(50) | NOT NULL, DEFAULT `planned` | (planned/started/completed/suspended...) |
| `suspension_reason` | text | NULL | سبب تعليق الرحلة (عطل مركبة مثلاً) |
| `scheduled_at` | datetime | NULL | |
| `started_at` / `completed_at` | datetime | NULL | |
| `scheduled_start_time` / `actual_start_time` | time | NULL | |
| `start_lat` / `start_lng` | decimal | NULL | موقع بدء الرحلة الحي |
| `trip_date` | date | NULL | |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `trip_stops` — Trip_Stop (محطات الرحلة الفعلية)

نسخة تنفيذية من `route_stops` خاصة برحلة يوم واحد بعينه، بها حالة صعود/نزول كل طفل.

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `trip_id` | int | NOT NULL | بدون FK صريح بقاعدة البيانات |
| `route_stop_id` | int | NULL | المحطة الأصل في خط السير |
| `stop_type` | enum(`home`,`school`) | NOT NULL | |
| `child_id` | int | NULL | |
| `school_id` | int | NULL | |
| `lat` / `lng` | decimal | NULL | |
| `label` | varchar(255) | NULL | |
| `sequence_order` | int unsigned | NOT NULL, DEFAULT 0 | |
| `status` | enum(`pending`,`absent_pre`,`absent_late`,`boarded`,`dropped_off_school`,`delivered_home`,`skipped_unresponsive`,`dropoff_failed`,`direct_parent_handling`) | NOT NULL, DEFAULT `pending` | حالة الطفل عند هذه المحطة — تُطبَّق عليها وظائف: صعود QR، نزول، غياب، تخطّي بعد الانتظار |
| `reason` | varchar(255) | NULL | سبب الغياب/التخطي |
| `eta_minutes` / `eta` | int/time | NULL | الوقت التقديري للوصول |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `trip_events` — Trip_Event (سجل أحداث الصعود/النزول عبر QR)

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `trip_id` | int | NOT NULL, FK → `trips.id` | |
| `child_id` | int | NULL, FK → `children.id` | |
| `subscription_id` | int | NULL | بدون FK صريح بقاعدة البيانات |
| `action_type` | varchar(50) | NOT NULL, DEFAULT `picked_up` | نوع الحدث (صعود/نزول) |
| `trip_type` | varchar(50) | NOT NULL, DEFAULT `morning` | |
| `location_lat` / `location_lng` | decimal | NULL | موقع الحدث وقت المسح |
| `scanned_at` | timestamp | NOT NULL, DEFAULT CURRENT_TIMESTAMP | لحظة مسح رمز QR الخاص بالطفل |
| `trip_cost` | decimal(10,2) | NOT NULL, DEFAULT 0.00 | |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `trip_tracking` — Trip_Tracking (التتبع الحي لموقع المركبة)

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `trip_id` | int | NOT NULL, FK → `trips.id` | |
| `latitude` / `longitude` | decimal(10,7) | NOT NULL | |
| `speed` | decimal(5,2) | NULL | |
| `accuracy` | decimal(5,2) | NULL | |
| `recorded_at` | timestamp | NOT NULL | |

**فهرس:** `(trip_id, recorded_at)` — لسحب مسار الرحلة زمنياً بسرعة (أساس "التتبع الجماعي" و"الخط الزمني").

---

### 🟢 `driver_absences` — Driver_Absence (غياب السائق)

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `driver_id` | int | NOT NULL, FK → `drivers.id` | |
| `absence_date` | date | NOT NULL | |
| `reason` | varchar(500) | NULL | |
| `status` | varchar(30) | NOT NULL, DEFAULT `pending` | (pending/approved/rejected) |
| `reviewed_by` | int | NULL | بدون FK صريح بقاعدة البيانات |
| `reviewed_at` | timestamp | NULL | |
| `admin_notes` | text | NULL | |
| `created_at` / `updated_at` | timestamp | NULL | |

**فهرس:** `UNIQUE(driver_id, absence_date)`.

---

### 🟢 `driver_absence_trips` — Driver_Absence_Trip (الرحلات المتأثرة بالغياب)

جدول وسيط يربط طلب غياب السائق بالرحلات المحددة المتأثرة به.

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `driver_absence_id` | int | NOT NULL, FK → `driver_absences.id` | |
| `trip_id` | int | NOT NULL | بدون FK صريح بقاعدة البيانات |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `absence_logs` — Absence_Log (غياب الطفل)

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `child_id` | int | NOT NULL, FK → `children.id` | |
| `absence_date` | date | NOT NULL | |
| `absence_type` | enum(`pickup`,`dropoff`,`both`) | NOT NULL, DEFAULT `both` | (ذهاب/عودة/كلاهما) |
| `created_at` / `updated_at` | timestamp | NULL | |

**فهرس:** `UNIQUE(child_id, absence_date)` — يخدم أيضاً "إلغاء غياب مجدول" (تحديث/حذف نفس الصف).

---

### 🟢 `trip_breakdown_dispatches` — Trip_Breakdown_Dispatch (الطوارئ والإنقاذ)

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `trip_id` | int | NOT NULL | بدون FK صريح؛ الرحلة المُعطَّلة |
| `original_driver_id` | int | NOT NULL | السائق الأصلي |
| `substitute_driver_id` | int | NULL | السائق البديل (بعد القبول) |
| `substitute_trip_id` | int | NULL | الرحلة البديلة الناتجة |
| `status` | enum(`pending`,`broadcasted`,`accepted`,`declined_all`,`expired`,`unresolved`,`completed`,`cancelled`) | NOT NULL, DEFAULT `pending` | |
| `breakdown_lat` / `breakdown_lng` | decimal(10,7) | NULL | موقع العطل |
| `reason` | varchar(255) | NULL | |
| `stranded_children_ids` | json | NULL | الأطفال المتعثرون |
| `stranded_children_count` | smallint unsigned | NOT NULL, DEFAULT 0 | |
| `candidate_driver_ids` | json | NULL | السائقون المؤهلون المُرشَّحون لمهمة الإنقاذ |
| `rejected_driver_ids` | json | NULL | من رفض المهمة |
| `trip_fare_amount` | decimal(10,2) | NOT NULL, DEFAULT 0.00 | |
| `financial_settled` | tinyint(1) | NOT NULL, DEFAULT 0 | |
| `settled_at` / `dispatched_at` / `accepted_at` / `completed_at` / `expires_at` | timestamp | NULL | |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `complaints` — Complaint (شكاوى وليّ الأمر ضد السائق)

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `submitted_by` | int | NOT NULL, FK → `users.id` | مُقدِّم الشكوى (وليّ الأمر) |
| `against_type` | varchar(255) | NOT NULL | نوع الطرف المشتكى منه (نص حر، polymorphic بدون FK) |
| `against_id` | int | NOT NULL | |
| `driver_id` | int | NULL, FK → `drivers.id` | |
| `trip_id` | int | NULL, FK → `trips.id` | تحديد الرحلة المعنية بالشكوى |
| `description` | text | NOT NULL | |
| `status` | varchar(50) | NOT NULL, DEFAULT `pending` | (pending/completed/all — للتصفية) |
| `resolved_by` | int | NULL, FK → `users.id` | الأدمن الذي بتّ فيها |
| `resolution_note` | text | NULL | |
| `action_taken` | varchar(50) | NOT NULL, DEFAULT `none` | القرار النهائي: إنذار/إيقاف/حفظ |
| `action_details` | text | NULL | |
| `ai_action` | varchar(30) | NULL | تصنيف/توصية آلية (إن وُجدت) |
| `ai_confidence` | decimal(5,4) | NULL | |
| `ai_severity` | tinyint unsigned | NULL | |
| `ai_analysis_message` | text | NULL | |
| `resolved_at` | timestamp | NULL | |
| `created_at` / `updated_at` | timestamp | NULL | |

**قيود العمل (منطق، وليس عمود):** لا يمكن تقديم شكوى إلا بوجود اشتراك سابق/حالي بين وليّ الأمر والسائق. التعديل/الإلغاء مسموحان فقط طالما `status = pending` ولم تُتَّخذ إجراءات إدارية بعد.

---

### 🟢 `master_escrow_vault` — Master_Escrow_Vault (الخزينة المركزية — الأمانات المعلّقة)

جدول تجميعي (عادة صف واحد) لمجاميع الأموال المحجوزة على مستوى المنصة بالكامل. يخدم وظيفة "عرض الأمانات المعلّقة".

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `parents_escrow_pool` | int | NOT NULL, DEFAULT 0 | إجمالي أموال أولياء الأمور المحجوزة (لم تُحوَّل بعد) |
| `driver_pending_pool` | int | NOT NULL, DEFAULT 0 | مستحقات السائقين المعلّقة (لم تُتَح للسحب بعد) |
| `driver_available_pool` | int | NOT NULL, DEFAULT 0 | مستحقات السائقين المتاحة للسحب |
| `pending_withdrawal_pool` | int | NOT NULL, DEFAULT 0 | طلبات سحب قيد المعالجة |
| `platform_revenue_pool` | int | NOT NULL, DEFAULT 0 | إيراد المنصة المتراكم (العمولات) |
| `penalty_pool` | int | NOT NULL, DEFAULT 0 | غرامات محجوزة |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `trip_escrow_holds` — Trip_Escrow_Hold (حجز أمانة لكل رحلة)

التفصيل على مستوى كل رحلة لِما يقابل مجاميع `master_escrow_vault` — "تحرير الأمانات المستحقة" هو تغيير `hold_status` هنا مع تحديث المجاميع أعلاه.

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `trip_id` | int | NOT NULL | بدون FK صريح بقاعدة البيانات |
| `parent_id` | int | NOT NULL | بدون FK صريح بقاعدة البيانات |
| `driver_id` | int | NOT NULL | بدون FK صريح بقاعدة البيانات |
| `amount` | int | NOT NULL | بأصغر وحدة عملة |
| `hold_status` | varchar(255) | NOT NULL, DEFAULT `held` | (held/captured/disputed/released...) |
| `held_at` | timestamp | NOT NULL, DEFAULT CURRENT_TIMESTAMP | |
| `captured_at` / `available_at` / `disputed_at` | timestamp | NULL | |
| `created_at` / `updated_at` | timestamp | NULL | |

---

### 🟢 `platform_finances` — Platform_Finance (الحجز المالي لكل اشتراك)

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `subscription_request_id` | int | NULL, FK → `requests.id` | |
| `active_subscription_id` | int | NULL, FK → `active_subscriptions.id` | |
| `trip_id` | int | NULL | بدون FK صريح بقاعدة البيانات |
| `parent_id` | int | NOT NULL | بدون FK صريح بقاعدة البيانات |
| `driver_id` | int | NOT NULL | بدون FK صريح بقاعدة البيانات |
| `total_amount` | decimal(10,2) | NOT NULL | |
| `platform_commission_rate` | decimal(5,2) | NOT NULL, DEFAULT 8.00 | |
| `platform_commission_amount` | decimal(10,2) | NOT NULL, DEFAULT 0.00 | |
| `driver_net_amount` | decimal(10,2) | NOT NULL, DEFAULT 0.00 | |
| `expected_trips_count` | int unsigned | NOT NULL, DEFAULT 1 | |
| `settled_trips_count` | int unsigned | NOT NULL, DEFAULT 0 | |
| `settled_amount` | decimal(10,2) | NOT NULL, DEFAULT 0.00 | |
| `refunded_amount` | decimal(10,2) | NOT NULL, DEFAULT 0.00 | |
| `compensation_fee` | decimal(10,2) | NOT NULL, DEFAULT 0.00 | |
| `status` | varchar(255) | NOT NULL, DEFAULT `held` | (held/settled/refunded) |
| `held_at` | timestamp | NOT NULL, DEFAULT CURRENT_TIMESTAMP | |
| `settled_at` / `refunded_at` | timestamp | NULL | |
| `notes` | text | NULL | |
| `created_at` / `updated_at` | timestamp | NULL | |

**يخدم:** المهمة المجدولة "إصدار الفواتير النهائية وتسوية الاشتراكات المنتهية يومياً".

---

### 🟢 `platform_finance_trip_settlements` — Platform_Finance_Trip_Settlement (تسوية كل رحلة)

سطر تفصيلي لكل رحلة مُسوَّاة ضمن `platform_finances`.

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `platform_finance_id` | int | NOT NULL | بدون FK صريح بقاعدة البيانات |
| `trip_id` | int | NOT NULL | بدون FK صريح بقاعدة البيانات |
| `gross_amount` | decimal(10,2) | NOT NULL, DEFAULT 0.00 | |
| `commission_amount` | decimal(10,2) | NOT NULL, DEFAULT 0.00 | |
| `driver_net_amount` | decimal(10,2) | NOT NULL, DEFAULT 0.00 | |
| `created_at` / `updated_at` | timestamp | NULL | |

**فهرس:** `UNIQUE(platform_finance_id, trip_id)` — لا تُسوَّى نفس الرحلة مرتين لنفس الحجز.

---

## جداول مشتركة تُعاد الإشارة إليها فقط (تعريفها الكامل في الدورة الثانية)

| الجدول | الحقل | ملاحظة |
|---|---|---|
| `pricing_settings` | `id` | إدارة إعدادات التسعير والعمولة من لوحة التحكم — نفس الجدول والتعريف في الدورة الثانية |
| `payment_methods` | `id` | إدارة طرق الدفع من لوحة التحكم — نفس الجدول والتعريف في الدورة الثانية |
| `withdrawal_requests` | `id` | عرض/معالجة طلبات سحب السائقين من لوحة التحكم — نفس الجدول والتعريف في الدورة الثانية |
| `invoices` | `id` | عرض الفواتير الصادرة وتفاصيلها من لوحة التحكم — نفس الجدول والتعريف في الدورة الثانية |
| `routes` / `route_stops` | `id` | خط سير السائق الذي تتفرّع منه رحلات `trips`/`trip_stops` — نفس الجدول والتعريف في الدورة الثانية |
| `active_subscriptions` | `id` | الاشتراك الذي تُبنى عليه الرحلة والفاتورة — نفس الجدول والتعريف في الدورة الثانية |
| `requests` | `id` | الطلب الأصلي وراء الاشتراك/التسوية المالية — نفس الجدول والتعريف في الدورة الأولى/الثانية |
| `drivers` / `children` / `users` | `id` | أطراف الرحلة والشكوى والغياب — نفس الجداول والتعريف في الدورة الأولى |

---
---

# الدورة الرابعة — الذكاء الاصطناعي (تحليل النصوص، اتخاذ القرار، ترتيب السائقين)

هذا الجزء غير مرتبط بقائمة وظائف مرقّمة أرسلتموها كسابقاتها، بل هو تحليل مباشر لكود الذكاء الاصطناعي في المشروع. يتكوّن من **3 أجزاء فعلية + جزأين معطَّلين (كود ميت) يستحقان التوضيح لتجنّب الخلط.**

## الأجزاء الفعّالة (🟢 تعمل فعلياً في الإنتاج)

### أ) تحليل النصوص (NLP) — تصنيف تعليق وليّ الأمر

**المسار:** وليّ الأمر يترك تقييماً/تعليقاً على سائق (`driver_reviews`) ← يُستدعى [`ClassifyDriverReviewJob`](app/Jobs/ClassifyDriverReviewJob.php) (Job غير متزامن) ← [`ReviewClassifierService::classify()`](app/Services/Ai/ReviewClassifierService.php:50) يرسل نص التعليق إلى خدمة FastAPI خارجية (`ai-service/inference_api.py`, المنفذ `/classify`) ← نموذج **MARBERTv2** (Multi-task Transformer مُدرَّب على اللهجة، `ai-service/driver_reviews_model.pt`) يُعيد 3 تصنيفات من رأس شبكة واحد:
- **label** (المشاعر): `Positive` / `Negative` / `Neutral` / `Mixed` / `Irrelevant`.
- **severity** (الخطورة): 0، 1، أو 2 (2 = الأقصى).
- **category** (الفئة): `Safety` / `Punctuality` / `Behavior` / `Vehicle_Condition` / `General` / `Off_Topic`.
مع درجة ثقة (confidence) منفصلة للمشاعر وللفئة.

**وظائف هذا الجزء:**
1. استقبال نص التعليق وإرساله للنموذج عند إنشاء التقييم.
2. التحقق من صحة استجابة النموذج (تصنيفات مسموح بها فقط، وإلا تُرفَض النتيجة).
3. حفظ نتيجة التصنيف داخل نفس صف `driver_reviews` (`ai_label`, `ai_severity`, `ai_category`, `ai_sentiment_pred`, `ai_sentiment_confidence`, `ai_category_pred`, `ai_category_confidence`, `ai_classified_at`).

### ب) اتخاذ القرار الآلي تجاه السائق (Decision Layer)

**المسار:** بعد نجاح التصنيف مباشرة، [`ClassifyDriverReviewJob`](app/Jobs/ClassifyDriverReviewJob.php:64) يستدعي [`AiDecisionService::evaluateDriverDecision()`](app/Services/Ai/AiDecisionService.php:75) وهو محرك هجين (Hybrid):

1. **قفل صف السائق** (`lockForUpdate`) لمنع تضارب معالجة متزامنة، ومنع إعادة معالجة نفس التقييم (`is_processed_in_decision`).
2. **التعافي الذاتي (Self-Healing):** أي تحذير `MODERATE_VIOLATION`/`FORMAL_WARNING` مضى عليه 30 يوماً دون شكوى جديدة من نفس التصنيف يُعتبَر "مُتعافى منه" ويُخصَم من عداد `active_warnings_count` (تُسجَّل لحظة التعافي داخل `ai_decision_audits.action_details`).
3. **إحصائيات نافذة 15 يوماً:** حساب نسبة أولياء الأمور (مصادر **مختلفة**، `DISTINCT parent_id`) الذين تركوا تعليقاً سلبياً/إيجابياً خلال آخر 15 يوماً، وتوزيعها حسب الفئة.
4. **بناء 7 ميزات (Features)** من حالة السائق والتقييم الحالي، وإرسالها إلى FastAPI (`/decision/predict`) حيث يُشغَّل نموذج **XGBoost** (`ai-service/models/xgboost_decision_engine_87k.json`, مُدرَّب على ~87 ألف سجل) فيُعيد `decision_code` مبدئياً + احتمالات لكل الحالات الخمس.
5. **طبقة الحوكمة (Guardrails):** قواعد صريحة مكتوبة يدوياً في [`determineFinalDecision()`](app/Services/Ai/AiDecisionService.php:161) **تُرجَّح فوق تنبؤ XGBoost الخام** لضمان سيادة القرار البشري/المنطقي عند السلامة، وتحسم القرار النهائي من 5 حالات:

| الكود | الاسم | الشرط المُلخَّص | الأثر |
|---|---|---|---|
| 0 | `NO_ACTION` | ضمن الحدود الطبيعية | لا شيء |
| 1 | `REWARD` | إيجابي ≥ 80% من المصادر المختلفة ولا شكاوى سلامة نشطة | تقييم +3%، تخفيض تحذير واحد |
| 2 | `MODERATE_VIOLATION` | سلبي متكرر من ≥ 2 وليّ أمر مختلفين بنفس الفئة خلال 15 يوماً | تقييم −5%، حجب بحث مؤقت 24 ساعة، تنبيه أدمن HIGH |
| 3 | `FORMAL_WARNING` | نسبة السلبي ≥ 40% من المصادر المختلفة | تقييم −5%، إنذار رسمي، تنبيه أدمن HIGH |
| 4 | `ADMIN_REVIEW_REQUIRED` | فئة `Safety` سلبية، أو خطورة `severity=2` سلبية | تقييم −10%، حجب احترازي فوري 24 ساعة، تنبيه أدمن CRITICAL، **القرار النهائي (إيقاف/رفع) بيد الأدمن حصراً** |

6. **تنفيذ الأثر:** تحديث `drivers.rating_avg`، `active_warnings_count`، `suspended_until`، `last_incident_at`، وإنشاء صف في `admin_alerts` عند الحالتين 2 و4 (و3 أيضاً).
7. **إشعار فوري** للسائق عبر `NotificationService` بمضمون القرار.
8. **تسجيل تدقيق كامل** لكل قرار في `ai_decision_audits` (المدخلات + المخرجات + الاحتمالات + لقطة التقييم قبل/بعد).

### ج) لوحة إدارة سياسة الذكاء الاصطناعي (للأدمن)

عبر [`DriverAiPolicyController`](app/Http/Controllers/Api/Admin/DriverAiPolicyController.php):
9. عرض/تصفية تنبيهات AI (`alertsIndex`) حسب مستوى الخطورة/الحسم/السائق.
10. عرض تفاصيل تنبيه (`alertsShow`).
11. حسم تنبيه (`resolveAlert`).
12. عرض سجل تدقيق قرارات AI (`auditsIndex`) وتفاصيل قرار محدد (`auditsShow`).
13. **إعادة تأهيل سائق يدوياً** (`resetDriver`): تصفير نافذة 30 يوم، رفع الحجب المؤقت، تصفير عداد التحذيرات، إعادة الثقة (`is_trusted=true`)، وحسم كل تنبيهات AI المفتوحة لهذا السائق دفعة واحدة.

### د) ترتيب السائقين ضمن نتائج بحث وليّ الأمر (المُطبَّق فعلياً)

المسار الحقيقي المُستخدَم إنتاجياً هو [`DriverMatchingService::matchDrivers()`](app/Services/Parent/DriverMatchingService.php:44) — **وهو ترتيب بمعيار قاعدة بيانات بسيط (rating-based)، وليس نموذج تعلّم آلي منفصل للترتيب:**
14. فلترة أولية: السائق `Approved`/`Active`، `is_trusted=true`، رخصته سارية، وغير محجوب مؤقتاً (`suspended_until` منتهٍ أو فارغ) — أي أن **قرارات الذكاء الاصطناعي في الجزء (ب) تؤثر مباشرة على من يظهر أصلاً في نتائج البحث**.
15. فلترة نصية (اسم/هاتف) أو فلاتر ذكية: جنس السائق، تكييف المركبة، تطابق مزدوج للمناطق (منطقة سكن الطفل ومنطقة مدرسته يجب أن تقعا ضمن تغطية السائق)، وتوفر مقاعد فعلي عبر `driver_seat_slots` للفترة/الاتجاه المطلوبين.
16. **الترتيب (Ranking) الفعلي:** `ORDER BY drivers.rating_avg DESC, drivers.id DESC` ثم `paginate(15)`.
17. حساب تسعير تقديري لكل سائق ظاهر بالنتائج (مسافة كل طفل عبر OSRM أو Haversine كـfallback، ثم تطبيق `pricing_settings` والخصومات).

## تنويهات على كود غير مفعّل (لتفادي الخلط عند القراءة)

**🔴 مسار "ترتيب بالتعلّم الآلي" منفصل وغير عامل:** يوجد [`DriverRecommendationController::getRankedDrivers()`](app/Http/Controllers/DriverRecommendationController.php:11) يستدعي سكريبت بايثون خارجي (`python/predict.py`) مفترَض أن يُشغّل نموذج **LightGBM Ranker** محفوظاً في `python/models/darbi_lgb_ranker_robust.txt`. لكن بالفحص المباشر: **(أ)** لا يوجد أي `route` في `routes/*.php` يستدعي هذا الكنترولر إطلاقاً، و**(ب)** ملف `python/predict.py` **فارغ تماماً (0 بايت)**. أي أن هذا مسار غير مكتمل/مهجور، ولا يُستخدَم في ترتيب السائقين الفعلي حالياً — الترتيب الحقيقي هو البند (د) أعلاه فقط.

**🔴 محرك قرار أقدم غير مستدعى:** [`DriverPolicyEngine`](app/Services/Ai/DriverPolicyEngine.php) هو نسخة أقدم من منطق القرار (قواعد نسب على مستوى الأطفال النشطين لا أولياء الأمور، نافذة 30 يوماً بلا XGBoost ولا `ai_decision_audits`)، لكنه **غير مُستدعى من أي مكان في الكود الحالي** — استُبدل عملياً بـ`AiDecisionService`. مُذكور هنا فقط لتفادي الخلط بينه وبين المحرك الفعلي.

---

## الجداول

### 🟢 `driver_reviews` — Driver_Review (تقييم/تعليق وليّ أمر على سائق)

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `subscription_request_id` | int | NULL, FK → `requests.id` | الطلب/الاشتراك الذي بُني عليه التقييم |
| `parent_id` | int | NOT NULL, FK → `users.id` | كاتب التعليق |
| `driver_id` | int | NOT NULL, FK → `drivers.id` | السائق المُقيَّم |
| `rating` | tinyint | NOT NULL | تقييم رقمي (1–5) |
| `comment` | text | NULL | النص الذي تُحلَّله خدمة NLP |
| `ai_label` | varchar(20) | NULL | ناتج النموذج: `Positive`/`Negative`/`Neutral`/`Mixed`/`Irrelevant` |
| `ai_category` | varchar(30) | NULL | ناتج النموذج: `Safety`/`Punctuality`/`Behavior`/`Vehicle_Condition`/`General`/`Off_Topic` |
| `ai_severity` | tinyint unsigned | NULL | خطورة 0–2 |
| `ai_classified_at` | timestamp | NULL | لحظة تصنيف NLP |
| `ai_sentiment_pred` | tinyint | NULL | فهرس تصنيف المشاعر الخام (0/1/2) — يُستخدم كميزة إدخال لنموذج القرار |
| `ai_sentiment_confidence` | decimal(4,2) | NULL | ثقة تصنيف المشاعر |
| `ai_category_pred` | tinyint | NULL | فهرس تصنيف الفئة الخام (0–4) — ميزة إدخال لنموذج القرار |
| `ai_category_confidence` | decimal(4,2) | NULL | ثقة تصنيف الفئة |
| `ai_decision_code` | tinyint | NULL | نتيجة محرك القرار (0–4) المُطبَّقة على هذا التعليق |
| `ai_decision_confidence` | decimal(4,2) | NULL | ثقة القرار النهائي (بحد أدنى 0.90 دوماً، انظر `AiDecisionService`) |
| `is_processed_in_decision` | tinyint(1) | NOT NULL, DEFAULT 0 | يمنع إعادة معالجة نفس التعليق مرتين بمحرك القرار |
| `is_flagged_malicious` | tinyint(1) | NOT NULL, DEFAULT 0 | 🟡 عمود موجود في `$fillable` لكن غير مُعبَّأ من أي مسار AI حالياً (محجوز غالباً لميزة كشف تعليقات كيدية مستقبلية أو وضع يدوي من الأدمن) |
| `status` | varchar(20) | NOT NULL, DEFAULT `active` | |
| `created_at` / `updated_at` | timestamp | NULL | |
| `deleted_at` | timestamp | NULL | Soft delete |

**🟡 أعمدة موجودة في القاعدة لكنها غير مُستخدمة إطلاقاً بالكود الحالي (لا في الموديل ولا في أي خدمة):** `ai_action` (varchar 30)، `ai_confidence` (decimal 5,4)، `ai_analysis_message` (text). يبدو أنها نُسخت وقت التصميم من نفس أعمدة جدول `complaints` (الدورة الثالثة) لكن لم تُفعَّل هنا؛ لا تعتمدوا على قيمتها.

---

### 🟢 `ai_decision_audits` — Ai_Decision_Audit (سجل تدقيق قرارات الذكاء الاصطناعي)

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `driver_id` | int | NOT NULL, FK → `drivers.id` | |
| `review_id` | int | NULL, FK → `driver_reviews.id` | التعليق الذي أطلق هذا القرار |
| `current_rating` | decimal(3,2) | NOT NULL | تقييم السائق وقت اتخاذ القرار (قبل التطبيق) — إحدى الميزات السبع |
| `previous_warnings` | int | NOT NULL, DEFAULT 0 | عدد التحذيرات السابقة (محدود بـ 10) — ميزة |
| `trips_count` | int | NOT NULL, DEFAULT 0 | عدد رحلات السائق (بحد أدنى 10 لتفادي التحيّز لسائق جديد) — ميزة |
| `sentiment_pred` | tinyint | NOT NULL, DEFAULT 1 | ميزة: فهرس المشاعر |
| `sentiment_confidence` | decimal(4,2) | NOT NULL, DEFAULT 0.00 | ميزة |
| `category_pred` | tinyint | NOT NULL, DEFAULT 0 | ميزة: فهرس الفئة |
| `category_confidence` | decimal(4,2) | NOT NULL, DEFAULT 0.00 | ميزة |
| `decision_code` | tinyint | NOT NULL | 0–4 (انظر جدول الحالات أعلاه) |
| `decision_name` | varchar(50) | NOT NULL | الاسم النصي للقرار |
| `decision_confidence` | decimal(4,2) | NOT NULL, DEFAULT 0.00 | |
| `probabilities` | json | NULL | احتمالات XGBoost الخام لكل الحالات الخمس |
| `action_applied` | varchar(100) | NOT NULL | قائمة نصية بالإجراءات المُطبَّقة (مفصولة بفواصل) |
| `action_details` | json | NULL | تفاصيل كاملة: التقييم قبل/بعد، نص التعليق، إحصائيات النافذة، ولاحقاً لحظة/سبب التعافي الذاتي إن حدث |
| `suspended_until` | timestamp | NULL | لقطة حجب السائق وقت كتابة السجل |
| `admin_override` | tinyint(1) | NOT NULL, DEFAULT 0 | هل تجاوز الأدمن هذا القرار يدوياً |
| `admin_id` | int | NULL, FK → `users.id` | الأدمن (إن وُجد تجاوز يدوي) |
| `created_at` | timestamp | NULL | لحظة اتخاذ القرار — الأساس الزمني لقاعدة "التعافي الذاتي 30 يوماً" |
| `updated_at` | timestamp | NULL | |

---

### 🟢 `admin_alerts` — Admin_Alert (تنبيهات الأدمن الناتجة عن الذكاء الاصطناعي)

| الحقل | النوع | القيود | الوصف |
|---|---|---|---|
| `id` | int | PK, auto_increment | |
| `driver_id` | int | NULL, FK → `drivers.id` | |
| `risk_level` | varchar(20) | NOT NULL, DEFAULT `NONE` | `HIGH` أو `CRITICAL` عملياً من محرك القرار |
| `actions_taken` | json | NULL | |
| `admin_message` | text | NULL | |
| `reasoning` | text | NULL | |
| `ai_metrics` | json | NULL | |
| `evaluated_reviews` | json | NULL | |
| `is_resolved` | tinyint(1) | NOT NULL, DEFAULT 0 | يُغلَق عبر `resolveAlert` أو `resetDriver` |
| `alert_type` | varchar(50) | NOT NULL, DEFAULT `info` | `ai_moderate_violation` / `ai_formal_warning` / `ai_admin_review_required` (المحرك الحالي)، أو `ai_critical`/`ai_warning` (من `DriverPolicyEngine` القديم غير المُستدعى حالياً) |
| `title` | varchar(255) | NOT NULL | |
| `message` | text | NOT NULL | |
| `severity` | tinyint unsigned | NOT NULL, DEFAULT 1 | 1=عادي … 3=حرج |
| `action_required` | varchar(255) | NULL | `notify` أو `review` |
| `metadata` | json | NULL | تفاصيل خام: `review_id`, `category`, `rating_before/after`, `window_stats`... |
| `is_read` | tinyint(1) | NOT NULL, DEFAULT 0 | |
| `created_at` / `updated_at` | timestamp | NULL | |

---

## جداول مشتركة تُعاد الإشارة إليها فقط

| الجدول | الحقل | ملاحظة |
|---|---|---|
| `drivers` | `id` | كل تأثيرات القرار الآلي (`rating_avg`, `active_warnings_count`, `suspended_until`, `last_incident_at`, `ai_last_reset_at`) هي أعمدة على نفس صف السائق المُعرَّف بالكامل في الدورة الأولى |
| `users` | `id` | كاتب التعليق (`parent_id`) والأدمن المُتجاوِز (`admin_id`) — التعريف الكامل في الدورة الأولى |
| `requests` | `id` | أساس ربط التقييم بالاشتراك — التعريف الكامل في الدورة الثانية |
| `vehicles` | `id` | يُستخدم في فلترة "تكييف" ضمن `DriverMatchingService` — التعريف الكامل في الدورة الأولى |
| `driver_seat_slots` | `id` | يُستخدم في فلترة توفر المقاعد ضمن `DriverMatchingService` — التعريف الكامل في الدورة الثانية |
| `pricing_settings` | `id` | يُستخدم لحساب السعر التقديري لكل سائق ظاهر بنتائج الترتيب — التعريف الكامل في الدورة الثانية |
| `zones` | `id` | أساس "التطابق الجغرافي المزدوج" (منطقة سكن + منطقة مدرسة داخل تغطية السائق) — التعريف الكامل في الدورة الأولى |

---

## ملحق: تسلسل تدفّق الذكاء الاصطناعي (Pipeline)

```
وليّ الأمر يكتب تعليقاً
        │
        ▼
  driver_reviews (رأي أولي: rating + comment)
        │  ClassifyDriverReviewJob::dispatch()
        ▼
ReviewClassifierService.classify()  ──HTTP──▶  FastAPI /classify  ──▶  MARBERTv2
        │ (label, severity, category, confidences)
        ▼
  driver_reviews.ai_* تُحدَّث
        │
        ▼
AiDecisionService.evaluateDriverDecision()
   ├─ applySelfHealing()                 (يقرأ/يحدّث ai_decision_audits + drivers.active_warnings_count)
   ├─ getWindowStats() (15 يوماً)        (يقرأ driver_reviews)
   ├─ buildFeatures() + callDecisionApi() ──HTTP──▶ FastAPI /decision/predict ──▶ XGBoost
   ├─ determineFinalDecision()           (قواعد الحوكمة تُرجَّح فوق تنبؤ XGBoost)
   ├─ apply{Reward|ModerateViolation|FormalWarning|AdminReviewRequired}()
   │     └─ drivers.{rating_avg, active_warnings_count, suspended_until, last_incident_at}
   │     └─ admin_alerts (عند الحالات 2/3/4)
   ├─ notifyDriver()                     (إشعار فوري)
   └─ writeAudit()                       → ai_decision_audits

الأدمن (DriverAiPolicyController): alertsIndex/Show/resolveAlert ، auditsIndex/Show ، resetDriver
        │
        ▼
درايفر ماتشينج (بحث وليّ الأمر لاحقاً):
DriverMatchingService.matchDrivers()
   ├─ يستبعد أي سائق status≠Approved/Active، is_trusted=false، أو suspended_until سارٍ  ← أثر مباشر لقرارات AI أعلاه
   ├─ فلاتر جنس/تكييف/منطقة مزدوجة/توفر مقاعد (driver_seat_slots)
   └─ ORDER BY rating_avg DESC, id DESC   ← الترتيب الفعلي المُطبَّق (وليس نموذج تعلّم آلي)
```
