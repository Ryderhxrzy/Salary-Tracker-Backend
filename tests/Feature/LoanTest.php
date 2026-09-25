<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\Feature\Concerns\CreatesTrackerUser;
use Tests\TestCase;

class LoanTest extends TestCase
{
    use CreatesTrackerUser, MakesGraphQLRequests, RefreshDatabase;

    public function test_borrowed_loan_payments_lower_take_home_or_the_wallet(): void
    {
        $this->actingAsTracker(true, ['salary_type' => 'per_period', 'basic_salary' => 10000]);
        $this->travelTo($this->manila('2026-09-22 12:00'));

        $wallets = collect($this->getJson('/api/wallets')->json('data'));
        $cash = $wallets->firstWhere('type', 'cash');
        $this->putJson("/api/wallets/{$cash['id']}", ['opening_balance' => 5000])->assertOk();

        // SSS salary loan: ₱20,000 to repay, ₱1,000 per cut-off taken from the payslip.
        $sss = $this->postJson('/api/loans', [
            'name' => 'SSS salary loan', 'lender' => 'SSS', 'principal_amount' => 18000, 'total_amount' => 20000,
            'installment_amount' => 1000, 'frequency' => 'per_cutoff', 'start_date' => '2026-09-01', 'via_payroll' => true,
        ])->assertCreated()
            ->assertJsonPath('data.remaining_amount', 20000)
            ->assertJsonPath('data.next_due_date', '2026-09-16')
            ->assertJsonPath('data.status', 'active')
            ->json('data');

        $this->postJson("/api/loans/{$sss['id']}/payments", ['amount' => 1000, 'payment_date' => '2026-09-15'])
            ->assertCreated()->assertJsonPath('data.via_payroll', true)->assertJsonPath('data.wallet_id', null);

        $loan = $this->getJson("/api/loans/{$sss['id']}")->assertOk()->json('data');
        $this->assertSame(19000.0, (float) $loan['remaining_amount']);
        $this->assertSame('2026-10-01', $loan['next_due_date'], 'due date moves to the next cut-off after a payment');
        $this->assertCount(1, $loan['payments']);

        // A personal loan paid in cash comes out of the cash wallet and off "left to spend".
        $personal = $this->postJson('/api/loans', ['name' => 'Utang kay Tita', 'principal_amount' => 2000, 'start_date' => '2026-09-10', 'frequency' => 'none'])
            ->assertCreated()->assertJsonPath('data.next_due_date', null)->json('data');
        $this->postJson("/api/loans/{$personal['id']}/payments", ['amount' => 500, 'payment_date' => '2026-09-20', 'wallet_id' => $cash['id']])
            ->assertCreated()->assertJsonPath('data.wallet.name', 'Cash');

        $summary = $this->getJson('/api/salary')->assertOk()->json('data.summary');
        $this->assertSame(1000.0, (float) $summary['loan_deductions']);
        $this->assertSame(500.0, (float) $summary['loan_payments']);
        $this->assertSame(round($summary['salary'] - 1000, 2), (float) $summary['take_home'], 'payroll loan payment lowers the take-home pay');
        $this->assertSame(round($summary['take_home'] - 500, 2), (float) $summary['remaining']);

        $cashNow = collect($this->getJson('/api/wallets')->json('data'))->firstWhere('id', $cash['id']);
        $this->assertSame(4500.0, (float) $cashNow['balance']);

        $money = $this->getJson('/api/dashboard')->assertOk()->json('data.money');
        $this->assertSame(19000.0 + 1500.0, (float) $money['loans']['owed']);
        $this->assertSame(2, $money['loans']['active_count']);
        $this->assertSame('SSS salary loan', $money['loans']['next_due']['name']);

        $overview = $this->getJson('/api/loans?range=period')->assertOk()->json('data');
        $this->assertCount(2, $overview['loans']);
        $this->assertSame(1500.0, (float) $overview['period']['paid'] + (float) $overview['period']['payroll']);
    }

    public function test_money_lent_is_a_receivable_that_returns_to_the_wallet(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-22 12:00'));
        $wallets = collect($this->getJson('/api/wallets')->json('data'));
        $gcash = $wallets->firstWhere('type', 'gcash');
        $this->putJson("/api/wallets/{$gcash['id']}", ['opening_balance' => 3000, 'balance_as_of' => '2026-09-01'])->assertOk();

        $lent = $this->postJson('/api/loans', ['name' => 'Pautang kay Ben', 'type' => 'lent', 'principal_amount' => 1000, 'start_date' => '2026-09-12', 'wallet_id' => $gcash['id'], 'frequency' => 'weekly'])
            ->assertCreated()->assertJsonPath('data.next_due_date', '2026-09-19')->json('data');

        // Lending the money took it out of GCash.
        $this->assertSame(2000.0, (float) collect($this->getJson('/api/wallets')->json('data'))->firstWhere('id', $gcash['id'])['balance']);

        $this->postJson("/api/loans/{$lent['id']}/payments", ['amount' => 1000, 'payment_date' => '2026-09-21'])->assertCreated();
        $loan = $this->getJson("/api/loans/{$lent['id']}")->json('data');
        $this->assertTrue($loan['is_paid']);
        $this->assertSame('paid', $loan['status']);
        $this->assertNull($loan['next_due_date']);
        $this->assertSame(3000.0, (float) collect($this->getJson('/api/wallets')->json('data'))->firstWhere('id', $gcash['id'])['balance']);

        $money = $this->getJson('/api/dashboard')->json('data.money');
        $this->assertSame(0.0, (float) $money['loans']['receivable']);
        $this->assertSame(1000.0, (float) $this->getJson('/api/salary')->json('data.summary.loan_received'));

        $this->deleteJson("/api/loans/{$lent['id']}")->assertOk();
        $this->getJson('/api/loans')->assertOk()->assertJsonCount(0, 'data.loans');
    }

    public function test_loans_through_graphql_and_validation(): void
    {
        $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-22 12:00'));

        $this->postJson('/api/loans', ['name' => 'X', 'principal_amount' => 0, 'start_date' => '2026-09-01'])->assertStatus(422);

        $loan = $this->graphQL('mutation ($input: LoanInput!) { createLoan(input: $input) { id name remaining_amount next_due_date } }', [
            'input' => ['name' => 'Pag-IBIG MPL', 'principal_amount' => 10000, 'installment_amount' => 500, 'frequency' => 'monthly', 'start_date' => '2026-09-05', 'via_payroll' => true],
        ])->assertJsonPath('data.createLoan.next_due_date', '2026-10-05')->json('data.createLoan');

        $this->graphQL('mutation ($id: Int!, $input: LoanPaymentInput!) { createLoanPayment(loan_id: $id, input: $input) { amount via_payroll loan { name } } }', [
            'id' => $loan['id'], 'input' => ['amount' => 500, 'payment_date' => '2026-09-15'],
        ])->assertJsonPath('data.createLoanPayment.via_payroll', true);

        $this->graphQL('{ loans(status: "active") { id name paid_amount remaining_amount payments_count } dashboard { money { loans { owed active_count next_due { name due_date } } } } }')
            ->assertJsonPath('data.loans.0.remaining_amount', 9500)
            ->assertJsonPath('data.loans.0.payments_count', 1)
            ->assertJsonPath('data.dashboard.money.loans.owed', 9500)
            ->assertJsonPath('data.dashboard.money.loans.next_due.due_date', '2026-10-05');

        $this->graphQL('query ($id: Int!) { loan(id: $id) { name payments { amount payment_date } } }', ['id' => $loan['id']])
            ->assertJsonPath('data.loan.payments.0.amount', 500);
    }
}
