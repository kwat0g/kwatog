import { useCallback } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { scheduleRealtimeQueryInvalidation } from '@/lib/realtime/invalidationMap';
import { useRealtimeEvent } from './useRealtimeEvent';

/** Refresh customer complaint and dashboard views on the customer's private signal. */
export function useCustomerComplaintRealtime(customerId?: string): void {
  const queryClient = useQueryClient();
  const refresh = useCallback(() => {
    scheduleRealtimeQueryInvalidation(queryClient, [
      ['portal', 'customer', 'complaints'],
      ['portal', 'customer', 'dashboard'],
    ]);
  }, [queryClient]);

  useRealtimeEvent(
    customerId ? `customer.complaint.${customerId}` : '',
    '.crm.complaint.changed',
    refresh,
    refresh,
    refresh,
  );
}

/** Keep a currently open, imperative 8D report modal aligned with fresh API state. */
export function useCustomerComplaintReportRealtime(customerId: string | undefined, onChange: () => void): void {
  useRealtimeEvent(
    customerId ? `customer.complaint.${customerId}` : '',
    '.crm.complaint.changed',
    onChange,
    onChange,
    onChange,
  );
}
