<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'icon', 'color', 'is_default', 'sort_order'])]
class ExpenseCategory extends Model
{
    public const DEFAULTS = [
        ['name' => 'Food', 'icon' => 'restaurant', 'color' => '#F59E0B'],
        ['name' => 'Transportation', 'icon' => 'directions_bus', 'color' => '#3B82F6'],
        ['name' => 'Bills', 'icon' => 'receipt_long', 'color' => '#EF4444'],
        ['name' => 'Shopping', 'icon' => 'shopping_bag', 'color' => '#EC4899'],
        ['name' => 'Family', 'icon' => 'family_restroom', 'color' => '#8B5CF6'],
        ['name' => 'Work', 'icon' => 'work', 'color' => '#0EA5E9'],
        ['name' => 'Entertainment', 'icon' => 'movie', 'color' => '#F97316'],
        ['name' => 'Health', 'icon' => 'medical_services', 'color' => '#10B981'],
        ['name' => 'Education', 'icon' => 'school', 'color' => '#6366F1'],
        ['name' => 'Other', 'icon' => 'category', 'color' => '#6B7280'],
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
