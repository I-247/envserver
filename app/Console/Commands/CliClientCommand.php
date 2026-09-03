<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Laravel\Passport\ClientRepository;

class CliClientCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'envserver:cli-client
                            {--name=Envserver CLI : The name shown on the approval screen}
                            {--env-file= : The env file to write to, defaults to the application .env}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create the OAuth device flow client the Envserver CLI logs in with';

    /**
     * Execute the console command.
     */
    public function handle(ClientRepository $clients): int
    {
        // Public rather than confidential: the CLI is installed on other
        // people's machines, so it cannot keep a client secret.
        $client = $clients->createDeviceAuthorizationGrantClient(
            (string) $this->option('name'),
            confidential: false,
        );

        $path = $this->option('env-file') ?: base_path('.env');

        if (File::exists($path)) {
            $contents = File::get($path);

            File::put($path, preg_match('/^ENVSERVER_CLI_CLIENT_ID=.*$/m', $contents)
                ? preg_replace('/^ENVSERVER_CLI_CLIENT_ID=.*$/m', "ENVSERVER_CLI_CLIENT_ID={$client->getKey()}", $contents)
                : rtrim($contents, "\n")."\nENVSERVER_CLI_CLIENT_ID={$client->getKey()}\n");
        }

        $this->components->twoColumnDetail('Client ID', (string) $client->getKey());
        $this->info('The CLI can now discover this client at /api/v1/cli.');

        return self::SUCCESS;
    }
}
