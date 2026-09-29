<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Part of the deploy rather than a command someone has to remember: until
     * it has run, the old payloads can still be moved between rows. It needs
     * the master key, and fails loudly without one rather than leaving half
     * the database on each scheme without saying so.
     */
    public function up(): void
    {
        Artisan::call('envserver:upgrade-encryption');
    }

    /**
     * Reverse the migrations.
     *
     * Nothing to undo: v1 payloads are still read, but writing them again
     * would only reopen what this closed.
     */
    public function down(): void
    {
        //
    }
};
