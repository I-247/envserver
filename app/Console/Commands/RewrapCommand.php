<?php

namespace App\Console\Commands;

use App\Cryptography\TeamKeyManager;
use App\Exceptions\DecryptionFailed;
use App\Models\Team;
use Illuminate\Console\Command;

class RewrapCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kluis:rewrap';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-wrap every team data key with the current master key, so the old one can be retired';

    /**
     * Execute the console command.
     */
    public function handle(TeamKeyManager $keys): int
    {
        $rewrapped = 0;
        $failed = [];

        Team::query()->each(function (Team $team) use ($keys, &$rewrapped, &$failed) {
            try {
                $keys->rewrap($team);
                $rewrapped++;
            } catch (DecryptionFailed) {
                // Keep going: one unreadable team should not stop the others
                // from being moved onto the new key.
                $failed[] = $team->slug;
            }
        });

        $this->components->info("{$rewrapped} team key(s) re-wrapped.");

        if ($failed !== []) {
            $this->components->error(count($failed).' could not be re-wrapped: '.implode(', ', $failed));
            $this->line('Keep the old key in KLUIS_PREVIOUS_MASTER_KEYS until this is resolved.');

            return self::FAILURE;
        }

        if ($rewrapped > 0) {
            $this->line('You can now remove the old key from KLUIS_PREVIOUS_MASTER_KEYS.');
        }

        return self::SUCCESS;
    }
}
