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
        Schema::create('deploy_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();

            // An OAuth client credentials token says "I am client X with
            // scope env:read" and nothing about which environment that means.
            // Binding the client to an environment is our concern, not the
            // OAuth server's, so it lives here rather than inside a scope
            // string that would have to be parsed.
            $table->uuid('oauth_client_id');

            $table->string('name');

            // Passport validates a requested scope against the scopes the
            // application knows, not against the client asking for it. Without
            // an allow list here, a read only deploy client could simply ask
            // for env:write at token exchange.
            $table->json('scopes');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique('oauth_client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deploy_tokens');
    }
};
