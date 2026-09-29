<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Email verification was switched on after these accounts were made, so
     * none of them ever had the chance to verify. They are accepted as they
     * are; only accounts registered from here on have to prove their mailbox.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    /**
     * Reverse the migrations.
     *
     * Nothing to undo: which accounts were verified by this migration and
     * which by a real link is not recorded, so clearing the column would
     * unverify people who did verify.
     */
    public function down(): void
    {
        //
    }
};
