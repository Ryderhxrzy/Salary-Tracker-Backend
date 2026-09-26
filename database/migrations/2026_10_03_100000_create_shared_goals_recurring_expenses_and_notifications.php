<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shared goals: the owner invites people by email; once accepted they add money from their own accounts.
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->boolean('is_shared')->default(false)->after('is_completed');
        });

        Schema::create('savings_goal_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('savings_goal_id')->constrained()->cascadeOnDelete();
            // Filled in once the invited person accepts (they may not have an account yet when invited).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('role', 20)->default('member'); // owner | member
            $table->string('status', 20)->default('pending'); // pending | accepted | declined
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['savings_goal_id', 'email']);
            $table->index(['email', 'status']);
        });

        // Bills paid again and again: what, how much, when, and which account it is taken from.
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('description');
            $table->string('frequency', 20); // weekly | monthly | semi_monthly | yearly
            $table->unsignedTinyInteger('day_of_month')->nullable(); // monthly / yearly / semi_monthly (first day)
            $table->unsignedTinyInteger('second_day_of_month')->nullable(); // semi_monthly (second day)
            $table->unsignedTinyInteger('weekday')->nullable(); // weekly: 0 = Sunday … 6 = Saturday
            $table->unsignedTinyInteger('month_of_year')->nullable(); // yearly
            $table->date('start_date');
            $table->date('next_date');
            $table->date('end_date')->nullable();
            $table->date('last_paid_date')->nullable();
            $table->boolean('auto_pay')->default(true); // record the expense by itself on the due date
            $table->boolean('remind')->default(true);
            $table->unsignedTinyInteger('remind_days_before')->default(1);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_active', 'next_date']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('recurring_expense_id')->nullable()->after('wallet_id')->constrained('recurring_expenses')->nullOnDelete();
        });

        // In-app inbox; the phone shows each row once as a local notification when it fetches the pending ones.
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title');
            $table->text('body');
            $table->json('data')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'delivered_at']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurring_expense_id');
        });
        Schema::dropIfExists('recurring_expenses');
        Schema::dropIfExists('savings_goal_members');
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->dropColumn('is_shared');
        });
    }
};
