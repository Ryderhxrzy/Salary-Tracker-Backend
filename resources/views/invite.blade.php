<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $kind === 'wallet' ? 'Shared account' : 'Shared goal' }} invitation</title>
  <style>
    body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #F5F4EF; color: #131A17; margin: 0; padding: 32px 16px; }
    .card { max-width: 420px; margin: 0 auto; background: #fff; border: 1px solid #E1DFD6; border-radius: 22px; padding: 28px; }
    h1 { font-size: 22px; margin: 0 0 8px; }
    p { line-height: 1.5; color: #5F6B66; }
    .btn { display: block; text-align: center; background: #0B7A5B; color: #fff; text-decoration: none; padding: 14px; border-radius: 999px; font-weight: 700; margin-top: 20px; }
    .muted { font-size: 13px; }
  </style>
</head>
<body>
  <div class="card">
    <h1>{{ $name }}</h1>
    @if ($status === 'pending')
      <p><strong>{{ $inviterName }}</strong> invited <strong>{{ $email }}</strong> to {{ $kind === 'wallet' ? 'use this account together' : 'save together for this goal' }}.</p>
      <p>Open the Tandem app, sign in with that email, then go to <strong>Savings → Invitations</strong> and tap <strong>Accept</strong>.</p>
      <a class="btn" href="{{ $appLink }}">Open the app</a>
    @elseif ($status === 'accepted')
      <p>This invitation was already accepted. Open the app to see it.</p>
      <a class="btn" href="{{ $appLink }}">Open the app</a>
    @else
      <p>This invitation was declined.</p>
    @endif
    <p class="muted">If the button does nothing, install the app first and register with the invited email address.</p>
  </div>
</body>
</html>
