<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An optimistic-lock conflict: the caller's `layout_version` is stale because
 * another session saved this dashboard first.
 *
 * Stays a bare RuntimeException. It already reaches the client correctly and by
 * its own doing — Laravel's handler calls an exception's own render() before it
 * consults the render callbacks in bootstrap/app.php — so it is a 409 with
 * `meta.layout_version`, which is the field the SPA needs to reload and retry.
 * DashboardLayoutTest pins that end to end.
 *
 * Reparenting onto BusinessRuleException would therefore change nothing at all:
 * the method below would still win. That is the argument against it, not for it.
 * A concurrency conflict is not a violated business rule — the request was
 * legitimate and will succeed on the next attempt — and 409 vs 422 is the only
 * thing telling the SPA to reload rather than to show the user a correction.
 */
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
