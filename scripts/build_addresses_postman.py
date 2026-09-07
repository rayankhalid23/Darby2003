# -*- coding: utf-8 -*-
"""
يولّد مجموعة Postman مخصصة لاندبوينتات دفتر العناوين (العنوان الرئيسي is_default)،
ويحدّث نفس المجلد داخل المجموعة الشاملة Darby_API_Full حتى تبقى الاثنتان متطابقتين.
"""
import io
import json
import os

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(BASE, 'postman', 'Darby_Addresses.postman_collection.json')
FULL = os.path.join(BASE, 'postman', 'Darby_API_Full.postman_collection.json')

BEARER = {
    "type": "bearer",
    "bearer": [{"key": "token", "value": "{{parent_token}}", "type": "string"}],
}
H_ACCEPT = {"key": "Accept", "value": "application/json"}
H_JSON = {"key": "Content-Type", "value": "application/json"}


def url(path_segments, raw_suffix=""):
    return {
        "raw": "{{base_url}}/" + "/".join(path_segments) + raw_suffix,
        "host": ["{{base_url}}"],
        "path": list(path_segments),
    }


def body(obj):
    return {
        "mode": "raw",
        "raw": json.dumps(obj, ensure_ascii=False, indent=2),
        "options": {"raw": {"language": "json"}},
    }


def test(lines):
    return {
        "listen": "test",
        "script": {"type": "text/javascript", "exec": lines},
    }


def req(name, method, u, description, auth=True, b=None, headers=None, events=None):
    r = {
        "method": method,
        "header": headers if headers is not None else [H_ACCEPT],
        "url": u,
        "description": description,
    }
    if auth:
        r["auth"] = BEARER
    if b is not None:
        r["body"] = b
    item = {"name": name, "request": r}
    if events:
        item["event"] = events
    return item


# ───────────────────────── 0) التجهيز ─────────────────────────

setup = {
    "name": "0) التجهيز (نفّذها أولاً)",
    "description": "تسجيل الدخول والتقاط التوكن ومعرّف المنطقة تلقائياً في متغيرات المجموعة.",
    "item": [
        req(
            "0.1 تسجيل دخول ولي الأمر",
            "POST",
            url(["api", "auth", "login"]),
            "يلتقط access_token تلقائياً في المتغير parent_token.\n\n"
            "المدخلات الإجبارية: email, password\n"
            "المدخلات الاختيارية: device_name, fcm_token, device_id, platform",
            auth=False,
            headers=[H_ACCEPT, H_JSON],
            b=body({"email": "parent@darby.test", "password": "Password123", "device_name": "postman"}),
            events=[test([
                "const j = pm.response.json();",
                "if (j.access_token) {",
                "  pm.collectionVariables.set('parent_token', j.access_token);",
                "  console.log('parent_token محفوظ');",
                "}",
                "pm.test('نجاح تسجيل الدخول', () => pm.response.to.have.status(200));",
            ])],
        ),
        req(
            "0.2 جلب المناطق (للحصول على zone_id)",
            "GET",
            url(["api", "parent", "zones"]),
            "zone_id إجباري عند إضافة عنوان. هذا الطلب يلتقط أول منطقة تلقائياً.",
            events=[test([
                "const j = pm.response.json();",
                "const list = j.data || [];",
                "if (list.length) {",
                "  pm.collectionVariables.set('zone_id', list[0].id);",
                "  console.log('zone_id = ' + list[0].id);",
                "}",
                "pm.test('نجاح جلب المناطق', () => pm.response.to.have.status(200));",
            ])],
        ),
    ],
}

# ───────────────────────── 1) المسار الأساسي ─────────────────────────

happy = {
    "name": "1) المسار الأساسي للعناوين",
    "description": "نفّذها بالترتيب: عرض ← إضافة رئيسي ← إضافة ثانوي ← تعديل ← تعيين رئيسي ← حذف.",
    "item": [
        req(
            "1.1 عرض دفتر العناوين",
            "GET",
            url(["api", "parent", "addresses"]),
            "المدخلات: لا شيء.\n\n"
            "المخرجات (200):\n"
            "- success (bool)\n"
            "- message (string)\n"
            "- default_address_id (int|null) ← معرّف العنوان الرئيسي\n"
            "- data[] : id, label, zone_id, zone_name, lat, lng, is_default\n\n"
            "الترتيب: العنوان الرئيسي أولاً دائماً.",
            events=[test([
                "const j = pm.response.json();",
                "pm.test('نجاح', () => pm.response.to.have.status(200));",
                "pm.test('يحتوي default_address_id', () => pm.expect(j).to.have.property('default_address_id'));",
                "pm.test('العنوان الرئيسي أولاً', () => {",
                "  if ((j.data || []).length > 1) pm.expect(j.data[0].is_default).to.eql(true);",
                "});",
                "if (j.default_address_id) pm.collectionVariables.set('address_id', j.default_address_id);",
            ])],
        ),
        req(
            "1.2 إضافة عنوان (الأول = رئيسي تلقائياً)",
            "POST",
            url(["api", "parent", "addresses"]),
            "المدخلات الإجبارية:\n"
            "- label (string) 2-100 حرفاً، عربي + أرقام + - / فقط، غير مكرر\n"
            "- lat (numeric) بين -90 و 90\n"
            "- lng (numeric) بين -180 و 180\n"
            "- zone_id (integer) موجود في جدول zones\n\n"
            "المدخلات الاختيارية:\n"
            "- is_default (boolean)\n\n"
            "المخرجات (201): success, message, data{id,label,zone_id,zone_name,lat,lng,is_default}\n\n"
            "ملاحظة: أول عنوان لولي الأمر يصبح is_default=true إجبارياً ويُسنَد إليه كل الأطفال.",
            headers=[H_ACCEPT, H_JSON],
            b=body({"label": "المنزل الرئيسي", "lat": 32.885, "lng": 13.185, "zone_id": "{{zone_id}}"}),
            events=[test([
                "const j = pm.response.json();",
                "pm.test('تم الإنشاء', () => pm.response.to.have.status(201));",
                "if (j.data && j.data.id) {",
                "  pm.collectionVariables.set('address_id', j.data.id);",
                "  console.log('address_id = ' + j.data.id);",
                "}",
                "pm.test('أول عنوان رئيسي تلقائياً', () => pm.expect(j.data.is_default).to.eql(true));",
            ])],
        ),
        req(
            "1.3 إضافة عنوان ثانوي",
            "POST",
            url(["api", "parent", "addresses"]),
            "نفس حقول 1.2. بما أنه يوجد عنوان رئيسي مسبقاً، يُنشأ هذا بحالة is_default=false.\n\n"
            "المخرجات (201): message = 'تم إضافة العنوان الجديد بنجاح كعنوان ثانوي.'",
            headers=[H_ACCEPT, H_JSON],
            b=body({"label": "منزل الجدة", "lat": 32.889, "lng": 13.189, "zone_id": "{{zone_id}}"}),
            events=[test([
                "const j = pm.response.json();",
                "pm.test('تم الإنشاء', () => pm.response.to.have.status(201));",
                "if (j.data && j.data.id) {",
                "  pm.collectionVariables.set('second_address_id', j.data.id);",
                "  console.log('second_address_id = ' + j.data.id);",
                "}",
                "pm.test('ثانوي', () => pm.expect(j.data.is_default).to.eql(false));",
            ])],
        ),
        req(
            "1.4 إضافة عنوان وجعله رئيسياً مباشرة",
            "POST",
            url(["api", "parent", "addresses"]),
            "بإرسال is_default=true يمر الطلب عبر حراسات تبديل العنوان الرئيسي:\n"
            "- وجود اشتراك مفعّل ⇒ 422 ولا يُنشأ العنوان إطلاقاً (العملية ذرّية)\n"
            "- وجود طلبات معلّقة فقط ⇒ تُلغى كلها ثم يُنشأ العنوان رئيسياً",
            headers=[H_ACCEPT, H_JSON],
            b=body({"label": "المنزل الجديد", "lat": 32.8911, "lng": 13.1911,
                    "zone_id": "{{zone_id}}", "is_default": True}),
            events=[test([
                "pm.test('201 أو 422 حسب الاشتراكات', () => pm.expect([201, 422]).to.include(pm.response.code));",
                "const j = pm.response.json();",
                "if (pm.response.code === 201) pm.collectionVariables.set('third_address_id', j.data.id);",
                "else console.log('مرفوض: ' + j.error_code + ' — ' + j.message);",
            ])],
        ),
        req(
            "1.5 تعديل عنوان (تعديل جزئي)",
            "PATCH",
            url(["api", "parent", "addresses", "{{second_address_id}}"]),
            "كل الحقول اختيارية — يُعدَّل ما تُرسله فقط:\n"
            "- label (string) نفس قيود الإضافة\n"
            "- lat (numeric) / lng (numeric)\n"
            "- zone_id (integer|null)\n"
            "- is_default (boolean): true ⇒ تبديل بكل الحراسات · false ⇒ مرفوض على الرئيسي\n\n"
            "المخرجات (200): success, message, data{...}\n\n"
            "المسار يقبل PATCH و PUT و POST.",
            headers=[H_ACCEPT, H_JSON],
            b=body({"label": "منزل الجدة المعدل"}),
            events=[test([
                "pm.test('نجاح التعديل', () => pm.response.to.have.status(200));",
            ])],
        ),
        req(
            "1.6 ⭐ تعيين العنوان الرئيسي (set-default)",
            "PATCH",
            url(["api", "parent", "addresses", "{{second_address_id}}", "set-default"]),
            "المدخلات: لا شيء (المعرّف في المسار فقط).\n\n"
            "المخرجات (200):\n"
            "- success (bool)\n"
            "- message (string)\n"
            "- changed (bool) ← false إذا كان العنوان رئيسياً أصلاً\n"
            "- cancelled_requests_count (int) ← عدد طلبات الاشتراك الملغاة\n"
            "- cancelled_request_ids (int[])\n"
            "- reassigned_children_count (int) ← عدد الأطفال المنقولين\n"
            "- data{...} العنوان بعد التعيين\n\n"
            "الرفض (422): error_code = ADDRESS_HAS_ACTIVE_SUBSCRIPTIONS مع context.children\n\n"
            "المسار يقبل PATCH و PUT و POST.",
            events=[test([
                "pm.test('200 أو 422', () => pm.expect([200, 422]).to.include(pm.response.code));",
                "const j = pm.response.json();",
                "if (pm.response.code === 200) {",
                "  pm.test('يحتوي عدّاد الإلغاء', () => pm.expect(j).to.have.property('cancelled_requests_count'));",
                "  pm.test('يحتوي عدّاد الأطفال', () => pm.expect(j).to.have.property('reassigned_children_count'));",
                "  console.log('ألغيت ' + j.cancelled_requests_count + ' طلبات · نُقل ' + j.reassigned_children_count + ' أطفال');",
                "} else {",
                "  pm.test('كود خطأ الاشتراكات', () => pm.expect(j.error_code).to.eql('ADDRESS_HAS_ACTIVE_SUBSCRIPTIONS'));",
                "  console.log(j.message);",
                "}",
            ])],
        ),
        req(
            "1.7 حذف عنوان (حذف ناعم)",
            "DELETE",
            url(["api", "parent", "addresses", "{{address_id}}"]),
            "المدخلات: لا شيء.\n\n"
            "المخرجات (200): success, message = 'تم حذف العنوان بنجاح.'\n\n"
            "القواعد:\n"
            "- عنوان مرتبط بطفل ⇒ 422\n"
            "- حذف عنوان رئيسي بلا أطفال ⇒ ينجح ويُرقّى أقدم عنوان متبقٍّ تلقائياً\n"
            "- الحذف ناعم: يمكن إعادة إضافة نفس المسمى والإحداثيات لاحقاً",
            events=[test([
                "pm.test('200 أو 422', () => pm.expect([200, 422]).to.include(pm.response.code));",
                "if (pm.response.code === 422) console.log(pm.response.json().error_code);",
            ])],
        ),
    ],
}

# ───────────────────────── 2) حالات الخطأ ─────────────────────────

errors = {
    "name": "2) حالات الخطأ (كل رسائل التحقق)",
    "description": "كل طلب هنا يجب أن يفشل — للتأكد من وجود الرسالة الصحيحة وكود الخطأ الصحيح.",
    "item": [
        req(
            "2.1 إضافة بلا حقول (كل رسائل الإجباري)",
            "POST",
            url(["api", "parent", "addresses"]),
            "المتوقع 422 مع errors لكل من: label, lat, lng, zone_id\n\n"
            "الرسائل:\n"
            "- مسمى العنوان مطلوب.\n"
            "- إحداثيات خط العرض مطلوبة.\n"
            "- إحداثيات خط الطول مطلوبة.\n"
            "- يرجى تحديد المنطقة الجغرافية التابع لها العنوان.",
            headers=[H_ACCEPT, H_JSON],
            b=body({}),
            events=[test([
                "pm.test('422', () => pm.response.to.have.status(422));",
                "const j = pm.response.json();",
                "pm.test('كود التحقق', () => pm.expect(j.error_code).to.eql('VALIDATION_ERROR'));",
                "['label','lat','lng','zone_id'].forEach(f =>",
                "  pm.test('خطأ في ' + f, () => pm.expect(j.errors).to.have.property(f)));",
            ])],
        ),
        req(
            "2.2 مسمى بحروف لاتينية (مرفوض)",
            "POST",
            url(["api", "parent", "addresses"]),
            "المتوقع 422 — 'مسمى العنوان يجب أن يكون بالعربية (ويمكن أن يتضمن أرقاماً).'",
            headers=[H_ACCEPT, H_JSON],
            b=body({"label": "My Home", "lat": 32.9, "lng": 13.2, "zone_id": "{{zone_id}}"}),
            events=[test([
                "pm.test('422', () => pm.response.to.have.status(422));",
                "pm.test('خطأ في label', () => pm.expect(pm.response.json().errors).to.have.property('label'));",
            ])],
        ),
        req(
            "2.3 إحداثيات خارج النطاق",
            "POST",
            url(["api", "parent", "addresses"]),
            "المتوقع 422 — 'إحداثيات خط العرض غير صالحة جغرافياً.' و 'إحداثيات خط الطول غير صالحة جغرافياً.'",
            headers=[H_ACCEPT, H_JSON],
            b=body({"label": "عنوان خاطئ", "lat": 999, "lng": -999, "zone_id": "{{zone_id}}"}),
            events=[test([
                "pm.test('422', () => pm.response.to.have.status(422));",
                "const e = pm.response.json().errors;",
                "pm.test('lat', () => pm.expect(e).to.have.property('lat'));",
                "pm.test('lng', () => pm.expect(e).to.have.property('lng'));",
            ])],
        ),
        req(
            "2.4 منطقة غير موجودة",
            "POST",
            url(["api", "parent", "addresses"]),
            "المتوقع 422 — 'المنطقة الجغرافية المختارة غير مسجلة بالنظام.'",
            headers=[H_ACCEPT, H_JSON],
            b=body({"label": "عنوان بمنطقة وهمية", "lat": 32.91, "lng": 13.21, "zone_id": 999999}),
            events=[test([
                "pm.test('422', () => pm.response.to.have.status(422));",
                "pm.test('خطأ في zone_id', () => pm.expect(pm.response.json().errors).to.have.property('zone_id'));",
            ])],
        ),
        req(
            "2.5 اسم عنوان مكرر",
            "POST",
            url(["api", "parent", "addresses"]),
            "أعد إرسال 1.2 بنفس المسمى. المتوقع 422 — 'اسم العنوان مسجل لديك مسبقاً.'",
            headers=[H_ACCEPT, H_JSON],
            b=body({"label": "المنزل الرئيسي", "lat": 32.8999, "lng": 13.1999, "zone_id": "{{zone_id}}"}),
            events=[test([
                "pm.test('422', () => pm.response.to.have.status(422));",
            ])],
        ),
        req(
            "2.6 إلغاء تفعيل العنوان الرئيسي (مرفوض)",
            "PATCH",
            url(["api", "parent", "addresses", "{{address_id}}"]),
            "المتوقع 422 — error_code = ADDRESS_DEFAULT_CANNOT_BE_UNSET\n"
            "'لا يمكن إلغاء تفعيل العنوان الرئيسي مباشرة، يجب تعيين عنوان آخر كعنوان رئيسي بدلاً منه.'",
            headers=[H_ACCEPT, H_JSON],
            b=body({"is_default": False}),
            events=[test([
                "pm.test('422', () => pm.response.to.have.status(422));",
                "pm.test('الكود الصحيح', () => pm.expect(pm.response.json().error_code)",
                "  .to.eql('ADDRESS_DEFAULT_CANNOT_BE_UNSET'));",
            ])],
        ),
        req(
            "2.7 حذف عنوان رئيسي مرتبط بأطفال (مرفوض)",
            "DELETE",
            url(["api", "parent", "addresses", "{{address_id}}"]),
            "يتطلب وجود طفل مضاف. المتوقع 422 — error_code = ADDRESS_DEFAULT_CANNOT_BE_DELETED\n"
            "'لا يمكن حذف العنوان الرئيسي لأن أطفالك مسنَدون إليه، يرجى تعيين عنوان آخر كعنوان رئيسي أولاً ثم إعادة المحاولة.'",
            events=[test([
                "pm.test('422', () => pm.response.to.have.status(422));",
                "pm.test('كود الحذف الممنوع', () => pm.expect(['ADDRESS_DEFAULT_CANNOT_BE_DELETED','ADDRESS_LINKED_TO_CHILD'])",
                "  .to.include(pm.response.json().error_code));",
            ])],
        ),
        req(
            "2.8 تعيين عنوان لا تملكه (حماية IDOR)",
            "PATCH",
            url(["api", "parent", "addresses", "999999", "set-default"]),
            "المتوقع 404 — error_code = NOT_FOUND\n"
            "'العنصر أو الرابط الذي تحاول الوصول إليه غير موجود أو تم حذفه.'",
            events=[test([
                "pm.test('404', () => pm.response.to.have.status(404));",
            ])],
        ),
        req(
            "2.9 بلا توكن (غير مصرح)",
            "GET",
            url(["api", "parent", "addresses"]),
            "المتوقع 401 — error_code = UNAUTHENTICATED\n"
            "'غير مصرح بالوصول، يرجى تسجيل الدخول أولاً.'",
            auth=False,
            events=[test([
                "pm.test('401', () => pm.response.to.have.status(401));",
            ])],
        ),
    ],
}

# ───────────────────────── 3) أثر العناوين على الأطفال ─────────────────────────

children = {
    "name": "3) أثر العنوان الرئيسي على الأطفال",
    "description": "الاندبوينتات المتأثرة بقاعدة «كل الأطفال على العنوان الرئيسي».",
    "item": [
        req(
            "3.1 إضافة طفل بدون address_id (إسناد تلقائي)",
            "POST",
            url(["api", "parent", "children"]),
            "address_id لم يعد إجبارياً — يُملأ تلقائياً من العنوان الرئيسي.\n\n"
            "المدخلات الإجبارية:\n"
            "- school_id (integer) موجود في schools\n"
            "- full_name (string) الاسم الثلاثي، 8-150 حرفاً\n"
            "- birth_date (date) العمر بين 6 و 21 سنة\n"
            "- gender (male|female)\n"
            "- grade (integer) 0-12\n"
            "- preferred_time_slot (morning|evening|both)\n"
            "- start_date (date) · end_date (date)\n\n"
            "المدخلات الاختيارية:\n"
            "- address_id (integer) ← إن أُرسل وجب أن يطابق العنوان الرئيسي\n"
            "- photo (image) · medical_notes (string) · notification_radius (100-5000)\n"
            "- pickup_time (H:i) · dropoff_time (H:i)\n\n"
            "المخرجات (201): success, message, data{... address_id = العنوان الرئيسي}",
            headers=[H_ACCEPT, H_JSON],
            b=body({
                "school_id": "{{school_id}}",
                "full_name": "محمد علي سالم",
                "birth_date": "2015-05-10",
                "gender": "male",
                "grade": 4,
                "preferred_time_slot": "morning",
                "start_date": "{{start_date}}",
                "end_date": "{{end_date}}",
            }),
            events=[test([
                "pm.test('201 أو 422', () => pm.expect([201, 422]).to.include(pm.response.code));",
                "const j = pm.response.json();",
                "if (pm.response.code === 201) {",
                "  pm.collectionVariables.set('child_id', j.data.id);",
                "  pm.test('أُسنِد للعنوان الرئيسي', () =>",
                "    pm.expect(String(j.data.address_id)).to.eql(String(pm.collectionVariables.get('address_id'))));",
                "} else { console.log(JSON.stringify(j.errors)); }",
            ])],
        ),
        req(
            "3.2 إضافة طفل بعنوان ثانوي (مرفوض)",
            "POST",
            url(["api", "parent", "children"]),
            "المتوقع 422 في مفتاح errors.address_id:\n"
            "'لا يمكن إسناد الطفل إلا للعنوان الرئيسي المفعّل [...]، يرجى تعيين العنوان المطلوب كعنوان رئيسي أولاً.'",
            headers=[H_ACCEPT, H_JSON],
            b=body({
                "school_id": "{{school_id}}",
                "address_id": "{{second_address_id}}",
                "full_name": "خالد علي سالم",
                "birth_date": "2015-05-10",
                "gender": "male",
                "grade": 4,
                "preferred_time_slot": "morning",
                "start_date": "{{start_date}}",
                "end_date": "{{end_date}}",
            }),
            events=[test([
                "pm.test('422', () => pm.response.to.have.status(422));",
                "pm.test('خطأ في address_id', () => pm.expect(pm.response.json().errors).to.have.property('address_id'));",
            ])],
        ),
        req(
            "3.3 عرض الأطفال (للتأكد من الإسناد)",
            "GET",
            url(["api", "parent", "children"]),
            "بعد أي عملية set-default يجب أن يكون address_id لكل الأطفال = العنوان الرئيسي الجديد.",
            events=[test([
                "pm.test('200', () => pm.response.to.have.status(200));",
                "const def = String(pm.collectionVariables.get('address_id'));",
                "const kids = pm.response.json().data || [];",
                "pm.test('كل الأطفال على العنوان الرئيسي', () => {",
                "  kids.forEach(k => pm.expect(String(k.address_id)).to.eql(def));",
                "});",
            ])],
        ),
        req(
            "3.4 جلب المدارس (للحصول على school_id)",
            "GET",
            url(["api", "parent", "schools"]),
            "school_id إجباري عند إضافة طفل. هذا الطلب يلتقط أول مدرسة تلقائياً.",
            events=[test([
                "const j = pm.response.json();",
                "const list = j.data || [];",
                "if (list.length) {",
                "  pm.collectionVariables.set('school_id', list[0].id);",
                "  console.log('school_id = ' + list[0].id);",
                "}",
            ])],
        ),
    ],
}

collection = {
    "info": {
        "name": "Darby — دفتر عناوين ولي الأمر (is_default)",
        "_postman_id": "darby-addresses-0001",
        "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
        "description": (
            "مجموعة مخصصة لاختبار اندبوينتات العناوين بعد إضافة العنوان الرئيسي is_default.\n\n"
            "طريقة الاستخدام:\n"
            "1. عدّل المتغير base_url إن لزم.\n"
            "2. نفّذ مجلد «0) التجهيز» — يلتقط parent_token و zone_id تلقائياً.\n"
            "3. نفّذ مجلد «1) المسار الأساسي» بالترتيب — يلتقط address_id و second_address_id تلقائياً.\n"
            "4. مجلد «2) حالات الخطأ» يتحقق من كل رسائل التحقق وأكواد الأخطاء.\n\n"
            "قواعد العمل:\n"
            "- عنوان رئيسي واحد فقط لكل ولي أمر، وأول عنوان يصبح رئيسياً تلقائياً.\n"
            "- كل الأطفال مسنَدون تلقائياً للعنوان الرئيسي.\n"
            "- تغيير العنوان الرئيسي مرفوض عند وجود اشتراك مفعّل لأي طفل.\n"
            "- بلا اشتراكات مفعّلة وبوجود طلبات معلّقة ⇒ تُلغى كلها تلقائياً ثم يتم التغيير."
        ),
    },
    "variable": [
        {"key": "base_url", "value": "http://127.0.0.1:8000"},
        {"key": "parent_token", "value": ""},
        {"key": "zone_id", "value": ""},
        {"key": "school_id", "value": ""},
        {"key": "address_id", "value": ""},
        {"key": "second_address_id", "value": ""},
        {"key": "third_address_id", "value": ""},
        {"key": "child_id", "value": ""},
        {"key": "start_date", "value": ""},
        {"key": "end_date", "value": ""},
    ],
    "event": [
        {
            "listen": "prerequest",
            "script": {
                "type": "text/javascript",
                "exec": [
                    "// توليد تواريخ اشتراك صالحة تلقائياً (تتخطى الجمعة والسبت)",
                    "function nextWorkingDay(d) {",
                    "  while (d.getDay() === 5 || d.getDay() === 6) d.setDate(d.getDate() + 1);",
                    "  return d;",
                    "}",
                    "const s = nextWorkingDay(new Date(Date.now() + 2 * 86400000));",
                    "const e = new Date(s.getTime() + 30 * 86400000);",
                    "const fmt = d => d.toISOString().slice(0, 10);",
                    "pm.collectionVariables.set('start_date', fmt(s));",
                    "pm.collectionVariables.set('end_date', fmt(e));",
                ],
            },
        }
    ],
    "item": [setup, happy, errors, children],
}

with io.open(OUT, 'w', encoding='utf-8') as f:
    json.dump(collection, f, ensure_ascii=False, indent=2)
print('written: ' + OUT)

# ── تحديث مجلد العناوين داخل المجموعة الشاملة ──
with io.open(FULL, encoding='utf-8') as f:
    full = json.load(f)

new_folder = {
    "name": "إدارة العناوين",
    "item": happy["item"] + [errors["item"][5]],
}


def replace(items):
    for i, it in enumerate(items):
        if 'item' in it:
            for sub in it['item']:
                u = sub.get('request', {}).get('url', {})
                raw = u.get('raw') if isinstance(u, dict) else u
                if 'parent/addresses' in str(raw):
                    items[i] = new_folder
                    return True
            if replace(it['item']):
                return True
    return False


if replace(full['item']):
    with io.open(FULL, 'w', encoding='utf-8') as f:
        json.dump(full, f, ensure_ascii=False, indent=2)
    print('updated address folder in: ' + FULL)
else:
    print('WARNING: address folder not found in full collection')
