<?php

namespace App\Http\Resources\Api\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class AdminDriverListResource extends JsonResource
{
    /**
     * تحويل كائن السائق إلى مخرجات مخصصة ومحسنة لجدول لوحة التحكم السريع
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'full_name'    => $this->user?->full_name ?? 'سائق غير معرف / حساب معلق',
            'phone_number' => $this->user?->phone_number ?? 'لا يوجد هاتف',
            'avatar_url'   => \App\Http\Controllers\Api\Shared\MediaController::urlFor($this->user?->avatar_url),
            'status'       => $this->status, 
            
            'created_at'   => $this->user?->created_at 
                ? Carbon::parse($this->user->created_at)->format('Y-m-d H:i') 
                : ($this->created_at ? Carbon::parse($this->created_at)->format('Y-m-d H:i') : null),
        ];
    }
}