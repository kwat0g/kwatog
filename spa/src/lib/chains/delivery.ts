import type { ChainStep } from '@/types/chain';
import type { Delivery } from '@/types/supplyChain';

/** Shipment progress follows departure, driver receipt and office confirmation. */
export function buildDeliveryChain(delivery: Pick<Delivery, 'status' | 'scheduled_date' | 'departed_at' | 'received_at' | 'confirmed_at' | 'updated_at' | 'proofs' | 'receipt_photo_url'>): ChainStep[] {
  const { status } = delivery;
  const planned = status === 'scheduled' || status === 'loading';
  const hasReceipt = Boolean(delivery.receipt_photo_url) || Boolean(delivery.proofs?.some((proof) =>
    !proof.deleted_at && ['signed_dr', 'photo', 'customer_po_confirmation'].includes(proof.proof_type),
  ));
  const plan: ChainStep = {
    key: 'shipment_plan', label: 'Shipment plan', state: planned ? 'active' : 'done', date: delivery.scheduled_date,
  };
  const dispatched: ChainStep = {
    key: 'dispatched', label: 'Dispatched', state: planned ? 'pending' : status === 'in_transit' ? 'active' : 'done', date: delivery.departed_at,
  };
  if (status === 'cancelled') {
    return [plan, { ...dispatched, state: delivery.departed_at ? 'done' : 'skipped' }, {
      key: 'cancelled', label: 'Cancelled', state: 'done', date: delivery.updated_at,
    }];
  }
  if (status === 'return_pending' || status === 'returned') {
    return [plan, dispatched, {
      key: status, label: status === 'return_pending' ? 'Return pending' : 'Returned', state: status === 'return_pending' ? 'active' : 'done',
    }];
  }
  return [plan, dispatched, {
    key: 'customer_receipt', label: 'Customer receipt',
    state: status === 'confirmed' || (status === 'delivered' && hasReceipt) ? 'done' : status === 'delivered' ? 'active' : 'pending',
    date: hasReceipt ? delivery.received_at : undefined,
  }, {
    key: 'confirmed', label: 'Confirmed',
    state: status === 'confirmed' ? 'done' : status === 'delivered' && hasReceipt ? 'active' : 'pending',
    date: delivery.confirmed_at,
  }];
}
