<?php

namespace App\Actions\Search;

use App\Enums\TeamPermission;
use App\Models\DeployToken;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\VariableAssignment;
use Illuminate\Contracts\Database\Eloquent\Builder;

class SearchTeam
{
    /**
     * How many results each group returns at most.
     */
    public const LIMIT = 6;

    /**
     * Find what in a team matches the query, grouped by kind.
     *
     * Only names, slugs and variable keys are searched. Values are encrypted
     * and never leave the vault for a search; deploy tokens only show up for
     * someone who may manage them, since the result links to that page.
     *
     * @return array{
     *     projects: list<array{title: string, subtitle: string, url: string}>,
     *     environments: list<array{title: string, subtitle: string, url: string}>,
     *     variables: list<array{title: string, subtitle: string, url: string}>,
     *     deployTokens: list<array{title: string, subtitle: string, url: string}>,
     * }
     */
    public function handle(Team $team, User $user, string $query): array
    {
        $pattern = $this->pattern($query);

        return [
            'projects' => $this->projects($team, $pattern),
            'environments' => $this->environments($team, $pattern),
            'variables' => $this->variables($team, $pattern),
            'deployTokens' => $user->hasTeamPermission($team, TeamPermission::ManageDeployToken)
                ? $this->deployTokens($team, $pattern)
                : [],
        ];
    }

    /**
     * @return list<array{title: string, subtitle: string, url: string}>
     */
    private function projects(Team $team, string $pattern): array
    {
        return array_values($team->projects()
            ->where(fn (Builder $query) => $this->matches($query, ['projects.name', 'projects.slug'], $pattern))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Project $project) => [
                'title' => $project->name,
                'subtitle' => $project->description ?? $project->slug,
                'url' => route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]),
            ])
            ->all());
    }

    /**
     * @return list<array{title: string, subtitle: string, url: string}>
     */
    private function environments(Team $team, string $pattern): array
    {
        return array_values(Environment::query()
            ->with('project')
            ->whereHas('project', fn (Builder $query) => $query->where('team_id', $team->id))
            ->where(fn (Builder $query) => $this->matches($query, ['environments.name', 'environments.slug'], $pattern))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Environment $environment) => [
                'title' => $environment->name,
                'subtitle' => $environment->project->name,
                'url' => $this->environmentUrl($team, $environment),
            ])
            ->all());
    }

    /**
     * One result per place a key is used, so you land in the environment
     * where it lives. An alias is searched too, since that is the name the
     * environment's .env actually shows.
     *
     * @return list<array{title: string, subtitle: string, url: string}>
     */
    private function variables(Team $team, string $pattern): array
    {
        return array_values(VariableAssignment::query()
            ->with(['variable', 'environment.project'])
            ->whereHas('environment.project', fn (Builder $query) => $query->where('team_id', $team->id))
            ->where(fn (Builder $query) => $query
                ->whereHas('variable', fn (Builder $query) => $this->matches($query, ['variables.key'], $pattern))
                ->orWhere(fn (Builder $query) => $this->matches($query, ['variable_assignments.alias_key'], $pattern)))
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (VariableAssignment $assignment) => [
                'title' => $assignment->effectiveKey(),
                'subtitle' => "{$assignment->environment->project->name} / {$assignment->environment->name}",
                'url' => $this->environmentUrl($team, $assignment->environment),
            ])
            ->all());
    }

    /**
     * @return list<array{title: string, subtitle: string, url: string}>
     */
    private function deployTokens(Team $team, string $pattern): array
    {
        return array_values(DeployToken::query()
            ->with('environment.project')
            ->whereNull('revoked_at')
            ->whereHas('environment.project', fn (Builder $query) => $query->where('team_id', $team->id))
            ->where(fn (Builder $query) => $this->matches($query, ['deploy_tokens.name'], $pattern))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (DeployToken $token) => [
                'title' => $token->name,
                'subtitle' => "{$token->environment->project->name} / {$token->environment->name}",
                'url' => route('environments.deploy-tokens.index', [
                    'current_team' => $team->slug,
                    'project' => $token->environment->project->slug,
                    'environment' => $token->environment->slug,
                ]),
            ])
            ->all());
    }

    private function environmentUrl(Team $team, Environment $environment): string
    {
        return route('environments.show', [
            'current_team' => $team->slug,
            'project' => $environment->project->slug,
            'environment' => $environment->slug,
        ]);
    }

    /**
     * Match any of the columns, case insensitively.
     *
     * Always grouped in its own parentheses: inside a whereHas() a bare
     * orWhere would be ORed with the relation's own join condition, and then
     * every row of the related table matches.
     *
     * Columns are literal strings from this class, never user input, since
     * they go into the SQL as is.
     *
     * @param  list<literal-string>  $columns
     */
    private function matches(Builder $query, array $columns, string $pattern): void
    {
        $query->where(function (Builder $query) use ($columns, $pattern) {
            foreach ($columns as $column) {
                $query->orWhereRaw("lower({$column}) like ? escape '!'", [$pattern]);
            }
        });
    }

    /**
     * Turn the query into a LIKE pattern that treats % and _ as text.
     *
     * The escape character is "!" rather than a backslash because a
     * backslash needs different quoting on SQLite, MySQL and Postgres.
     */
    private function pattern(string $query): string
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($query)));

        return "%{$escaped}%";
    }
}
