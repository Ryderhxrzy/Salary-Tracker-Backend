<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Shared accounts (a joint GCash, the family cash box): invited by email, used by everyone who accepted. */
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->boolean('is_shared')->default(false)->after('is_default');
        });

        Schema::create('wallet_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('role', 20)->default('member');
            $table->string('status', 20)->default('pending'); // pending | accepted | declined
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['wallet_id', 'email']);
            $table->index(['email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_members');
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('is_shared');
        });
    }
};
