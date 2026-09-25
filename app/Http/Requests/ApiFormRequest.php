<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class ApiFormRequest extends FormRequest
{
    /**
     * Authentication is enforced by the `auth:sanctum` middleware and ownership
     * by policies, so form requests only validate input.
     */
    public function authorize(): bool
    {
        return true;
    }
}
