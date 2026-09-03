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
            // How many days a secret may go unchanged before the team wants
            // to hear about it. Null is the default and means "no policy":
            // an age is still a fact, it is just not yet a finding.
            $table->unsignedSmallInteger('default_rotate_after_days')->nullable()->after('two_factor_required');
        });

        Schema::table('variables', function (Blueprint $table) {
            // Overrides the team's interval for this one variable. A build
            // number and a database password age at very different speeds,
            // so a single team wide number would be either useless or noise.
            $table->unsignedSmallInteger('rotate_after_days')->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('default_rotate_after_days');
        });

        Schema::table('variables', function (Blueprint $table) {
            $table->dropColumn('rotate_after_days');
        });
    }
};
