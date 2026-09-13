<?php

namespace App\Http\Requests\Api\Driver;

use App\Services\Shared\FinancialLedgerService;
use Illuminate\Foundation\Http\FormRequest;

class WithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // الاستلام يدوي من مقرّ الشركة، فالطلب لا يحتاج بيانات بنكية أو رقم هاتف —
        // فقط قيمة السحب. الحد الأدنى يُشتق من ثابت النظام المالي بدل تكراره هنا.
        return [
            'amount' => 'required|numeric|min:' . (FinancialLedgerService::MIN_WITHDRAWAL_AMOUNT / 100) . '|max:50000',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'المبلغ المطلوب سحبه مطلوب.',
            'amount.numeric'  => 'المبلغ يجب أن يكون رقماً.',
            'amount.min'      => 'الحد الأدنى للسحب هو ' . (FinancialLedgerService::MIN_WITHDRAWAL_AMOUNT / 100) . ' د.ل.',
            'amount.max'      => 'الحد الأقصى للسحب هو 50,000 دينار.',
        ];
    }
}
