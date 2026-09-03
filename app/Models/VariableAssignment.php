<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Links a variable to an environment.
 *
 * One variable can be assigned to many environments across many projects;
 * changing its value once therefore reaches every environment that uses it.
 *
 * @property int $id
 * @property int $variable_id
 * @property int $environment_id
 * @property string|null $alias_key
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Variable $variable
 * @property-read Environment $environment
 */
#[Fillable(['variable_id', 'environment_id', 'alias_key', 'sort_order'])]
class VariableAssignment extends Model
{
    /**
     * Get the variable being assigned.
     *
     * @return BelongsTo<Variable, $this>
     */
    public function variable(): BelongsTo
    {
        return $this->belongsTo(Variable::class);
    }

    /**
     * Get the environment the variable is assigned to.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get the key this variable is exposed under in the environment.
     */
    public function effectiveKey(): string
    {
        return $this->alias_key ?? $this->variable->key;
    }
}
