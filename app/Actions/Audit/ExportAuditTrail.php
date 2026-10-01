<?php

namespace App\Actions\Audit;

use App\Models\AuditEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ExportAuditTrail
{
    /**
     * The columns of the export, in order.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'id',
        'created_at',
        'team',
        'action',
        'label',
        'actor',
        'actor_id',
        'subject_type',
        'subject_id',
        'ip_address',
        'metadata',
    ];

    /**
     * Write the audit trail to a stream as CSV, oldest event first.
     *
     * Read in chunks by id so a trail of millions of rows never has to fit
     * in memory. Metadata goes out as one JSON column: by convention it holds
     * names, counts and slugs, never a secret value, so nothing is redacted.
     *
     * @param  resource  $stream
     * @return int The number of events written.
     */
    public function handle(
        $stream,
        ?Team $team = null,
        ?Carbon $from = null,
        ?Carbon $until = null,
    ): int {
        fputcsv($stream, self::COLUMNS, escape: '');

        $written = 0;

        AuditEvent::query()
            ->with('team:id,slug')
            ->when($team, fn (Builder $query, Team $team) => $query->where('team_id', $team->id))
            ->when($from, fn (Builder $query, Carbon $from) => $query->where('created_at', '>=', $from))
            ->when($until, fn (Builder $query, Carbon $until) => $query->where('created_at', '<=', $until))
            ->chunkById(1000, function ($events) use ($stream, &$written) {
                foreach ($events as $event) {
                    fputcsv($stream, $this->row($event), escape: '');
                    $written++;
                }
            });

        return $written;
    }

    /**
     * Turn one event into a CSV row.
     *
     * @return list<string>
     */
    private function row(AuditEvent $event): array
    {
        return array_map($this->neutralise(...), [
            (string) $event->id,
            $event->created_at?->toIso8601String() ?? '',
            $event->team->slug,
            $event->action->value,
            $event->action->label(),
            $event->actor_name ?? '',
            $event->actor_id === null ? '' : (string) $event->actor_id,
            $event->subject_type ?? '',
            $event->subject_id === null ? '' : (string) $event->subject_id,
            $event->ip_address ?? '',
            $event->metadata === null ? '' : (string) json_encode($event->metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Keep a spreadsheet from running a cell as a formula.
     *
     * Actor names, project names and token names are typed by people, and
     * Excel or Sheets executes a cell that starts with = + - @ or a control
     * character. A leading apostrophe makes it plain text again.
     */
    private function neutralise(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
