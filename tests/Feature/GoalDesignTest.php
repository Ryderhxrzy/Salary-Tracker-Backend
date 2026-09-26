<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTrackerUser;
use Tests\TestCase;

class GoalDesignTest extends TestCase
{
    use CreatesTrackerUser;
    use RefreshDatabase;

    public function test_goal_icon_and_card_design_are_saved_and_validated(): void
    {
        $this->actingAsTracker();
        $design = ['mode' => 'gradient', 'colors' => ['#0B3D91', '#38BDF8'], 'direction' => 'right', 'pattern' => 'stars'];

        $goal = $this->postJson('/api/goals', ['name' => 'Laptop', 'target_amount' => 50000, 'icon' => 'laptop', 'design' => $design])
            ->assertCreated()->assertJsonPath('data.icon', 'laptop')->assertJsonPath('data.design.pattern', 'stars')->json('data');

        $this->putJson("/api/goals/{$goal['id']}", ['design' => ['mode' => 'solid', 'colors' => ['#123456'], 'direction' => 'down', 'pattern' => 'nope']])->assertStatus(422);
        $this->putJson("/api/goals/{$goal['id']}", ['icon' => 'Not Valid!'])->assertStatus(422);
        $this->putJson("/api/goals/{$goal['id']}", ['design' => null, 'icon' => null])->assertOk()->assertJsonPath('data.design', null)->assertJsonPath('data.icon', null);

        $this->putJson("/api/goals/{$goal['id']}", ['design' => $design])->assertOk();
        $this->postJson('/api/graphql', ['query' => '{ goals { icon design } }'])->assertOk()->assertJsonPath('data.goals.0.design.colors.1', '#38BDF8');
    }
}
