<?php

declare(strict_types=1);

namespace App\Modules\MRP\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/**
 * A bill of materials cannot be exploded because its own structure is wrong —
 * a component that (transitively) contains its parent, or a nesting depth past
 * the configured ceiling, which is what a cycle the path check cannot see looks
 * like from the inside.
 *
 * This is authored data, not a deployment fault: somebody built A → B → A in
 * the BOM editor and the same editor is where they undo it. As a bare
 * RuntimeException it reached the browser as a 500 from any endpoint whose
 * controller did not happen to wrap the call — so the one message that names
 * the offending product and item was replaced by "Server Error".
 *
 * errorKey 'bom' matches MissingBomException's on purpose, so that any surface
 * keying off the errors bag treats the two BOM faults alike — `ChainErrorPanel`
 * offers "Manage BOMs" on `error.code === 'missing_bom' || error.errors?.bom`.
 * Be clear that this is preparation, not a live route today: on the one surface
 * that renders ChainErrorPanel, SalesOrderController::confirm catches
 * RuntimeException and returns `['message' => …]`, discarding both `errors` and
 * `code`; on the surface that does deliver the full envelope
 * (MrpPlanController::rerun, which has no catch) that component is not rendered.
 * The key is shared so no SPA change is needed once either half is fixed.
 *
 * The code stays distinct from 'missing_bom' because the two are not the same
 * problem — one BOM is absent, the other is wrong.
 */
class BomStructureException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'bom_structure';
    }

    public function errorKey(): string
    {
        return 'bom';
    }
}
