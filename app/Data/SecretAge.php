<?php

namespace App\Data;

use App\Models\Variable;
use Carbon\CarbonInterface;

/**
 * How long a variable has held the same value, against the policy for it.
 *
 * The vault can rotate its own master key, but until now it knew nothing
 * about the age of the secrets inside it: a three year old access key looked
 * exactly like one written this morning.
 */
final readonly class SecretAge
{
    public function __construct(
        public Variable $variable,
        public ?CarbonInterface $rotatedAt,
        public ?int $intervalDays,
    ) {}

    /**
     * Build the age of a variable from its newest version.
     */
    public static function for(Variable $variable, ?CarbonInterface $rotatedAt, ?int $teamDefault): self
    {
        return new self($variable, $rotatedAt, $variable->rotate_after_days ?? $teamDefault);
    }

    /**
     * Get the day the value was last written.
     */
    public function dueAt(): ?CarbonInterface
    {
        if ($this->intervalDays === null || $this->rotatedAt === null) {
            return null;
        }

        return $this->rotatedAt->copy()->addDays($this->intervalDays);
    }

    /**
     * Get how many days the value has been standing.
     */
    public function ageInDays(): ?int
    {
        return $this->rotatedAt === null
            ? null
            : (int) $this->rotatedAt->diffInDays(now(), absolute: true);
    }

    /**
     * Determine whether the value is past the interval set for it.
     *
     * A variable without a policy is never overdue: there is nothing to be
     * late for. A variable without a version has no value to rotate yet.
     */
    public function isOverdue(): bool
    {
        return $this->dueAt()?->isPast() ?? false;
    }

    /**
     * Get how many days late the rotation is, zero when it is not.
     */
    public function overdueByDays(): int
    {
        $due = $this->dueAt();

        return $due === null || $due->isFuture()
            ? 0
            : (int) $due->diffInDays(now(), absolute: true);
    }
}
