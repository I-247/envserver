<?php

namespace App\Enums;

/**
 * What to do with a key the environment already has.
 */
enum ConflictStrategy: string
{
    /**
     * Write the incoming value as a new version of the existing variable.
     */
    case Overwrite = 'overwrite';

    /**
     * Leave the vault's value alone and only add the keys that are new.
     */
    case Keep = 'keep';
}
