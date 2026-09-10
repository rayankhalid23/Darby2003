<?php

namespace App\Http\Requests\Api\Parent;

use Illuminate\Foundation\Http\FormRequest;

class RechargeWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount'          => 'required|numeric|min:1|max:50000',
            // ⚠️ كانت مقيَّدة بـ in:ncb,libyana,almadar — أكواد لا وجود لها بجدول
            // payment_methods الفعلي (sadad/tadawe/moamalat وما يُضيفه الأدمن لاحقاً
            // عبر PaymentMethodController)، فكان هذا المسار يرفض كل قيمة حقيقية
            // دائماً. WalletRechargeService::initiateRecharge() هو من يتحقق فعلياً
            // من صحة الكود/الرقم مقابل جدول payment_methods، فلا داعٍ لتكرار قائمة
            // ثابتة هنا عرضة للانحراف عنه.
            'payment_method'  => 'required|string|max:50',
            'reference_number' => 'nullable|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required'           => 'مبلغ الشحن مطلوب.',
            'amount.numeric'            => 'المبلغ يجب أن يكون رقماً.',
            'amount.min'                => 'الحد الأدنى للشحن هو دينار واحد.',
            'amount.max'                => 'الحد الأقصى للشحن هو 50,000 دينار.',
            'payment_method.required'   => 'طريقة الدفع مطلوبة.',
        ];
    }
}
