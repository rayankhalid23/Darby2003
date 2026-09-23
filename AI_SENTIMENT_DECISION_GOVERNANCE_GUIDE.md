# 🤖 دليل مرحلة النشر: نموذج تحليل المشاعر + محرك اتخاذ القرار + طبقة الحوكمة

هذا التوثيق يغطي المسار الكامل الذي يمر به تعليق/تقييم ولي الأمر عن السائق، من لحظة إرساله عبر التطبيق وحتى تنفيذ القرار الآلي (زيادة تقييم، إنقاص تقييم، تنبيه، تنبيه إيقاف، أو دون إجراء)، بالإضافة إلى 3 اختبارات **واقعية تم تنفيذها فعلاً والتحقق منها على قاعدة بيانات التطوير**.

> ⚠️ **ملاحظة مهمة جداً**: المسار الحي المستخدم فعلياً هو جدول `driver_reviews` عبر `POST /api/parent/driver-reviews`. جداول `complaints` و `trip_disputes` تحتوي على أعمدة AI قديمة (`ai_action`, `ai_confidence`...) لكنها **غير مفعّلة وغير مستخدمة في الكود الحالي** — لا تُستخدم في أي اختبار أو تكامل جديد.

---

## 1. نظرة عامة على المعمارية

```
ولي الأمر (Parent)
   │  POST /api/parent/driver-reviews   { driver_id, rating, comment }
   ▼
DriverReviewController::store()
   │  ينشئ سجل DriverReview (status = active)
   ▼
ClassifyDriverReviewJob (Queue Job)
   │
   ├─ 1) ReviewClassifierService → FastAPI (ai-service) POST /classify
   │        نموذج MARBERTv2 (تحليل مشاعر + خطورة + تصنيف الشكوى)
   │
   ├─ 2) حفظ نتائج NLP على DriverReview
   │        (ai_label, ai_category, ai_severity, ai_sentiment_confidence...)
   │
   └─ 3) AiDecisionService::evaluateDriverDecision()
            a. applySelfHealing()      — تعافي تلقائي للإنذارات القديمة (30 يوماً)
            b. getWindowStats()        — إحصائيات آخر 15 يوماً (تعدد أولياء الأمور، لسقف الأمان فقط)
            c. buildFeatures() → FastAPI POST /decision/predict (XGBoost)
            d. determineFinalDecision() ← 🏛️ سقف الأمان (3 قواعد ثابتة تعلو قرار النموذج)
            e. تطبيق القرار: تعديل rating_avg / active_warnings_count / suspended_until
            f. AdminAlert (فقط للحالات HIGH/CRITICAL)
            g. إشعار للسائق (NotificationFormatter::TYPE_DRIVER_AI_ALERT)
            h. writeAudit() → جدول ai_decision_audits (سجل تدقيق كامل)
```

**نقطة جوهرية في التصميم (محدّثة)**: منذ إزالة طبقة الحوكمة القائمة على النسب، قرار XGBoost الخام (`/decision/predict`) **هو القرار الفعلي المُطبَّق على السائق** في كل الحالات. الاستثناء الوحيد: 3 قواعد "سقف أمان" ثابتة في `determineFinalDecision()` تتجاوز قرار النموذج، لأنها تعتمد على بيانات لا يستقبلها النموذج أصلاً (تعدد المصادر عبر نافذة 15 يوماً) — انظر §4. الإيقاف **النهائي/الدائم** يبقى دائماً بيد الأدمن حصراً (§4، سيادة القرار البشري)؛ الأدمن يتلقى تنبيهاً فورياً (`AdminAlert`) في كل حالة يُطبَّق فيها إيقاف مؤقت (تلقائي، من AI أو من سقف الأمان)، ليقرر هو الإجراء النهائي.

---

## 2. نموذج تحليل المشاعر (Sentiment Model)

- **الخدمة**: `ai-service/inference_api.py` — FastAPI، منفذ (port) **8001**، اسم الخدمة "Darby AI Service v2.0".
- **النموذج**: `MARBERTv2` (UBC-NLP) بطبقة Multi-Task مخصصة تُخرج 3 تصنيفات من نفس النص دفعة واحدة.

**Endpoint**: `POST /classify`
```json
// Request
{ "text": "السائق كان يستخدم الهاتف ويقود بسرعة جنونية..." }

// Response
{
  "label": "Positive | Negative | Neutral | Mixed | Irrelevant",
  "severity": 0 | 1 | 2,
  "category": "Behavior | General | Off_Topic | Punctuality | Safety | Vehicle_Condition",
  "sentiment_confidence": 0.0-1.0,
  "category_confidence": 0.0-1.0,
  "sentiment_pred": <int>,
  "category_pred": <int>
}
```

- **الغلاف في Laravel**: `App\Services\Ai\ReviewClassifierService::classify()` — يستدعي الخدمة ويتحقق من صحة القيم المُرجعة قبل حفظها.
- **الإعدادات**: `config/services.php` → `services.ai_classifier.base_url` (env: `AI_CLASSIFIER_BASE_URL`, افتراضي `http://127.0.0.1:8001`), `.endpoint` (`/classify`), `.timeout` (15 ثانية في `.env` الحالي).

---

## 3. محرك اتخاذ القرار (Decision Engine)

- **النموذج**: XGBoost مُدرّب على 87 ألف حالة (`xgboost_decision_engine_87k.json`).
- **Endpoint**: `POST /decision/predict` على نفس خدمة FastAPI.
- **المدخلات (7 خصائص)** تُبنى في `AiDecisionService::buildFeatures()`:

| الخاصية | المصدر |
|---|---|
| `current_rating` | `driver.rating_avg` |
| `previous_warnings` | `driver.active_warnings_count` (سقف 10) |
| `trips_count` | `max(10, عدد رحلات السائق)` |
| `sentiment_pred` | من تصنيف NLP (إعادة ترميز داخلي) |
| `sentiment_confidence` | من تصنيف NLP |
| `category_pred` | من تصنيف NLP (إعادة ترميز داخلي) |
| `category_confidence` | من تصنيف NLP |

- **المخرجات**: `decision_code` (0-4)، `decision_name`، `confidence`، `probabilities` — تُحفظ في التدقيق فقط، ولا تحدد القرار النهائي مباشرة (انظر §4).

---

## 4. سقف الأمان (Safety Ceiling) — `AiDecisionService::determineFinalDecision()`

> ⚠️ **تحديث معماري**: طبقة الحوكمة القائمة على عتبات النسب (40%/80%) أُزيلت. القرار الفعلي الآن هو **قرار نموذج XGBoost الخام مباشرة** في كل الحالات، إلا ثلاث حالات "سقف أمان" ثابتة تتجاوزه — لأنها تعتمد على معلومات لا يستقبلها النموذج أصلاً كخصائص (تعدد المصادر عبر نافذة 15 يوماً). هذه القواعد مكتوبة صراحة في `app/Services/Ai/AiDecisionService.php`:

| # | الشرط | يتجاوز قرار النموذج بـ | لماذا (النموذج لا يرى هذه المعلومة) |
|---|---|---|---|
| 1 | شكوى سلبية بتصنيف **Safety** *أو* خطورة (`severity=2`) على التعليق الحالي | **4** `ADMIN_REVIEW_REQUIRED` دائماً | — (هذه معلومة موجودة أصلاً بخصائص النموذج، لكنها حُوّلت لسقف صريح لضمانها 100%) |
| 2 | نفس تصنيف الشكوى تكرر من **2 ولي أمر مختلفين على الأقل** خلال 15 يوماً | **2** `MODERATE_VIOLATION` دائماً | النموذج يرى تعليقاً واحداً فقط، لا "كم شخص اشتكى نفس الشي" |
| 3 | بلاغ **Safety** نشط (غير محلول) ضمن نافذة 15 يوماً، والنموذج رشّح مكافأة | يُحوَّل **1** `REWARD` ← **0** `NO_ACTION` | النموذج لا يعرف أن فيه بلاغ سلامة سابق عالق لنفس السائق |

**كل حالة غير هذي الثلاث** (بما فيها REWARD وFORMAL_WARINING وNO_ACTION وMODERATE_VIOLATION خارج شرط التكرار) → **قرار النموذج كما هو، بلا أي تعديل**.

**التنفيذ الفعلي لكل قرار** (`applyReward` / `applyFormalWarning` / `applyModerateViolation` / `applyAdminReviewRequired`):

| القرار | الأثر على التقييم | إجراء إضافي |
|---|---|---|
| `REWARD` | ×1.03 (+3%) | إنقاص إنذار سابق واحد إن وجد |
| `FORMAL_WARNING` | ×0.95 (−5%) | `active_warnings_count +1` + تنبيه إداري **HIGH** (بلا إيقاف بحث) |
| `MODERATE_VIOLATION` | ×0.95 (−5%) | إخفاء 24 ساعة من نتائج البحث + تنبيه إداري **HIGH** |
| `ADMIN_REVIEW_REQUIRED` | ×0.90 (−10%) | إخفاء 24 ساعة من نتائج البحث + تنبيه إداري **CRITICAL** |
| `NO_ACTION` | بدون تغيير | لا شيء |

الخفض/الحجب يُطبَّقان **فوراً** مع التنبيه، وليسا معلَّقين بانتظار الأدمن — الأدمن يتلقى التنبيه ليقرر الإجراء **النهائي** (رفع الإيقاف، تثبيته دائماً، أو غير ذلك)، لا ليوافق على كل قرار مؤقت.

**ملاحظات مهمة**:
- "تعدد أولياء الأمور" (سقف الأمان #2 و#3) يُحسب على **آخر تعليق لكل ولي أمر مختلف** خلال نافذة 15 يوماً (`getWindowStats()`)، وليس على كل التعليقات — هذا يمنع ولي أمر واحد من التلاعب بإرسال عدة تعليقات.
- **تعافي ذاتي (Self-Healing)**: أي إنذار (`MODERATE_VIOLATION`/`FORMAL_WARNING`) يمر عليه 30 يوماً دون شكوى جديدة من نفس التصنيف يُزال تلقائياً وينقص من `active_warnings_count` (`applySelfHealing()`).
- **سيادة القرار البشري**: أقصى ما يمكن للـ AI فعله هو **إخفاء مؤقت لمدة 24 ساعة** (`suspended_until`) — الإيقاف الدائم الفعلي (`user.is_active=false`, `driver.status=Suspended`) **حصراً بيد الأدمن** عبر `POST /api/admin/drivers/{id}/suspend` (صلاحية `drivers.suspend`)، وله سجل تدقيق منفصل تماماً عبر `AdminAuditLogService`.
- **مراجعة/حل التنبيهات الإدارية**: `GET /api/admin/ai-alerts`, `POST /api/admin/ai-alerts/{id}/resolve`.
- **سجل التدقيق الكامل**: `GET /api/admin/ai-audits` (جدول `ai_decision_audits` — يحفظ كل الخصائص السبعة، الاحتمالات الكاملة، والحالة قبل/بعد).
- **إعادة تأهيل السائق يدوياً**: `POST /api/admin/drivers/{driverId}/ai-reset` — يصفّر الإنذارات ويُلغي الإيقاف المؤقت ويحل تنبيهات AI المفتوحة لهذا السائق.

### جدول الحدود (Thresholds) المتبقية

| الحد | القيمة | أين |
|---|---|---|
| نافذة تعدد المصادر (سقف الأمان #2/#3) | 15 يوماً | `WINDOW_DAYS` |
| نافذة التعافي الذاتي | 30 يوماً | `SELF_HEALING_DAYS` |
| تكرار نفس التصنيف لسقف الأمان #2 | ≥ 2 أولياء أمور مختلفين | inline |
| مضاعف المكافأة | ×1.03 | `REWARD_FACTOR` |
| مضاعف التنبيه | ×0.95 | `WARNING_FACTOR` |
| مضاعف المراجعة الإدارية | ×0.90 | `ADMIN_REVIEW_FACTOR` |
| مدة الإخفاء المؤقت (مخالفة متوسطة / مراجعة إدارية) | 24 ساعة | inline |
| الحد الأقصى للطلبات (Rate Limit) | 10 تعليقات/ساعة لكل ولي أمر | `RateLimiter::for('reviews', ...)` |

> عتبتا النسب القديمتان (`REWARD_THRESHOLD` = 80%، `WARNING_THRESHOLD` = 40%) حُذفتا من الكود بالكامل — القرار في نطاقهما السابق صار لنموذج XGBoost مباشرة.

كل القيم أعلاه **ثوابت مكتوبة في الكود** (`AiDecisionService.php`) وليست قابلة للتعديل عبر `.env`.

---

## 5. عقد الـ API لإرسال شكوى/تقييم

**الخطوة 1 — تسجيل الدخول**
```
POST /api/auth/login
{ "email": "...", "password": "...", "device_name": "..." }
→ { "access_token": "<sanctum token>", ... }
```

**الخطوة 2 — إرسال التقييم/الشكوى**
```
POST /api/parent/driver-reviews
Authorization: Bearer <access_token>
{ "driver_id": <int>, "rating": 1-5, "comment": "<نص عربي>" }
```

**شرط الأهلية (Validation)** — `StoreDriverReviewRequest`:
- `driver_id`: موجود في جدول `drivers`.
- **يجب أن يكون لدى ولي الأمر اشتراك فعّال (active) أو مكتمل (completed) مع هذا السائق تحديداً** — يتحقق منه `SubscriptionRequestService::parentHasActiveOrCompletedSubscriptionWithDriver()`. بدون اشتراك، الطلب يُرفض بخطأ تحقق (422) قبل أن يصل لأي منطق AI.
- `rating`: عدد صحيح 1-5.
- `comment`: نص اختياري حتى 2000 حرف — **إذا كان فارغاً لا يتم استدعاء AI إطلاقاً** (الـ Job لا يُطلق).

**الخطوة 3 — رصد النتيجة**:
- `GET /api/parent/driver-reviews/driver/{driverId}` (كولي أمر).
- `GET /api/admin/ai-audits?driver_id={id}` (كأدمن — سجل القرار الكامل).
- `GET /api/admin/ai-alerts?driver_id={id}` (تنبيهات HIGH/CRITICAL فقط).

> ملاحظة تقنية: الإرسال عبر `POST /api/parent/driver-reviews` يضع المهمة في **طابور (Queue)** (`ClassifyDriverReviewJob::dispatch`) — تحتاج `php artisan queue:work` شغّالاً لتنفيذها، أو تُنفَّذ فوراً بأمر `dispatchSync()` كما في الاختبارات أدناه.

---

## 6. الاختبارات الواقعية الثلاثة — تم تنفيذها والتحقق منها بنجاح ✅

نُفّذت الاختبارات الثلاثة التالية **فعلياً** على قاعدة بيانات التطوير (`school_transport_v2`)، باستخدام بيانات ولي أمر/سائق حقيقيين لديهما اشتراك فعّال (شرط الأهلية محقق)، ومرّت بنفس الكود الذي يُنفَّذه `DriverReviewController::store` تماماً (إنشاء `DriverReview` ثم `ClassifyDriverReviewJob`)، مع استدعاء **حي وفعلي** لخدمة الذكاء الاصطناعي (MARBERTv2 + XGBoost على المنفذ 8001) — وليس بيانات وهمية (mock).

بعد التحقق من نجاح الحالات الثلاث، **تم حذف كل البيانات التجريبية (التقييمات، سجلات التدقيق، التنبيهات الإدارية، الإشعارات) وإرجاع تقييم السائقين للقيمة الأصلية**، بحيث قاعدة البيانات نظيفة تماماً ويمكنكم إعادة تنفيذ هذه الاختبارات بأنفسكم بنفس الخطوات لرؤية نفس النتائج.

### الحالة 1: تنبيه إيقاف + مراجعة إدارية (ADMIN_REVIEW_REQUIRED)

| | |
|---|---|
| **ولي الأمر** | user id = **2016** — خالد إبراهيم محمد الككلي (`parent3@darby.ly`) |
| **السائق** | driver id = **917** — سالم إدريس محمد الزنتاني (اشتراك فعّال موجود) |
| **التقييم** | `rating = 1` |
| **نص الشكوى** | *"السائق كان يستخدم الهاتف ويقود بسرعة جنونية ولم يربط حزام الأمان لابنتي، كدنا نتعرض لحادث خطير جدا"* |
| **لماذا هذا النص؟** | شكوى سلامة صريحة (استخدام هاتف + سرعة + عدم ربط حزام) → متوقع تصنيف `category=Safety` و/أو `severity=2`، وهذا يُفعّل المستوى الثالث من الحوكمة فوراً بلا شرط نسبة. |

**النتيجة الفعلية المرصودة**:
- تصنيف NLP: `label=Negative`, `category=Safety`, `severity=2`, `sentiment_confidence=0.97`
- القرار: `decision_code=4` → **ADMIN_REVIEW_REQUIRED**
- التقييم: `4.50 → 4.05` (خصم 10%)
- `suspended_until` تم ضبطه لـ +24 ساعة (إخفاء مؤقت من البحث)
- `AdminAlert` أُنشئ بمستوى خطورة **CRITICAL** (`alert_type = ai_admin_review_required`)
- إشعار أُرسل للسائق: "⛔ إشعار عاجل — حسابك قيد المراجعة الإدارية"
- سجل تدقيق (`ai_decision_audits`): `action_applied = hidden_from_search_precautionary, rating_down_10pct, admin_review_required, admin_alerted_critical`

---

### الحالة 2: زيادة تقييم (REWARD)

| | |
|---|---|
| **ولي الأمر** | user id = **2018** — إدريس المبروك سالم القبلاوي (`parent5@darby.ly`) |
| **السائق** | driver id = **916** — أشرف الطيب محمود العبيدي (اشتراك فعّال موجود) |
| **التقييم** | `rating = 5` |
| **نص الشكوى/الملاحظة** | *"السائق ممتاز جدا، محترم، يلتزم بالمواعيد ويهتم براحة وسلامة ابني كثيرا، شكرا له"* |
| **لماذا هذا النص؟** | تعليق إيجابي صريح وواضح، ولا توجد أي شكوى سلامة سابقة نشطة لهذا السائق ضمن نافذة الـ 15 يوماً (سائق نظيف السجل) → يحقق شرط المكافأة (نسبة إيجابية ≥ 80% ولا شكوى سلامة نشطة). |

**النتيجة الفعلية المرصودة**:
- تصنيف NLP: `label=Positive`, `severity=0`, `sentiment_confidence=0.98`
- القرار: `decision_code=1` → **REWARD**
- التقييم: `4.25 → 4.38` (زيادة 3%)
- لا إيقاف، لا تنبيه إداري جديد (المكافأة لا تُنشئ `AdminAlert` أصلاً — التنبيهات فقط للحالات HIGH/CRITICAL)
- سجل تدقيق: `action_applied = rating_up_3pct, warnings_reduced, driver_rewarded`

> ⚠️ ملاحظة أثناء الاختبار: النموذج صنّف تصنيف الفئة (`category`) لهذا النص كـ `Safety` رغم أن المشاعر `Positive` — هذا **لا يُفعّل** قاعدة "شكوى سلامة نشطة" لأن تلك القاعدة تُحسب فقط من التعليقات **السلبية** (`getWindowStats()` يضيف للفئة فقط عند `label=negative`)، لذلك لم يتأثر قرار المكافأة. هذا سلوك مصمم بشكل صحيح، لكنه يستحق الانتباه إذا لاحظتم تصنيف فئة غير متوقع على تعليقات إيجابية.

---

### الحالة 3: تنبيه رسمي (FORMAL_WARNING)

| | |
|---|---|
| **ولي الأمر** | user id = **2019** — ناصر السنوسي عبدالله الفاخري (`parent6@darby.ly`) |
| **السائق** | driver id = **914** — محمد الهادي سالم بن يونس (اشتراك فعّال موجود) |
| **التقييم** | `rating = 2` |
| **نص الشكوى** | *"السائق يتأخر كثيرا عن الموعد المحدد كل يوم تقريبا ولا يخبرنا مسبقا بالتأخير"* |
| **لماذا هذا النص؟** | شكوى سلبية لكنها **ليست** سلامة (Punctuality وليس Safety) وخطورتها متوسطة (severity=1 وليس 2) → لا تُفعّل المستوى الثالث. ولأنها أول شكوى من ولي أمر واحد فقط (نسبة سلبية = 100% ≥ 40%) ولا يوجد تكرار من ولي أمر ثانٍ لنفس الفئة → تقع بالضبط عند حد "تنبيه رسمي" وليس "تنبيه إيقاف مؤقت" (الذي يتطلب ولي أمر ثانٍ مختلف بنفس فئة الشكوى). |

**النتيجة الفعلية المرصودة**:
- تصنيف NLP: `label=Negative`, `category=Punctuality`, `severity=1`, `sentiment_confidence=0.97`
- القرار: `decision_code=3` → **FORMAL_WARNING**
- التقييم: `4.45 → 4.23` (خصم 5%)
- `active_warnings_count`: `0 → 1` (بدون إيقاف/إخفاء)
- `AdminAlert` أُنشئ بمستوى خطورة **HIGH** (`alert_type = ai_formal_warning`)
- سجل تدقيق: `action_applied = rating_down_5pct, formal_warning_issued, warning_incremented, admin_alerted`

---

## 7. كيف تُعيدون هذه الاختبارات بأنفسكم

### الطريقة أ — عبر الـ API الحقيقي (الأقرب لتجربة المستخدم الفعلية)
1. سجّلوا دخول بحساب ولي الأمر (`POST /api/auth/login`، كلمة المرور الافتراضية للحسابات التجريبية المزروعة هي `Password123!`).
2. أرسلوا `POST /api/parent/driver-reviews` بنفس البيانات (driver_id/rating/comment) من الجدول أعلاه — **تأكدوا من اختيار زوج ولي أمر/سائق بينهما اشتراك فعّال أو مكتمل**، وإلا سيُرفض الطلب بخطأ 422.
3. تأكدوا من تشغيل `php artisan queue:work` حتى تُعالَج المهمة، أو راقبوا `GET /api/admin/ai-audits?driver_id=<id>` بعد ثوانٍ.

### الطريقة ب — تنفيذ متزامن مباشر (ما استُخدم في هذه الاختبارات، ولا يحتاج طابور)
```bash
php artisan tinker --execute="
\$review = App\Models\Shared\DriverReview::create([
    'parent_id' => 2016, 'driver_id' => 917, 'rating' => 1,
    'comment'   => 'نص الشكوى هنا', 'status' => 'active',
]);
App\Jobs\ClassifyDriverReviewJob::dispatchSync(\$review->id);
\$review->refresh();
echo 'label='.\$review->ai_label.' code='.\$review->ai_decision_code.PHP_EOL;
"
```
هذا الأسلوب يُنفّذ **نفس** الكود الذي يُنفّذه الكونترولر (`DriverReviewController::store`) حرفياً، لكن بشكل متزامن فوري ودون الحاجة لتوكن مصادقة — مفيد جداً للاختبار السريع والتوثيق.

### أدوات جاهزة إضافية للاختبار (موجودة مسبقاً في المشروع)
```bash
php artisan ai:test-decision-scenarios --cleanup   # 6 سيناريوهات جاهزة A-F لكل أكواد القرار
php artisan ai:test-pipeline --driver=1            # فحص صحة الاتصال بخدمة AI + تجربة سريعة
php artisan ai:test-decision-layer                 # اختبار شامل لكل الأكواد الخمسة + التعافي الذاتي
```

---

## 8. ملاحظات وتنبيهات فنية (اكتُشفت أثناء المراجعة)

- **كود قديم غير مستخدم**: `App\Services\Ai\DriverPolicyEngine` محرك قواعد أقدم وأبسط، **غير مستدعى من أي مكان في الكود الحالي** — لا تُبنى عليه أي تطويرات جديدة، المُعتمد فعلياً هو `AiDecisionService`.
- **أعمدة قديمة غير مستخدمة**: `complaints.ai_action/ai_confidence/ai_severity/ai_analysis_message` و `driver_reviews.ai_action/ai_confidence` (من migrations أقدم) — المسار الحي يستخدم الأعمدة الأحدث (`ai_label`, `ai_category`, `ai_decision_code`...).
- **خلل بسيط محتمل**: `NotificationFormatter.php` يحتوي على `case self::TYPE_DRIVER_AI_ALERT` مكرر مرتين — الحالة الثانية كود ميت لا يُنفَّذ أبداً (PHP `match`/`switch` يأخذ أول تطابق). يستحق التنظيف لاحقاً لتفادي اللبس.
- **الحدود (Thresholds) ثابتة بالكود**: لا يوجد أي متغير `.env` يُغيّر نسبة 40%/80% أو نوافذ الـ 15/30 يوماً — أي تعديل مستقبلي عليها يتطلب تعديل كود `AiDecisionService.php` مباشرة.
