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
        Schema::create('variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Deliberately not unique on (team_id, key): two projects in one
            // team can each have their own DATABASE_URL. Uniqueness is
            // enforced per environment, where the .env is actually rendered.
            $table->index(['team_id', 'key']);
        });

        Schema::create('variable_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variable_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('ciphertext');
            $table->string('checksum', 64);
            $table->unsignedInteger('team_key_version');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['variable_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('variable_versions');
        Schema::dropIfExists('variables');
    }
};
