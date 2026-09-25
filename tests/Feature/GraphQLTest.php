<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\Feature\Concerns\CreatesTrackerUser;
use Tests\TestCase;

class GraphQLTest extends TestCase
{
    use CreatesTrackerUser, MakesGraphQLRequests, RefreshDatabase;

    public function test_login_and_me_with_a_bearer_token(): void
    {
        $user = User::factory()->create(['email' => 'gql@example.com', 'password' => 'password123']);

        $this->graphQL('mutation ($input: LoginInput!) { login(input: $input) { token user { id email salary_configured } } }', [
            'input' => ['email' => 'gql@example.com', 'password' => 'wrong'],
        ])->assertGraphQLValidationError('email', 'The provided credentials are incorrect.');

        $response = $this->graphQL('mutation ($input: LoginInput!) { login(input: $input) { token user { id email salary_configured } } }', [
            'input' => ['email' => 'gql@example.com', 'password' => 'password123', 'device_name' => 'test'],
        ])->assertJsonPath('data.login.user.email', 'gql@example.com');
        $token = $response->json('data.login.token');
        $this->assertNotEmpty($token);

        // Unauthenticated queries are refused, the token unlocks them.
        $this->graphQL('{ me { id } }')->assertGraphQLErrorMessage('Unauthenticated.');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->graphQL('{ me { id email } wallets { name type receives_salary institution_id } }')
            ->assertJsonPath('data.me.id', $user->id)
            ->assertJsonPath('data.wallets', []);
    }

    public function test_dashboard_attendance_and_money_flow(): void
    {
        $this->actingAsTracker(true, ['salary_type' => 'per_period', 'basic_salary' => 10000]);
        $this->travelTo($this->manila('2026-09-25 08:00'));

        $this->graphQL('mutation { timeIn { state already_recorded attendance { work_date time_in_label } notification { current { state } } } }')
            ->assertJsonPath('data.timeIn.state', 'ON_DUTY')
            ->assertJsonPath('data.timeIn.attendance.time_in_label', '8:00 AM');

        $this->travelTo($this->manila('2026-09-25 17:00'));
        $this->graphQL('mutation { timeOut { state attendance { worked_minutes salary_amount } } }')
            ->assertJsonPath('data.timeOut.state', 'COMPLETED')
            ->assertJsonPath('data.timeOut.attendance.worked_minutes', 480)
            ->assertJsonPath('data.timeOut.attendance.salary_amount', 769.23);

        $categories = $this->graphQL('{ expenseCategories { id name } }')->json('data.expenseCategories');
        $food = collect($categories)->firstWhere('name', 'Food');

        $this->postJson('/api/wallets', ['name' => 'GCash', 'type' => 'gcash', 'category' => 'ewallet', 'institution_id' => 'gcash'])->assertCreated();
        $this->graphQL('mutation ($input: ExpenseInput!) { createExpense(input: $input) { id amount payment_method wallet { name } category { name } } }', [
            'input' => ['amount' => 120, 'expense_date' => '2026-09-25', 'expense_category_id' => $food['id'], 'payment_method' => 'gcash'],
        ])->assertJsonPath('data.createExpense.wallet.name', 'GCash')->assertJsonPath('data.createExpense.category.name', 'Food');

        $this->graphQL('mutation ($input: ExpenseInput!) { createExpense(input: $input) { id } }', [
            'input' => ['amount' => -1, 'expense_date' => '2026-09-25'],
        ])->assertGraphQLValidationKeys(['amount']);

        $goal = $this->graphQL('mutation { createGoal(input: { name: "Phone", target_amount: 1000 }) { id } }')->json('data.createGoal');
        $this->graphQL('mutation ($input: SavingsTransactionInput!) { createSavingsTransaction(input: $input) { type amount goal { name } } }', [
            'input' => ['amount' => 300, 'transaction_date' => '2026-09-25', 'savings_goal_id' => $goal['id']],
        ])->assertJsonPath('data.createSavingsTransaction.goal.name', 'Phone');

        $dashboard = $this->graphQL('{ dashboard { today { state earned } period { summary { expenses savings remaining take_home } } money { total_saved wallets { name balance } } recent_attendance { work_date } } }')
            ->assertJsonPath('data.dashboard.today.state', 'COMPLETED')
            ->assertJsonPath('data.dashboard.today.earned', 769.23)
            ->assertJsonPath('data.dashboard.period.summary.expenses', 120)
            ->assertJsonPath('data.dashboard.period.summary.savings', 300)
            ->assertJsonPath('data.dashboard.money.total_saved', 300)
            ->json('data.dashboard');
        $this->assertSame(round($dashboard['period']['summary']['take_home'] - 420, 2), (float) $dashboard['period']['summary']['remaining']);

        $this->graphQL('{ expenses(range: "period") { total expenses { amount wallet { name } } pagination { total } } expenseSummary(range: "period") { total by_day } }')
            ->assertJsonPath('data.expenses.total', 120)
            ->assertJsonPath('data.expenseSummary.by_day.2026-09-25', 120);

        $this->graphQL('{ statistics(range: "custom", from: "2026-09-25", to: "2026-09-25") { summary { days_worked } series { date salary } } }')
            ->assertJsonPath('data.statistics.summary.days_worked', 1)
            ->assertJsonPath('data.statistics.series.0.salary', 769.23);
    }

    public function test_rows_of_other_users_are_not_reachable(): void
    {
        $other = $this->trackerUser();
        $foreign = $other->expenses()->create(['amount' => 50, 'expense_date' => '2026-09-25', 'payment_method' => 'cash']);

        $this->actingAsTracker();
        $this->graphQL('query ($id: Int!) { expense(id: $id) { id } }', ['id' => $foreign->id])
            ->assertGraphQLErrorMessage('Resource not found.');
        $this->graphQL('mutation ($id: Int!) { deleteExpense(id: $id) { success } }', ['id' => $foreign->id])
            ->assertJsonMissingPath('data.deleteExpense.success');
        $this->assertDatabaseHas('expenses', ['id' => $foreign->id, 'deleted_at' => null]);
    }
}
