<?php

namespace App\Http\Requests;

use App\Models\EmployeeProfile;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return static::rulesFor();
    }

    public static function rulesFor(): array
    {
        return [
            'full_name' => ['nullable', 'string', 'max:150'],
            'nickname' => ['nullable', 'string', 'max:60'],
            'position' => ['nullable', 'string', 'max:150'],
            'company' => ['nullable', 'string', 'max:150'],
            'employment_type' => ['nullable', Rule::in(EmployeeProfile::EMPLOYMENT_TYPES)],
            'timezone' => ['nullable', 'timezone:all'],
            'currency' => ['nullable', 'string', 'size:3'],
            'late_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
        ];
    }
}
