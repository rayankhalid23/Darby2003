<?php

namespace App\Http\Resources\Api\Parent;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'label'     => $this->label,
            'zone_id'   => $this->zone_id,
            'zone_name' => $this->zone?->name,
            'lat'       => (float) $this->lat,
            'lng'       => (float) $this->lng,
            // العنوان الرئيسي المفعل: واحد فقط لكل ولي أمر، وكل أطفاله مسنَدون إليه.
            'is_default' => (bool) $this->is_default,
        ];
    }
}