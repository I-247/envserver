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
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('message')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['environment_id', 'version']);
        });

        Schema::create('release_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variable_id')->constrained()->cascadeOnDelete();

            // Pinning the exact version is what makes a deploy reproducible:
            // pulling release 42 tomorrow yields the same file as today, even
            // if the variable has moved on since.
            $table->foreignId('variable_version_id')->constrained('variable_versions')->cascadeOnDelete();

            // The name the variable was exposed under at publish time, which
            // may have been an alias that has since changed.
            $table->string('key');
            $table->timestamps();

            $table->unique(['release_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('release_items');
        Schema::dropIfExists('releases');
    }
};
