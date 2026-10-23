<?php

namespace App\Http\Requests\Api\Shared;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class OtpRequest extends FormRequest
{
    /**
     * تحديد صلاحية المستخدم للقيام بهذا الطلب
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        if (!$this->has('otp') && $this->has('code')) {
            $this->merge(['otp' => $this->input('code')]);
        }
        if ($this->has('email')) {
            $email = trim(mb_strtolower((string) $this->input('email')));
            $this->merge(['email' => $email]);

            // استعادة بيانات التسجيل من الخطوة الأولى إن لم يُعد الفرونت إرسالها كاملة في verify-otp
            $cachedRegData = \Illuminate\Support\Facades\Cache::get('driver_reg_data:' . $email);
            if (is_array($cachedRegData)) {
                $restore = [];
                foreach (['full_name', 'phone_number', 'gender', 'password', 'alternative_phone', 'device_name', 'platform', 'fcm_token'] as $key) {
                    if (!$this->filled($key) && !empty($cachedRegData[$key])) {
                        $restore[$key] = $cachedRegData[$key];
                    }
                }
                if (!empty($restore)) {
                    $this->merge($restore);
                }
            }
        }
        if ($this->has('gender') && is_string($this->input('gender'))) {
            $this->merge(['gender' => trim(mb_strtolower($this->input('gender')))]);
        }
    }

    /**
     * قواعد التحقق من البيانات (تأمين التحقق الفعلي وإنشاء الحساب)
     */
    public function rules(): array
    {
        $rules = [
            // 1. التحقق من البريد ورمز الـ OTP ومطابقة الحساب الفعلي
            'email'             => 'required|email|max:100|unique:users,email', // هنا الفحص الفعلي لعدم تكرار الإيميل
            'otp'               => 'required|numeric|digits:6', // تم تعديله ليتوافق مع $request->otp في الـ Controller
            
            // 2. شروط إنشاء الحساب الأساسية لضمان وصول بيانات كاملة وسليمة
            'full_name'         => 'required|string|min:10|max:100',
            'phone_number'      => 'required|digits:10|unique:users,phone_number|regex:/^09[0-9]{8}$/',
            'gender'            => 'required|in:male,female',
            'password'          => ['required', 'string', 'min:6', 'regex:/^(?=.*[a-zA-Z])(?=.*\d).+$/'],
            
            // 3. تفاصيل الجهاز والـ FCM (اختيارية ولكن يتم التحقق من بنيتها)
            'alternative_phone' => 'nullable|string|min:7',
            'device_name'       => 'nullable|string',
            'platform'          => 'nullable|string',
            'fcm_token'         => 'nullable|string',
        ];

        // 4. الصورة الشخصية في حال إرسالها أثناء التحقق وإنشاء الحساب (تدعم ملف صورة أو Base64/رابط)
        foreach (['avatar', 'avatar_url', 'photo', 'driver_photo', 'personal_photo', 'image', 'profile_image'] as $field) {
            $rules[$field] = $this->hasFile($field)
                ? 'nullable|file|mimes:jpeg,png,jpg,webp,heic,heif|max:10240'
                : 'nullable|string';
        }

        return $rules;
    }

    /**
     * رسائل الخطأ الجمالية والاحترافية باللغة العربية
     */
    public function messages(): array
    {
        return [
            // رسائل الإيميل والـ OTP
            'email.required'         => 'عذراً، خانة البريد الإلكتروني لا يمكن أن تكون فارغة.',
            'email.email'            => 'صيغة البريد الإلكتروني غير صحيحة، يرجى كتابته بشكل سليم.',
            'email.max'              => 'البريد الإلكتروني طويل جداً، لا يمكن أن يتجاوز 100 حرف.',
            'email.unique'           => 'هذا البريد الإلكتروني مسجل مسبقاً لمستخدم آخر.',
            
            'otp.required'           => 'برجاء إدخال رمز التحقق، لا يمكن ترك الخانة فارغة.',
            'otp.numeric'            => 'عذراً، يجب أن يتكون رمز التحقق من أرقام فقط.',
            'otp.digits'             => 'يجب أن يتكون رمز التحقق من 6 أرقام بالضبط.',

            // رسائل بيانات السائق الأساسية
            'full_name.required'     => 'الاسم الكامل مطلوب لإتمام العملية.',
            'full_name.string'       => 'اسم السائق يجب أن يكون نصاً صالحاً.',
            'full_name.min'          => 'يرجى إدخال الاسم الثلاثي على الأقل (10 أحرف كحد أدنى).',
            'full_name.max'          => 'اسم السائق لا يمكن أن يتجاوز 100 حرف.',

            'phone_number.required'  => 'رقم الهاتف مطلوب.',
            'phone_number.digits'    => 'يجب أن يتكون رقم الهاتف من 10 أرقام.',
            'phone_number.unique'    => 'رقم الهاتف هذا مستخدم من قبل سائق آخر بالفعل.',
            'phone_number.regex'     => 'يجب أن يبدأ رقم الهاتف بـ 09 ويتكون من 10 أرقام (مثال: 0910000000).',

            'gender.required'        => 'يرجى تحديد الجنس.',
            'gender.in'              => 'القيمة المختارة للجنس غير صحيحة، يجب أن تكون male أو female.',

            'password.required'      => 'كلمة المرور مطلوبة.',
            'password.string'        => 'كلمة المرور يجب أن تكون نصاً.',
            'password.min'           => 'يجب ألا تقل كلمة المرور عن 6 أحرف.',
            'password.regex'         => 'كلمة المرور يجب أن تحتوي على حرف إنجليزي ورقم على الأقل.',

            'alternative_phone.string' => 'رقم الهاتف الاحتياطي يجب أن يكون نصاً صالحاً.',
            'alternative_phone.min'    => 'رقم الهاتف الاحتياطي يجب ألا يقل عن 7 أرقام.',
            'device_name.string'       => 'اسم الجهاز يجب أن يكون نصاً صالحاً.',
            'platform.string'          => 'نوع المنصة يجب أن يكون نصاً صالحاً.',
            'fcm_token.string'         => 'رمز الإشعارات (FCM Token) يجب أن يكون نصاً صالحاً.',
        ];
    }

    /**
     * التعامل مع فشل التحقق لإرجاع استجابة JSON موحدة واحترافية للـ API
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status'     => false,
            'error_code' => 'VALIDATION_ERROR',
            'message'    => $validator->errors()->first() ?: 'خطأ في البيانات المرسلة، يرجى تصحيح الحقول.',
            'errors'     => $validator->errors()
        ], 422));
    }
}