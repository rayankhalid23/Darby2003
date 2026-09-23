<?php

namespace App\Http\Resources\Api\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class AdminParentListResource extends JsonResource
{
    /**
     * تحويل كائن ولي الأمر إلى مخرجات كاملة لبيانات الحساب وأبنائه لواجهة إدارة الأدمن
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'full_name'         => $this->full_name,
            'email'             => $this->email,
            'phone_number'      => $this->phone_number,
            'alternative_phone' => $this->alternative_phone,
            'gender'            => $this->gender,
            'avatar_url'        => $this->avatar_url ? \App\Http\Controllers\Api\Shared\MediaController::urlFor($this->avatar_url) : null,
            'is_active'         => (bool) $this->is_active,
            'is_trusted'        => (bool) $this->is_trusted,
            'phone_verified'    => (bool) $this->phone_verified,
            'last_login_at'     => $this->last_login_at ? Carbon::parse($this->last_login_at)->format('Y-m-d H:i:s') : null,
            'created_at'        => $this->created_at ? Carbon::parse($this->created_at)->format('Y-m-d H:i:s') : null,

            'default_address' => $this->whenLoaded('defaultAddress', function () {
                return $this->defaultAddress ? [
                    'id'    => $this->defaultAddress->id,
                    'label' => $this->defaultAddress->label,
                    'lat'   => $this->defaultAddress->lat,
                    'lng'   => $this->defaultAddress->lng,
                ] : null;
            }),

            'children_count' => $this->whenCounted('children'),

            'children' => $this->whenLoaded('children', function () {
                return $this->children->map(function ($child) {
                    return [
                        'id'                => $child->id,
                        'full_name'         => $child->full_name,
                        'birth_date'        => $child->birth_date ? $child->birth_date->format('Y-m-d') : null,
                        'age'               => $child->age,
                        'gender'            => $child->gender,
                        'grade'             => $child->grade,
                        'school_stage'      => $child->school_stage,
                        'school_name'       => $child->school?->name,
                        'photo_url'         => $child->photo_url ? \App\Http\Controllers\Api\Shared\MediaController::urlFor($child->photo_url) : null,
                        'is_active'         => (bool) $child->is_active,
                    ];
                });
            }),
        ];
    }
}
