<?php

declare(strict_types=1);

namespace App\Common\Exceptions;

use RuntimeException;

/**
 * A business rule the caller violated — "PO already closed", "amount exceeds
 * the remaining balance", "shot schedules are mold-only".
 *
 * Services across this codebase signal these with a bare RuntimeException, which
 * bootstrap/app.php does not map, so every one of them reaches the browser as a
 * 500. They are not server faults: the request was well-formed but the system
 * state forbids it. This subclass is rendered as a 422 in the same envelope
 * Laravel uses for validation failures, so the SPA's existing error handling
 * surfaces the message instead of a generic "Server Error" toast.
 *
 * Extends RuntimeException deliberately: the suite has several
 * `expectException(RuntimeException::class)` assertions against services, and
 * those keep passing as a service is migrated onto this class.
 *
 * Prefer ValidationException::withMessages() when the violation maps cleanly to
 * a specific input the user can correct — that keys the error to the form field.
 * Use this when the failure is about record state rather than one input.
 */
class BusinessRuleException extends RuntimeException
{
    /**
     * Stable machine-readable identifier for this violation, e.g. `missing_bom`.
     *
     * The SPA needs to react differently to different rules — a missing BOM
     * wants a link to /mrp/boms, an exceeded credit limit wants the customer
     * record — and without a code it had no choice but to guess from the prose.
     * `ChainErrorPanel` classified sales-order failures with
     * `error.message.toLowerCase().includes('bom')`, so rewording a sentence
     * silently removed the button that fixed the problem.
     *
     * Null keeps the envelope unchanged for the many rules that need no
     * client-side branch.
     */
    public function errorCode(): ?string
    {
        return null;
    }

    /**
     * Errors-bag key used when rendering. Defaults to a non-field key so the SPA
     * shows the message without highlighting an unrelated input.
     */
    public function errorKey(): string
    {
        return 'error';
    }
}
