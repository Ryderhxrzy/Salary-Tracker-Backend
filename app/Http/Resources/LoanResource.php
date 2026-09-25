<?php

namespace App\Http\Resources;

use App\Models\Loan;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Loan */
class LoanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $paid = $this->paidAmount();
        $remaining = $this->remainingAmount();
        $isPaid = $remaining <= 0.0;
        // Avoid a user query per loan: the relation is only used when it was eager loaded.
        $today = CarbonImmutable::now($this->relationLoaded('user') ? $this->user->timezone() : config('salary_tracker.timezone'))->toDateString();
        $nextDue = $this->next_due_date?->toDateString();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'lender' => $this->lender,
            'type' => $this->type,
            'principal_amount' => (float) $this->principal_amount,
            'total_amount' => (float) $this->total_amount,
            'opening_paid_amount' => (float) $this->opening_paid_amount,
            'installment_amount' => $this->installment_amount === null ? null : (float) $this->installment_amount,
            'frequency' => $this->frequency,
            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'next_due_date' => $nextDue,
            'wallet_id' => $this->wallet_id,
            'wallet' => $this->whenLoaded('wallet', fn () => $this->wallet ? ['id' => $this->wallet->id, 'name' => $this->wallet->name, 'type' => $this->wallet->type] : null),
            'via_payroll' => (bool) $this->via_payroll,
            'is_closed' => (bool) $this->is_closed,
            'notes' => $this->notes,
            'paid_amount' => $paid,
            'remaining_amount' => $remaining,
            'progress_percent' => $this->progressPercent(),
            'is_paid' => $isPaid,
            // active | paid | closed
            'status' => $this->is_closed ? 'closed' : ($isPaid ? 'paid' : 'active'),
            'is_overdue' => ! $isPaid && ! $this->is_closed && $nextDue !== null && $nextDue < $today,
            'payments_count' => $this->whenCounted('payments'),
            'payments' => LoanPaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
