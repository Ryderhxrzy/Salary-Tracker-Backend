<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Loans: money the user owes (SSS, Pag-IBIG, company or personal loans) and
     * money lent to others. Each payment lowers what is left; a payment that the
     * employer takes from the payslip lowers the take-home pay, any other payment
     * comes out of a wallet (or, for money lent, goes back into one).
     */
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // Who the money is owed to (SSS, Pag-IBIG, company, a person) or who borrowed it.
            $table->string('lender', 120)->nullable();
            // borrowed (I owe) | lent (someone owes me)
            $table->string('type', 20)->default('borrowed');
            $table->decimal('principal_amount', 12, 2);
            // Everything to be repaid including interest / fees (defaults to the principal).
            $table->decimal('total_amount', 12, 2);
            // Already paid before the app started tracking this loan.
            $table->decimal('opening_paid_amount', 12, 2)->default(0);
            $table->decimal('installment_amount', 12, 2)->nullable();
            // per_cutoff | monthly | weekly | none
            $table->string('frequency', 20)->default('per_cutoff');
            $table->date('start_date');
            $table->date('due_date')->nullable();
            $table->date('next_due_date')->nullable();
            // Wallet the principal went into (borrowed) or came out of (lent); null = not tracked.
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            // Payments are taken from the payslip by the employer (SSS / Pag-IBIG / company loans).
            $table->boolean('via_payroll')->default(false);
            $table->boolean('is_closed')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'type']);
        });

        Schema::create('loan_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('payment_date');
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->boolean('via_payroll')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_payments');
        Schema::dropIfExists('loans');
    }
};
