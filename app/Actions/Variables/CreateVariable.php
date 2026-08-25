<?php

namespace App\Actions\Variables;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Team;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class CreateVariable
{
    public function __construct(
        private readonly WriteVariableVersion $writeVersion,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * Create a variable for the team along with its first version.
     */
    public function handle(
        Team $team,
        string $key,
        #[SensitiveParameter] string $value,
        ?User $author = null,
        ?string $description = null,
    ): Variable {
        return DB::transaction(function () use ($team, $key, $value, $author, $description) {
            $variable = $team->variables()->create([
                'key' => $key,
                'description' => $description,
                'created_by' => $author?->id,
            ]);

            $this->writeVersion->handle($variable, $value, $author);

            $this->audit->handle($team, AuditAction::VariableCreated, $author, $variable, ['key' => $key]);

            return $variable;
        });
    }
}
