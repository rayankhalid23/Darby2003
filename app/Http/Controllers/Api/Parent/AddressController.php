<?php

namespace App\Http\Controllers\Api\Parent;

use App\Exceptions\Parent\AddressOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Parent\StoreAddressRequest;
use App\Http\Requests\Api\Parent\UpdateAddressRequest;
use App\Http\Resources\Api\Parent\AddressResource;
use App\Models\Parent\Address;
use App\Services\Parent\AddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class AddressController extends Controller
{
    protected AddressService $addressService;

    public function __construct(AddressService $addressService)
    {
        $this->addressService = $addressService;
    }

    /**
     * تحويل أخطاء الأعمال إلى رد API موحّد.
     *
     * بدون هذا كانت رسائل مثل «لا يمكن حذف عنوان مرتبط بطفل» تسقط في شبكة الأمان
     * العامة وتعود للمستخدم كـ 500 برسالة «حدث خطأ داخلي في النظام».
     */
    private function businessError(AddressOperationException $e): JsonResponse
    {
        return response()->json(array_filter([
            'success'    => false,
            'status'     => false,
            'error_code' => $e->getErrorCode(),
            'message'    => $e->getMessage(),
            'context'    => $e->getContext() ?: null,
        ], fn ($value) => $value !== null), $e->getStatusCode());
    }

    /**
     * جلب العنوان المطلوب مقيّداً بمالكه الحالي حصراً.
     *
     * 🔒 إغلاق ثغرة IDOR: لا نعتمد على Route-Model-Binding العام، فبدون هذا التقييد
     * كان بإمكان أي مستخدم الوصول لعنوان مستخدم آخر برقمه.
     */
    private function ownedAddress($address, int $userId): Address
    {
        return Address::withTrashed()
            ->where('id', $address instanceof Address ? $address->id : $address)
            ->where('user_id', $userId)
            ->firstOrFail();
    }

    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $userId = auth()->id();
        
        $isDefault = null;
        if ($request->has('is_default')) {
            $isDefault = filter_var($request->query('is_default'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $addresses = $this->addressService->getParentAddresses($userId, $isDefault);

        return response()->json([
            'success'            => true,
            'message'            => 'تم جلب دفتر العناوين بنجاح.',
            'default_address_id' => $addresses->firstWhere('is_default', true)?->id,
            'data'               => AddressResource::collection($addresses)
        ], Response::HTTP_OK);
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        $userId = auth()->id();

        try {
            $address = $this->addressService->createAddress($userId, $request->validated());
        } catch (AddressOperationException $e) {
            return $this->businessError($e);
        }

        return response()->json([
            'success' => true,
            'message' => $address->is_default
                ? 'تم إضافة العنوان الجديد وتعيينه كعنوان رئيسي وإسناد جميع أطفالك إليه.'
                : 'تم إضافة العنوان الجديد بنجاح كعنوان ثانوي.',
            'data'    => new AddressResource($address)
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateAddressRequest $request, $address): JsonResponse
    {
        $userId  = auth()->id();
        $address = $this->ownedAddress($address, $userId);

        try {
            $result = $this->addressService->updateAddress($address, $userId, $request->validated());
        } catch (AddressOperationException $e) {
            return $this->businessError($e);
        }

        $responseData = [
            'success' => true,
            'message' => $result['message'],
            'data'    => new AddressResource($result['address'])
        ];

        if (isset($result['cancelled_requests_count']) && $result['cancelled_requests_count'] > 0) {
            $responseData['cancelled_requests_count'] = $result['cancelled_requests_count'];
            $responseData['cancelled_request_ids'] = $result['cancelled_request_ids'];
        }

        return response()->json($responseData, Response::HTTP_OK);
    }

    /**
     * تعيين عنوان كعنوان رئيسي مفعّل لولي الأمر.
     * PATCH /api/parent/addresses/{address}/set-default
     */
    public function setDefault($address): JsonResponse
    {
        $userId  = auth()->id();
        $address = $this->ownedAddress($address, $userId);

        try {
            $result = $this->addressService->setDefaultAddress($address, $userId);
        } catch (AddressOperationException $e) {
            return $this->businessError($e);
        }

        return response()->json([
            'success'                   => true,
            'message'                   => $result['message'],
            'changed'                   => $result['changed'],
            'cancelled_requests_count'  => $result['cancelled_requests_count'],
            'cancelled_request_ids'     => $result['cancelled_request_ids'],
            'reassigned_children_count' => $result['reassigned_children_count'],
            'data'                      => new AddressResource($address->fresh()->load('zone')),
        ], Response::HTTP_OK);
    }

    public function destroy($address): JsonResponse
    {
        $address = $this->ownedAddress($address, auth()->id());

        try {
            $this->addressService->deleteAddress($address);
        } catch (AddressOperationException $e) {
            return $this->businessError($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم حذف العنوان بنجاح.'
        ], Response::HTTP_OK);
    }
}
