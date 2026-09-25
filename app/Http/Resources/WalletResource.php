<?php

namespace App\Http\Resources;

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Wallet */
class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'opening_balance' => (float) $this->opening_balance,
            'balance_as_of' => $this->balance_as_of?->toDateString(),
            'receives_salary' => (bool) $this->receives_salary,
            'is_default' => (bool) $this->is_default,
            'sort_order' => (int) $this->sort_order,
            // Set by WalletService::withBalances(); absent on plain CRUD responses.
            'balance' => $this->when(isset($this->balance), fn () => (float) $this->balance),
            'salary_received' => $this->when(isset($this->salary_received), fn () => (float) $this->salary_received),
            'spent' => $this->when(isset($this->spent), fn () => (float) $this->spent),
            'saved' => $this->when(isset($this->saved), fn () => (float) $this->saved),
            'loans' => $this->when(isset($this->loans), fn () => (float) $this->loans),
        ];
    }
}
