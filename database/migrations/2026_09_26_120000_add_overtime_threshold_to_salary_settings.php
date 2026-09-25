<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Databases that ran an early version of 2026_09_26_100000 are missing this column;
     * fresh databases already have it from that migration.
     */
    public function up(): void
    {
        if (Schema::hasColumn('salary_settings', 'overtime_threshold_minutes')) {
            return;
        }

        Schema::table('salary_settings', function (Blueprint $table) {
            // Overtime only counts when the time out is at least this long after the shift end (5 PM + 60 => 6 PM).
            $table->unsignedSmallInteger('overtime_threshold_minutes')->default(60)->after('overtime_hourly_rate');
        });
    }

    public function down(): void
    {
        // Owned by 2026_09_26_100000 on fresh databases; nothing to undo here.
    }
};
