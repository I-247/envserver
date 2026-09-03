<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('variables', function (Blueprint $table) {
            /**
             * Whether the owning project offers this variable to the rest of
             * the team. Off by default: a secret becomes reusable because
             * somebody decided it should be, never because nobody objected.
             *
             * Stored rather than derived, unlike isShared(). This is a
             * decision by the owner, and a decision has to survive the last
             * project unsharing the variable.
             */
            $table->boolean('shareable')->default(false)->after('owner_project_id');
        });

        $this->markAlreadySharedVariables();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('variables', function (Blueprint $table) {
            $table->dropColumn('shareable');
        });
    }

    /**
     * Keep variables that already span two projects shareable.
     *
     * Defaulting these to false would leave existing shares in a state the
     * portal cannot explain: borrowed by a project, yet not offered.
     */
    private function markAlreadySharedVariables(): void
    {
        $shared = DB::table('variable_assignments')
            ->join('environments', 'environments.id', '=', 'variable_assignments.environment_id')
            ->groupBy('variable_assignments.variable_id')
            ->havingRaw('COUNT(DISTINCT environments.project_id) > 1')
            ->pluck('variable_assignments.variable_id');

        if ($shared->isNotEmpty()) {
            DB::table('variables')->whereIn('id', $shared)->update(['shareable' => true]);
        }
    }
};
