<?php

declare(strict_types=1);

namespace App\Modules\MRP\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/**
 * A product on the requested work has no active bill of materials.
 *
 * Thrown on the sales-order confirmation path, where it is the single most
 * common reason a confirmation fails and the one the user can actually fix —
 * by authoring the BOM. It carries a code so the SPA can offer that route
 * directly instead of inferring intent from the sentence: `ChainErrorPanel`
 * classified this with `error.message.toLowerCase().includes('bom')`, so
 * rewording the message silently removed the "Manage BOMs" button.
 *
 * Previously a bare RuntimeException, which bootstrap/app.php does not map —
 * so a missing BOM reached the browser as a 500 with a generic message, and
 * with APP_DEBUG=false the user could not even see which product was at fault.
 */
class MissingBomException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'missing_bom';
    }

    public function errorKey(): string
    {
        return 'bom';
    }
}
