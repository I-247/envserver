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
        Schema::table('deploy_tokens', function (Blueprint $table) {
            // Narrows the environment's own list for this one token. Null is
            // "no restriction of its own", as with every other allow list.
            $table->json('ip_allowlist')->nullable()->after('scopes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deploy_tokens', function (Blueprint $table) {
            $table->dropColumn('ip_allowlist');
        });
    }
};
