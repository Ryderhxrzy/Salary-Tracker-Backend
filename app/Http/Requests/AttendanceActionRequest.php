<?php

namespace App\Http\Requests;

use App\Models\AttendanceRecord;
use Illuminate\Validation\Rule;

/**
 * Shared validation for POST /attendance/time-in and /attendance/time-out.
 */
class AttendanceActionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'occurred_at' => ['nullable', 'date'],
            'source' => ['nullable', Rule::in(AttendanceRecord::SOURCES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function options(): array
    {
        return [
            'idempotency_key' => $this->input('idempotency_key'),
            'occurred_at' => $this->input('occurred_at'),
            'source' => $this->input('source', 'app'),
            'notes' => $this->input('notes'),
        ];
    }
}
