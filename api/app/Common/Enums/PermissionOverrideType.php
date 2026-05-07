<?php

declare(strict_types=1);

namespace App\Common\Enums;

/**
 * Per-user permission override polarity.
 *
 *  - Grant adds a permission the user's role does not provide.
 *  - Revoke removes a permission the user's role does provide.
 */
enum PermissionOverrideType: string
{
    case Grant = 'grant';
    case Revoke = 'revoke';
}
