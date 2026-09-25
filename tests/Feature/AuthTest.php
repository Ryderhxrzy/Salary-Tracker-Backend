<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_account_with_defaults_and_returns_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@example.com',
            'password' => 'secret123',
            'device_name' => 'pixel',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'juan@example.com')
            ->assertJsonStructure(['data' => ['token', 'user']]);

        $user = User::where('email', 'juan@example.com')->firstOrFail();
        $this->assertNotNull($user->profile);
        $this->assertSame('Asia/Manila', $user->profile->timezone);
        $this->assertCount(7, $user->workSchedules);
        $this->assertFalse($user->workSchedules->firstWhere('day_of_week', 0)->is_working_day, 'Sunday is a rest day by default');
        $this->assertTrue($user->workSchedules->firstWhere('day_of_week', 6)->is_working_day, 'Saturday is a working day by default');
        $this->assertCount(10, $user->expenseCategories);
        $this->assertFalse($user->salarySetting->isConfigured(), 'Salary must not be assumed');
    }

    public function test_login_returns_token_and_rejects_bad_password(): void
    {
        $user = User::factory()->create(['password' => 'secret123']);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret123']);
        $response->assertOk()->assertJsonPath('success', true);

        $token = $response->json('data.token');
        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->postJson('/api/attendance/time-in')->assertStatus(401);
    }
}
