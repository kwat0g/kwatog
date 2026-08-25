<?php

declare(strict_types=1);

namespace App\Modules\MRP\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/**
 * A BOM contains a component that cannot participate in planning safely.
 */
final class BomComponentIntegrityException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'bom_component_integrity';
    }

    public function errorKey(): string
    {
        return 'bom';
    }
}
