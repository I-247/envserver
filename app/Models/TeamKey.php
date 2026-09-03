<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The wrapped data encryption key for a team.
 *
 * Only the wrapped form is ever stored. Unwrapping happens in
 * TeamKeyManager, which is the single place that touches the master key.
 *
 * @property int $id
 * @property int $team_id
 * @property int $version
 * @property string $wrapped_key
 * @property string $algorithm
 * @property Carbon|null $retired_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable(['version', 'wrapped_key', 'algorithm', 'retired_at'])]
#[Hidden(['wrapped_key'])]
class TeamKey extends Model
{
    /**
     * Get the team this key belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retired_at' => 'datetime',
        ];
    }
}
