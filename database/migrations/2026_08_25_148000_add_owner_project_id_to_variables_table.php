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
             * The project responsible for the variable. Sharing a variable
             * with a second project adds an assignment, never a second owner:
             * exactly one project answers for the value at any time.
             *
             * Nullable on purpose. A variable can outlive every project that
             * used it (releases still pin its versions), and nullOnDelete is
             * the safety net for a project deleted outside DeleteProject,
             * which would otherwise cascade a shared secret out of existence.
             */
            $table->foreignId('owner_project_id')
                ->nullable()
                ->after('team_id')
                ->constrained('projects')
                ->nullOnDelete();
        });

        $this->backfillOwners();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('variables', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_project_id');
        });
    }

    /**
     * Adopt every existing variable into the project that first used it.
     *
     * Before this migration ownership was implicit, and the closest thing to
     * an owner is the project holding the oldest assignment.
     */
    private function backfillOwners(): void
    {
        $owners = DB::table('variable_assignments')
            ->join('environments', 'environments.id', '=', 'variable_assignments.environment_id')
            ->orderBy('variable_assignments.id')
            ->get(['variable_assignments.variable_id', 'environments.project_id'])
            ->unique('variable_id');

        foreach ($owners as $owner) {
            DB::table('variables')
                ->where('id', $owner->variable_id)
                ->update(['owner_project_id' => $owner->project_id]);
        }
    }
};
