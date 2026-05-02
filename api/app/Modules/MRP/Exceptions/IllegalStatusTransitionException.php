<?php

declare(strict_types=1);

namespace App\Modules\MRP\Exceptions;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

class IllegalStatusTransitionException extends HttpResponseException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct(new JsonResponse([
            'message' => "Illegal status transition: {$from} → {$to}.",
        ], 409));
    }
}
