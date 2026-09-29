<?php

namespace App\Console\Commands;

use App\Actions\Variables\UpgradeLegacyCiphertexts;
use App\Cryptography\TeamKeyManager;
use App\Models\Team;
use Illuminate\Console\Command;

class UpgradeEncryptionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'envserver:upgrade-encryption';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Move every stored key and value onto the current encryption scheme';

    /**
     * Execute the console command.
     *
     * Keys first: a value is re-encrypted under the team's data key, so that
     * key has to be readable in the new scheme before anything is written
     * with it. Safe to run again; rows already on the current scheme are
     * left alone apart from a fresh wrapping of the team keys.
     */
    public function handle(TeamKeyManager $keys, UpgradeLegacyCiphertexts $upgrade): int
    {
        $teams = 0;

        Team::query()->whereHas('keys')->each(function (Team $team) use ($keys, &$teams) {
            $keys->rewrap($team);
            $teams++;
        });

        $values = $upgrade->handle();

        $this->components->info("{$teams} team key(s) re-wrapped, {$values} value(s) re-encrypted.");

        return self::SUCCESS;
    }
}
