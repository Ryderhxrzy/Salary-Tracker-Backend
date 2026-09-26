<?php

namespace Tests\Feature;

use App\Mail\WalletInviteMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesTrackerUser;
use Tests\TestCase;

class SharedWalletTest extends TestCase
{
    use CreatesTrackerUser;
    use RefreshDatabase;

    public function test_shared_account_is_used_by_everyone_who_accepted(): void
    {
        Mail::fake();
        $owner = $this->actingAsTracker();
        $partner = $this->trackerUser();
        $this->travelTo($this->manila('2026-09-22 12:00'));

        $joint = $this->postJson('/api/wallets', ['name' => 'Joint GCash', 'type' => 'gcash', 'category' => 'ewallet', 'institution_id' => 'gcash', 'opening_balance' => 1000])->assertCreated()->json('data');
        $this->postJson("/api/wallets/{$joint['id']}/invites", ['email' => $partner->email])->assertCreated()->assertJsonPath('data.status', 'pending');
        Mail::assertSent(WalletInviteMail::class, fn (WalletInviteMail $mail) => $mail->hasTo($partner->email));
        $this->assertTrue(collect($this->getJson('/api/wallets')->json('data'))->firstWhere('id', $joint['id'])['is_shared']);

        // Until they accept, the partner cannot spend from it.
        Sanctum::actingAs($partner);
        $this->postJson('/api/expenses', ['amount' => 100, 'expense_date' => '2026-09-22', 'wallet_id' => $joint['id']])->assertStatus(422);
        $invite = $this->getJson('/api/wallet-invites')->assertOk()->json('data.0');
        $this->assertSame('Joint GCash', $invite['wallet']['name']);
        $this->postJson("/api/wallet-invites/{$invite['id']}/accept")->assertOk()->assertJsonPath('data.name', 'Joint GCash')->assertJsonPath('data.is_owner', false)->assertJsonPath('data.is_default', false);

        // The shared account shows up after the partner's own accounts, and their spending lowers the one balance.
        $wallets = $this->getJson('/api/wallets')->assertOk()->json('data');
        $this->assertCount(2, $wallets);
        $this->assertSame($joint['id'], $wallets[1]['id']);
        $this->assertSame($owner->name, $wallets[1]['owner']['name']);
        $this->postJson('/api/expenses', ['amount' => 250, 'expense_date' => '2026-09-22', 'description' => 'Groceries', 'wallet_id' => $joint['id']])->assertCreated()->assertJsonPath('data.payment_method', 'gcash');
        $this->putJson("/api/wallets/{$joint['id']}", ['name' => 'Mine now'])->assertStatus(403);
        $this->assertSame(750.0, (float) collect($this->getJson('/api/wallets')->json('data'))->firstWhere('id', $joint['id'])['balance']);

        // The owner sees the same balance and was told about the spending; both movements count.
        Sanctum::actingAs($owner);
        $this->postJson('/api/expenses', ['amount' => 50, 'expense_date' => '2026-09-22', 'wallet_id' => $joint['id']])->assertCreated();
        $this->assertSame(700.0, (float) collect($this->getJson('/api/wallets')->json('data'))->firstWhere('id', $joint['id'])['balance']);
        $types = collect($this->getJson('/api/notifications')->json('data.notifications'))->pluck('type')->all();
        $this->assertContains('wallet_joined', $types);
        $this->assertContains('wallet_activity', $types);
        $this->postJson('/api/graphql', ['query' => '{ wallets { name is_shared is_owner members { email status } } walletInvites { id } }'])
            ->assertOk()->assertJsonPath('data.wallets.1.members.0.status', 'accepted');

        Sanctum::actingAs($partner);
        $this->assertSame(700.0, (float) collect($this->getJson('/api/wallets')->json('data'))->firstWhere('id', $joint['id'])['balance']);
        $this->assertSame(700.0, (float) collect($this->getJson('/api/dashboard')->json('data.money.wallets'))->firstWhere('id', $joint['id'])['balance']);

        // The owner removes the partner; it disappears from their list.
        Sanctum::actingAs($owner);
        $memberId = collect($this->getJson('/api/wallets')->json('data'))->firstWhere('id', $joint['id'])['members'][0]['id'];
        $this->deleteJson("/api/wallets/{$joint['id']}/members/{$memberId}")->assertOk();
        Sanctum::actingAs($partner);
        $this->assertCount(1, $this->getJson('/api/wallets')->json('data'));
    }
}
