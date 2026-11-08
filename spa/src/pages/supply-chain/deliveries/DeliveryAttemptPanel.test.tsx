import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import type { DeliveryAttemptOutcome } from '@/types/deliveryAttempt';
import { DeliveryAttemptPanel } from './DeliveryAttemptPanel';

it('stores the internal actor response in its exact Delivery detail cache', async () => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const detailKey = ['supply-chain', 'deliveries', 'delivery-1'] as const;
  const initial = { id: 'delivery-1', status: 'in_transit', attempt_outcome: null };
  const outcome: DeliveryAttemptOutcome = {
    id: 'outcome-1',
    reason_code: 'partial_delivery',
    reason_label: 'Partial delivery',
    notes: null,
    reported_at: '2026-10-11T08:00:00Z',
    lines: [{
      delivery_item_id: 'item-1',
      customer_received_quantity: '2',
      customer_received_damaged_quantity: '0',
      truck_return_quantity: '1',
      truck_return_damaged_quantity: '0',
      unaccounted_quantity: '0',
    }],
  };
  const updated = { ...initial, status: 'return_pending', attempt_outcome: outcome };
  const report = vi.fn().mockResolvedValue(updated);
  queryClient.setQueryData(detailKey, initial);
  const view = render(
    <QueryClientProvider client={queryClient}>
      <DeliveryAttemptPanel
        deliveryId="delivery-1"
        lines={[{ id: 'item-1', quantity: '3', product: { part_number: 'BR-1', name: 'Bracket' } }]}
        report={report}
        can_report_attempt_outcome
        attempt_outcome_reasons={[{ value: 'partial_delivery', label: 'Partial delivery' }]}
      />
    </QueryClientProvider>,
  );

  fireEvent.click(screen.getByRole('button', { name: 'Report delivery problem' }));
  fireEvent.change(view.container.querySelector('select[name="reason_code"]')!, { target: { value: 'partial_delivery' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save delivery outcome' }));

  await waitFor(() => expect(report).toHaveBeenCalled());
  await waitFor(() => expect(queryClient.getQueryData(detailKey)).toEqual(updated));
});
