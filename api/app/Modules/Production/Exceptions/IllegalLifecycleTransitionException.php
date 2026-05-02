<?php

declare(strict_types=1);

namespace App\Modules\Production\Exceptions;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

class IllegalLifecycleTransitionException extends HttpResponseException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct(new JsonResponse([
            'message' => "Illegal work-order lifecycle transition: {$from} → {$to}.",
        ], 409));
    }
}
