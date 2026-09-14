# 🛡️ الدليل الشامل لمطوري الويب: إدارة صلاحيات المشرفين ولوحة التحكم الإدارية (RBAC Frontend Manual)
### منصة دربي للنقل المدرسي الذكي (Darby Platform)

---

## 📑 فهرس المحتويات
1. [الفلسفة الهندسية لمنطق الصلاحيات في الباك إند](#1-الفلسفة-الهندسية-لمنطق-الصلاحيات-في-الباك-إند)
2. [دورة حياة البيانات والمصادقة (Authentication & Data Lifecycle)](#2-دورة-حياة-البيانات-والمصادقة-authentication--data-lifecycle)
3. [هيكلية شجرة الصلاحيات ومصفوفة الأدوار المعتمدة](#3-هيكلية-شجرة-الصلاحيات-ومصفوفة-الأدوار-المعتمدة)
4. [كود التحقق في الواجهة الأمامية (Vue / React / TypeScript)](#4-كود-التحقق-في-الواجهة-الأمامية-vue--react--typescript)
5. [حماية مسارات التوجيه (Route Guards & Middleware)](#5-حماية-مسارات-التوجيه-route-guards--middleware)
6. [التحكم الديناميكي بالقائمة الجانبية (Sidebar Dynamic Rendering)](#6-التحكم-الديناميكي-بالقائمة-الجانبية-sidebar-dynamic-rendering)
7. [حماية الأزرار والعمليات الميدانية (Action-Level Directives & Components)](#7-حماية-الأزرار-والعمليات-الميدانية-action-level-directives--components)
8. [شاشة إدارة المشرفين والصلاحيات (Supervisors Management UI)](#8-شاشة-إدارة-المشرفين-والصلاحيات-supervisors-management-ui)
9. [المعالجة المركزية للأخطاء ورفض الوصول (Handling 403 Forbidden Centrally)](#9-المعالجة-المركزية-للأخطاء-ورفض-الوصول-handling-403-forbidden-centrally)
10. [جدول المطابقة الشامل (المسارات ⟷ الصلاحيات المطلوبة ⟷ الأدوار)](#10-جدول-المطابقة-الشامل-المسارات--الصلاحيات-المطلوبة--الأدوار)

---

## 1. الفلسفة الهندسية لمنطق الصلاحيات في الباك إند

يعتمد نظام الصلاحيات في منصة **دربي (Darby)** على معمارية **Role-Based Access Control (RBAC V2)** المصممة وفق المبادئ التالية:

1. **المشرفون جزء أصيل من جدول المستخدمين (`users` table):**
   - لم يعد المشرف جدولاً منفصلاً، بل هو مستخدم مسجل في جدول `users` بأحد الأدوار الإدارية (`roles.kind = 'staff'`).
   - كلاس `App\Models\Admin\Admin` في الباك إند يرث من كلاس `User` كـ Model Proxy للتعامل بسلاسة مع هؤلاء المستخدمين.
2. **المدير العام (`super_admin` / `role_id = 1`):**
   - يملك صلاحية الجذر الشاملة `["*"]` (Wildcard Access).
   - يتم تخطي كافة فحوصات الصلاحيات تلقائياً فور التحقق من هويته في الباك إند والميدلوير.
3. **المشرفون المتخصصون (Staff Roles):**
   - كل مشرف يرتبط بدور إداري محدد عبر حقل `role_id`.
   - الصلاحيات مرتبطة بالأدوار عبر الجدول الوسيط `permission_role` ومحفوظة في الكاش السريع (`user.perms.role.{role_id}`).
   - أسماء ومفاتيح الصلاحيات مبنية بصيغة نقطية قياسية: `category.action` (مثل `financial.manage_withdrawals` و `drivers.review_initial`).
4. **دعم الصلاحيات الشاملة للأقسام (Wildcard Categories):**
   - إذا امتلك المستخدم صلاحية مثل `financial.*`، فإن النظام يعتبره مالكاً لجميع صلاحيات القسم المالي تلقائياً.
5. **طبقة الحماية الميدانية (Middleware):**
   - يتم تطبيق ميدلوير `CheckAdminPermission` (`middleware('permission:key')`) على كل مسار في الباك إند.
   - التحقق يتم بالترتيب:
     1. التوكن صالح والمستخدم مسجل دخول (وإلا يُرجع `401 Unauthorized`).
     2. حساب المشرف نشط `is_active == true` (إذا كان مجمداً يُطرد بـ `403 Forbidden` فوراً).
     3. إذا كان المشرف مدير نظام عام (`role_id = 1`) -> يُسمح بالمرور فوراً.
     4. فحص امتلاك الصلاحية المطلوبة (أو إحداها عند تمرير مصفوفة خيارات).

---

## 2. دورة حياة البيانات والمصادقة (Authentication & Data Lifecycle)

### أ. تسجيل الدخول (`POST /api/auth/login`)
يتم إرسال الطلب بواسطة رقم الهاتف:
```json
{
  "phone_number": "0912345678",
  "password": "Password123",
  "platform": "web",
  "device_name": "Chrome Admin Dashboard"
}
```

تستجيب المنصة بكائن المستخدم الشامل ومصفوفة الصلاحيات المتاحة له:
```json
{
  "status": true,
  "message": "مرحباً أحمد، تم تسجيل الدخول بنجاح!",
  "access_token": "1|abcdef123456789...",
  "token_type": "Bearer",
  "role_name": "مشرف العمليات والتشغيل الميداني",
  "user": {
    "id": 5,
    "user_id": 5,
    "full_name": "أحمد محمود الفيتوري",
    "email": "ahmed.ops@darby.ly",
    "phone_number": "0912345678",
    "avatar_url": "https://api.darby.ly/api/admin/avatars/avatar_5.png",
    "is_active": true,
    "role_id": 2,
    "role_key": "operations_supervisor",
    "role_name": "مشرف العمليات والتشغيل الميداني",
    "permissions": [
      "dashboard.view_stats",
      "dashboard.view_radar",
      "trips.generate_daily",
      "trips.emergency_cancel",
      "schools.manage",
      "geography.manage",
      "reports.view"
    ]
  }
}
```

### ب. جلب الملف الشخصي عند تحديث الصفحة (`GET /api/admin/profile`)
يجب على تطبيق الفرونت إند حفظ التوكن في `localStorage` أو `Cookies`، وعند إعادة تحميل الصفحة (F5) يتم طلب الملف الشخصي لتحديث حالة الصلاحيات:
```http
GET /api/admin/profile
Authorization: Bearer <TOKEN>
Accept: application/json
```
يرجع نفس كائن المستخدم أعلاه داخل المفتاح `data`.

---

## 3. هيكلية شجرة الصلاحيات ومصفوفة الأدوار المعتمدة

يتم جلب الشجرة الكاملة للأدوار والصلاحيات من المسار:
```http
GET /api/admin/roles-permissions
```

### الأدوار الرسمية في النظام (Standard System Roles):
| معرف الدور (`role_id`) | المفتاح البرمجي (`key`) | المسمى العربي الرسمي | نطاق الصلاحيات الافتراضية |
| :---: | :--- | :--- | :--- |
| **1** | `super_admin` | **مدير النظام العام** | وصول كامل وغير مقيد لكافة مسارات النظام `["*"]` |
| **2** | `operations_supervisor` | **مشرف العمليات والتشغيل الميداني** | الداشبورد، رادار تتبع الحافلات، توليد الرحلات، إلغاء الطوارئ، المدارس، المناطق |
| **5** | `fleet_supervisor` | **مشرف شؤون وأسطول السائقين** | عرض السائقين، مراجعة واعتماد السائقين الجدد، مراجعة تعديلات المركبات، تجميد الحسابات |
| **6** | `support_supervisor` | **مشرف الدعم والشكاوى والجودة** | عرض ومراجعة الشكاوى، البت فيها، إدارة تقييمات السائقين وحذفها، إرسال التعاميم |
| **7** | `finance_officer` | **المشرف المالي ومسؤول الخزينة** | ملخص الخزينة والملاءة، دفتر القيود، طلبات السحب، طلبات الشحن، الأمانات، النزاعات، التسويات، التسعير، بوابات الدفع |
| **8** | `geography_supervisor` | **مشرف التخطيط الجغرافي والمدارس** | إدارة البلديات، المحلات، النطاقات الجغرافية، والمدارس وبواباتها |

---

## 4. كود التحقق في الواجهة الأمامية (Vue / React / TypeScript)

احفظ هذا الملف في مشروع الواجهة الأمامية في المسار:
`src/utils/permissions.ts`

```typescript
export interface AdminUser {
  id: number;
  full_name: string;
  email: string;
  phone_number: string;
  role_id: number;
  role_key: string;
  role_name: string;
  is_active: boolean;
  permissions: string[];
}

/**
 * 1. فحص امتلاك المشرف لصلاحية محددة مع دعم الـ Wildcards
 */
export function hasPermission(user: AdminUser | null, requiredPermission: string): boolean {
  if (!user || !user.is_active || !user.permissions || user.permissions.length === 0) {
    return false;
  }

  // المدير العام يملك صلاحية كل شيء
  if (user.role_id === 1 || user.permissions.includes('*')) {
    return true;
  }

  // مطابقة تامة للصلاحية
  if (user.permissions.includes(requiredPermission)) {
    return true;
  }

  // دعم الصلاحيات الشاملة للقسم مثل financial.*
  const category = requiredPermission.split('.')[0];
  if (user.permissions.includes(`${category}.*`)) {
    return true;
  }

  return false;
}

/**
 * 2. فحص امتلاك أي من الصلاحيات الممررة (OR Condition)
 * مفيدة جداً للقوائم التي تضم خيارات فرعية مختلفة
 */
export function hasAnyPermission(user: AdminUser | null, requiredPermissions: string[]): boolean {
  if (!user || !user.is_active || !user.permissions || user.permissions.length === 0) {
    return false;
  }
  if (user.role_id === 1 || user.permissions.includes('*')) {
    return true;
  }
  return requiredPermissions.some(perm => hasPermission(user, perm));
}

/**
 * 3. فحص امتلاك كافة الصلاحيات الممررة (AND Condition)
 */
export function hasAllPermissions(user: AdminUser | null, requiredPermissions: string[]): boolean {
  if (!user || !user.is_active || !user.permissions || user.permissions.length === 0) {
    return false;
  }
  if (user.role_id === 1 || user.permissions.includes('*')) {
    return true;
  }
  return requiredPermissions.every(perm => hasPermission(user, perm));
}
```

---

## 5. حماية مسارات التوجيه (Route Guards & Middleware)

لحماية الصفحات من الوصول المباشر عبر شريط العنوان في المتصفح (URL Navigation):

### أ. في Vue 3 / Vue Router:
```typescript
// src/router/index.ts
import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { hasPermission, hasAnyPermission } from '@/utils/permissions';

const routes = [
  {
    path: '/admin/drivers',
    component: () => import('@/views/drivers/DriversListView.vue'),
    meta: { requiresAuth: true, permission: 'drivers.view' }
  },
  {
    path: '/admin/financial/withdrawals',
    component: () => import('@/views/financial/WithdrawalsView.vue'),
    meta: { requiresAuth: true, permission: 'financial.manage_withdrawals' }
  },
  {
    path: '/admin/supervisors',
    component: () => import('@/views/supervisors/SupervisorsView.vue'),
    meta: { requiresAuth: true, permission: 'admins.manage' }
  },
  {
    path: '/403',
    component: () => import('@/views/errors/ForbiddenView.vue')
  }
];

const router = createRouter({
  history: createWebHistory(),
  routes
});

router.beforeEach(async (to, from, next) => {
  const authStore = useAuthStore();

  if (to.meta.requiresAuth) {
    if (!authStore.isAuthenticated) {
      return next({ path: '/login', query: { redirect: to.fullPath } });
    }

    const requiredPermission = to.meta.permission as string | undefined;
    const requiredAny = to.meta.anyPermission as string[] | undefined;

    if (requiredPermission && !hasPermission(authStore.user, requiredPermission)) {
      return next('/403');
    }

    if (requiredAny && !hasAnyPermission(authStore.user, requiredAny)) {
      return next('/403');
    }
  }

  next();
});

export default router;
```

### ب. في React Router (v6+ Guarded Route Wrapper):
```tsx
// src/components/GuardedRoute.tsx
import React from 'react';
import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '@/hooks/useAuth';
import { hasPermission } from '@/utils/permissions';

interface GuardedRouteProps {
  permission?: string;
  redirectPath?: string;
}

export const GuardedRoute: React.FC<GuardedRouteProps> = ({
  permission,
  redirectPath = '/403'
}) => {
  const { user, isAuthenticated } = useAuth();

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  if (permission && !hasPermission(user, permission)) {
    return <Navigate to={redirectPath} replace />;
  }

  return <Outlet />;
};
```

---

## 6. التحكم الديناميكي بالقائمة الجانبية (Sidebar Dynamic Rendering)

يتم تعريف هيكل القائمة الجانبية في ملف إعداد موحد، ثم تنقيته بناءً على صلاحيات المشرف الحالي:

```typescript
// src/config/navigation.ts
export interface NavItem {
  id: string;
  title: string;
  icon: string;
  route: string;
  permission?: string;
  anyPermission?: string[];
  subItems?: NavItem[];
}

export const adminNavMenu: NavItem[] = [
  {
    id: 'dashboard',
    title: 'الرئيسية والإحصائيات',
    icon: 'chart-bar',
    route: '/admin/dashboard',
    permission: 'dashboard.view_stats'
  },
  {
    id: 'radar',
    title: 'رادار التتبع الحي',
    icon: 'map-pin',
    route: '/admin/radar',
    permission: 'dashboard.view_radar'
  },
  {
    id: 'drivers',
    title: 'أسطول السائقين',
    icon: 'truck',
    route: '/admin/drivers',
    permission: 'drivers.view',
    subItems: [
      { id: 'drivers-list', title: 'قائمة السائقين', icon: 'users', route: '/admin/drivers', permission: 'drivers.view' },
      { id: 'drivers-pending', title: 'تعديلات المركبات المعلقة', icon: 'document-text', route: '/admin/drivers/pending-changes', permission: 'drivers.review_changes' }
    ]
  },
  {
    id: 'complaints',
    title: 'الشكاوى والدعم الفني',
    icon: 'lifebuoy',
    route: '/admin/complaints',
    permission: 'complaints.view'
  },
  {
    id: 'financial',
    title: 'الإدارة المالية والخزينة',
    icon: 'banknotes',
    route: '/admin/financial',
    anyPermission: [
      'financial.view_summary',
      'financial.view_ledger',
      'financial.manage_withdrawals',
      'financial.manage_recharges'
    ],
    subItems: [
      { id: 'fin-summary', title: 'ملخص الخزينة والملاءة', icon: 'chart-pie', route: '/admin/financial/summary', permission: 'financial.view_summary' },
      { id: 'fin-withdrawals', title: 'طلبات سحب الأرباح', icon: 'arrow-up-tray', route: '/admin/financial/withdrawals', permission: 'financial.manage_withdrawals' },
      { id: 'fin-recharges', title: 'طلبات شحن المحافظ', icon: 'arrow-down-tray', route: '/admin/financial/recharges', permission: 'financial.manage_recharges' },
      { id: 'fin-escrows', title: 'الأمانات المعلقة', icon: 'lock-closed', route: '/admin/financial/escrows', permission: 'financial.release_escrows' },
      { id: 'fin-disputes', title: 'النزاعات المالية', icon: 'scale', route: '/admin/financial/disputes', permission: 'financial.resolve_disputes' },
      { id: 'fin-ledger', title: 'دفتر القيود المحاسبية', icon: 'book-open', route: '/admin/financial/ledger', permission: 'financial.view_ledger' },
      { id: 'fin-pricing', title: 'إعدادات التسعير والعمولات', icon: 'cog-6-tooth', route: '/admin/financial/pricing', permission: 'financial.manage_pricing' },
      { id: 'fin-payment', title: 'بوابات وطرق الدفع', icon: 'credit-card', route: '/admin/financial/payment-methods', permission: 'financial.manage_payment_methods' }
    ]
  },
  {
    id: 'schools',
    title: 'المدارس',
    icon: 'academic-cap',
    route: '/admin/schools',
    permission: 'schools.manage'
  },
  {
    id: 'geography',
    title: 'المناطق والبلديات',
    icon: 'globe-alt',
    route: '/admin/geography',
    permission: 'geography.manage'
  },
  {
    id: 'supervisors',
    title: 'إدارة المشرفين والصلاحيات',
    icon: 'user-group',
    route: '/admin/supervisors',
    permission: 'admins.manage'
  },
  {
    id: 'audit-logs',
    title: 'سجل تدقيق الإجراءات',
    icon: 'clipboard-document-list',
    route: '/admin/audit-logs',
    permission: 'audit_logs.view'
  },
  {
    id: 'reports',
    title: 'التقارير والإحصائيات',
    icon: 'presentation-chart-line',
    route: '/admin/reports',
    permission: 'reports.view'
  }
];

export function getAuthorizedMenu(user: AdminUser | null): NavItem[] {
  return adminNavMenu
    .filter(item => {
      if (item.permission && !hasPermission(user, item.permission)) return false;
      if (item.anyPermission && !hasAnyPermission(user, item.anyPermission)) return false;
      return true;
    })
    .map(item => {
      if (!item.subItems) return item;
      return {
        ...item,
        subItems: item.subItems.filter(sub => !sub.permission || hasPermission(user, sub.permission))
      };
    })
    .filter(item => !item.subItems || item.subItems.length > 0 || item.permission);
}
```

---

## 7. حماية الأزرار والعمليات الميدانية (Action-Level Directives & Components)

لحماية أزرار العمليات (مثل "اعتماد سائق"، "صرف مستحقات"، "حذف"):

### أ. في Vue 3 (`v-permission` Directive):
```typescript
// src/directives/permission.ts
import { Directive } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { hasPermission } from '@/utils/permissions';

export const vPermission: Directive = {
  mounted(el, binding) {
    const authStore = useAuthStore();
    const requiredPermission = binding.value;

    if (!hasPermission(authStore.user, requiredPermission)) {
      el.parentNode && el.parentNode.removeChild(el);
    }
  }
};
```
**طريقة الاستخدام:**
```html
<!-- زر اعتماد سائق جديد -->
<button v-permission="'drivers.review_initial'" @click="approveDriver(driver.id)" class="btn-success">
  اعتماد الحساب
</button>

<!-- زر معالجة طلب سحب -->
<button v-permission="'financial.manage_withdrawals'" @click="processWithdrawal(req.id)" class="btn-primary">
  صرف المستحقات
</button>

<!-- زر توليد الرحلات يدوياً -->
<button v-permission="'trips.generate_daily'" @click="generateDailyTrips()" class="btn-warning">
  توليد الرحلات اليومية
</button>
```

### ب. في React (`<Can>` Component):
```tsx
// src/components/Can.tsx
import React from 'react';
import { useAuth } from '@/hooks/useAuth';
import { hasPermission } from '@/utils/permissions';

export const Can: React.FC<{ permission: string; fallback?: React.ReactNode; children: React.ReactNode }> = ({
  permission,
  fallback = null,
  children
}) => {
  const { user } = useAuth();
  if (!hasPermission(user, permission)) return <>{fallback}</>;
  return <>{children}</>;
};
```
**طريقة الاستخدام:**
```tsx
<Can permission="drivers.suspend">
  <button onClick={() => suspendDriver(driver.id)} className="btn-danger">
    تجميد حساب السائق
  </button>
</Can>
```

---

## 8. شاشة إدارة المشرفين والصلاحيات (Supervisors Management UI)

شاشة المشرفين مخصصة لمدير النظام العام (`super_admin`).

### 1. جلب شجرة الأدوار والصلاحيات عند فتح النافذة:
```javascript
const response = await axios.get('/api/admin/roles-permissions');
const { roles, permissions_tree } = response.data.data;
```

### 2. إضافة مشرف جديد (`POST /api/admin/admins`):
```javascript
const payload = {
  full_name: "محمد سالم الورفلي", // اسم ثلاثي عربي
  email: "mohamed.fleet@darby.ly",
  phone_number: "0912345678",     // 10 أرقام تبدأ بـ 09
  password: "Password123",        // اختياري، ينشئه السيرفر تلقائياً لو تُرك فارغاً
  role_id: 5,                     // معرف الدور المختار
  is_active: 1
};

const formData = new FormData();
Object.keys(payload).forEach(key => formData.append(key, payload[key]));
if (avatarFile) formData.append('avatar', avatarFile);

await axios.post('/api/admin/admins', formData);
```

### 3. تعديل بيانات المشرف:
> ⚠️ **تنبيه تقني حاسم:** عند تعديل بيانات المشرف مع رفع ملف صورة، استخدم **`POST`** إلى المسار `/api/admin/admins/{id}` وليس `PUT`؛ لأن PHP لا يستخرج ملفات `multipart/form-data` في طلبات `PUT/PATCH`.
```javascript
await axios.post(`/api/admin/admins/${adminId}`, formData);
```

---

## 9. المعالجة المركزية للأخطاء ورفض الوصول (Handling 403 Forbidden Centrally)

عندما ينتهي التوكن أو يُرفض وصول المشرف لنقص صلاحية، يُرجع الباك إند:
```json
{
  "status": false,
  "success": false,
  "message": "عذراً، ليس لديك الصلاحية الكافية لتنفيذ هذا الإجراء.",
  "required_permission": "financial.manage_withdrawals"
}
```

### كود معالجة Axios Interceptor المعتمد:
```javascript
// src/api/axios.ts
import axios from 'axios';
import { toast } from 'react-toastify';
import router from '@/router';

const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api/admin',
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  }
});

// حقن التوكن تلقائياً في كل طلب
apiClient.interceptors.request.use(config => {
  const token = localStorage.getItem('admin_access_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// اعتراض الأخطاء والاستجابات
apiClient.interceptors.response.use(
  response => response,
  error => {
    if (!error.response) {
      toast.error('تعذر الاتصال بالخادم، يرجى فحص الشبكة.');
      return Promise.reject(error);
    }

    const { status, data } = error.response;

    // 401 انتهاء الجلسة أو عدم صحة التوكن
    if (status === 401) {
      localStorage.removeItem('admin_access_token');
      toast.warning('انتهت جلسة العمل، يرجى إعادة تسجيل الدخول.');
      router.push('/login');
    }

    // 403 رفض الصلاحية أو الحساب مجمّد
    if (status === 403) {
      const msg = data?.message || 'ليس لديك الصلاحية الكافية لتنفيذ هذا الإجراء.';
      toast.error(msg, { position: 'top-right' });

      // إذا كان الحساب مجمداً من الإدارة، يتم إنهاء الجلسة وتوجيهه لشاشة الدخول
      if (msg.includes('تجميد') || msg.includes('إيقاف حسابك')) {
        localStorage.removeItem('admin_access_token');
        router.push('/login?status=frozen');
      }
    }

    return Promise.reject(error);
  }
);

export default apiClient;
```

---

## 10. جدول المطابقة الشامل (المسارات ⟷ الصلاحيات المطلوبة ⟷ الأدوار)

| القسم في الواجهة | المسار في الـ Backend | الطريقة (Method) | الصلاحية المفتاحية | الأدوار المسموح لها افتراضياً |
| :--- | :--- | :---: | :--- | :--- |
| **شجرة الصلاحيات والأدوار** | `/api/admin/roles-permissions` | `GET` | متاح لكافة المشرفين المسجلين | كافة المشرفين |
| **الملف الشخصي للمشرف** | `/api/admin/profile` | `GET/POST` | متاح للمشرف صاحب الجلسة | كافة المشرفين |
| **إحصائيات الداشبورد** | `/api/admin/dashboard/stats` | `GET` | `dashboard.view_stats` | العمليات / العام |
| **رادار حركة الحافلات الحية** | `/api/admin/dashboard/active-trips` | `GET` | `dashboard.view_radar` | العمليات / العام |
| **توليد الرحلات اليومية يدوياً** | `/api/admin/trips/generate-daily` | `POST` | `trips.generate_daily` | العمليات / العام |
| **إلغاء الرحلات الطارئة بالتعويض** | `/api/admin/financial/trips/{id}/cancel-with-matrix` | `POST` | `trips.emergency_cancel` | العمليات / المالي |
| **قائمة وتفاصيل السائقين** | `/api/admin/drivers` & `/{id}` | `GET` | `drivers.view` | أسطول السائقين |
| **اعتماد طلبات تسجيل السائقين** | `/api/admin/drivers/{id}/review` | `POST` | `drivers.review_initial` | أسطول السائقين |
| **مراجعة تعديلات المركبات والرخص** | `/api/admin/drivers/pending-changes` | `GET/POST` | `drivers.review_changes` | أسطول السائقين |
| **تعديل بيانات السائق مباشرة** | `/api/admin/drivers/{id}` | `POST/PUT` | `drivers.edit_data` | أسطول السائقين |
| **عرض الشكاوى والدعم الفني** | `/api/admin/complaints` & `/{id}` | `GET` | `complaints.view` | الدعم والشكاوى |
| **البت في الشكاوى وإغلاقها** | `/api/admin/complaints/{id}/review` | `POST` | `complaints.resolve` | الدعم والشكاوى |
| **إدارة تقييمات السائقين وحذفها** | `/api/admin/driver-reviews/all` | `GET/DELETE` | `driver_reviews.manage` | الدعم والشكاوى |
| **الملخص المالي والخزينة المركزية** | `/api/admin/financial/summary` | `GET` | `financial.view_summary` | المشرف المالي |
| **دفتر القيود المحاسبية (Ledger)** | `/api/admin/financial/ledger` | `GET` | `financial.view_ledger` | المشرف المالي |
| **طلبات سحب أرباح السائقين** | `/api/admin/financial/withdrawals` | `GET/POST` | `financial.manage_withdrawals` | المشرف المالي |
| **طلبات شحن محافظ المشتركين** | `/api/admin/financial/recharges` | `GET/POST` | `financial.manage_recharges` | المشرف المالي |
| **تحرير مبالغ الأمانات (Escrows)** | `/api/admin/financial/release-escrows`| `POST` | `financial.release_escrows` | المشرف المالي |
| **البت في النزاعات المالية والاسترداد**| `/api/admin/financial/disputes` | `GET/POST` | `financial.resolve_disputes` | المشرف المالي |
| **التسويات الشهرية وإنهاء العقود** | `/api/admin/financial/contracts/...` | `POST` | `financial.manage_settlements` | المشرف المالي |
| **إعدادات التسعير وعمولة المنصة** | `/api/admin/financial/pricing-settings`| `GET/POST`| `financial.manage_pricing` | المشرف المالي |
| **إدارة بوابات وطرق الدفع** | `/api/admin/payment-methods` | `CRUD` | `financial.manage_payment_methods` | المشرف المالي |
| **إدارة المدارس وبواباتها** | `/api/admin/schools` | `CRUD` | `schools.manage` | المدارس / العمليات |
| **إدارة البلديات والمناطق الجغرافية**| `/api/admin/municipalities` / `zones`| `CRUD` | `geography.manage` | التخطيط الجغرافي |
| **إدارة حسابات المشرفين والأدوار** | `/api/admin/admins` | `CRUD` | `admins.manage` | مدير النظام العام |
| **سجل تدقيق إجراءات المشرفين** | `/api/admin/admin-audit-logs` | `GET` | `audit_logs.view` | مدير النظام العام |
| **إدارة الشروط والأحكام وموادها** | `/api/admin/terms` | `CRUD` | `content.manage_terms` | مدير النظام العام |
| **استعراض وتصدير التقارير (Excel/PDF)**| `/api/admin/reports/...` | `GET` | `reports.view` / `reports.export`| المالي / العمليات / العام |

---

> 💡 **نصائح ذهبية لمهندس الفرونت إند:**
> 1. عند استلام كائن المشرف من مسار `login` أو `profile`، احفظ التوكن في `localStorage` والصلاحيات في الـ Store.
> 2. استعمل دوال الفحص (`hasPermission` / `hasAnyPermission`) لإخفاء عناصر الـ UI غير المصرح بها تماماً لتوفير تجربة مستخدم سلسة ونظيفة.
> 3. تعامل دائماً مع الاستجابة `403 Forbidden` مركزياً عبر Axios Interceptor لعرض رسائل خطأ واضحة باللغة العربية.