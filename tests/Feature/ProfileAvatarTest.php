<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTrackerUser;
use Tests\TestCase;

class ProfileAvatarTest extends TestCase
{
    use CreatesTrackerUser;
    use RefreshDatabase;

    public function test_profile_picture_can_be_uploaded_served_and_removed(): void
    {
        Storage::fake('local');
        $this->actingAsTracker();

        $this->getJson('/api/profile')->assertOk()->assertJsonPath('data.avatar_url', null);
        $this->postJson('/api/profile/avatar', [])->assertStatus(422);

        $url = $this->post('/api/profile/avatar', ['avatar' => $this->png('me.png')])
            ->assertOk()->json('data.avatar_url');
        $this->assertIsString($url);
        $this->assertStringContainsString('/api/avatars/', $url);
        $file = basename($url);
        Storage::disk('local')->assertExists("avatars/{$file}");

        // Served without a token by its random name; shows up on the dashboard and in GraphQL too.
        $this->get("/api/avatars/{$file}", ['Authorization' => ''])->assertOk();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.profile.avatar_url', $url);
        $this->postJson('/api/graphql', ['query' => '{ profile { avatar_url } }'])->assertOk()->assertJsonPath('data.profile.avatar_url', $url);

        // A new picture replaces the old file.
        $second = basename($this->post('/api/profile/avatar', ['avatar' => $this->png('new.png')])->assertOk()->json('data.avatar_url'));
        Storage::disk('local')->assertMissing("avatars/{$file}");
        Storage::disk('local')->assertExists("avatars/{$second}");

        $this->deleteJson('/api/profile/avatar')->assertOk()->assertJsonPath('data.avatar_url', null);
        Storage::disk('local')->assertMissing("avatars/{$second}");
        $this->get("/api/avatars/{$second}")->assertNotFound();
    }

    /** A real 1×1 PNG, so the upload passes the `image` rule without the GD extension. */
    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    }
}
