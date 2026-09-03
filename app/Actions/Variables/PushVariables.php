<?php

namespace App\Actions\Variables;

use App\Actions\Releases\PublishAutomaticReleases;
use App\Enums\ConflictStrategy;
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
 * does not quietly leave a shadowed duplicate behind. Pasting a .env into the
 * portal takes the same road, except that it may choose to leave those
 * existing keys alone and only add what is new.
 */
class PushVariables
{
    public function __construct(
        private readonly CreateVariable $create,
        private readonly UpdateVariableValue $update,
        private readonly AttachVariableToEnvironment $attach,
        private readonly ResolveEnvironmentVariables $resolve,
        private readonly PublishAutomaticReleases $publishAutomatically,
    ) {}

    /**
     * Push the given values into the environment.
     *
     * @param  array<string, string>  $variables
     * @return array{created: int, updated: int, unchanged: int, skipped: int, shared_impact: list<string>}
     */
    public function handle(
        Environment $environment,
        #[SensitiveParameter] array $variables,
        ?User $author = null,
        ConflictStrategy $onConflict = ConflictStrategy::Overwrite,
    ): array {
        return DB::transaction(function () use ($environment, $variables, $author, $onConflict) {
            $touched = collect();

            // One push is one change. Left to itself every key would trigger
            // its own automatic release, so a forty line .env would bury the
            // change under forty near identical releases.
            $counts = $this->publishAutomatically->batch(
                fn () => $this->apply($environment, $variables, $author, $onConflict, $touched),
                $author,
            );

            return [
                ...$counts,
                'shared_impact' => $this->sharedImpact($touched, $environment),
            ];
        });
    }

    /**
     * Write the values, recording which variables actually changed.
     *
     * @param  array<string, string>  $variables
     * @param  Collection<int, Variable>  $touched
     * @return array{created: int, updated: int, unchanged: int, skipped: int}
     */
    private function apply(
        Environment $environment,
        #[SensitiveParameter] array $variables,
        ?User $author,
        ConflictStrategy $onConflict,
        Collection $touched,
    ): array {
        $existing = $this->resolve->handle($environment)->keyBy(fn ($entry) => $entry->key);

        $created = $updated = $unchanged = $skipped = 0;

        foreach ($variables as $key => $value) {
            $entry = $existing->get($key);

            if ($entry === null) {
                $variable = $this->create->handle(
                    $environment->project->team,
                    $key,
                    $value,
                    $author,
                    ownerProject: $environment->project,
                );
                $this->attach->handle($variable, $environment);
                $created++;

                continue;
            }

            if ($onConflict === ConflictStrategy::Keep) {
                $skipped++;

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
            'skipped' => $skipped,
        ];
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
