<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Driver\Driver;
use App\Models\Parent\Child;
use App\Models\Shared\Trip;
use App\Models\Shared\TripBreakdownDispatch;
use App\Models\Shared\TripEvent;
use App\Models\Shared\TripStop;
use App\Models\User;
use App\Services\Trip\EmergencyBreakdownService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AdminEmergencyController extends Controller
{
    protected EmergencyBreakdownService $breakdownService;

    public function __construct(EmergencyBreakdownService $breakdownService)
    {
        $this->breakdownService = $breakdownService;
    }

    /**
     * قائمة بلاغات الطوارئ وحالات تعطل الحافلات
     * GET /api/admin/trips/emergencies
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = TripBreakdownDispatch::with([
                'trip.driver.user',
                'trip.driver.vehicles',
                'trip.route',
                'substituteDriver.user',
                'substituteDriver.vehicles',
            ])->latest('id');

            $statusFilter = strtolower($request->query('status', 'all'));

            if ($statusFilter === 'active') {
                $query->whereIn('status', [
                    TripBreakdownDispatch::STATUS_PENDING,
                    TripBreakdownDispatch::STATUS_BROADCASTED,
                    TripBreakdownDispatch::STATUS_ACCEPTED,
                    TripBreakdownDispatch::STATUS_UNRESOLVED,
                    TripBreakdownDispatch::STATUS_DECLINED_ALL,
                ]);
            } elseif ($statusFilter === 'unresolved') {
                $query->whereIn('status', [
                    TripBreakdownDispatch::STATUS_UNRESOLVED,
                    TripBreakdownDispatch::STATUS_DECLINED_ALL,
                ]);
            } elseif ($statusFilter === 'accepted') {
                $query->where('status', TripBreakdownDispatch::STATUS_ACCEPTED);
            } elseif ($statusFilter === 'completed') {
                $query->where('status', TripBreakdownDispatch::STATUS_COMPLETED);
            } elseif ($statusFilter !== 'all') {
                $query->where('status', $statusFilter);
            }

            $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
            $paginated = $query->paginate($perPage);

            $items = collect($paginated->items())->map(function (TripBreakdownDispatch $dispatch) {
                return $this->formatDispatchSummary($dispatch);
            });

            // عدادات سريعة للوحة التحكم
            $stats = [
                'total_emergencies' => TripBreakdownDispatch::count(),
                'active_now'        => TripBreakdownDispatch::whereIn('status', [
                    TripBreakdownDispatch::STATUS_PENDING,
                    TripBreakdownDispatch::STATUS_BROADCASTED,
                    TripBreakdownDispatch::STATUS_ACCEPTED,
                ])->count(),
                'unresolved_count'  => TripBreakdownDispatch::whereIn('status', [
                    TripBreakdownDispatch::STATUS_UNRESOLVED,
                    TripBreakdownDispatch::STATUS_DECLINED_ALL,
                ])->count(),
                'completed_count'   => TripBreakdownDispatch::where('status', TripBreakdownDispatch::STATUS_COMPLETED)->count(),
            ];

            return response()->json([
                'status'     => 'success',
                'stats'      => $stats,
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'total'        => $paginated->total(),
                    'per_page'     => $paginated->perPage(),
                    'last_page'    => $paginated->lastPage(),
                ],
                'data'       => $items,
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'حدث خطأ أثناء جلب بلاغات الطوارئ: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * تفاصيل شاملة 360 درجة لحالة طوارئ محددة
     * GET /api/admin/trips/emergencies/{dispatchId}
     */
    public function show($dispatchId): JsonResponse
    {
        try {
            /** @var TripBreakdownDispatch $dispatch */
            $dispatch = TripBreakdownDispatch::with([
                'trip.driver.user',
                'trip.driver.vehicles',
                'trip.route',
                'substituteDriver.user',
                'substituteDriver.vehicles',
            ])->find($dispatchId);

            if (!$dispatch) {
                return response()->json([
                    'status'     => 'error',
                    'error_code' => 'DISPATCH_NOT_FOUND',
                    'message'    => "طلب الطوارئ رقم #{$dispatchId} غير موجود في النظام.",
                ], 404);
            }

            $trip = $dispatch->trip;
            $origDriver = $trip?->driver;
            $origUser = $origDriver?->user;
            $origVehicle = $origDriver?->vehicles->first();

            $subDriver = $dispatch->substituteDriver;
            $subUser = $subDriver?->user;
            $subVehicle = $subDriver?->vehicles->first();

            $lat = (float) $dispatch->breakdown_lat;
            $lng = (float) $dispatch->breakdown_lng;
            $mapsUrl = "https://maps.google.com/?q={$lat},{$lng}";

            // 1. تفصيل الأطفال وحالاتهم بدقة
            $strandedIds = $dispatch->stranded_children_ids ?? [];
            $childrenCollection = Child::with(['school', 'parent', 'address'])
                ->whereIn('id', $strandedIds)
                ->get();

            // فحص من صعد بالحافلة الأصلية
            $originalBoardedIds = TripStop::where('trip_id', $dispatch->trip_id)
                ->whereIn('child_id', $strandedIds)
                ->where('status', TripStop::STATUS_BOARDED)
                ->pluck('child_id')
                ->all();

            // أحداث رحلة السائق البديل إن وُجدت
            $subTripId = $dispatch->substitute_trip_id;
            $subEvents = $subTripId
                ? TripEvent::where('trip_id', $subTripId)->whereIn('child_id', $strandedIds)->get()
                : collect();

            $subStops = $subTripId
                ? TripStop::where('trip_id', $subTripId)->whereIn('child_id', $strandedIds)->get()->keyBy('child_id')
                : collect();

            $childrenDetails = [];
            $boardedInBusCount = 0;
            $waitingCount = 0;
            $deliveredCount = 0;

            $isGo = in_array($trip?->trip_type, ['Morning', 'ذهاب']) 
                || in_array($trip?->shift_slot, ['morning_go', 'afternoon_go']);

            foreach ($childrenCollection as $c) {
                $hasDroppedOff = $subEvents->contains(fn($e) => $e->child_id == $c->id && $e->action_type === 'dropped_off');
                $wasBoardedInOriginal = in_array($c->id, $originalBoardedIds);
                $hasPickedUpBySub = $subEvents->contains(fn($e) => $e->child_id == $c->id && $e->action_type === 'picked_up');

                $childStop = $subStops->get($c->id);

                if ($hasDroppedOff || $childStop?->status === TripStop::STATUS_DROPPED_OFF_SCHOOL || $childStop?->status === TripStop::STATUS_DELIVERED_HOME) {
                    $cStatus = 'delivered';
                    $cStatusLabel = 'تم التوصيل بسلام 🏫';
                    $deliveredCount++;
                } elseif ($hasPickedUpBySub || ($wasBoardedInOriginal && $dispatch->status === TripBreakdownDispatch::STATUS_ACCEPTED)) {
                    $cStatus = 'on_board';
                    $cStatusLabel = 'على متن حافلة الإنقاذ البديلة 🚌';
                    $boardedInBusCount++;
                } elseif ($wasBoardedInOriginal) {
                    $cStatus = 'stranded_in_broken_bus';
                    $cStatusLabel = 'داخل الحافلة المعطلة (بانتظار وصول البديل) ⚠️';
                    $boardedInBusCount++;
                } else {
                    $cStatus = 'waiting_pickup';
                    $cStatusLabel = $isGo ? 'ينتظر في منزله (لم يُلتقط بعد)' : 'ينتظر في مدرسته (لم يُلتقط بعد)';
                    $waitingCount++;
                }

                $parentUser = $c->parent;

                $childrenDetails[] = [
                    'child_id'         => (int) $c->id,
                    'full_name'        => $c->full_name ?? $c->name,
                    'grade'            => $c->grade,
                    'gender'           => $c->gender,
                    'status'           => $cStatus,
                    'status_label'     => $cStatusLabel,
                    'was_in_bus'       => $wasBoardedInOriginal,
                    'school'           => [
                        'id'      => $c->school?->id,
                        'name'    => $c->school?->name ?? 'المدرسة',
                        'address' => $c->school?->address,
                        'lat'     => (float) ($c->school?->lat ?? 0),
                        'lng'     => (float) ($c->school?->lng ?? 0),
                    ],
                    'parent'           => [
                        'id'                => $parentUser?->id,
                        'name'              => $parentUser?->full_name ?? 'ولي الأمر',
                        'phone'             => $parentUser?->phone_number,
                        'alternative_phone' => $parentUser?->alternative_phone,
                    ],
                    'home_address'     => [
                        'label' => $c->address?->label ?? 'المنزل',
                        'lat'   => (float) ($c->address?->lat ?? 0),
                        'lng'   => (float) ($c->address?->lng ?? 0),
                    ],
                ];
            }

            $candidateDrivers = Driver::with('user', 'vehicles')
                ->whereIn('id', $dispatch->candidate_driver_ids ?? [])
                ->get()
                ->map(fn($d) => [
                    'id'           => $d->id,
                    'name'         => $d->user?->full_name,
                    'phone'        => $d->user?->phone_number,
                    'plate_number' => $d->vehicles->first()?->plate_number,
                ]);

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'dispatch_id'             => $dispatch->id,
                    'status'                  => $dispatch->status,
                    'status_label'            => $this->getStatusLabel($dispatch->status),
                    'is_unresolved'           => in_array($dispatch->status, [TripBreakdownDispatch::STATUS_UNRESOLVED, TripBreakdownDispatch::STATUS_DECLINED_ALL]),
                    'has_substitute_accepted' => $dispatch->status === TripBreakdownDispatch::STATUS_ACCEPTED || !empty($dispatch->substitute_driver_id),
                    'financial_settled'       => (bool) $dispatch->financial_settled,
                    'settled_at'              => $dispatch->settled_at,
                    'trip_fare_amount'        => (float) $dispatch->trip_fare_amount,
                    'breakdown_location'      => [
                        'latitude'  => $lat,
                        'longitude' => $lng,
                        'lat'       => $lat,
                        'lng'       => $lng,
                        'maps_url'  => $mapsUrl,
                    ],
                    'reason'                  => $dispatch->reason ?? $trip?->suspension_reason ?? 'عطل طارئ وتوقف للمركبة',
                    'dispatched_at'           => $dispatch->dispatched_at,
                    'expires_at'              => $dispatch->expires_at,
                    'accepted_at'             => $dispatch->accepted_at,
                    'completed_at'            => $dispatch->completed_at,

                    // معلومات الرحلة الأصلية
                    'original_trip'           => [
                        'id'         => $trip?->id,
                        'trip_type'  => $trip?->trip_type,
                        'shift_slot' => $trip?->shift_slot,
                        'trip_date'  => $trip?->trip_date,
                        'route_name' => $trip?->route?->route_name ?? $trip?->route?->name ?? 'المسار المدرسي',
                        'status'     => $trip?->status,
                    ],

                    // السائق الأصلي المعطل
                    'original_driver'         => [
                        'id'           => $origDriver?->id,
                        'name'         => $origUser?->full_name,
                        'phone'        => $origUser?->phone_number,
                        'current_lat'  => (float) ($origDriver?->current_lat ?? $lat),
                        'current_lng'  => (float) ($origDriver?->current_lng ?? $lng),
                        'vehicle'      => $origVehicle ? [
                            'brand'        => $origVehicle->brand ?? $origVehicle->make,
                            'model'        => $origVehicle->model,
                            'plate_number' => $origVehicle->plate_number,
                            'color'        => $origVehicle->color,
                            'capacity'     => $origVehicle->capacity_manual ?? $origVehicle->capacity,
                        ] : null,
                    ],

                    // السائق البديل المكلف (إن وجد)
                    'substitute_driver'       => $subDriver ? [
                        'id'                 => $subDriver->id,
                        'name'               => $subUser?->full_name,
                        'phone'              => $subUser?->phone_number,
                        'current_lat'        => (float) ($subDriver->current_lat ?? 0),
                        'current_lng'        => (float) ($subDriver->current_lng ?? 0),
                        'substitute_trip_id' => $dispatch->substitute_trip_id,
                        'vehicle'            => $subVehicle ? [
                            'brand'        => $subVehicle->brand ?? $subVehicle->make,
                            'model'        => $subVehicle->model,
                            'plate_number' => $subVehicle->plate_number,
                            'color'        => $subVehicle->color,
                        ] : null,
                    ] : null,

                    // ملخص الأطفال
                    'children_summary'        => [
                        'total_stranded_count'    => count($strandedIds),
                        'boarded_in_bus_count'    => $boardedInBusCount,
                        'waiting_at_pickup_count' => $waitingCount,
                        'delivered_count'         => $deliveredCount,
                    ],

                    // تفاصيل جميع الأطفال
                    'children_details'        => $childrenDetails,

                    // السائقين المرشحين
                    'candidates'              => [
                        'count'   => $candidateDrivers->count(),
                        'drivers' => $candidateDrivers,
                    ],
                ],
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'حدث خطأ أثناء جلب تفاصيل الطوارئ: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * تكليف يدوي لسائق بديل من قِبل الأدمن (تدخل إداري)
     * POST /api/admin/trips/emergencies/{dispatchId}/assign-substitute
     */
    public function assignSubstitute(Request $request, $dispatchId): JsonResponse
    {
        $request->validate([
            'substitute_driver_id' => 'required|integer|exists:drivers,id',
        ]);

        try {
            $substituteDriverId = (int) $request->input('substitute_driver_id');

            $result = $this->breakdownService->acceptBreakdownDispatch($dispatchId, $substituteDriverId);

            return response()->json([
                'status'             => 'success',
                'message'            => 'تم تكليف السائق البديل وتحديث مسار الرحلة بنجاح.',
                'dispatch_id'        => (int) $dispatchId,
                'substitute_trip_id' => $result['substitute_trip_id'],
                'substitute_driver'  => $result['substitute_driver'],
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500);
        }
    }

    /**
     * إغلاق/حل حالة الطوارئ يدوياً من قِبل الأدمن
     * POST /api/admin/trips/emergencies/{dispatchId}/resolve
     */
    public function resolve(Request $request, $dispatchId): JsonResponse
    {
        try {
            $dispatch = TripBreakdownDispatch::findOrFail($dispatchId);
            $dispatch->update([
                'status'       => TripBreakdownDispatch::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            return response()->json([
                'status'      => 'success',
                'message'     => 'تم إغلاق وتسجيل انتهاء حالة الطوارئ بنجاح.',
                'dispatch_id' => (int) $dispatchId,
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function formatDispatchSummary(TripBreakdownDispatch $dispatch): array
    {
        $trip = $dispatch->trip;
        $origDriver = $trip?->driver;
        $origUser = $origDriver?->user;
        $subDriver = $dispatch->substituteDriver;
        $subUser = $subDriver?->user;

        $lat = (float) $dispatch->breakdown_lat;
        $lng = (float) $dispatch->breakdown_lng;

        return [
            'id'                      => $dispatch->id,
            'dispatch_id'             => $dispatch->id,
            'trip_id'                 => $dispatch->trip_id,
            'status'                  => $dispatch->status,
            'status_label'            => $this->getStatusLabel($dispatch->status),
            'reason'                  => $dispatch->reason ?? $trip?->suspension_reason ?? 'عطل طارئ',
            'stranded_children_count' => (int) $dispatch->stranded_children_count,
            'trip_fare_amount'        => (float) $dispatch->trip_fare_amount,
            'breakdown_location'      => [
                'latitude'  => $lat,
                'longitude' => $lng,
                'lat'       => $lat,
                'lng'       => $lng,
                'maps_url'  => "https://maps.google.com/?q={$lat},{$lng}",
            ],
            'original_driver'         => [
                'id'    => $origDriver?->id,
                'name'  => $origUser?->full_name ?? 'السائق الأصلي',
                'phone' => $origUser?->phone_number,
            ],
            'substitute_driver'       => $subDriver ? [
                'id'    => $subDriver->id,
                'name'  => $subUser?->full_name ?? 'السائق البديل',
                'phone' => $subUser?->phone_number,
            ] : null,
            'substitute_trip_id'      => $dispatch->substitute_trip_id,
            'dispatched_at'           => $dispatch->dispatched_at,
            'expires_at'              => $dispatch->expires_at,
            'accepted_at'             => $dispatch->accepted_at,
        ];
    }

    private function getStatusLabel(string $status): string
    {
        return match ($status) {
            TripBreakdownDispatch::STATUS_PENDING     => 'قيد الإعداد',
            TripBreakdownDispatch::STATUS_BROADCASTED  => 'جاري البحث وبث الطلب للسائقين البدلاء 🔄',
            TripBreakdownDispatch::STATUS_ACCEPTED     => 'تم قبول المهمة من سائق بديل وهو في الطريق 🚐',
            TripBreakdownDispatch::STATUS_DECLINED_ALL => 'رفض جميع المرشحين - استلام يدوي ⚠️',
            TripBreakdownDispatch::STATUS_UNRESOLVED   => 'تعذر إيجاد بديل - تم إشعار أولياء الأمور للاستلام 🚨',
            TripBreakdownDispatch::STATUS_COMPLETED    => 'مكتملة ومُغلقة بنجاح ✅',
            TripBreakdownDispatch::STATUS_CANCELLED    => 'تم إلغاء البلاغ (إصلاح العطل) ℹ️',
            TripBreakdownDispatch::STATUS_EXPIRED      => 'انتهت صلاحية الطلب 🕒',
            default                                   => $status,
        };
    }
}
