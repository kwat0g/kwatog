/**
 * Sprint P1 — centralized chain-step builder for Deliveries.
 *
 * Order-to-Cash chain (Delivery scope):
 *   Scheduled → Loading → In Transit → Delivered → Confirmed
 *
 * The page-level "Order-to-Cash" extended chain (Order → MRP → WO → QC →
 * Delivered → Invoiced → Collected) is built by `buildDeliveryO2cChain`,
 * which derives prior steps as already-done because a Delivery cannot exist
 * without an upstream confirmed/produced Sales Order.
 */
import type { ChainStep } from '@/types/chain';
import type { Delivery, DeliveryStatus } from '@/types/supplyChain';

const ORDER: DeliveryStatus[] = ['scheduled', 'loading', 'in_transit', 'delivered', 'confirmed'];

function isAtOrPast(status: DeliveryStatus, target: DeliveryStatus): boolean {
  if (status === 'cancelled') return false;
  return ORDER.indexOf(status) >= ORDER.indexOf(target);
}

/** Narrow chain showing only the Delivery lifecycle. */
export function buildDeliveryChain(delivery: Delivery): ChainStep[] {
  const status = delivery.status;
  if (status === 'cancelled') {
    return [
      { key: 'scheduled', label: 'Scheduled', state: 'done', date: delivery.scheduled_date ?? undefined },
      { key: 'cancelled', label: 'Cancelled', state: 'done', date: delivery.updated_at?.slice(0, 10) },
    ];
  }
  return [
    {
      key: 'scheduled',
      label: 'Scheduled',
      state: 'done',
      date: delivery.scheduled_date ?? undefined,
    },
    {
      key: 'loading',
      label: 'Loading',
      state: isAtOrPast(status, 'loading') ? (status === 'loading' ? 'active' : 'done') : 'pending',
    },
    {
      key: 'in_transit',
      label: 'In Transit',
      state:
        isAtOrPast(status, 'in_transit') ? (status === 'in_transit' ? 'active' : 'done') : 'pending',
      date: delivery.departed_at?.slice(0, 10),
    },
    {
      key: 'delivered',
      label: 'Delivered',
      state:
        isAtOrPast(status, 'delivered') ? (status === 'delivered' ? 'active' : 'done') : 'pending',
      date: delivery.delivered_at?.slice(0, 10),
    },
    {
      key: 'confirmed',
      label: 'Confirmed',
      state: status === 'confirmed' ? 'done' : 'pending',
      date: delivery.confirmed_at?.slice(0, 10),
    },
  ];
}
