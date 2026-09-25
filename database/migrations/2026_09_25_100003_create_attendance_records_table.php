<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->dateTime('scheduled_start')->nullable();
            $table->dateTime('scheduled_end')->nullable();
            $table->dateTime('time_in')->nullable();
            $table->dateTime('time_out')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('regular_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            // present | late | absent | rest_day | leave | incomplete
            $table->string('status', 20)->default('incomplete');
            // app | notification | manual | sync
            $table->string('source', 20)->default('app');
            $table->decimal('regular_amount', 12, 2)->nullable();
            $table->decimal('overtime_amount', 12, 2)->nullable();
            $table->decimal('salary_amount', 12, 2)->nullable();
            $table->string('time_in_key', 64)->nullable()->unique();
            $table->string('time_out_key', 64)->nullable()->unique();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'work_date']);
            $table->index(['user_id', 'time_in', 'time_out']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
