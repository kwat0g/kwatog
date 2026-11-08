import { useCallback } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { scheduleRealtimeQueryInvalidation } from '@/lib/realtime/invalidationMap';
import { useRealtimeEvent } from './useRealtimeEvent';

/** Customer pricing refresh pings are scoped to the authenticated customer account channel. */
export function useCustomerPriceRequestRealtime(customerId?: string): void {
  const queryClient = useQueryClient();
  const refresh = useCallback(() => {
    scheduleRealtimeQueryInvalidation(queryClient, [['portal', 'pricing']]);
  }, [queryClient]);

  useRealtimeEvent(
    customerId ? `customer.price-request.${customerId}` : '',
    '.crm.price-request.changed',
    refresh,
    refresh,
    refresh,
  );
}
