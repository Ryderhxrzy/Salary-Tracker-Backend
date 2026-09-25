<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreDeviceTokenRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', Rule::in(['android', 'ios', 'web'])],
            'device_name' => ['nullable', 'string', 'max:150'],
        ];
    }
}
