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
        Schema::create('team_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('wrapped_key');
            $table->string('algorithm');
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'version']);
            $table->index(['team_id', 'retired_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_keys');
    }
};
