<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * OGAMI-001 — thrown when a posting/back-dating attempt targets a date that
 * falls inside an accounting period whose status is `closed`.
 *
 * Deliberately NOT a BusinessRuleException, and that is a decision about
 * behaviour rather than taste. Two controllers already depend on it in writing:
 *
 *   - GoodsReceiptNoteController's docblock ("None of those three extends
 *     BusinessRuleException, and every one of them tells the receiving clerk what
 *     to do");
 *   - WorkOrderController::recordOutput ("its `catch
 *     (ProductionReceiptHandoffException|BusinessRuleException|
 *     InvalidMovementException)` does NOT cover it, so a closed period hit while
 *     posting the FG movement's GL entry escapes record() and lands here").
 *
 * Reparenting would move this class inside the family that ~20 chain-listener and
 * GL-posting arms treat as "expected business failure, degrade to manual". A
 * closed period would then stop reaching the operator on those paths: the
 * physical fact would commit, the handoff would be marked manual_required, and
 * nobody would be told which period to reopen. That is a policy question about
 * whether a closed period blocks the physical operation or only defers its GL —
 * and the codebase currently answers it both ways in different comments (see
 * MovementGlPostingService and PostStockMovementToGlOnRequested, whose arms name
 * a closed posting period but cannot catch it). Not a typing decision.
 *
 * What it did need is a render arm, because the 21 controllers that name it are
 * not all of them. `AssetService::dispose` back-dates a disposal JE and
 * AssetController has no catch at all, so an accountant disposing an asset into
 * last month's closed period got a 500 and "Server Error" — for a condition whose
 * own message names the period to reopen. render() closes that without touching
 * the hierarchy: Laravel calls it only when nothing caught the exception, so all
 * 21 existing arms behave exactly as before.
 *
 * Following DashboardLayoutConflictException, which states its own envelope the
 * same way, rather than adding an Accounting import to bootstrap/app.php.
 */
class ClosedPeriodException extends RuntimeException
{
    public function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly string $date,
    ) {
        parent::__construct(sprintf(
            'Accounting period %04d-%02d is closed. Cannot post or back-date a transaction dated %s. '
            . 'Reopen the period first or post to the next open period.',
            $year,
            $month,
            $date,
        ));
    }

    /**
     * 422, matching the 21 controller arms that already answer this way, so the
     * status does not depend on which endpoint the operator happened to reach.
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! ($request->is('api/*') || $request->expectsJson())) {
            return null;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'errors'  => ['error' => [$this->getMessage()]],
        ], 422);
    }
}
