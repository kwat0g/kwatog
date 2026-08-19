<?php

declare(strict_types=1);

namespace App\Modules\Production\Exceptions;

use RuntimeException;

/**
 * Expected product/location/setup gap in the production → inventory handoff.
 *
 * A sentinel between two layers of WorkOrderOutputService, not a rendering
 * marker, and it stays a bare RuntimeException for that reason.
 *
 * Every boundary it can cross already names it —
 * `catch (ProductionReceiptHandoffException|BusinessRuleException|
 * InvalidMovementException)` in WorkOrderOutputService::record (degrade the
 * receipt to manual, keep the output), the same arm in ::retryProductionReceipt
 * (mark manual, rethrow), CreateProductionReceiptOnOutputRequested (the queued
 * replay), and both WorkOrderController methods. So it is never rendered by the
 * default handler and has no 500 to fix.
 *
 * Reparenting onto BusinessRuleException would be invisible in those arms and
 * wrong in meaning. This class says "the output is a fact and it committed; a
 * downstream step needs setup". BusinessRuleException says "your request was
 * refused". `record()` returns 201 on this path — a class whose base means
 * "refused" has no business describing it.
 *
 * The reasonCode is why the type still matters at the catch site:
 * WorkOrderOutputService branches on `$e instanceof
 * ProductionReceiptHandoffException` to choose between this code and the generic
 * 'production_receipt_business_rule', which is what the recovery event carries.
 */
class ProductionReceiptHandoffException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reasonCode = 'inventory_setup_missing',
    ) {
        parent::__construct($message);
    }
}
