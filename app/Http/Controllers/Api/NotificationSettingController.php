<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationSettingRequest;
use App\Http\Resources\NotificationSettingResource;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationSettingController extends Controller
{
    public function __construct(protected NotificationService $notifications) {}

    public function show(Request $request): JsonResponse
    {
        return $this->ok(new NotificationSettingResource($this->notifications->settings($request->user())));
    }

    public function update(UpdateNotificationSettingRequest $request): JsonResponse
    {
        $settings = $this->notifications->settings($request->user());
        $settings->fill($request->validated())->save();

        return $this->ok(new NotificationSettingResource($settings->fresh()), 'Notification settings saved.');
    }

    public function plan(Request $request): JsonResponse
    {
        return $this->ok($this->notifications->plan($request->user()));
    }
}
