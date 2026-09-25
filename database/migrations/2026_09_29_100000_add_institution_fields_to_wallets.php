<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wallets become "accounts" with a bank / e-wallet identity: the app resolves
     * `institution_id` (bpi, bdo, gcash, maya…) to a built-in card design. Only the
     * account facts are stored here, never the design itself.
     */
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            // bank | ewallet | cash | other
            $table->string('category', 20)->default('other')->after('type');
            // Key of the built-in institution (bpi, bdo, gcash…) or "other" for a custom one.
            $table->string('institution_id', 40)->nullable()->after('category');
            // savings | checking | payroll | debit | credit | ewallet | virtual_card | other
            $table->string('account_type', 20)->nullable()->after('institution_id');
            $table->string('last4', 4)->nullable()->after('account_type');
            $table->string('holder_name', 100)->nullable()->after('last4');
            // Custom card color for "other" institutions (#RRGGBB).
            $table->string('color', 9)->nullable()->after('holder_name');
        });

        // Existing wallets keep working: derive the category / institution from their type.
        DB::table('wallets')->where('type', 'cash')->update(['category' => 'cash', 'institution_id' => 'cash']);
        DB::table('wallets')->where('type', 'gcash')->update(['category' => 'ewallet', 'institution_id' => 'gcash', 'account_type' => 'ewallet']);
        DB::table('wallets')->where('type', 'maya')->update(['category' => 'ewallet', 'institution_id' => 'maya', 'account_type' => 'ewallet']);
        DB::table('wallets')->whereIn('type', ['bank', 'card'])->update(['category' => 'bank', 'institution_id' => 'other', 'account_type' => 'savings']);
        DB::table('wallets')->where('type', 'other')->update(['category' => 'other', 'institution_id' => 'other']);
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn(['category', 'institution_id', 'account_type', 'last4', 'holder_name', 'color']);
        });
    }
};
