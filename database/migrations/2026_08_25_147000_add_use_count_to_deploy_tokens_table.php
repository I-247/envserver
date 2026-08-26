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
            // last_used_at is overwritten on every pull, so it can say when a
            // project was last deployed but never how often. Counting is the
            // only way to keep that number, and it starts at zero: deploys
            // that happened before this column existed are not recoverable.
            $table->unsignedInteger('use_count')->default(0)->after('scopes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deploy_tokens', function (Blueprint $table) {
            $table->dropColumn('use_count');
        });
    }
};
