<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A deduction (SSS, Pag-IBIG, PhilHealth, company loan…) or an allowance can
     * repeat every payday instead of being a one-time entry: it is then applied to
     * every pay period from its date on, until an optional end date.
     */
    public function up(): void
    {
        Schema::table('salary_adjustments', function (Blueprint $table) {
            $table->boolean('recurring')->default(false)->after('adjustment_date');
            $table->date('recurring_until')->nullable()->after('recurring');
        });
    }

    public function down(): void
    {
        Schema::table('salary_adjustments', function (Blueprint $table) {
            $table->dropColumn(['recurring', 'recurring_until']);
        });
    }
};
