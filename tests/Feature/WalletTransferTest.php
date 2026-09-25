<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesTrackerUser;
use Tests\TestCase;

class WalletTransferTest extends TestCase
{
    use CreatesTrackerUser, RefreshDatabase;

    public function test_transfers_move_money_between_wallets(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-22 12:00'));

        $cash = $this->getJson('/api/wallets')->assertOk()->json('data.0');
        $gcash = $this->postJson('/api/wallets', ['name' => 'GCash', 'type' => 'gcash', 'category' => 'ewallet', 'institution_id' => 'gcash'])->assertCreated()->json('data');
        $this->putJson("/api/wallets/{$cash['id']}", ['opening_balance' => 2000])->assertOk();

        // Both sides must be different accounts of the user.
        $this->postJson('/api/wallet-transfers', ['from_wallet_id' => $cash['id'], 'to_wallet_id' => $cash['id'], 'amount' => 500, 'transfer_date' => '2026-09-22'])
            ->assertStatus(422)->assertJsonValidationErrors(['to_wallet_id']);
        $this->postJson('/api/wallet-transfers', ['from_wallet_id' => $cash['id'], 'to_wallet_id' => 999999, 'amount' => 500, 'transfer_date' => '2026-09-22'])
            ->assertStatus(422)->assertJsonValidationErrors(['to_wallet_id']);
        $this->postJson('/api/wallet-transfers', ['from_wallet_id' => $cash['id'], 'to_wallet_id' => $gcash['id'], 'amount' => 0, 'transfer_date' => '2026-09-22'])
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);

        // ₱500 cash-in to GCash: cash loses 500, GCash gains 500.
        $transfer = $this->postJson('/api/wallet-transfers', ['from_wallet_id' => $cash['id'], 'to_wallet_id' => $gcash['id'], 'amount' => 500, 'transfer_date' => '2026-09-22', 'notes' => 'Cash-in'])
            ->assertCreated()
            ->assertJsonPath('data.from_wallet.name', 'Cash')
            ->assertJsonPath('data.to_wallet.name', 'GCash')
            ->json('data');

        $wallets = collect($this->getJson('/api/wallets')->assertOk()->json('data'));
        $cashNow = $wallets->firstWhere('id', $cash['id']);
        $gcashNow = $wallets->firstWhere('id', $gcash['id']);
        $this->assertSame(1500.0, (float) $cashNow['balance']);
        $this->assertSame(500.0, (float) $cashNow['transfers_out']);
        $this->assertSame(500.0, (float) $gcashNow['balance']);
        $this->assertSame(500.0, (float) $gcashNow['transfers_in']);

        // A transfer is neither income nor an expense on the salary period.
        $summary = $this->getJson('/api/salary')->assertOk()->json('data.summary');
        $this->assertSame(0.0, (float) $summary['expenses']);
        $this->assertSame(0.0, (float) $summary['other_income']);

        // Listed by range on REST and GraphQL, optionally only for one wallet.
        $list = $this->getJson('/api/wallet-transfers?range=period')->assertOk()->json('data');
        $this->assertSame(500.0, (float) $list['total']);
        $this->assertCount(1, $list['transfers']);
        $this->assertCount(1, $this->getJson("/api/wallet-transfers?range=period&wallet={$gcash['id']}")->assertOk()->json('data.transfers'));

        $maya = $this->postJson('/api/wallets', ['name' => 'Maya', 'type' => 'maya', 'category' => 'ewallet', 'institution_id' => 'maya'])->assertCreated()->json('data');
        $this->assertCount(0, $this->getJson("/api/wallet-transfers?range=period&wallet={$maya['id']}")->assertOk()->json('data.transfers'));

        $graphql = $this->postJson('/api/graphql', ['query' => '{ walletTransfers(range: "period") { total transfers { id amount from_wallet { name } to_wallet { name } } } wallets { name balance transfers_in transfers_out } }'])
            ->assertOk();
        $this->assertSame(500.0, (float) $graphql->json('data.walletTransfers.total'));
        $this->assertSame('GCash', $graphql->json('data.walletTransfers.transfers.0.to_wallet.name'));
        $this->assertSame(1500.0, (float) collect($graphql->json('data.wallets'))->firstWhere('name', 'Cash')['balance']);
        $this->assertCount(1, $this->postJson('/api/graphql', ['query' => "{ walletTransfers(range: \"period\", wallet: {$gcash['id']}) { transfers { id } } }"])->assertOk()->json('data.walletTransfers.transfers'));

        // Editing moves the money again; on an update the two sides still may not match.
        $this->putJson("/api/wallet-transfers/{$transfer['id']}", ['to_wallet_id' => $cash['id']])->assertStatus(422)->assertJsonValidationErrors(['to_wallet_id']);
        $this->putJson("/api/wallet-transfers/{$transfer['id']}", ['amount' => 300])->assertOk();
        $wallets = collect($this->getJson('/api/wallets')->assertOk()->json('data'));
        $this->assertSame(1700.0, (float) $wallets->firstWhere('id', $cash['id'])['balance']);
        $this->assertSame(300.0, (float) $wallets->firstWhere('id', $gcash['id'])['balance']);

        // Deleting puts everything back.
        $this->deleteJson("/api/wallet-transfers/{$transfer['id']}")->assertOk();
        $wallets = collect($this->getJson('/api/wallets')->assertOk()->json('data'));
        $this->assertSame(2000.0, (float) $wallets->firstWhere('id', $cash['id'])['balance']);
        $this->assertSame(0.0, (float) $wallets->firstWhere('id', $gcash['id'])['balance']);
        $this->assertCount(0, $this->getJson('/api/wallet-transfers?range=period')->assertOk()->json('data.transfers'));
    }

    public function test_transfers_belong_to_their_user(): void
    {
        $owner = $this->actingAsTracker();
        $cash = $this->getJson('/api/wallets')->assertOk()->json('data.0');
        $gcash = $this->postJson('/api/wallets', ['name' => 'GCash', 'type' => 'gcash', 'category' => 'ewallet', 'institution_id' => 'gcash'])->assertCreated()->json('data');
        $transfer = $this->postJson('/api/wallet-transfers', ['from_wallet_id' => $cash['id'], 'to_wallet_id' => $gcash['id'], 'amount' => 100, 'transfer_date' => '2026-09-22'])->assertCreated()->json('data');

        $other = $this->trackerUser();
        Sanctum::actingAs($other);
        // Someone else's wallets are unknown to this user…
        $this->postJson('/api/wallet-transfers', ['from_wallet_id' => $cash['id'], 'to_wallet_id' => $gcash['id'], 'amount' => 100, 'transfer_date' => '2026-09-22'])
            ->assertStatus(422);
        // …and so is their transfer.
        $this->putJson("/api/wallet-transfers/{$transfer['id']}", ['amount' => 1])->assertStatus(403);
        $this->deleteJson("/api/wallet-transfers/{$transfer['id']}")->assertStatus(403);
        $this->assertCount(0, $this->getJson('/api/wallet-transfers?range=custom&from=2026-09-01&to=2026-09-30')->assertOk()->json('data.transfers'));

        Sanctum::actingAs($owner);
        $this->assertCount(1, $this->getJson('/api/wallet-transfers?range=custom&from=2026-09-01&to=2026-09-30')->assertOk()->json('data.transfers'));
    }
}
