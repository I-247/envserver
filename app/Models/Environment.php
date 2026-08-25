<?php

namespace App\Models;

use Database\Factories\EnvironmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $slug
 * @property bool $auto_publish
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Collection<int, VariableAssignment> $assignments
 */
#[Fillable(['name', 'slug', 'auto_publish', 'sort_order'])]
class Environment extends Model
{
    /** @use HasFactory<EnvironmentFactory> */
    use HasFactory;

    /**
     * The environments every new project starts with.
     *
     * Production deliberately opts out of auto publishing: a change to a
     * variable that is shared with production should surface as a pending
     * change you promote on purpose, not as a silent new release.
     *
     * @var list<array{name: string, slug: string, auto_publish: bool}>
     */
    public const DEFAULTS = [
        ['name' => 'Development', 'slug' => 'development', 'auto_publish' => true],
        ['name' => 'Staging', 'slug' => 'staging', 'auto_publish' => true],
        ['name' => 'Production', 'slug' => 'production', 'auto_publish' => false],
    ];

    /**
     * Get the project that owns the environment.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the variables assigned to this environment.
     *
     * @return HasMany<VariableAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(VariableAssignment::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auto_publish' => 'boolean',
        ];
    }
}
