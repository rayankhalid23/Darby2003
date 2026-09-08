<?php

namespace App\Services\Parent;

use App\Exceptions\Parent\AddressOperationException;
use App\Models\Parent\Address;
use App\Models\Parent\Child;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\SubscriptionRequest;
use App\Services\Notification\NotificationService;
use App\Services\Shared\SubscriptionRequestService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AddressService
{
    /**
     * جلب كافة عناوين ولي الأمر الحالي (العنوان الرئيسي أولاً).
     */
    public function getParentAddresses(int $parentId, ?bool $isDefault = null)
    {
        $query = Address::where('user_id', $parentId)->with('zone');

        if ($isDefault !== null) {
            $query->where('is_default', $isDefault);
        }

        return $query->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
    }

    /**
     * إنشاء عنوان جديد لولي الأمر.
     *
     * قاعدة العمل: أول عنوان يُضاف يصبح رئيسياً تلقائياً (ويُسنَد إليه كل الأطفال)،
     * وأي عنوان لاحق يُضاف ثانوياً (is_default = false). لجعل عنوان لاحق رئيسياً
     * يُمرَّر is_default = true فيمر عبر نفس حراسات تبديل العنوان الرئيسي.
     */
    public function createAddress(int $parentId, array $data): Address
    {
        return DB::transaction(function () use ($parentId, $data) {
            $wantsDefault = $this->toBool($data['is_default'] ?? false);
            $hasDefault   = $this->currentDefault($parentId) !== null;

            $address = Address::create([
                'user_id'    => $parentId,
                'zone_id'    => $data['zone_id'] ?? null,
                'label'      => $data['label'],
                'lat'        => $data['lat'],
                'lng'        => $data['lng'],
                // أول عنوان على الإطلاق يصبح الرئيسي إجبارياً حتى لا يبقى ولي الأمر
                // بلا عنوان مفعّل تُسنَد إليه أطفاله.
                'is_default' => $hasDefault ? false : true,
            ]);

            if (!$hasDefault) {
                // لا يوجد عنوان رئيسي سابق ⇒ لا اشتراكات على عنوان قديم، إسناد مباشر.
                $this->assignChildrenToAddress($parentId, $address->id);
            } elseif ($wantsDefault) {
                // يوجد عنوان رئيسي سابق ⇒ تبديل كامل بكل الحراسات.
                $this->setDefaultAddress($address, $parentId);
                $address->refresh();
            }

            return $address->load('zone');
        });
    }

    /**
     * تحديث عنوان موجود (يدعم التعديل الجزئي الصارم مع الحماية الكاملة).
     */
    public function updateAddress(Address $address, int $parentId, array $data): array
    {
        $newLat = $data['lat'] ?? $address->lat;
        $newLng = $data['lng'] ?? $address->lng;
        $newZoneId = $data['zone_id'] ?? $address->zone_id;

        $locationChanged = (array_key_exists('lat', $data) && $newLat != $address->lat) || 
                           (array_key_exists('lng', $data) && $newLng != $address->lng);
        $zoneChanged = (array_key_exists('zone_id', $data) && $newZoneId != $address->zone_id);

        $addressId = $address->id;

        // 3. معالجة تغيير حالة العنوان الرئيسي (is_default) بمعزل عن باقي الحقول
        $defaultRequested = null;
        if (array_key_exists('is_default', $data)) {
            $defaultRequested = $this->toBool($data['is_default']);
            unset($data['is_default']);
        }

        return DB::transaction(function () use ($address, $parentId, $data, $addressId, $defaultRequested, $locationChanged, $zoneChanged) {
            $cancelledIds = [];
            $message = 'تم تحديث بيانات العنوان بنجاح.';

            // إذا تم تغيير الإحداثيات أو المنطقة الجغرافية
            if ($locationChanged || $zoneChanged) {
                $children = Child::where('address_id', $addressId)->get();

                if ($children->isNotEmpty()) {
                    // الحارس 1: اشتراكات مفعّلة أو مجدولة -> رفض قاطع
                    $blockingChildren = $this->childrenWithActiveSubscriptions($children);

                    if ($blockingChildren->isNotEmpty()) {
                        throw new AddressOperationException(
                            'لا يمكن تعديل الموقع أو المنطقة الجغرافية: يوجد اشتراكات مفعّلة للأطفال ('
                                . $blockingChildren->implode('، ')
                                . ') على هذا العنوان. لا يُسمح بتعديل العنوان إلا إذا لم يكن لدى هؤلاء الأطفال اشتراك قائم.',
                            'ADDRESS_HAS_ACTIVE_SUBSCRIPTIONS',
                            422,
                            ['children' => $blockingChildren->values()->all()]
                        );
                    }
                }

                // الحارس 2: طلبات معلقة -> إلغاء تلقائي للطلبات المرتبطة بهذا العنوان
                $cancelledIds = $this->cancelPendingRequestsForAddress($addressId, $parentId, $address);

                if (!empty($cancelledIds)) {
                    $message = 'تم تحديث بيانات العنوان بنجاح. تم إلغاء طلبات الاشتراك المعلقة المرتبطة بهذا العنوان (عددها '
                        . count($cancelledIds)
                        . ') بسبب تغيير الموقع أو المنطقة الجغرافية.';
                }
            }

            // 3-أ. تنفيذ التعديل الجزئي لباقي الحقول
            if (!empty($data)) {
                $address->update($data);
            }

            // 3-ب. تبديل العنوان الرئيسي عبر نفس الحراسات المركزية
            if ($defaultRequested === true) {
                $defaultResult = $this->setDefaultAddress($address->refresh(), $parentId);
                // دمج رسائل التبديل إذا حدثت
                if ($defaultResult['cancelled_requests_count'] > 0) {
                    $cancelledIds = array_unique(array_merge($cancelledIds, $defaultResult['cancelled_request_ids']));
                    $message = $defaultResult['message'];
                }
            } elseif ($defaultRequested === false && $address->refresh()->is_default) {
                // إلغاء التفعيل المباشر ممنوع
                throw new AddressOperationException(
                    'لا يمكن إلغاء تفعيل العنوان الرئيسي مباشرة، يجب تعيين عنوان آخر كعنوان رئيسي بدلاً منه.',
                    'ADDRESS_DEFAULT_CANNOT_BE_UNSET'
                );
            }

            return [
                'address'                  => Address::with('zone')->withTrashed()->findOrFail($addressId),
                'message'                  => $message,
                'cancelled_requests_count' => count($cancelledIds),
                'cancelled_request_ids'    => $cancelledIds,
            ];
        });
    }

    /**
     * حذف العنوان ناعماً.
     */
    public function deleteAddress(Address $address): void
    {
        // كل أطفال ولي الأمر مسنَدون للعنوان الرئيسي، فوجود طفل مرتبط بالعنوان يعني
        // أن حذفه يقطع إسناد الطفل — وهو ما يمنع ضمناً حذف العنوان الرئيسي طالما
        // لدى ولي الأمر أطفال.
        if ($address->children()->exists()) {
            throw new AddressOperationException(
                $address->is_default
                    ? 'لا يمكن حذف العنوان الرئيسي لأن أطفالك مسنَدون إليه، يرجى تعيين عنوان آخر كعنوان رئيسي أولاً ثم إعادة المحاولة.'
                    : 'لا يمكن حذف هذا العنوان لأنه مرتبط بطفل مضاف في حسابك.',
                $address->is_default ? 'ADDRESS_DEFAULT_CANNOT_BE_DELETED' : 'ADDRESS_LINKED_TO_CHILD'
            );
        }

        DB::transaction(function () use ($address) {
            $wasDefault = (bool) $address->is_default;
            $parentId   = (int) $address->user_id;

            $address->delete();

            // الحفاظ على الثابت «عنوان رئيسي واحد طالما توجد عناوين»: عند حذف العنوان
            // الرئيسي تُرقّى أقدم العناوين المتبقية تلقائياً بدل ترك الحساب بلا عنوان مفعّل.
            if ($wasDefault) {
                $replacement = Address::where('user_id', $parentId)
                    ->where('id', '!=', $address->id)
                    ->orderBy('id')
                    ->first();

                if ($replacement) {
                    $replacement->forceFill(['is_default' => true])->save();
                    $this->assignChildrenToAddress($parentId, $replacement->id);
                }
            }
        });
    }

    /**
     * العنوان الرئيسي الفعلي لولي الأمر للقراءة فقط.
     *
     * يعيد العنوان المفعّل، وإن لم يوجد (بيانات قديمة سابقة لإدراج is_default)
     * يعيد أقدم عنوان كمرشّح حتمي للترقية — دون أي كتابة.
     */
    public function resolveEffectiveDefault(int $parentId): ?Address
    {
        return $this->currentDefault($parentId)
            ?? Address::where('user_id', $parentId)->orderBy('id')->first();
    }

    /**
     * ضمان وجود عنوان رئيسي مفعّل لولي الأمر (يُرقّي أقدم عنوان عند غيابه).
     */
    public function ensureDefaultAddress(int $parentId): ?Address
    {
        $default = $this->currentDefault($parentId);

        if ($default) {
            return $default;
        }

        $candidate = Address::where('user_id', $parentId)->orderBy('id')->first();

        if (!$candidate) {
            return null;
        }

        $candidate->forceFill(['is_default' => true])->save();
        $this->assignChildrenToAddress($parentId, $candidate->id);

        return $candidate;
    }

    /**
     * تعيين عنوان كعنوان رئيسي لولي الأمر مع تطبيق كامل حراسات الاشتراكات.
     *
     * التسلسل:
     *   1. رفض التبديل إذا كان لدى أي طفل اشتراك مفعّل/مجدول على العنوان الحالي.
     *   2. إذا لا توجد اشتراكات مفعّلة لكن توجد طلبات اشتراك معلّقة ⇒ تُلغى كلها
     *      مع استرجاع المبالغ المحجوزة وإشعار السائقين.
     *   3. تبديل العنوان الرئيسي وإسناد كل الأطفال إليه.
     *
     * @return array{changed: bool, message: string, cancelled_requests_count: int, cancelled_request_ids: array<int>, reassigned_children_count: int}
     */
    public function setDefaultAddress(Address $address, int $parentId): array
    {
        if ((int) $address->user_id !== $parentId) {
            throw new AddressOperationException(
                'هذا العنوان غير مسجل ضمن دفتر عناوينك.',
                'ADDRESS_NOT_OWNED',
                403
            );
        }

        if ($address->trashed()) {
            throw new AddressOperationException(
                'لا يمكن تعيين عنوان محذوف كعنوان رئيسي.',
                'ADDRESS_DELETED'
            );
        }

        return DB::transaction(function () use ($address, $parentId) {
            // قفل صفوف عناوين ولي الأمر لمنع تعيين عنوانين رئيسيين في طلبين متزامنين
            Address::where('user_id', $parentId)->lockForUpdate()->get();

            $currentDefault = $this->currentDefault($parentId);

            if ($currentDefault && (int) $currentDefault->id === (int) $address->id) {
                return [
                    'changed'                   => false,
                    'message'                   => 'هذا العنوان هو العنوان الرئيسي المفعل لديك بالفعل.',
                    'cancelled_requests_count'  => 0,
                    'cancelled_request_ids'     => [],
                    'reassigned_children_count' => 0,
                ];
            }

            $children = Child::where('parent_id', $parentId)->get();

            // ── الحارس 1: اشتراكات مفعّلة أو مجدولة ⇒ رفض قاطع ──────────────
            $blockingChildren = $this->childrenWithActiveSubscriptions($children);

            if ($blockingChildren->isNotEmpty()) {
                throw new AddressOperationException(
                    'لا يمكن تغيير العنوان الرئيسي: يوجد اشتراكات مفعّلة للأطفال ('
                        . $blockingChildren->implode('، ')
                        . ') على العنوان الحالي. لا يُسمح بتغيير العنوان إلا إذا لم يكن لدى أي من أطفالك اشتراك قائم.',
                    'ADDRESS_HAS_ACTIVE_SUBSCRIPTIONS',
                    422,
                    ['children' => $blockingChildren->values()->all()]
                );
            }

            // ── الحارس 2: طلبات اشتراك معلّقة ⇒ إلغاء تلقائي شامل ───────────
            $cancelledIds = $this->cancelPendingRequestsForParent($parentId, $currentDefault);

            // ── التبديل الفعلي ────────────────────────────────────────────
            Address::where('user_id', $parentId)
                ->where('id', '!=', $address->id)
                ->update(['is_default' => false]);

            $address->forceFill(['is_default' => true])->save();

            $reassigned = $this->assignChildrenToAddress($parentId, $address->id);

            $message = 'تم تعيين العنوان الرئيسي بنجاح، وتم إسناد جميع أطفالك إليه.';

            if (!empty($cancelledIds)) {
                $message = 'تم إلغاء جميع طلبات الاشتراك المرتبطة بالعنوان السابق (عددها '
                    . count($cancelledIds)
                    . ')، وتم تعيين العنوان الرئيسي الجديد وإسناد جميع أطفالك إليه.';
            }

            return [
                'changed'                   => true,
                'message'                   => $message,
                'cancelled_requests_count'  => count($cancelledIds),
                'cancelled_request_ids'     => $cancelledIds,
                'reassigned_children_count' => $reassigned,
            ];
        });
    }

    /**
     * جلب العنوان الرئيسي الحالي لولي الأمر (null إن لم يوجد).
     */
    public function currentDefault(int $parentId): ?Address
    {
        return Address::where('user_id', $parentId)
            ->where('is_default', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * إسناد كل أطفال ولي الأمر إلى عنوان محدد. يعيد عدد الأطفال المتأثرين.
     */
    public function assignChildrenToAddress(int $parentId, int $addressId): int
    {
        return Child::where('parent_id', $parentId)
            ->where(function ($q) use ($addressId) {
                $q->whereNull('address_id')->orWhere('address_id', '!=', $addressId);
            })
            ->update(['address_id' => $addressId]);
    }

    /**
     * أسماء الأطفال الذين لديهم اشتراك مفعّل أو مجدول (يمنع تبديل العنوان).
     *
     * @param  Collection<int, Child>  $children
     * @return Collection<int, string>
     */
    private function childrenWithActiveSubscriptions(Collection $children): Collection
    {
        if ($children->isEmpty()) {
            return collect();
        }

        $childIds = $children->pluck('id')->all();

        // 1. اشتراكات قائمة فعلياً في active_subscriptions
        $fromActive = ActiveSubscription::forChildren($childIds)
            ->whereIn('status', ['active', 'paused'])
            ->with('requestChild')
            ->get()
            ->pluck('child_id')
            ->all();

        // 2. طلبات مقبولة/سارية لم تنتهِ مدتها بعد (اشتراك مجدول)
        $fromRequests = DB::table('request_children')
            ->join('requests', 'requests.id', '=', 'request_children.request_id')
            ->whereIn('request_children.child_id', $childIds)
            ->whereIn('requests.status', [SubscriptionRequest::STATUS_ACCEPTED, 'active'])
            ->where(function ($q) {
                $q->whereNull('requests.end_date')
                  ->orWhereDate('requests.end_date', '>=', now()->toDateString());
            })
            ->pluck('request_children.child_id')
            ->all();

        $blockingIds = array_unique(array_merge($fromActive, $fromRequests));

        return $children->whereIn('id', $blockingIds)->pluck('full_name')->values();
    }

    /**
     * إلغاء كل طلبات الاشتراك المعلّقة لولي الأمر المرتبطة بالعنوان الرئيسي الحالي.
     *
     * @return array<int> معرفات الطلبات الملغاة
     */
    private function cancelPendingRequestsForParent(int $parentId, ?Address $currentDefault): array
    {
        $pendingRequests = SubscriptionRequest::where('parent_id', $parentId)
            ->whereIn('status', [
                SubscriptionRequest::STATUS_PENDING,
                SubscriptionRequest::STATUS_ACQUIRED,
            ])
            ->get();

        if ($pendingRequests->isEmpty()) {
            return [];
        }

        $subscriptionRequestService = app(SubscriptionRequestService::class);
        $reason = 'تم إلغاء الطلب تلقائياً بسبب تغيير ولي الأمر لعنوانه الرئيسي'
            . ($currentDefault ? ' [' . $currentDefault->label . ']' : '') . '.';

        $cancelledIds = [];

        foreach ($pendingRequests as $request) {
            try {
                $request->update([
                    'status'           => SubscriptionRequest::STATUS_CANCELLED,
                    'rejection_reason' => $reason,
                ]);

                // استرجاع المبالغ المحجوزة في محفظة ولي الأمر إن وُجدت
                $subscriptionRequestService->refundHeldFundsOnCancellation($request->id, 'parent');

                $cancelledIds[] = (int) $request->id;

                // إشعار السائق إن كان الطلب مرتبطاً بسائق
                $driverUser = $request->driver?->user;
                if ($driverUser) {
                    try {
                        app(NotificationService::class)->sendToUser(
                            $driverUser,
                            'إلغاء طلب اشتراك',
                            'تم إلغاء طلب الاشتراك رقم #' . $request->id . ' بسبب تغيير ولي الأمر لعنوانه الرئيسي.',
                            'subscription_request_cancelled',
                            (string) $request->id
                        );
                    } catch (\Throwable $notifEx) {
                        // تجاهل أخطاء الإشعار لعدم تعطيل العملية
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to cancel pending request #' . $request->id
                    . ' during default-address switch for parent #' . $parentId . ': ' . $e->getMessage());
            }
        }

        return $cancelledIds;
    }

    /**
     * إلغاء كل طلبات الاشتراك المعلّقة المرتبطة بهذا العنوان تحديداً.
     */
    private function cancelPendingRequestsForAddress(int $addressId, int $parentId, Address $address): array
    {
        // نجلب الطلبات المعلقة التي أُرسلت من هذا العنوان
        $pendingRequests = SubscriptionRequest::where('parent_id', $parentId)
            ->where('home_address_id', $addressId)
            ->whereIn('status', [
                SubscriptionRequest::STATUS_PENDING,
                SubscriptionRequest::STATUS_ACQUIRED,
            ])
            ->get();

        if ($pendingRequests->isEmpty()) {
            return [];
        }

        $subscriptionRequestService = app(SubscriptionRequestService::class);
        $reason = 'تم إلغاء الطلب تلقائياً بسبب تعديل ولي الأمر لإحداثيات أو منطقة العنوان [' . $address->label . '].';

        $cancelledIds = [];

        foreach ($pendingRequests as $request) {
            try {
                $request->update([
                    'status'           => SubscriptionRequest::STATUS_CANCELLED,
                    'rejection_reason' => $reason,
                ]);

                // استرجاع المبالغ المحجوزة
                $subscriptionRequestService->refundHeldFundsOnCancellation($request->id, 'parent');

                $cancelledIds[] = (int) $request->id;

                // إشعار السائق
                $driverUser = $request->driver?->user;
                if ($driverUser) {
                    try {
                        app(NotificationService::class)->sendToUser(
                            $driverUser,
                            'إلغاء طلب اشتراك',
                            'تم إلغاء طلب الاشتراك رقم #' . $request->id . ' بسبب تعديل ولي الأمر لإحداثيات أو منطقة العنوان.',
                            'subscription_request_cancelled',
                            (string) $request->id
                        );
                    } catch (\Throwable $notifEx) {
                        // تجاهل
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to cancel pending request #' . $request->id . ' during address modification: ' . $e->getMessage());
            }
        }

        return $cancelledIds;
    }

    /**
     * تحويل قيم الـ API النصية ("1" / "true" / "on") إلى boolean حقيقي.
     */
    private function toBool($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }
}
