<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Driver\StoreAddressRequest;
use App\Http\Requests\Api\Driver\UpdateAddressRequest;
use App\Http\Resources\Api\Driver\AddressResource;
use App\Models\Driver\Address;
use App\Services\Driver\AddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class AddressController extends Controller
{
    protected AddressService $addressService;

    public function __construct(AddressService $addressService)
    {
        $this->addressService = $addressService;
    }

    private function getUserId(): int
    {
        return auth('sanctum')->id() ?? auth()->id() ?? 1;
    }

    public function index(): JsonResponse
    {
        $userId = $this->getUserId(); 
        $addresses = $this->addressService->getDriverAddresses($userId);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب دفتر عناوين السائق بنجاح.',
            'data'    => AddressResource::collection($addresses)
        ], Response::HTTP_OK);
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        $userId = $this->getUserId(); 
        $address = $this->addressService->createAddress($userId, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة العنوان الجديد للسائق بنجاح.',
            'data'    => new AddressResource($address)
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateAddressRequest $request, Address $address): JsonResponse
    {
        $userId = $this->getUserId(); 
        $updatedAddress = $this->addressService->updateAddress($address, $userId, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث بيانات عنوان السائق بنجاح.',
            'data'    => new AddressResource($updatedAddress)
        ], Response::HTTP_OK);
    }

    public function destroy(Address $address): JsonResponse
    {
        try {
            $this->addressService->deleteAddress($address);

            return response()->json([
                'success' => true,
                'message' => 'تم حذف العنوان بنجاح من دفتر عناوين السائق.'
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'success'    => false,
                'error_code' => 'ADDRESS_ALREADY_IN_USE',
                'message'    => $e->getMessage()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
