<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreExpenseCategoryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $isUpdate = $this->route('category') !== null;
        $unique = Rule::unique('expense_categories', 'name')->where('user_id', $this->user()->id);
        if ($isUpdate) {
            $unique = $unique->ignore($this->route('category'));
        }

        return [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:60', $unique],
            'icon' => ['nullable', 'string', 'max:40'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
