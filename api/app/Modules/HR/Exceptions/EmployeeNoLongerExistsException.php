<?php

declare(strict_types=1);

namespace App\Modules\HR\Exceptions;

/**
 * The source employee was removed before a queued onboarding event ran.
 * There is no remaining employee account handoff to perform.
 */
final class EmployeeNoLongerExistsException extends \DomainException
{
}
