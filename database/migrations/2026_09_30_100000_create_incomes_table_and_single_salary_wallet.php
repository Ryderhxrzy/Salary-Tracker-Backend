<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Other income (side hustle, freelance, business, gifts…) that is not part of the
     * salary: added to "left to spend" and to the wallet it went into.
     * Also guarantees that only one wallet per user receives the salary.
     */
    public function up(): void
    {
        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            // side_hustle | freelance | business | gift | refund | other
            $table->string('type', 20)->default('side_hustle');
            $table->string('source', 120)->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('income_date');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'income_date']);
        });

        // One salary wallet per user: keep the first (by sort order) that had the flag.
        $rows = DB::table('wallets')->whereNull('deleted_at')->where('receives_salary', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'user_id']);
        $seen = [];
        foreach ($rows as $row) {
            if (isset($seen[$row->user_id])) {
                DB::table('wallets')->where('id', $row->id)->update(['receives_salary' => false]);
            }
            $seen[$row->user_id] = true;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('incomes');
    }
};
