import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import toast from 'react-hot-toast';
import { deliveriesApi } from '@/api/supply-chain';
import { Button } from '@/components/ui/Button';
import { Panel } from '@/components/ui/Panel';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { useAuthStore } from '@/stores/authStore';
import type { Delivery, DeliveryCostHandoff } from '@/types/supplyChain';

export function DeliveryStockCostPanel({ delivery }: { delivery: Delivery }) {
  const qc = useQueryClient();
  const [failure, setFailure] = useState('');
  const userId = useAuthStore((state) => state.user?.id);
  const requestKey = (kind: string) => {
    const storageKey = `ogami:delivery-recovery:${userId}:${delivery.id}:${kind}`;
    try {
      const existing = localStorage.getItem(storageKey);
      if (existing && /^[0-9a-f-]{36}$/i.test(existing)) return existing;
      const key = crypto.randomUUID(); localStorage.setItem(storageKey, key); return key;
    } catch { return crypto.randomUUID(); }
  };
  const refresh = () => qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries'] });
  const failed = (error: unknown) => {
    const message = isAxiosError(error) ? error.response?.data?.message : undefined;
    setFailure(message ?? 'Could not confirm this action. Reload the delivery and retry if it still needs attention.');
    toast.error('Delivery action needs attention.');
    void refresh();
  };
  const reserve = useMutation({
    mutationFn: () => deliveriesApi.reserveStock(delivery.id, requestKey('reserve')),
    onSuccess: async () => { setFailure(''); await refresh(); toast.success('Approved stock reserved for this delivery.'); }, onError: failed,
  });
  const retryCost = useMutation({
    mutationFn: (kind: 'customer_cogs' | 'unaccounted_loss') => deliveriesApi.retryCost(delivery.id, kind, requestKey(kind)),
    onSuccess: async () => { setFailure(''); await refresh(); toast.success('Accounting handoff checked.'); }, onError: failed,
  });
  const costRow = (label: string, value: DeliveryCostHandoff | undefined, kind: 'customer_cogs' | 'unaccounted_loss') => value && <div className="space-y-2 border-t border-default pt-3">
    <div className="flex flex-wrap items-center justify-between gap-2"><span className="text-sm font-medium">{label}</span><Chip variant={chipVariantForStatus(value.status)}>{value.status.replace(/_/g, ' ')}</Chip></div>
    <p className="text-sm font-mono tabular-nums">₱{value.amount}</p>
    {value.message && <p className="text-sm text-muted break-words">{value.message}</p>}
    {value.can_retry && <Button variant="secondary" loading={retryCost.isPending} onClick={() => retryCost.mutate(kind)}>Retry {label.toLowerCase()}</Button>}
  </div>;
  if (!delivery.stock_reservation_status && !delivery.cogs_handoff && !delivery.loss_handoff) return null;
  return <Panel title="Stock and accounting">
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2"><span className="text-sm font-medium">Shipment stock</span><Chip variant={chipVariantForStatus(delivery.stock_reservation_status ?? 'unreserved')}>{(delivery.stock_reservation_status ?? 'unreserved').replace(/_/g, ' ')}</Chip></div>
      {delivery.stock_reservation_status === 'reserved' && <p className="text-sm text-muted">The approved lot is held for this shipment until departure or cancellation.</p>}
      {delivery.can_reserve_stock && <><p className="text-sm text-muted">Reserve the approved physical stock before this delivery departs.</p><Button variant="secondary" loading={reserve.isPending} onClick={() => reserve.mutate()}>Reserve shipment stock</Button></>}
      {Boolean(delivery.stock_reservation_allocations?.length) && <details className="text-sm">
        <summary className="cursor-pointer text-link">Reserved lots and locations</summary>
        <ul className="mt-2 space-y-2">{delivery.stock_reservation_allocations?.map((row, index) => <li key={`${row.delivery_item_id}-${row.location_id}-${index}`} className="break-words">{row.item.code} · {row.location_code} · Lot {row.lot_number}<span className="block font-mono tabular-nums">{row.quantity} reserved · {row.consumed_quantity} dispatched</span></li>)}</ul>
      </details>}
      {costRow('Customer cost', delivery.cogs_handoff, 'customer_cogs')}
      {costRow('Shipment loss', delivery.loss_handoff, 'unaccounted_loss')}
      {failure && <p role="alert" className="text-sm text-danger-fg">{failure}</p>}
    </div>
  </Panel>;
}
