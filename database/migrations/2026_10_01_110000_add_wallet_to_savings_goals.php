<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Where a goal's money is kept (e.g. MariBank): deposits into the goal land in that wallet. */
    public function up(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->foreignId('wallet_id')->nullable()->after('user_id')->constrained('wallets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wallet_id');
        });
    }
};
