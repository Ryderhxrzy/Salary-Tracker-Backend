<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('work_notification')->default(true);
            $table->unsignedSmallInteger('work_notification_lead_minutes')->default(60);
            $table->boolean('before_work_reminder')->default(false);
            $table->unsignedSmallInteger('before_work_minutes')->default(15);
            $table->boolean('still_on_duty_reminder')->default(true);
            $table->unsignedSmallInteger('still_on_duty_minutes')->default(30);
            $table->boolean('salary_period_reminder')->default(true);
            $table->boolean('expense_reminder')->default(false);
            $table->time('expense_reminder_time')->default('20:00:00');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
