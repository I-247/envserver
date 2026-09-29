<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Codes were stored in plain text. Hashing them in place keeps every mail
     * already sent working: the link still carries the plain code, and the
     * lookup now hashes what arrives. Not reversible, which is the point.
     */
    public function up(): void
    {
        DB::table('team_invitations')->orderBy('id')->each(function (object $invitation) {
            DB::table('team_invitations')
                ->where('id', $invitation->id)
                ->update(['code' => hash('sha256', $invitation->code)]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
