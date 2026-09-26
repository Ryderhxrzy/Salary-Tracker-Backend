<?php

namespace App\Http\Resources;

use App\Models\RecurringExpense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RecurringExpense */
class RecurringExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'amount' => (float) $this->amount,
            'category_id' => $this->expense_category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? new ExpenseCategoryResource($this->category) : null),
            'wallet_id' => $this->wallet_id,
            'wallet' => $this->whenLoaded('wallet', fn () => $this->wallet ? ['id' => $this->wallet->id, 'name' => $this->wallet->name, 'type' => $this->wallet->type] : null),
            'frequency' => $this->frequency,
            'day_of_month' => $this->day_of_month,
            'second_day_of_month' => $this->second_day_of_month,
            'weekday' => $this->weekday,
            'month_of_year' => $this->month_of_year,
            'start_date' => $this->start_date?->toDateString(),
            'next_date' => $this->next_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'last_paid_date' => $this->last_paid_date?->toDateString(),
            'auto_pay' => (bool) $this->auto_pay,
            'remind' => (bool) $this->remind,
            'remind_days_before' => (int) $this->remind_days_before,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
        ];
    }
}
