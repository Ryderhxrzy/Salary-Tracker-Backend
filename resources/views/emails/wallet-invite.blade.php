<x-mail::message>
# An account was shared with you

**{{ $inviterName }}** invited you to use the account **"{{ $walletName }}"** together in Tandem.

Once you accept, the account shows up with your own cards. Expenses, savings and transfers you make from it count on the one shared balance, and everyone using it is told when money moves.

<x-mail::button :url="$webLink">
Open the invitation
</x-mail::button>

Sign in to the app with **{{ $email }}**, open **Savings → Invitations**, and tap **Accept**. If you do not have the app yet, install it and register with this email address first.

If you were not expecting this, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
