<?php

use App\Models\SavingsGoalMember;
use App\Models\WalletMember;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

$name = fn ($user) => $user ? ($user->profile?->nickname ?: ($user->profile?->full_name ?: $user->name)) : 'Someone';

// The links in invitation emails: a small page that opens the app.
Route::get('/goal-invites/{token}', function (string $token) use ($name) {
    $member = SavingsGoalMember::where('token', $token)->with(['goal.owner.profile', 'inviter.profile'])->firstOrFail();

    return view('invite', ['kind' => 'goal', 'name' => $member->goal?->name ?? 'Shared goal', 'status' => $member->status, 'email' => $member->email, 'inviterName' => $name($member->inviter ?? $member->goal?->owner), 'appLink' => 'tandem://goal-invites/'.$member->token]);
})->where('token', '[A-Za-z0-9]+');

Route::get('/wallet-invites/{token}', function (string $token) use ($name) {
    $member = WalletMember::where('token', $token)->with(['wallet.owner.profile', 'inviter.profile'])->firstOrFail();

    return view('invite', ['kind' => 'wallet', 'name' => $member->wallet?->name ?? 'Shared account', 'status' => $member->status, 'email' => $member->email, 'inviterName' => $name($member->inviter ?? $member->wallet?->owner), 'appLink' => 'tandem://wallet-invites/'.$member->token]);
})->where('token', '[A-Za-z0-9]+');
