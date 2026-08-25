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
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // Nullable, and kept when the user is deleted: an audit trail that
            // forgets who did something the moment they leave is not a trail.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();

            $table->string('action');
            $table->nullableMorphs('subject');

            // Names, versions and counts only. Never a value: an audit log is
            // read by more people than the secrets it describes.
            $table->json('metadata')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['team_id', 'created_at']);
            $table->index(['team_id', 'action']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
