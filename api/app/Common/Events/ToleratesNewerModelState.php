<?php

declare(strict_types=1);

namespace App\Common\Events;

/**
 * Opt-in for an outbox event that states a FACT ("this sales order was
 * confirmed"), not a snapshot. Outbox model markers pin the row version that
 * was published, and a mismatch normally stops delivery. For a fact that is
 * wrong: the order stays confirmed even after MRP touches its row a second
 * later, yet the pin failed the event forever and its listeners (PPC notice,
 * customer email, MRP queueing) never ran.
 *
 * Only implement this when every listener re-reads the current state and
 * guards on it, because the event then carries the CURRENT row, which may be
 * newer than the moment it describes. The codec still refuses an older row.
 */
interface ToleratesNewerModelState
{
}
