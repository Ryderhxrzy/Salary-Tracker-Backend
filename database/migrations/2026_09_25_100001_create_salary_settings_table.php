<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // daily | hourly | weekly | biweekly | monthly  (null = not configured)
            $table->string('salary_type', 20)->nullable();
            $table->decimal('daily_rate', 12, 2)->nullable();
            $table->decimal('hourly_rate', 12, 2)->nullable();
            $table->decimal('weekly_rate', 12, 2)->nullable();
            $table->decimal('biweekly_rate', 12, 2)->nullable();
            $table->decimal('monthly_rate', 12, 2)->nullable();
            $table->decimal('expected_hours_per_day', 5, 2)->default(8);
            $table->boolean('overtime_enabled')->default(true);
            $table->decimal('overtime_multiplier', 5, 2)->default(1.25);
            $table->decimal('overtime_hourly_rate', 12, 2)->nullable();
            $table->boolean('prorate_undertime')->default(false);
            $table->boolean('deduct_absences')->default(false);
            // weekly | biweekly | monthly | custom
            $table->string('period_type', 20)->default('monthly');
            $table->unsignedTinyInteger('period_start_day')->default(1);      // monthly: day of month 1-28
            $table->unsignedTinyInteger('period_start_weekday')->default(1);  // weekly: 0=Sun..6=Sat
            $table->date('period_anchor_date')->nullable();                   // biweekly/custom anchor
            $table->unsignedSmallInteger('custom_period_days')->default(15);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_settings');
    }
};
