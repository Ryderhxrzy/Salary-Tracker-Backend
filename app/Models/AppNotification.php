<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of the in-app inbox; shown once on the phone as a local notification. */
#[Fillable(['type', 'title', 'body', 'data', 'delivered_at', 'read_at'])]
class AppNotification extends Model
{
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
