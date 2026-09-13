<?php

namespace App\Http\Controllers\Api\Parent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Parent\AvailableTripsRequest;
use App\Http\Requests\Api\Parent\StoreLocationChangeRequestRequest;
use App\Http\Resources\Api\Shared\LocationChangeRequestResource;
use App\Services\Shared\LocationChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;

class LocationChangeController extends Controller
{
    protected LocationChangeService $service;

    public function __construct(LocationChangeService $service)
    {
        $this->service = $service;
    }

    /**
     * GET /options — أطفال ولي الأمر + عناوينه المحفوظة كلها.
     */
    public function options(Request $request): JsonResponse
    {
        try {
            $options = $this->service->getChangeableOptions($request->user()->id);

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الخيارات بنجاح.',
                'data'    => $options,
            ], 200);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /available-trips — لكل طفل مختار، الرحلات المتاحة في التاريخ.
     */
    public function availableTrips(AvailableTripsRequest $request): JsonResponse
    {
        try {
            $data = $this->service->getAvailableTripsForChildren(
                $request->user()->id,
                $request->input('child_ids', []),
                $request->input('date')
            );

            return response()->json([
                'success' => true,
                'message' => 'تم جلب الرحلات المتاحة بنجاح.',
                'data'    => $data,
            ], 200);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /preview — معاينة السعر بعد التجميع بالسائق (لا حفظ).
     */
    public function preview(StoreLocationChangeRequestRequest $request): JsonResponse
    {
        try {
            $quote = $this->service->quoteGroupedChange(
                $request->user()->id,
                $request->input('point_type'),
                $request->input('date'),
                $request->input('selections', []),
                $request->filled('address_id') ? (int) $request->input('address_id') : null,
                $request->filled('lat') ? (float) $request->input('lat') : null,
                $request->filled('lng') ? (float) $request->input('lng') : null,
                $request->input('label')
            );

            // نُنظِّف الحقل الداخلي trips_internal قبل الإرسال.
            $grouped = array_map(function ($g) {
                unset($g['trips_internal']);
                return $g;
            }, $quote['grouped']);

            return response()->json([
                'success' => true,
                'message' => 'راجع التفاصيل قبل التأكيد.',
                'data'    => [
                    'date'         => $quote['parsed_date'],
                    'point_type'   => $quote['point_type'],
                    'new_location' => [
                        'address_id' => $quote['address_id'],
                        'lat'        => $quote['new_lat'],
                        'lng'        => $quote['new_lng'],
                        'label'      => $quote['new_label'],
                    ],
                    'grouped_requests' => $grouped,
                    'summary'          => $quote['summary'],
                ],
            ], 200);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST / — إنشاء الطلبات المُجمَّعة (طلب لكل سائق).
     */
    public function store(StoreLocationChangeRequestRequest $request): JsonResponse
    {
        try {
            $created = $this->service->createGroupedChange(
                $request->user()->id,
                $request->input('point_type'),
                $request->input('date'),
                $request->input('selections', []),
                $request->filled('address_id') ? (int) $request->input('address_id') : null,
                $request->filled('lat') ? (float) $request->input('lat') : null,
                $request->filled('lng') ? (float) $request->input('lng') : null,
                $request->input('label')
            );

            $count = count($created);
            $totalFee = array_sum(array_map(fn ($r) => (float) $r->fee_amount, $created));

            return response()->json([
                'success' => true,
                'message' => "تم إرسال {$count} طلب تغيير موقع للسائقين المعنيين.",
                'data'    => [
                    'created_requests'  => LocationChangeRequestResource::collection(collect($created)),
                    'total_fee_pending' => round($totalFee, 2),
                    'currency'          => 'د.ل',
                ],
            ], 201);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET / — سجل طلبات ولي الأمر (مع فلترة اختيارية بالحالة).
     */
    public function index(Request $request): JsonResponse
    {
        $requests = $this->service->getParentRequests(
            $request->user()->id,
            $request->query('status')
        );

        return response()->json([
            'success' => true,
            'message' => 'تم جلب طلبات تغيير الموقع بنجاح.',
            'data'    => LocationChangeRequestResource::collection($requests),
        ], 200);
    }

    /**
     * DELETE /{id} — إلغاء طلب معلّق قبل رد السائق.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $cancelled = $this->service->cancelPendingRequest($request->user()->id, $id);

            return response()->json([
                'success' => true,
                'message' => 'تم إلغاء الطلب بنجاح.',
                'data'    => new LocationChangeRequestResource($cancelled),
            ], 200);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
