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
 * errorKey 'bom' is load-bearing, not cosmetic: `ChainErrorPanel` offers the
 * "Manage BOMs" button on `error.code === 'missing_bom' || error.errors?.bom`,
 * so sharing the key routes a circular BOM to the same editor without the SPA
 * having to learn a second code. The code stays distinct because the two are
 * not the same problem — one BOM is absent, the other is wrong.
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
