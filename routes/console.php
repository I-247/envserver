<?php

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

/*
 * Revoked and expired OAuth tokens serve no purpose once they are dead, and a
 * table of them is one more place a leak could start.
 */
Schedule::command('passport:purge')
    ->daily()
    ->description('Purge revoked and expired OAuth tokens');
