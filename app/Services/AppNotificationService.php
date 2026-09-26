<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The in-app inbox. Anything worth telling the user (an invitation, a bill that
 * was paid, a reminder) is stored here; the phone fetches the undelivered rows
 * and shows each one as a local notification, and the Notifications screen lists them.
 */
class AppNotificationService
{
    public function notify(User $user, string $type, string $title, string $body, array $data = []): AppNotification
    {
        return $user->appNotifications()->create([
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data ?: null,
        ]);
    }

    /** Recent inbox rows, newest first. */
    public function recent(User $user, int $limit = 50): Collection
    {
        return $user->appNotifications()->orderByDesc('id')->limit(max(1, min(200, $limit)))->get();
    }

    public function unreadCount(User $user): int
    {
        return $user->appNotifications()->whereNull('read_at')->count();
    }

    /** Rows the phone has not shown yet; they are marked delivered as they are handed out. */
    public function takeUndelivered(User $user): Collection
    {
        $rows = $user->appNotifications()->whereNull('delivered_at')->orderBy('id')->limit(50)->get();
        if ($rows->isNotEmpty()) {
            AppNotification::whereIn('id', $rows->pluck('id'))->update(['delivered_at' => now()]);
        }

        return $rows;
    }

    /** @param  int[]|null  $ids  null = everything */
    public function markRead(User $user, ?array $ids = null): int
    {
        $query = $user->appNotifications()->whereNull('read_at');
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        return $query->update(['read_at' => now(), 'delivered_at' => now()]);
    }
}
