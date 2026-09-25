<?php

namespace App\Http\Resources;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Expense */
class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            'description' => $this->description,
            'expense_date' => $this->expense_date?->toDateString(),
            'payment_method' => $this->payment_method,
            'notes' => $this->notes,
            'category_id' => $this->expense_category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? new ExpenseCategoryResource($this->category) : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
