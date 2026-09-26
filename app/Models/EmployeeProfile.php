<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'full_name', 'nickname', 'position', 'company', 'employment_type',
    'timezone', 'currency', 'late_grace_minutes', 'avatar_path',
])]
class EmployeeProfile extends Model
{
    public const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'contractual', 'probationary', 'freelance', 'self_employed', 'other'];

    protected function casts(): array
    {
        return [
            'late_grace_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Absolute URL of the profile picture, built from the current request host so it works from the phone. */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? url('api/avatars/'.basename($this->avatar_path)) : null;
    }
}
