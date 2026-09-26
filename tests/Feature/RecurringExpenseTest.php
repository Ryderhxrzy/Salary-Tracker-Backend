<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTrackerUser;
use Tests\TestCase;

class RecurringExpenseTest extends TestCase
{
    use CreatesTrackerUser;
    use RefreshDatabase;

    public function test_monthly_bill_is_recorded_from_the_chosen_account_and_reminded(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-10 09:00'));
        $wallet = $this->getJson('/api/wallets')->json('data.0');
        $this->putJson("/api/wallets/{$wallet['id']}", ['opening_balance' => 5000])->assertOk();
        $category = $this->getJson('/api/expense-categories')->json('data.0');

        $bill = $this->postJson('/api/recurring-expenses', ['description' => 'Netflix', 'amount' => 549, 'frequency' => 'monthly', 'day_of_month' => 15, 'wallet_id' => $wallet['id'], 'expense_category_id' => $category['id'], 'start_date' => '2026-09-10'])
            ->assertCreated()->assertJsonPath('data.next_date', '2026-09-15')->assertJsonPath('data.auto_pay', true)->json('data');
        $this->postJson('/api/recurring-expenses', ['description' => 'Bad', 'amount' => 1, 'frequency' => 'daily'])->assertStatus(422);

        // The day before: a reminder, nothing recorded yet.
        $this->travelTo($this->manila('2026-09-14 09:00'));
        $this->getJson('/api/dashboard')->assertOk();
        $this->assertSame(['expense_due_soon'], collect($this->getJson('/api/notifications')->json('data.notifications'))->pluck('type')->all());
        $this->assertSame(0, (int) $this->getJson('/api/expenses?range=month')->json('data.pagination.total'));

        // On the 15th the expense is recorded from the wallet and the schedule moves to October.
        $this->travelTo($this->manila('2026-09-15 09:00'));
        $this->getJson('/api/dashboard')->assertOk();
        $expenses = $this->postJson('/api/graphql', ['query' => '{ expenses(range: "month") { total expenses { amount description wallet { id } } } recurringExpenses { next_date last_paid_date } }'])->assertOk()->json('data');
        $this->assertSame(549.0, (float) $expenses['expenses']['total']);
        $this->assertSame('Netflix', $expenses['expenses']['expenses'][0]['description']);
        $this->assertSame($wallet['id'], $expenses['expenses']['expenses'][0]['wallet']['id']);
        $this->assertSame('2026-10-15', $expenses['recurringExpenses'][0]['next_date']);
        $this->assertSame('2026-09-15', $expenses['recurringExpenses'][0]['last_paid_date']);
        $this->assertSame(5000.0 - 549.0, (float) collect($this->getJson('/api/wallets')->json('data'))->firstWhere('id', $wallet['id'])['balance']);
        $types = collect($this->getJson('/api/notifications')->json('data.notifications'))->pluck('type')->all();
        $this->assertContains('expense_paid', $types);

        // Running again the same day records nothing new.
        $this->getJson('/api/dashboard')->assertOk();
        $this->assertSame(1, (int) $this->getJson('/api/expenses?range=month')->json('data.pagination.total'));

        // A reminder-only bill just notifies; "pay now" records it by hand.
        $rent = $this->postJson('/api/recurring-expenses', ['description' => 'Rent', 'amount' => 3000, 'frequency' => 'semi_monthly', 'day_of_month' => 15, 'second_day_of_month' => 30, 'wallet_id' => $wallet['id'], 'auto_pay' => false, 'remind' => false])
            ->assertCreated()->assertJsonPath('data.next_date', '2026-09-15')->json('data');
        $this->getJson('/api/dashboard')->assertOk();
        $this->assertContains('expense_due', collect($this->getJson('/api/notifications')->json('data.notifications'))->pluck('type')->all());
        $this->assertSame('2026-09-30', collect($this->getJson('/api/recurring-expenses')->json('data'))->firstWhere('id', $rent['id'])['next_date']);
        $this->postJson("/api/recurring-expenses/{$rent['id']}/pay-now")->assertCreated()->assertJsonPath('data.description', 'Rent');
        $this->assertSame(2, (int) $this->getJson('/api/expenses?range=month')->json('data.pagination.total'));

        $this->deleteJson("/api/recurring-expenses/{$rent['id']}")->assertOk();
        $this->assertCount(1, $this->getJson('/api/recurring-expenses')->json('data'));
    }
}
