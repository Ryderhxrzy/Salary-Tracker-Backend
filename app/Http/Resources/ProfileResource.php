<?php

namespace App\Http\Resources;

use App\Models\EmployeeProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeProfile */
class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'full_name' => $this->full_name,
            'nickname' => $this->nickname,
            'position' => $this->position,
            'company' => $this->company,
            'employment_type' => $this->employment_type,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'late_grace_minutes' => $this->late_grace_minutes,
        ];
    }
}
