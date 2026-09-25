<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_settings', function (Blueprint $table) {
            // Fixed basic salary paid every pay period (e.g. ₱10,000 per semi-monthly cut-off).
            // Used when salary_type = "per_period"; the daily rate is derived from it.
            $table->decimal('basic_salary', 12, 2)->nullable()->after('salary_type');
        });
    }

    public function down(): void
    {
        Schema::table('salary_settings', function (Blueprint $table) {
            $table->dropColumn('basic_salary');
        });
    }
};
