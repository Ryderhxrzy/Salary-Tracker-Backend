<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
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
}
