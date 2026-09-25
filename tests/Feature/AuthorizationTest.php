<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesTrackerUser;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use CreatesTrackerUser, RefreshDatabase;

    public function test_users_cannot_access_each_others_data_by_id(): void
    {
        $alice = $this->actingAsTracker();
        $this->travelTo($this->manila('2026-09-22 12:00'));

        $expenseId = $this->postJson('/api/expenses', ['amount' => 100, 'expense_date' => '2026-09-22'])->assertCreated()->json('data.id');
        $attendanceId = $this->postJson('/api/attendance/manual', ['work_date' => '2026-09-21', 'time_in' => '08:00', 'time_out' => '17:00'])->assertCreated()->json('data.id');
        $goalId = $this->postJson('/api/goals', ['name' => 'Bike', 'target_amount' => 5000])->assertCreated()->json('data.id');
        $adjustmentId = $this->postJson('/api/salary-adjustments', ['type' => 'bonus', 'amount' => 500, 'adjustment_date' => '2026-09-22'])->assertCreated()->json('data.id');
        $leaveId = $this->postJson('/api/leave-records', ['leave_date' => '2026-09-23', 'type' => 'vacation'])->assertCreated()->json('data.id');
        $categoryId = $alice->expenseCategories()->first()->id;

        $bob = $this->trackerUser();
        Sanctum::actingAs($bob);

        $this->getJson("/api/expenses/{$expenseId}")->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
        $this->putJson("/api/expenses/{$expenseId}", ['amount' => 1])->assertStatus(403);
        $this->deleteJson("/api/expenses/{$expenseId}")->assertStatus(403);

        $this->getJson("/api/attendance/{$attendanceId}")->assertStatus(403);
        $this->putJson("/api/attendance/{$attendanceId}", ['time_out' => '18:00'])->assertStatus(403);
        $this->deleteJson("/api/attendance/{$attendanceId}")->assertStatus(403);

        $this->putJson("/api/goals/{$goalId}", ['current_amount' => 1])->assertStatus(403);
        $this->deleteJson("/api/goals/{$goalId}")->assertStatus(403);
        $this->deleteJson("/api/salary-adjustments/{$adjustmentId}")->assertStatus(403);
        $this->deleteJson("/api/leave-records/{$leaveId}")->assertStatus(403);
        $this->deleteJson("/api/expense-categories/{$categoryId}")->assertStatus(403);

        // Bob cannot attach his expense to Alice's category.
        $this->postJson('/api/expenses', ['amount' => 10, 'expense_date' => '2026-09-22', 'expense_category_id' => $categoryId])->assertStatus(422);

        // Bob's lists and dashboard contain none of Alice's data.
        $this->getJson('/api/expenses?range=month')->assertOk()->assertJsonCount(0, 'data.expenses');
        $this->getJson('/api/attendance?range=month')->assertOk()->assertJsonCount(0, 'data.records');
        $this->getJson('/api/goals')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.period.summary.expenses', 0);

        // Nothing of Alice's was modified.
        $this->assertDatabaseHas('expenses', ['id' => $expenseId, 'amount' => 100.00, 'deleted_at' => null]);
        $this->assertDatabaseHas('savings_goals', ['id' => $goalId, 'deleted_at' => null]);
    }

    public function test_device_tokens_are_owned(): void
    {
        $this->actingAsTracker();
        $id = $this->postJson('/api/device-tokens', ['token' => 'ExponentPushToken[abc]', 'platform' => 'android'])
            ->assertOk()->json('data.id');

        Sanctum::actingAs($this->trackerUser());
        $this->deleteJson("/api/device-tokens/{$id}")->assertStatus(403);
    }
}
