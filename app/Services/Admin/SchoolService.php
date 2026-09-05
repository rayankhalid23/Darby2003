<?php

namespace App\Services\Admin;

use App\Models\Parent\School;
use Illuminate\Database\Eloquent\Collection;

class SchoolService
{
    /**
     * جلب المدارس بناءً على الحالة مع شحن العلاقات الجغرافية كاملة لتسريع الأداء
     * (للأدمن يجلب الكل، وللأب يجلب المعتمد فقط)
     */
    public function getSchools(?string $status = null): Collection
    {
        // استخدام with لشحن شجرة الجغرافيا بالكامل كلمح البصر في استعلام واحد
        $query = School::with('zone.subMunicipality.municipality');

        if ($status) {
            return $query->where('status', $status)->get();
        }

        return $query->get();
    }

    /**
     * إضافة مدرسة جديدة في النظام وتفعيلها تلقائياً (status = active)
     */
    public function createSchool(array $data): School
    {
        // 1. التحقق من عدم تكرار اسم المدرسة
        if (isset($data['name']) && School::where('name', $data['name'])->exists()) {
            throw new \Exception('اسم المدرسة موجود مسبقاً.');
        }
    
        // 2. التحقق من عدم تكرار الإحداثيات
        $lat = $data['lat'] ?? $data['latitude'] ?? null;
        $lng = $data['lng'] ?? $data['longitude'] ?? null;
        if ($lat !== null && $lng !== null) {
            $coordsExist = School::where('lat', $lat)
                ->where('lng', $lng)
                ->exists();
    
            if ($coordsExist) {
                throw new \Exception('توجد مدرسة أخرى بنفس الإحداثيات تماماً.');
            }
        }
    
        $status = $data['status'] ?? 'Approved';
        if (strtolower($status) === 'active') {
            $status = 'Approved';
        }
        $data['status'] = $status;
        $school = School::create($data);
    
        return $school->load('zone.subMunicipality.municipality');
    }
    
    /**
     * تحديث بيانات مدرسة وضمان بقاء حالتها نشطة (status = active)
     */
    public function updateSchool(School $school, array $data): School
    {
        // 1. التحقق من عدم تكرار اسم المدرسة (مع استثناء المدرسة الحالية)
        if (isset($data['name'])) {
            $nameExists = School::where('name', $data['name'])
                ->where('id', '!=', $school->id)
                ->exists();
    
            if ($nameExists) {
                throw new \Exception('اسم المدرسة موجود مسبقاً.');
            }
        }
    
        // 2. التحقق من عدم تكرار الإحداثيات في حال تم إرسالها أو تغييرها
        // أعمدة الإحداثيات في جدول schools اسمها lat/lng لا latitude/longitude،
        // فكان هذا الفحص لا يعمل إطلاقاً (والاستعلام به يسقط بخطأ عمود غير موجود).
        if (array_key_exists('lat', $data) || array_key_exists('lng', $data)) {
            $lat = $data['lat'] ?? $school->lat;
            $lng = $data['lng'] ?? $school->lng;

            if ($lat !== null && $lng !== null) {
                $coordsExist = School::where('lat', $lat)
                    ->where('lng', $lng)
                    ->where('id', '!=', $school->id)
                    ->exists();
    
                if ($coordsExist) {
                    throw new \Exception('توجد مدرسة أخرى بنفس الإحداثيات تماماً.');
                }
            }
        }
    
        $status = $data['status'] ?? $school->status ?? 'Approved';
        if (strtolower($status) === 'active') {
            $status = 'Approved';
        }
        $data['status'] = $status;
        $school->update($data);
    
        // شحن البيانات الجغرافية المحدثة لضمان رجوع الـ Resource كامل للفرونت إند
        return $school->load('zone.subMunicipality.municipality');
    }

    /**
     * حذف مدرسة نهائياً من النظام
     */
    public function deleteSchool(School $school): void
    {
        $school->delete();
    }
}