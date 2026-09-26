<?php

namespace Tests\Feature;

use App\Mail\GoalInviteMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesTrackerUser;
use Tests\TestCase;

class SharedGoalTest extends TestCase
{
    use CreatesTrackerUser;
    use RefreshDatabase;

    public function test_invite_accept_contribute_and_leave(): void
    {
        Mail::fake();
        $owner = $this->actingAsTracker();
        $friend = $this->trackerUser();
        $ownerWallet = $this->getJson('/api/wallets')->json('data.0');

        // A shared goal is created with the friend's email; the email goes out and the friend gets an inbox row.
        $goal = $this->postJson('/api/goals', ['name' => 'Boracay trip', 'target_amount' => 20000, 'wallet_id' => $ownerWallet['id'], 'invite_emails' => [strtoupper($friend->email)]])
            ->assertCreated()->assertJsonPath('data.is_shared', true)->assertJsonPath('data.is_owner', true)->assertJsonPath('data.members.0.status', 'pending')->json('data');
        Mail::assertSent(GoalInviteMail::class, fn (GoalInviteMail $mail) => $mail->hasTo($friend->email) && $mail->goal->id === $goal['id']);
        $this->postJson("/api/goals/{$goal['id']}/invites", ['email' => $owner->email])->assertStatus(422);

        // The friend sees the invitation, accepts, and the goal appears in their list as a member.
        Sanctum::actingAs($friend);
        $invites = $this->getJson('/api/goal-invites')->assertOk()->json('data');
        $this->assertCount(1, $invites);
        $this->assertSame('Boracay trip', $invites[0]['goal']['name']);
        $this->assertSame($owner->name, $invites[0]['invited_by_name']);
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('data.unread', 1)->assertJsonPath('data.notifications.0.type', 'goal_invite');
        $this->postJson("/api/goal-invites/{$invites[0]['id']}/accept")->assertOk()->assertJsonPath('data.name', 'Boracay trip');
        $this->assertCount(0, $this->getJson('/api/goal-invites')->json('data'));
        $mine = $this->getJson('/api/goals')->assertOk()->json('data');
        $this->assertCount(1, $mine);
        $this->assertFalse($mine[0]['is_owner']);
        $this->assertSame($owner->name, $mine[0]['owner']['name']);
        $this->putJson("/api/goals/{$goal['id']}", ['name' => 'Hacked'])->assertStatus(403);

        // The friend saves ₱500 from their own account into the shared goal.
        $friendWallet = $this->getJson('/api/wallets')->json('data.0');
        $this->postJson('/api/savings/transactions', ['amount' => 500, 'transaction_date' => '2026-09-26', 'savings_goal_id' => $goal['id'], 'wallet_id' => $friendWallet['id']])->assertCreated();
        $this->assertSame(500.0, (float) $this->getJson('/api/goals')->json('data.0.current_amount'));
        $this->assertSame(500.0, (float) $this->getJson('/api/goals')->json('data.0.my_contribution'));
        $this->assertSame(500.0, (float) $this->getJson('/api/savings')->json('data.goal_balance'));
        $this->assertSame(500.0, (float) collect($this->getJson('/api/wallets')->json('data'))->firstWhere('id', $friendWallet['id'])['saved']);

        // The owner was told twice (joined, contributed) and sees the member's money in the goal.
        Sanctum::actingAs($owner);
        $types = collect($this->getJson('/api/notifications')->json('data.notifications'))->pluck('type')->all();
        $this->assertContains('goal_joined', $types);
        $this->assertContains('goal_contribution', $types);
        $ownerView = $this->getJson('/api/goals')->json('data.0');
        $this->assertSame(500.0, (float) $ownerView['current_amount']);
        $this->assertSame(0.0, (float) $ownerView['my_contribution']);
        $this->assertSame(500.0, (float) $ownerView['members'][0]['contributed']);
        $this->postJson('/api/graphql', ['query' => '{ goals { name is_shared members { email status contributed } } goalInvites { id } notifications { unread } }'])
            ->assertOk()->assertJsonPath('data.goals.0.members.0.status', 'accepted')->assertJsonPath('data.goals.0.members.0.contributed', 500);

        // Pending inbox rows are handed to the phone once.
        $this->assertCount(2, $this->getJson('/api/notifications/pending')->json('data'));
        $this->assertCount(0, $this->getJson('/api/notifications/pending')->json('data'));
        $this->postJson('/api/notifications/read')->assertOk()->assertJsonPath('data.unread', 0);

        // The member leaves; the goal disappears from their list.
        Sanctum::actingAs($friend);
        $memberId = $mine[0]['members'][0]['id'];
        $this->deleteJson("/api/goals/{$goal['id']}/members/{$memberId}")->assertOk();
        $this->assertCount(0, $this->getJson('/api/goals')->json('data'));
    }
}
