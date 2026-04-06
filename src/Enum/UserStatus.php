<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Lifecycle / access state for a platform user (distinct from account-level blocks).
 */
enum UserStatus: string
{
    case ACTIVE  = 'ACTIVE';
    case BLOCKED = 'BLOCKED';
}
