<?php

namespace App\Http\Requests\Api\Parent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Shared\SubscriptionRequest;

class StoreDriverReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = auth()->id();
        $driverId = (int) $this->input('driver_id');

        $driverIdRules = [
            'required',
            'integer',
            'exists:drivers,id',
        ];

        // ولي الأمر الذي لديه اشتراك (بأي حالة) مع هذا السائق يقدر يترك أكثر
        // من تعليق عادي (لا نطبّق قيد "تعليق واحد فقط" عليه).
        $hasSubscriptionWithDriver = $userId && $driverId
            && SubscriptionRequest::existsForParentAndDriver($userId, $driverId);

        if (!$hasSubscriptionWithDriver) {
            $driverIdRules[] = Rule::unique('driver_reviews', 'driver_id')
                ->where(function ($q) use ($userId) {
                    $q->where('parent_id', $userId);
                })
                ->whereNull('deleted_at');
        }

        return [
            'driver_id' => $driverIdRules,
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ];
    }

    public function messages(): array
    {
        return [
            'driver_id.required'  => 'معرّف السائق مطلوب.',
            'driver_id.exists'    => 'السائق المحدد غير موجود في النظام.',
            'driver_id.unique'    => 'لقد قيمت هذا السائق مسبقاً. يمكنك تعديل تقييمك بدلاً من إضافة تقييم جديد.',
            'rating.required'    => 'التقييم مطلوب.',
            'rating.min'         => 'أقل تقييم هو نجمة واحدة.',
            'rating.max'         => 'أعلى تقييم هو 5 نجوم.',
            'comment.max'        => 'التعليق لا يتجاوز 2000 حرف.',
        ];
    }
}
