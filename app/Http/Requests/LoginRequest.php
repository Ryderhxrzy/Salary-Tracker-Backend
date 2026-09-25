<?php

namespace App\Http\Requests;

class LoginRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return static::rulesFor();
    }

    public static function rulesFor(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
