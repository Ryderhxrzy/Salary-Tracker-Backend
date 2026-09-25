<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('full_name')->nullable();
            $table->string('nickname', 60)->nullable();
            $table->string('position')->nullable();
            $table->string('company')->nullable();
            $table->string('employment_type', 30)->nullable();
            $table->string('timezone', 64)->default('Asia/Manila');
            $table->string('currency', 3)->default('PHP');
            $table->unsignedSmallInteger('late_grace_minutes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
    }
};
