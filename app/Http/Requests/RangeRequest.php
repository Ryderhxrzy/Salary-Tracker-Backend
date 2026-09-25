<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Query validation for range based reports: ?range=today|week|month|period|custom&from=&to=
 */
class RangeRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'range' => ['nullable', Rule::in(['today', 'week', 'month', 'period', 'custom'])],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_if:range,custom'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_if:range,custom', 'after_or_equal:from'],
        ];
    }

    public function range(): string
    {
        return $this->input('range', 'period');
    }
}
