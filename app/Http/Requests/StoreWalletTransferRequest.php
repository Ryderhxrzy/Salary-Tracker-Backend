<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Models\WalletTransfer;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWalletTransferRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return static::rulesFor($this->user(), $this->route('transfer') !== null);
    }

    public static function rulesFor(User $user, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';
        $owned = Rule::exists('wallets', 'id')->where('user_id', $user->id)->whereNull('deleted_at');

        return [
            'from_wallet_id' => [$required, 'integer', $owned],
            'to_wallet_id' => [$required, 'integer', 'different:from_wallet_id', $owned],
            'amount' => [$required, 'numeric', 'min:0.01', 'max:999999999'],
            'transfer_date' => [$required, 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** On an update only one side may be sent; it still may not equal the other side. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $transfer = $this->route('transfer');
                if (! $transfer instanceof WalletTransfer) {
                    return;
                }
                $from = (int) $this->input('from_wallet_id', $transfer->from_wallet_id);
                $to = (int) $this->input('to_wallet_id', $transfer->to_wallet_id);
                if ($from !== 0 && $from === $to) {
                    $validator->errors()->add('to_wallet_id', 'Choose a different account to transfer to.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'to_wallet_id.different' => 'Choose a different account to transfer to.',
        ];
    }
}
