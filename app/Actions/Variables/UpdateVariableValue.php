<?php

namespace App\Actions\Variables;

use App\Models\User;
use App\Models\Variable;
use App\Models\VariableVersion;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class UpdateVariableValue
{
    public function __construct(private readonly WriteVariableVersion $writeVersion) {}

    /**
     * Append a new version, unless the value did not actually change.
     *
     * Saving the same value twice should not pollute the history, and should
     * not trigger a new release for every environment using the variable.
     */
    public function handle(
        Variable $variable,
        #[SensitiveParameter] string $value,
        ?User $author = null,
        ?string $note = null,
    ): VariableVersion {
        return DB::transaction(function () use ($variable, $value, $author, $note) {
            $current = $variable->currentVersion();

            if ($current && hash_equals($current->checksum, $this->writeVersion->checksum($variable->team, $value))) {
                return $current;
            }

            return $this->writeVersion->handle($variable, $value, $author, $note);
        });
    }
}
