<?php

declare(strict_types=1);

namespace App\Modules\HR\Exceptions;

/**
 * The employee already has the one system account allowed by the employee
 * account invariant. Replaying an onboarding event may safely treat this as
 * an idempotent no-op.
 */
final class AccountAlreadyProvisionedException extends \DomainException
{
}
