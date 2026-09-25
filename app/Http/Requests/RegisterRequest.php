<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Password;

class RegisterRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return static::rulesFor();
    }

    public static function rulesFor(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
