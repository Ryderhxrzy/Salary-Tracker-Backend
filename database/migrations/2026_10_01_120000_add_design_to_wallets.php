<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The look of the account card, chosen by the user:
     * {"mode":"solid|gradient","colors":["#RRGGBB",...],"direction":"down-right","pattern":"rings"}.
     * Null means the built-in look of the institution.
     */
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->json('design')->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('design');
        });
    }
};
