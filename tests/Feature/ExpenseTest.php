<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTrackerUser;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use CreatesTrackerUser, RefreshDatabase;

    public function test_expense_crud_and_summary(): void
    {
        $user = $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-22 12:00'));

        $categories = $this->getJson('/api/expense-categories')->assertOk()->json('data');
        $this->assertCount(10, $categories);
        $food = collect($categories)->firstWhere('name', 'Food');

        $created = $this->postJson('/api/expenses', [
            'amount' => 120,
            'expense_category_id' => $food['id'],
            'description' => 'Breakfast',
            'expense_date' => '2026-09-22',
            'payment_method' => 'cash',
        ])->assertCreated()->assertJsonPath('data.category.name', 'Food');

        $id = $created->json('data.id');
        $this->putJson("/api/expenses/{$id}", ['amount' => 150])->assertOk()->assertJsonPath('data.amount', 150);

        $this->postJson('/api/expenses', ['amount' => 50, 'expense_date' => '2026-09-22'])->assertCreated();

        $this->getJson('/api/expenses?range=today')->assertOk()
            ->assertJsonPath('data.total', 200)
            ->assertJsonCount(2, 'data.expenses');

        $summary = $this->getJson('/api/expenses/summary?range=today')->assertOk()->json('data');
        $this->assertSame(200.0, (float) $summary['total']);
        $this->assertSame('Food', $summary['by_category'][0]['name']);

        $this->deleteJson("/api/expenses/{$id}")->assertOk();
        $this->getJson('/api/expenses?range=today')->assertOk()->assertJsonPath('data.total', 50);
        $this->assertSoftDeleted('expenses', ['id' => $id]);

        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.today.expenses', 50);
    }

    public function test_custom_categories_and_validation(): void
    {
        $this->actingAsTracker();

        $this->postJson('/api/expense-categories', ['name' => 'Pets', 'color' => '#22C55E'])
            ->assertCreated()->assertJsonPath('data.is_default', false);

        $this->postJson('/api/expense-categories', ['name' => 'Pets'])->assertStatus(422);
        $this->postJson('/api/expenses', ['amount' => -5, 'expense_date' => '2026-09-22'])->assertStatus(422)->assertJsonPath('success', false);
        $this->postJson('/api/expenses', ['amount' => 10, 'expense_date' => '2026-09-22', 'expense_category_id' => 999999])->assertStatus(422);
    }

    public function test_savings_goals(): void
    {
        $this->actingAsTracker();

        $goal = $this->postJson('/api/goals', ['name' => 'Emergency fund', 'type' => 'emergency_fund', 'target_amount' => 10000, 'current_amount' => 6500])
            ->assertCreated()
            ->assertJsonPath('data.progress_percent', 65)
            ->json('data');

        $this->putJson("/api/goals/{$goal['id']}", ['current_amount' => 10000])
            ->assertOk()->assertJsonPath('data.is_completed', true);

        $this->getJson('/api/goals')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/goals/{$goal['id']}")->assertOk();
        $this->getJson('/api/goals')->assertOk()->assertJsonCount(0, 'data');
    }
}
