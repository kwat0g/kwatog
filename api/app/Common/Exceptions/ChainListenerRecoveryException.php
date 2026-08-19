<?php

declare(strict_types=1);

namespace App\Common\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A refusal from the chain-listener recovery console — "this run is not in a
 * replayable state", "a resolution note is required", "the underlying record is
 * gone".
 *
 * Stays a bare RuntimeException because it carries its own status. Different
 * throw sites in ChainListenerRecoveryService pass 409 or 422, and
 * ChainListenerRecoveryController::replay / ::resolve — the only two callers —
 * render `$exception->status`. A BusinessRuleException parent would flatten that
 * to a single 422 the moment anything rendered it by inheritance instead, and the
 * distinction is the point: 409 means "the run moved under you", 422 means "your
 * input is incomplete".
 *
 * There is also a self-reference problem. This class is thrown by the service
 * that operates ON failed chain listener runs, and BusinessRuleException is the
 * sentinel those listeners' own catch arms treat as "degrade to manual". A
 * recovery attempt being absorbed by the same arm it is trying to clear would be
 * exactly backwards.
 */
class ChainListenerRecoveryException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 409, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
