<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Exceptions;

use RuntimeException;

/**
 * A delivery is confirmed, but its draft AR invoice needs data or
 * configuration that Finance must correct before the handoff can complete.
 *
 * Stays a bare RuntimeException because it never reaches a renderer. It is
 * thrown only inside DeliveryService::createDraftInvoice and
 * ::retryInvoiceHandoff, and both callers are closed:
 * `confirm()` wraps createDraftInvoice in `catch (\Throwable)` so a failed
 * invoice can never fail a delivery, and retryInvoiceHandoff is called only from
 * the queued CreateDraftInvoiceOnDeliveryInvoiceRequested listener, whose
 * `catch (DeliveryInvoiceHandoffException|BusinessRuleException)` records
 * manual_required. No controller can see it, so there is no 500 to fix and no
 * status a render arm could improve.
 *
 * Reparenting would therefore change nothing observable today — which is the
 * argument against doing it. It would only widen what the class matches, so a
 * later refactor that moved a throw into a different service would find it
 * absorbed by an unrelated `catch (BusinessRuleException)` arm, silently.
 */
class DeliveryInvoiceHandoffException extends RuntimeException
{
}
