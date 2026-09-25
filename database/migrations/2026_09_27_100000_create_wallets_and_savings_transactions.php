<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the money is (cash, GCash, bank...) and where savings go.
     *
     *   wallet balance = opening balance
     *                  + salary received (paid cut-offs, into the wallet that receives the salary)
     *                  − expenses paid from it
     *                  − savings deposited from it
     *                  + savings withdrawn into it
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            // cash | gcash | maya | card | bank | other
            $table->string('type', 20)->default('cash');
            $table->decimal('opening_balance', 12, 2)->default(0);
            // Salary earned from this date on is credited to the wallet that receives it.
            $table->date('balance_as_of');
            $table->boolean('receives_salary')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'sort_order']);
        });

        Schema::create('savings_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('savings_goal_id')->nullable()->constrained('savings_goals')->nullOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            // deposit (money set aside) | withdrawal (money taken back)
            $table->string('type', 20)->default('deposit');
            $table->decimal('amount', 12, 2);
            $table->date('transaction_date');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'transaction_date']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('wallet_id')->nullable()->after('payment_method')->constrained('wallets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wallet_id');
        });
        Schema::dropIfExists('savings_transactions');
        Schema::dropIfExists('wallets');
    }
};
