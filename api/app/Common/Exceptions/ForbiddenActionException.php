<?php

declare(strict_types=1);

namespace App\Common\Exceptions;

use RuntimeException;

/**
 * The actor is not allowed to take this action on this record — segregation of
 * duties, or the wrong role for an approval step.
 *
 * Deliberately NOT a BusinessRuleException. The request was well-formed and the
 * record state permits the action; the *actor* does not. Two consequences follow
 * from that distinction, and both matter:
 *
 *  - it renders 403, not 422 — the status itself says "not yours to do" rather
 *    than "fix your input";
 *  - it sits outside the family the chain-listener arms treat as "expected
 *    business failure, degrade to manual" (`catch (…|BusinessRuleException)` in
 *    WorkOrderOutputService, DeliveryService, ConsolidatePurchaseOrders, …). A
 *    refusal must never be silently absorbed by a degrade arm.
 *
 * These refusals were stated as `abort(403, '…')`, which raises a Symfony
 * HttpException, and that has cost a defect in two consecutive rounds of this
 * work — both times by being invisible to a `catch` someone narrowed correctly:
 *
 *  1. 2b82cba8 narrowed a bulk-approve `catch (\Throwable)` to
 *     BusinessRuleException, verified with `grep 'throw new'`. `abort()` is not
 *     a throw, so the grep structurally could not see these guards: both fell to
 *     the residual arm, the segregation-of-duties sentence was replaced with
 *     "An unexpected error stopped this request", and a Log::error was written
 *     per row for a refusal the system had made on purpose (fixed in f54822f7).
 *  2. That fix then had to infer "is this sentence safe to show a user" from a
 *     4xx status code, because nothing typed them.
 *
 * Extending RuntimeException is load-bearing, not stylistic. Symfony's
 * HttpException extends RuntimeException too, so PurchaseRequestService::
 * bulkApprove — which catches RuntimeException to skip one bad row and keep the
 * batch going — still sees these refusals. A class rooted anywhere else (e.g.
 * Illuminate\Auth\Access\AuthorizationException extends Exception) would let one
 * wrong-role row abort the entire batch with a 403.
 *
 * Rendered by the arm in bootstrap/app.php, which also registers it as
 * dontReport: a deliberate refusal should not write an error log line, and
 * abort() did not, because Laravel's internalDontReport list contains
 * HttpException.
 */
class ForbiddenActionException extends RuntimeException
{
    /**
     * Stable machine-readable identifier for this refusal, mirroring
     * BusinessRuleException::errorCode().
     *
     * Null by default, and the render arm omits the key entirely when it is —
     * the SPA's 403 branch switches on `data.code` ('password_expired' redirects
     * to /change-password, 'feature_disabled' suppresses the toast), so a value
     * it has no case for must not appear by accident.
     */
    public function errorCode(): ?string
    {
        return null;
    }

    /**
     * Errors-bag key used when rendering. A non-field key, like
     * BusinessRuleException: an authorization refusal is never about one input.
     */
    public function errorKey(): string
    {
        return 'error';
    }
}
