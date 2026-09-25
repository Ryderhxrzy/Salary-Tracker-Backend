<?php

namespace App\Http\Resources;

use App\Models\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DeviceToken */
class DeviceTokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'token' => $this->token,
            'platform' => $this->platform,
            'device_name' => $this->device_name,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}
