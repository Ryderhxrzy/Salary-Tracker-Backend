<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use App\Models\EmployeeProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProfileController extends Controller
{
    /** Where profile pictures live on the private disk. */
    private const AVATAR_DIR = 'avatars';

    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->profile()->firstOrCreate([], ['full_name' => $request->user()->name]);

        return $this->ok(new ProfileResource($profile));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $profile = $request->user()->profile()->firstOrCreate([], ['full_name' => $request->user()->name]);
        $profile->fill($request->validated())->save();

        if ($request->filled('full_name')) {
            $request->user()->forceFill(['name' => $request->string('full_name')])->save();
        }

        return $this->ok(new ProfileResource($profile->fresh()), 'Profile updated.');
    }

    /** Upload a profile picture (multipart field `avatar`, JPEG / PNG / WebP up to 5 MB). Replaces the previous one. */
    public function storeAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $profile = $request->user()->profile()->firstOrCreate([], ['full_name' => $request->user()->name]);
        $this->deleteAvatarFile($profile);

        $extension = strtolower($request->file('avatar')->extension() ?: 'jpg');
        $name = Str::random(40).'.'.($extension === 'jpeg' ? 'jpg' : $extension);
        $path = $request->file('avatar')->storeAs(self::AVATAR_DIR, $name, 'local');

        $profile->forceFill(['avatar_path' => $path])->save();
        $request->user()->setRelation('profile', $profile);

        return $this->ok(new ProfileResource($profile), 'Profile picture updated.');
    }

    public function destroyAvatar(Request $request): JsonResponse
    {
        $profile = $request->user()->profile()->firstOrCreate([], ['full_name' => $request->user()->name]);
        $this->deleteAvatarFile($profile);
        $profile->forceFill(['avatar_path' => null])->save();
        $request->user()->setRelation('profile', $profile);

        return $this->ok(new ProfileResource($profile), 'Profile picture removed.');
    }

    /** Serve a picture by its random file name (public route; the name is unguessable). */
    public function avatar(string $file): BinaryFileResponse
    {
        $relative = self::AVATAR_DIR.'/'.basename($file);
        abort_unless(Storage::disk('local')->exists($relative), 404);

        return response()->file(Storage::disk('local')->path($relative), [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private function deleteAvatarFile(EmployeeProfile $profile): void
    {
        if ($profile->avatar_path && Storage::disk('local')->exists($profile->avatar_path)) {
            Storage::disk('local')->delete($profile->avatar_path);
        }
    }
}
