<x-mail::message>
# You're invited to save together

**{{ $inviterName }}** invited you to join the shared goal **"{{ $goalName }}"** in Tandem.

- Target: ₱{{ $target }}
- Saved so far: ₱{{ $saved }}

Once you accept, the goal shows up in your Savings tab and you can add money to it from any of your accounts. Everyone in the goal sees the progress.

<x-mail::button :url="$webLink">
Open the invitation
</x-mail::button>

Sign in to the app with **{{ $email }}**, open **Savings → Invitations**, and tap **Accept**. If you do not have the app yet, install it and register with this email address first.

If you were not expecting this, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
