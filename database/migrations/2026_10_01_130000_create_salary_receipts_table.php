<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Receive salary": the user confirms that a cut-off's take-home pay arrived.
     * Only then is it credited to a wallet. One receipt per cut-off (period_from).
     */
    public function up(): void
    {
        Schema::create('salary_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_period_id')->nullable()->constrained('salary_periods')->nullOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->date('pay_date');
            $table->decimal('amount', 12, 2);
            $table->date('received_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'period_from']);
            $table->index(['wallet_id', 'received_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_receipts');
    }
};
