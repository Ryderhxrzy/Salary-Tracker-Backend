<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            // Profile picture, stored on the private disk under avatars/ and served by GET /api/avatars/{file}.
            $table->string('avatar_path')->nullable()->after('employment_type');
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
