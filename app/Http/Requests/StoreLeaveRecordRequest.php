<?php

namespace App\Http\Requests;

use App\Models\LeaveRecord;
use Illuminate\Validation\Rule;

class StoreLeaveRecordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $isUpdate = $this->route('leave') !== null;

        return [
            'leave_date' => [$isUpdate ? 'nullable' : 'required', 'date_format:Y-m-d'],
            'type' => [$isUpdate ? 'nullable' : 'required', Rule::in(LeaveRecord::TYPES)],
            'is_paid' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
