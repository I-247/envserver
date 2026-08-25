<?php

namespace App\Enums;

enum ChangeType: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Changed = 'changed';
}
