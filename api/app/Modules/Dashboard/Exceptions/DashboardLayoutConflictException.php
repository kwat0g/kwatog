<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class DashboardLayoutConflictException extends RuntimeException
{
    public function __construct(public readonly string $currentVersion)
    {
        parent::__construct('This dashboard layout changed in another session. Reload it before saving again.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['layout' => [$this->getMessage()]],
            'meta' => ['layout_version' => $this->currentVersion],
        ], 409);
    }
}
