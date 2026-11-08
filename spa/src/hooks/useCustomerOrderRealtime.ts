import { useCallback } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import type { RealtimeEntityType } from '@/lib/realtime/invalidationMap';
import { scheduleRealtimeChangeInvalidation, scheduleRealtimeEntityCatchUp } from '@/lib/realtime/invalidationMap';
import { useRealtimeEvent } from './useRealtimeEvent';

interface CustomerOrderRefreshPayload {
  entity_type?: RealtimeEntityType;
  entity_id?: string;
}

/** Refresh customer order and delivery views through the customer's private account channel. */
export function useCustomerOrderRealtime(customerId?: string): void {
  const queryClient = useQueryClient();
  const catchUp = useCallback(() => {
    scheduleRealtimeEntityCatchUp(queryClient, ['sales_order', 'delivery']);
  }, [queryClient]);
  const refresh = useCallback((payload: CustomerOrderRefreshPayload) => {
    if ((payload.entity_type !== 'sales_order' && payload.entity_type !== 'delivery') || !payload.entity_id) return;

    scheduleRealtimeChangeInvalidation(queryClient, {
      entityType: payload.entity_type,
      entityId: payload.entity_id,
    });
  }, [queryClient]);

  useRealtimeEvent<CustomerOrderRefreshPayload>(
    customerId ? `customer.order.${customerId}` : '',
    '.chain.refresh',
    refresh,
    catchUp,
    catchUp,
  );
}
