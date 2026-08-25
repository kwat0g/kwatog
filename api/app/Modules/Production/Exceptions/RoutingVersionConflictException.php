<?php

declare(strict_types=1);

namespace App\Modules\Production\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * A routing version was allocated concurrently and the database rejected the
 * losing insert. The caller can safely retry; this is not invalid form data.
 */
final class RoutingVersionConflictException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            'This product routing changed in another session. Reload the routing list and retry.',
            0,
            $previous,
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors'  => ['routing' => [$this->getMessage()]],
            'code'    => 'ROUTING_VERSION_CONFLICT',
        ], 409);
    }
}
