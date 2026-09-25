<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('period_type', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('open'); // open | closed
            $table->timestamps();

            $table->unique(['user_id', 'start_date', 'end_date']);
            $table->index(['user_id', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_periods');
    }
};
