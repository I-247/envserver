<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MasterKeyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kluis:master-key
                            {--show : Print a new key without writing it anywhere}
                            {--force : Rotate: move the current key to KLUIS_PREVIOUS_MASTER_KEYS}
                            {--env-file= : The env file to write to, defaults to the application .env}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate the master key that wraps every team\'s data encryption key';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $key = 'base64:'.base64_encode(random_bytes(32));

        if ($this->option('show')) {
            $this->line($key);

            return self::SUCCESS;
        }

        $path = $this->option('env-file') ?: base_path('.env');

        if (! File::exists($path)) {
            $this->error("No env file found at [{$path}].");

            return self::FAILURE;
        }

        $contents = File::get($path);
        $current = $this->currentKey($contents);

        if ($current !== null && ! $this->option('force')) {
            $this->error('KLUIS_MASTER_KEY is already set. Pass --force to rotate it.');
            $this->line('Rotating keeps the old key in KLUIS_PREVIOUS_MASTER_KEYS so existing secrets stay readable.');

            return self::FAILURE;
        }

        if ($current !== null) {
            $contents = $this->retire($contents, $current);
        }

        File::put($path, $this->replace($contents, 'KLUIS_MASTER_KEY', $key));

        $this->info($current === null ? 'Master key generated.' : 'Master key rotated.');

        if ($current !== null) {
            $this->line('The previous key was kept so existing secrets stay readable. Re-wrap the team keys with "php artisan kluis:rewrap" before removing it.');
        }

        return self::SUCCESS;
    }

    /**
     * Read the currently configured master key from the env file contents.
     */
    private function currentKey(string $contents): ?string
    {
        preg_match('/^KLUIS_MASTER_KEY=(.*)$/m', $contents, $matches);

        $value = trim($matches[1] ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * Append the retired key to the previous keys list.
     */
    private function retire(string $contents, string $key): string
    {
        preg_match('/^KLUIS_PREVIOUS_MASTER_KEYS=(.*)$/m', $contents, $matches);

        $previous = array_filter(explode(',', trim($matches[1] ?? '')));

        return $this->replace($contents, 'KLUIS_PREVIOUS_MASTER_KEYS', implode(',', [$key, ...$previous]));
    }

    /**
     * Set a key in the env file contents, appending it when absent.
     */
    private function replace(string $contents, string $name, string $value): string
    {
        if (preg_match("/^{$name}=.*$/m", $contents)) {
            return preg_replace("/^{$name}=.*$/m", "{$name}={$value}", $contents);
        }

        return rtrim($contents, "\n")."\n{$name}={$value}\n";
    }
}
