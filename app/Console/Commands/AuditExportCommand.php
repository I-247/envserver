<?php

namespace App\Console\Commands;

use App\Actions\Audit\ExportAuditTrail;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class AuditExportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'envserver:audit-export
        {--output= : The file to write; "-" writes to stdout (default: storage/app/private/audit-trail-<timestamp>.csv)}
        {--team= : Only export this team, by slug}
        {--from= : Only events on or after this date or moment}
        {--until= : Only events on or before this date or moment}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export the full audit trail as CSV';

    /**
     * Execute the console command.
     */
    public function handle(ExportAuditTrail $export): int
    {
        $team = null;

        if ($this->option('team') !== null) {
            $team = Team::query()->where('slug', $this->option('team'))->first();

            if ($team === null) {
                $this->components->error("No team with the slug \"{$this->option('team')}\".");

                return self::FAILURE;
            }
        }

        try {
            $from = $this->moment('from', endOfDay: false);
            $until = $this->moment('until', endOfDay: true);
        } catch (Throwable) {
            $this->components->error('--from and --until take a date like 2026-10-01 or a moment like "2026-10-01 14:30".');

            return self::FAILURE;
        }

        if ($from !== null && $until !== null && $from->isAfter($until)) {
            $this->components->error('--from is after --until, so nothing could match.');

            return self::FAILURE;
        }

        $output = $this->option('output');

        if ($output === '-') {
            $export->handle(STDOUT, $team, $from, $until);

            return self::SUCCESS;
        }

        $path = $output ?? storage_path('app/private/audit-trail-'.now()->format('Ymd-His').'.csv');

        if (file_exists($path)) {
            $this->components->error("{$path} already exists; pass another --output.");

            return self::FAILURE;
        }

        // Created private before anything is written: the trail names people,
        // their IP addresses and what they did, which is not for every user
        // on the machine to read.
        $previousUmask = umask(0077);
        $stream = @fopen($path, 'x');
        umask($previousUmask);

        if ($stream === false) {
            $this->components->error("Cannot write to {$path}.");

            return self::FAILURE;
        }

        try {
            $written = $export->handle($stream, $team, $from, $until);
        } finally {
            fclose($stream);
        }

        $this->components->info("{$written} audit event(s) written to {$path}.");

        return self::SUCCESS;
    }

    /**
     * Read a date option. A bare date means the whole day, so --until
     * 2026-10-01 still includes events from that afternoon.
     */
    private function moment(string $option, bool $endOfDay): ?Carbon
    {
        $value = $this->option($option);

        if (! is_string($value) || $value === '') {
            return null;
        }

        $moment = Carbon::parse($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $endOfDay ? $moment->endOfDay() : $moment->startOfDay();
        }

        return $moment;
    }
}
