<?php

namespace App\Actions\Variables;

use App\Models\Environment;
use App\Models\User;
use App\Models\Variable;
use App\Models\VariableAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Applies a map of key/value pairs to an environment, the way the CLI pushes
 * a local .env file back to the portal.
 *
 * A key that already resolves in this environment updates the variable behind
 * it rather than creating a second one with the same name, so pushing twice
 * does not quietly leave a shadowed duplicate behind.
 */
class PushVariables
{
    public function __construct(
        private readonly CreateVariable $create,
        private readonly UpdateVariableValue $update,
        private readonly AttachVariableToEnvironment $attach,
        private readonly ResolveEnvironmentVariables $resolve,
    ) {}

    /**
     * Push the given values into the environment.
     *
     * @param  array<string, string>  $variables
     * @return array{created: int, updated: int, unchanged: int, shared_impact: list<string>}
     */
    public function handle(
        Environment $environment,
        #[SensitiveParameter] array $variables,
        ?User $author = null,
    ): array {
        return DB::transaction(function () use ($environment, $variables, $author) {
            $existing = $this->resolve->handle($environment)->keyBy(fn ($entry) => $entry->key);

            $created = $updated = $unchanged = 0;
            $touched = collect();

            foreach ($variables as $key => $value) {
                $entry = $existing->get($key);

                if ($entry === null) {
                    $variable = $this->create->handle($environment->project->team, $key, $value, $author);
                    $this->attach->handle($variable, $environment);
                    $created++;

                    continue;
                }

                $before = $entry->version->id;
                $version = $this->update->handle($entry->variable, $value, $author);

                if ($version->id === $before) {
                    $unchanged++;

                    continue;
                }

                $updated++;
                $touched->push($entry->variable);
            }

            return [
                'created' => $created,
                'updated' => $updated,
                'unchanged' => $unchanged,
                'shared_impact' => $this->sharedImpact($touched, $environment),
            ];
        });
    }

    /**
     * Name the other environments the changed variables also reach.
     *
     * @param  Collection<int, Variable>  $variables
     * @return list<string>
     */
    private function sharedImpact(Collection $variables, Environment $environment): array
    {
        return array_values($variables
            ->flatMap(fn (Variable $variable) => $variable->assignments()->with('environment.project')->get())
            ->reject(fn (VariableAssignment $assignment) => $assignment->environment_id === $environment->id)
            ->map(fn (VariableAssignment $assignment) => sprintf(
                '%s/%s',
                $assignment->environment->project->slug,
                $assignment->environment->slug,
            ))
            ->unique()
            ->sort()
            ->all());
    }
}
