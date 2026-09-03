<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->json('ip_allowlist')->nullable()->after('is_personal');
        });

        Schema::table('environments', function (Blueprint $table) {
            $table->json('ip_allowlist')->nullable()->after('auto_publish');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('ip_allowlist');
        });

        Schema::table('environments', function (Blueprint $table) {
            $table->dropColumn('ip_allowlist');
        });
    }
};
