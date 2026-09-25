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

        // Everyone starts with one Cash account (receives the salary); the user adds the rest.
        $cash = $this->getJson('/api/wallets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.institution_id', 'cash')->json('data.0');
        $gcash = $this->postJson('/api/wallets', ['name' => 'GCash', 'type' => 'gcash', 'category' => 'ewallet', 'institution_id' => 'gcash', 'account_type' => 'ewallet'])->assertCreated()->json('data');
        $wallets = $this->getJson('/api/wallets')->assertOk()->json('data');
        $this->assertSame(['Cash', 'GCash'], array_column($wallets, 'name'));
        $this->assertSame('gcash', $wallets[1]['institution_id']);
        $this->assertSame('ewallet', $wallets[1]['category']);
        $this->assertTrue($wallets[0]['receives_salary']);
        $this->assertSame(0.0, (float) $wallets[0]['balance']);

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
        $this->assertNotEmpty($overview['range']['label']);
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
        $big = $this->postJson('/api/savings/transactions', ['amount' => 800, 'transaction_date' => '2026-09-23', 'savings_goal_id' => $goal['id'], 'wallet_id' => $cash['id']])->assertCreated()->json('data');
        $this->getJson('/api/goals')->assertOk()->assertJsonPath('data.0.is_completed', true);
        $this->deleteJson("/api/savings/transactions/{$big['id']}")->assertOk();
        $this->getJson('/api/goals')->assertOk()->assertJsonPath('data.0.is_completed', false)->assertJsonPath('data.0.current_amount', 200);
    }

    public function test_salary_is_credited_when_received_into_the_salary_wallet(): void
    {
        $this->actingAsTracker(true, ['salary_type' => 'per_period', 'basic_salary' => 10000]);

        // The default Cash account receives the salary; work the whole Sep 11-25 cut-off (paid on the 30th).
        $this->travelTo($this->manila('2026-09-01 09:00'));
        $cash = $this->getJson('/api/wallets')->assertOk()->json('data.0');
        foreach (['11', '12', '14', '15', '16', '17', '18', '19', '21', '22', '23', '24', '25'] as $day) {
            $this->postJson('/api/attendance/manual', ['work_date' => "2026-09-{$day}", 'time_in' => '08:00', 'time_out' => '17:00'])->assertCreated();
        }

        // While the cut-off runs nothing waits to be received and it cannot be received yet.
        $this->travelTo($this->manila('2026-09-20 09:00'));
        $this->assertCount(0, $this->getJson('/api/dashboard')->assertOk()->json('data.pending_salary'));
        $this->postJson('/api/salary-receipts', ['period_from' => '2026-09-11', 'period_to' => '2026-09-25'])->assertStatus(422);

        // Once it ends it waits for "Receive salary" — before and after its pay day — and nothing is credited yet.
        $this->travelTo($this->manila('2026-09-28 09:00'));
        $pending = $this->getJson('/api/dashboard')->assertOk()->json('data.pending_salary');
        $this->assertCount(1, $pending);
        $this->assertSame('2026-09-11', $pending[0]['summary']['from']);
        $this->assertSame(10000.0, (float) $pending[0]['summary']['take_home']);
        $this->assertFalse($pending[0]['summary']['received']);
        $this->assertSame('Cash', $pending[0]['wallet']['name']);
        $this->assertSame(-2, $pending[0]['days_since_pay']);
        $this->travelTo($this->manila('2026-10-03 09:00'));
        $pending = $this->getJson('/api/dashboard')->json('data.pending_salary');
        $this->assertCount(1, $pending);
        $this->assertSame(3, $pending[0]['days_since_pay']);
        $this->assertSame(0.0, (float) collect($this->getJson('/api/wallets')->json('data'))->firstWhere('id', $cash['id'])['salary_received']);

        // Only real cut-offs can be received.
        $this->postJson('/api/salary-receipts', ['period_from' => '2026-09-12', 'period_to' => '2026-09-25'])->assertStatus(422);

        // Move the salary to a new BPI payroll account, then receive: the take-home lands there.
        $bank = $this->postJson('/api/wallets', ['name' => 'BPI Payroll', 'type' => 'bank', 'category' => 'bank', 'institution_id' => 'bpi', 'account_type' => 'payroll', 'last4' => '1234', 'holder_name' => 'Juan', 'balance_as_of' => '2026-09-01'])
            ->assertCreated()->assertJsonPath('data.institution_id', 'bpi')->assertJsonPath('data.last4', '1234')->json('data');
        $this->putJson("/api/wallets/{$bank['id']}", ['receives_salary' => true])->assertOk();
        $receipt = $this->postJson('/api/salary-receipts', ['period_from' => '2026-09-11', 'period_to' => '2026-09-25'])
            ->assertCreated()->assertJsonPath('data.wallet.name', 'BPI Payroll')->assertJsonPath('data.received_date', '2026-10-03')->json('data');
        $this->assertSame(10000.0, (float) $receipt['amount']);
        $this->postJson('/api/salary-receipts', ['period_from' => '2026-09-11', 'period_to' => '2026-09-25'])->assertStatus(422); // not twice
        $wallets = collect($this->getJson('/api/wallets')->json('data'));
        $this->assertSame(10000.0, (float) $wallets->firstWhere('id', $bank['id'])['salary_received']);
        $this->assertSame(10000.0, (float) $wallets->firstWhere('id', $bank['id'])['balance']);
        $this->assertSame(0.0, (float) $wallets->firstWhere('id', $cash['id'])['balance']);
        $this->assertFalse($wallets->firstWhere('id', $cash['id'])['receives_salary']);
        $this->assertSame(1, $wallets->where('receives_salary', true)->count());
        $this->assertCount(0, $this->getJson('/api/dashboard')->json('data.pending_salary'));
        $gql = $this->postJson('/api/graphql', ['query' => '{ salaryReceipts { id amount period_from wallet { name } } salaryPeriods(count: 2) { start_date summary { received received_at receipt_id } } }'])->assertOk();
        $this->assertSame('BPI Payroll', $gql->json('data.salaryReceipts.0.wallet.name'));
        $this->assertTrue($gql->json('data.salaryPeriods.1.summary.received'));
        $this->assertSame('2026-10-03', $gql->json('data.salaryPeriods.1.summary.received_at'));

        // Two finished cut-offs wait together (oldest first) until each one is received; undo puts one back.
        $this->travelTo($this->manila('2026-10-27 09:00'));
        foreach (['09-28', '09-29', '09-30', '10-01', '10-02', '10-12', '10-13', '10-14'] as $day) {
            $this->postJson('/api/attendance/manual', ['work_date' => "2026-{$day}", 'time_in' => '08:00', 'time_out' => '17:00'])->assertCreated();
        }
        $pending = $this->getJson('/api/dashboard')->json('data.pending_salary');
        $this->assertSame(['2026-09-26', '2026-10-11'], array_column(array_column($pending, 'summary'), 'from'));
        $second = $this->postJson('/api/salary-receipts', ['period_from' => '2026-09-26', 'period_to' => '2026-10-10'])->assertCreated()->json('data');
        $this->assertSame(['2026-10-11'], array_column(array_column($this->getJson('/api/dashboard')->json('data.pending_salary'), 'summary'), 'from'));
        $this->assertGreaterThan(10000.0, (float) collect($this->getJson('/api/wallets')->json('data'))->firstWhere('id', $bank['id'])['salary_received']);
        $this->deleteJson("/api/salary-receipts/{$second['id']}")->assertOk();
        $this->assertSame(['2026-09-26', '2026-10-11'], array_column(array_column($this->getJson('/api/dashboard')->json('data.pending_salary'), 'summary'), 'from'));
        $this->assertSame(10000.0, (float) collect($this->getJson('/api/wallets')->json('data'))->firstWhere('id', $bank['id'])['salary_received']);

        // Side-hustle income lands in the account it was received into and adds to "left to spend".
        $this->postJson('/api/incomes', ['amount' => 1500, 'income_date' => '2026-10-27', 'type' => 'freelance', 'source' => 'Logo design', 'wallet_id' => $bank['id']])
            ->assertCreated()->assertJsonPath('data.wallet.name', 'BPI Payroll');
        $this->postJson('/api/incomes', ['amount' => 0, 'income_date' => '2026-10-27'])->assertStatus(422);
        $this->assertSame(11500.0, (float) collect($this->getJson('/api/wallets')->json('data'))->firstWhere('id', $bank['id'])['balance']);
        $incomes = $this->getJson('/api/incomes?range=custom&from=2026-10-26&to=2026-11-10')->assertOk()->json('data');
        $this->assertSame(1500.0, (float) $incomes['total']);
        $summary = $this->getJson('/api/salary/summary?range=custom&from=2026-10-26&to=2026-11-10')->assertOk()->json('data.summary');
        $this->assertSame(1500.0, (float) $summary['other_income']);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.money.other_income', 1500);
    }

    public function test_recurring_deductions_apply_every_payday(): void
    {
        $this->actingAsTracker(true, ['salary_type' => 'per_period', 'basic_salary' => 10000]);
        $this->travelTo($this->manila('2026-09-22 12:00'));

        // ₱600 SSS every payday from Sep 1, a one-time ₱2,000 bonus on Sep 15.
        $sss = $this->postJson('/api/salary-adjustments', ['type' => 'deduction', 'amount' => 600, 'adjustment_date' => '2026-09-01', 'description' => 'SSS', 'recurring' => true])
            ->assertCreated()->assertJsonPath('data.recurring', true)->json('data');
        $this->postJson('/api/salary-adjustments', ['type' => 'bonus', 'amount' => 2000, 'adjustment_date' => '2026-09-15'])->assertCreated();
        $this->postJson('/api/salary-adjustments', ['type' => 'deduction', 'amount' => 10, 'adjustment_date' => '2026-09-10', 'recurring' => true, 'recurring_until' => '2026-09-01'])->assertStatus(422);

        $current = $this->getJson('/api/salary')->assertOk()->json('data.summary'); // Sep 11-25
        $this->assertSame(600.0, (float) $current['deductions']);
        $this->assertSame(2000.0, (float) $current['additional_income']);

        $list = $this->getJson('/api/salary-adjustments?range=period')->assertOk()->json('data');
        $this->assertSame(2000.0, (float) $list['income']);
        $this->assertSame(600.0, (float) $list['deductions']);
        $this->assertCount(2, $list['adjustments']);

        // The next cut-off (Sep 26 - Oct 10) still has the SSS deduction but not the bonus.
        $next = $this->getJson('/api/salary/summary?range=custom&from=2026-09-26&to=2026-10-10')->assertOk()->json('data.summary');
        $this->assertSame(600.0, (float) $next['deductions']);
        $this->assertSame(0.0, (float) $next['additional_income']);

        // Ending the recurrence stops it for later cut-offs.
        $this->putJson("/api/salary-adjustments/{$sss['id']}", ['recurring_until' => '2026-09-25'])->assertOk();
        $this->assertSame(0.0, (float) $this->getJson('/api/salary/summary?range=custom&from=2026-09-26&to=2026-10-10')->json('data.summary.deductions'));
    }

    public function test_wallet_validation_and_ownership(): void
    {
        $this->actingAsTracker();
        $this->getJson('/api/wallets')->assertOk()->assertJsonCount(1, 'data');

        $created = $this->postJson('/api/wallets', ['name' => 'Maya', 'type' => 'maya', 'category' => 'ewallet', 'institution_id' => 'maya', 'opening_balance' => 50])->assertCreated()
            ->assertJsonPath('data.category', 'ewallet')->json('data');
        $this->postJson('/api/wallets', ['name' => 'X', 'type' => 'crypto'])->assertStatus(422);
        $this->postJson('/api/wallets', ['name' => 'X', 'type' => 'bank', 'last4' => '12ab'])->assertStatus(422);
        $this->postJson('/api/wallets', ['name' => 'X', 'type' => 'bank', 'color' => 'blue'])->assertStatus(422);
        $this->postJson('/api/savings/transactions', ['amount' => 0, 'transaction_date' => '2026-09-22'])->assertStatus(422);
        $this->postJson('/api/savings/transactions', ['amount' => 10, 'transaction_date' => '2026-09-22', 'wallet_id' => 999999])->assertStatus(422);

        $this->deleteJson("/api/wallets/{$created['id']}")->assertOk();
        $this->assertCount(1, $this->getJson('/api/wallets')->json('data'));
    }

    public function test_goals_are_kept_in_a_wallet(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-22 12:00'));
        $cash = $this->getJson('/api/wallets')->assertOk()->json('data.0');
        $this->putJson("/api/wallets/{$cash['id']}", ['opening_balance' => 1000])->assertOk();
        $bank = $this->postJson('/api/wallets', ['name' => 'MariBank', 'type' => 'bank', 'category' => 'bank', 'institution_id' => 'seabank', 'opening_balance' => 500])->assertCreated()->json('data');

        // Savings always come from an account; a goal lives in one.
        $this->postJson('/api/savings/transactions', ['amount' => 100, 'transaction_date' => '2026-09-22'])->assertStatus(422)->assertJsonValidationErrors(['wallet_id']);
        $this->postJson('/api/goals', ['name' => 'X', 'target_amount' => 10, 'wallet_id' => 999999])->assertStatus(422);
        $goal = $this->postJson('/api/goals', ['name' => 'Laptop', 'target_amount' => 5000, 'wallet_id' => $bank['id']])->assertCreated()->assertJsonPath('data.wallet_id', $bank['id'])->json('data');
        $this->getJson('/api/goals')->assertOk()->assertJsonPath('data.0.wallet.name', 'MariBank');

        // ₱300 from cash into the goal: cash loses it, MariBank holds it, reserved for the goal.
        $this->postJson('/api/savings/transactions', ['amount' => 300, 'transaction_date' => '2026-09-22', 'savings_goal_id' => $goal['id'], 'wallet_id' => $cash['id']])->assertCreated();
        $wallets = collect($this->getJson('/api/wallets')->assertOk()->json('data'));
        $this->assertSame(700.0, (float) $wallets->firstWhere('id', $cash['id'])['balance']);
        $bankNow = $wallets->firstWhere('id', $bank['id']);
        $this->assertSame(800.0, (float) $bankNow['balance']);
        $this->assertSame(300.0, (float) $bankNow['goals_in']);
        $this->assertSame(300.0, (float) $bankNow['goals_held']);
        $this->assertSame(500.0, (float) $bankNow['available']);

        // Saving from MariBank itself keeps its balance; only the reserved part grows.
        $this->postJson('/api/savings/transactions', ['amount' => 50, 'transaction_date' => '2026-09-23', 'savings_goal_id' => $goal['id'], 'wallet_id' => $bank['id']])->assertCreated();
        $bankNow = collect($this->getJson('/api/wallets')->json('data'))->firstWhere('id', $bank['id']);
        $this->assertSame(800.0, (float) $bankNow['balance']);
        $this->assertSame(350.0, (float) $bankNow['goals_held']);
        $this->assertSame(450.0, (float) $bankNow['available']);

        // Withdrawing ₱100 of the goal back to cash: cash gains it, MariBank lets it go.
        $this->postJson('/api/savings/transactions', ['type' => 'withdrawal', 'amount' => 100, 'transaction_date' => '2026-09-23', 'savings_goal_id' => $goal['id'], 'wallet_id' => $cash['id']])->assertCreated();
        $wallets = collect($this->getJson('/api/wallets')->json('data'));
        $this->assertSame(800.0, (float) $wallets->firstWhere('id', $cash['id'])['balance']);
        $bankNow = $wallets->firstWhere('id', $bank['id']);
        $this->assertSame(700.0, (float) $bankNow['balance']);
        $this->assertSame(250.0, (float) $bankNow['goals_held']);
        $this->assertSame(450.0, (float) $bankNow['available']);

        // An expense "not from a wallet" is tracked but charges no account.
        $this->postJson('/api/expenses', ['amount' => 40, 'expense_date' => '2026-09-23', 'wallet_id' => null, 'payment_method' => 'other'])->assertCreated()->assertJsonPath('data.wallet_id', null);
        $this->assertSame(800.0, (float) collect($this->getJson('/api/wallets')->json('data'))->firstWhere('id', $cash['id'])['balance']);
        $this->assertSame(40.0, (float) $this->getJson('/api/salary')->assertOk()->json('data.summary.expenses'));

        // A custom card design is stored with the wallet and validated.
        $design = ['mode' => 'gradient', 'colors' => ['#0B3D91', '#1E5AC8', '#38BDF8'], 'direction' => 'down-right', 'pattern' => 'waves'];
        $this->putJson("/api/wallets/{$bank['id']}", ['design' => $design])->assertOk()->assertJsonPath('data.design.pattern', 'waves');
        $this->putJson("/api/wallets/{$bank['id']}", ['design' => ['mode' => 'solid', 'colors' => ['red'], 'direction' => 'down', 'pattern' => 'rings']])->assertStatus(422);
        $this->putJson("/api/wallets/{$bank['id']}", ['design' => ['mode' => 'solid', 'colors' => ['#112233'], 'direction' => 'sideways', 'pattern' => 'rings']])->assertStatus(422);
        $this->assertSame('waves', $this->postJson('/api/graphql', ['query' => "{ wallets { id design goals_held available } }"])->assertOk()->json('data.wallets.1.design.pattern'));
        $this->putJson("/api/wallets/{$bank['id']}", ['design' => null])->assertOk()->assertJsonPath('data.design', null);
    }
}
