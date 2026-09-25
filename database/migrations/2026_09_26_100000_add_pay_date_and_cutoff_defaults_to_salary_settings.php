<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_settings', function (Blueprint $table) {
            // Pay date = cut-off end + N days (11-25 => 30th, 26-10 => 15th).
            $table->unsignedTinyInteger('pay_delay_days')->default(5)->after('custom_period_days');
            // Overtime only counts when the time out is at least this long after the shift end (5 PM + 60 => 6 PM).
            $table->unsignedSmallInteger('overtime_threshold_minutes')->default(60)->after('overtime_hourly_rate');
            // Default to the usual semi-monthly cut-offs: 11th-25th and 26th-10th.
            $table->string('period_type', 20)->default('semi_monthly')->change();
            $table->unsignedTinyInteger('period_start_day')->default(11)->change();
            $table->unsignedTinyInteger('period_second_day')->default(26)->change();
        });

        // Semi-monthly settings still on the old untouched 1st/16th defaults move to 11th/26th.
        DB::table('salary_settings')
            ->where('period_type', 'semi_monthly')
            ->where('period_start_day', 1)
            ->where('period_second_day', 16)
            ->update(['period_start_day' => 11, 'period_second_day' => 26]);
    }

    public function down(): void
    {
        Schema::table('salary_settings', function (Blueprint $table) {
            $table->dropColumn(['pay_delay_days', 'overtime_threshold_minutes']);
            $table->string('period_type', 20)->default('monthly')->change();
            $table->unsignedTinyInteger('period_start_day')->default(1)->change();
            $table->unsignedTinyInteger('period_second_day')->default(16)->change();
        });
    }
};
