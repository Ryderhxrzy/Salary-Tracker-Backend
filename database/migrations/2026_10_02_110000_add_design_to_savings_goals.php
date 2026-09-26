<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The look of a goal card, chosen by the user, same shape as `wallets.design`:
     * {"mode":"solid|gradient","colors":["#RRGGBB",...],"direction":"down-right","pattern":"rings"},
     * plus the icon drawn on the card (a MaterialCommunityIcons name). Null = the app's default look.
     */
    public function up(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->string('icon', 60)->nullable()->after('notes');
            $table->json('design')->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->dropColumn(['icon', 'design']);
        });
    }
};
