<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppNotificationResource;
use App\Services\AppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The in-app inbox (invitations, bills paid, reminders). */
class AppNotificationController extends Controller
{
    public function __construct(protected AppNotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->ok([
            'unread' => $this->notifications->unreadCount($user),
            'notifications' => AppNotificationResource::collection($this->notifications->recent($user, (int) $request->integer('limit', 50))),
        ]);
    }

    /** Rows not shown on the phone yet; handing them out marks them delivered. */
    public function pending(Request $request): JsonResponse
    {
        return $this->ok(AppNotificationResource::collection($this->notifications->takeUndelivered($request->user())));
    }

    /** Mark some (`ids`) or all notifications as read. */
    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['integer']]);
        $count = $this->notifications->markRead($request->user(), $data['ids'] ?? null);

        return $this->ok(['marked' => $count, 'unread' => $this->notifications->unreadCount($request->user())]);
    }
}
