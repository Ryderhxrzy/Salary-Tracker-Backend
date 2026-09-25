<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTrackerUser;
use Tests\TestCase;

class SavingsTest extends TestCase
{
    use CreatesTrackerUser, RefreshDatabase;

    public function test_default_wallets_and_savings_flow(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-22 12:00'));

        // Cash / GCash / Bank are created on first use; cash receives the salary.
        $wallets = $this->getJson('/api/wallets')->assertOk()->json('data');
        $this->assertSame(['Cash', 'GCash'], array_column($wallets, 'name'));
        $this->assertSame('gcash', $wallets[1]['institution_id']);
        $this->assertSame('ewallet', $wallets[1]['category']);
        $cash = collect($wallets)->firstWhere('type', 'cash');
        $gcash = collect($wallets)->firstWhere('type', 'gcash');
        $this->assertTrue($cash['receives_salary']);
        $this->assertSame(0.0, (float) $cash['balance']);

        // Opening balances: ₱2,000 cash on hand, ₱500 in GCash.
        $this->putJson("/api/wallets/{$cash['id']}", ['opening_balance' => 2000])->assertOk();
        $this->putJson("/api/wallets/{$gcash['id']}", ['opening_balance' => 500])->assertOk();

        // An expense paid with GCash comes out of the GCash wallet, cash stays untouched.
        $this->postJson('/api/expenses', ['amount' => 120, 'expense_date' => '2026-09-22', 'payment_method' => 'gcash'])
            ->assertCreated()->assertJsonPath('data.wallet_id', $gcash['id']);
        // Choosing a wallet sets the payment method from it.
        $this->postJson('/api/expenses', ['amount' => 80, 'expense_date' => '2026-09-22', 'wallet_id' => $cash['id']])
            ->assertCreated()->assertJsonPath('data.payment_method', 'cash');

        // A goal, then ₱300 deposited into it from cash.
        $goal = $this->postJson('/api/goals', ['name' => 'New phone', 'target_amount' => 1000])->assertCreated()->json('data');
        $this->postJson('/api/savings/transactions', ['amount' => 300, 'transaction_date' => '2026-09-22', 'savings_goal_id' => $goal['id'], 'wallet_id' => $cash['id']])
            ->assertCreated()
            ->assertJsonPath('data.type', 'deposit')
            ->assertJsonPath('data.goal.name', 'New phone');

        $overview = $this->getJson('/api/savings?range=period')->assertOk()->json('data');
        $this->assertSame(300.0, (float) $overview['total_saved']);
        $this->assertSame(300.0, (float) $overview['deposits']);
        $this->assertSame(300.0, (float) $overview['goals'][0]['current_amount']);
        $cashNow = collect($overview['wallets'])->firstWhere('id', $cash['id']);
        $gcashNow = collect($overview['wallets'])->firstWhere('id', $gcash['id']);
        $this->assertSame(2000.0 - 80 - 300, (float) $cashNow['balance']);
        $this->assertSame(500.0 - 120, (float) $gcashNow['balance']);

        // The period computation subtracts expenses and savings from the take-home pay.
        $summary = $this->getJson('/api/salary')->assertOk()->json('data.summary');
        $this->assertSame(200.0, (float) $summary['expenses']);
        $this->assertSame(300.0, (float) $summary['savings']);
        $this->assertSame(round($summary['take_home'] - 200 - 300, 2), (float) $summary['remaining']);

        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json('data.money');
        $this->assertSame(300.0, (float) $dashboard['savings']);
        $this->assertSame(300.0, (float) $dashboard['total_saved']);
        $this->assertCount(2, $dashboard['wallets']);

        // Withdrawing ₱100 back into cash lowers the goal and raises the wallet.
        $this->postJson('/api/savings/transactions', ['type' => 'withdrawal', 'amount' => 100, 'transaction_date' => '2026-09-23', 'savings_goal_id' => $goal['id'], 'wallet_id' => $cash['id']])
            ->assertCreated();
        $this->getJson('/api/goals')->assertOk()->assertJsonPath('data.0.current_amount', 200);
        $wallets = $this->getJson('/api/wallets')->assertOk()->json('data');
        $this->assertSame(2000.0 - 80 - 300 + 100, (float) collect($wallets)->firstWhere('id', $cash['id'])['balance']);

        // Reaching the target completes the goal; deleting the deposit reverts it.
        $big = $this->postJson('/api/savings/transactions', ['amount' => 800, 'transaction_date' => '2026-09-23', 'savings_goal_id' => $goal['id']])->assertCreated()->json('data');
        $this->getJson('/api/goals')->assertOk()->assertJsonPath('data.0.is_completed', true);
        $this->deleteJson("/api/savings/transactions/{$big['id']}")->assertOk();
        $this->getJson('/api/goals')->assertOk()->assertJsonPath('data.0.is_completed', false)->assertJsonPath('data.0.current_amount', 200);
    }

    public function test_salary_is_credited_to_the_salary_wallet_once_paid(): void
    {
        $this->actingAsTracker(true, ['salary_type' => 'per_period', 'basic_salary' => 10000]);

        // Wallets start on Sep 1; work the whole Sep 11-25 cut-off (paid on the 30th).
        $this->travelTo($this->manila('2026-09-01 09:00'));
        $wallets = $this->getJson('/api/wallets')->assertOk()->json('data');
        $cash = collect($wallets)->firstWhere('type', 'cash');

        foreach (['11', '12', '14', '15', '16', '17', '18', '19', '21', '22', '23', '24', '25'] as $day) {
            $this->postJson('/api/attendance/manual', ['work_date' => "2026-09-{$day}", 'time_in' => '08:00', 'time_out' => '17:00'])->assertCreated();
        }

        // Before payday nothing is credited yet.
        $this->travelTo($this->manila('2026-09-28 09:00'));
        $this->assertSame(0.0, (float) collect($this->getJson('/api/wallets')->json('data'))->firstWhere('id', $cash['id'])['salary_received']);

        // After payday the take-home pay of the cut-off lands in the cash wallet.
        $this->travelTo($this->manila('2026-10-01 09:00'));
        $wallet = collect($this->getJson('/api/wallets')->json('data'))->firstWhere('id', $cash['id']);
        $this->assertSame(10000.0, (float) $wallet['salary_received']);
        $this->assertSame(10000.0, (float) $wallet['balance']);

        // Moving the salary to a new BPI payroll account moves the credit with it.
        $bank = $this->postJson('/api/wallets', ['name' => 'BPI Payroll', 'type' => 'bank', 'category' => 'bank', 'institution_id' => 'bpi', 'account_type' => 'payroll', 'last4' => '1234', 'holder_name' => 'Juan', 'balance_as_of' => '2026-09-01'])
            ->assertCreated()->assertJsonPath('data.institution_id', 'bpi')->assertJsonPath('data.last4', '1234')->json('data');
        $this->putJson("/api/wallets/{$bank['id']}", ['receives_salary' => true])->assertOk();
        $wallets = collect($this->getJson('/api/wallets')->json('data'));
        $this->assertSame(10000.0, (float) $wallets->firstWhere('id', $bank['id'])['balance']);
        $this->assertSame(0.0, (float) $wallets->firstWhere('id', $cash['id'])['balance']);
        $this->assertFalse($wallets->firstWhere('id', $cash['id'])['receives_salary']);
    }

    public function test_wallet_validation_and_ownership(): void
    {
        $this->actingAsTracker();
        $this->getJson('/api/wallets')->assertOk();

        $created = $this->postJson('/api/wallets', ['name' => 'Maya', 'type' => 'maya', 'category' => 'ewallet', 'institution_id' => 'maya', 'opening_balance' => 50])->assertCreated()
            ->assertJsonPath('data.category', 'ewallet')->json('data');
        $this->postJson('/api/wallets', ['name' => 'X', 'type' => 'crypto'])->assertStatus(422);
        $this->postJson('/api/wallets', ['name' => 'X', 'type' => 'bank', 'last4' => '12ab'])->assertStatus(422);
        $this->postJson('/api/wallets', ['name' => 'X', 'type' => 'bank', 'color' => 'blue'])->assertStatus(422);
        $this->postJson('/api/savings/transactions', ['amount' => 0, 'transaction_date' => '2026-09-22'])->assertStatus(422);
        $this->postJson('/api/savings/transactions', ['amount' => 10, 'transaction_date' => '2026-09-22', 'wallet_id' => 999999])->assertStatus(422);

        $this->deleteJson("/api/wallets/{$created['id']}")->assertOk();
        $this->assertCount(2, $this->getJson('/api/wallets')->json('data'));
    }
}
