<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeviceTokenRequest;
use App\Http\Resources\DeviceTokenResource;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    public function store(StoreDeviceTokenRequest $request): JsonResponse
    {
        $user = $request->user();

        // A token belongs to exactly one account: re-registering moves it to the current user.
        DeviceToken::where('token', $request->string('token'))->where('user_id', '!=', $user->id)->delete();

        $device = $user->deviceTokens()->updateOrCreate(
            ['token' => $request->string('token')],
            ['platform' => $request->input('platform', 'android'), 'device_name' => $request->input('device_name'), 'last_seen_at' => now()]
        );

        return $this->ok(new DeviceTokenResource($device), 'Device registered.');
    }

    public function destroy(Request $request, DeviceToken $deviceToken): JsonResponse
    {
        $this->authorize('delete', $deviceToken);
        $deviceToken->delete();

        return $this->ok(null, 'Device removed.');
    }
}
