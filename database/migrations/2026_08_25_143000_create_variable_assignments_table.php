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
        Schema::create('variable_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();

            // Lets one shared variable land under a different name per
            // project, e.g. a shared MAILGUN_SECRET exposed as MAIL_PASSWORD.
            $table->string('alias_key')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['variable_id', 'environment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('variable_assignments');
    }
};
