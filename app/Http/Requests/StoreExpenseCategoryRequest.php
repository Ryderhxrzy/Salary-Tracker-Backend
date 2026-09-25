<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Validation\Rule;

class StoreExpenseCategoryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return static::rulesFor($this->user(), $this->route('category')?->id);
    }

    /** @param  int|null  $ignoreId  the category being updated (its own name is allowed) */
    public static function rulesFor(User $user, ?int $ignoreId = null): array
    {
        $isUpdate = $ignoreId !== null;
        $unique = Rule::unique('expense_categories', 'name')->where('user_id', $user->id);
        if ($isUpdate) {
            $unique = $unique->ignore($ignoreId);
        }

        return [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:60', $unique],
            'icon' => ['nullable', 'string', 'max:40'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
