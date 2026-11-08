import { useCallback } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { scheduleRealtimeListInvalidation } from '@/lib/realtime/invalidationMap';
import { useRealtimeEvent } from './useRealtimeEvent';

/** Keep one customer's portal invoice views fresh through its private account channel. */
export function useCustomerInvoiceRealtime(customerId?: string): void {
  const queryClient = useQueryClient();
  const refresh = useCallback(() => {
    scheduleRealtimeListInvalidation(queryClient, 'invoice');
  }, [queryClient]);

  useRealtimeEvent(
    customerId ? `customer.invoice.${customerId}` : '',
    '.chain.refresh',
    refresh,
    refresh,
    refresh,
  );
}
