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
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind');
            $table->text('url');
            // Signs the body so the receiver can tell a delivery from Envserver
            // apart from anyone who learned the URL. Encrypted at rest with
            // APP_KEY rather than the team's data key: losing it costs a
            // reconfigured webhook, not a secret, so it does not belong
            // inside the envelope that protects the vault itself.
            $table->text('signing_secret');
            // Empty means every action. Stored as the audit action values, so
            // a filter reads the same as the trail it follows.
            $table->json('events')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // What happened last time, so "why is my webhook quiet" is a
            // question the page can answer instead of the server's logs.
            $table->timestamp('last_attempted_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();

            $table->index(['team_id', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
