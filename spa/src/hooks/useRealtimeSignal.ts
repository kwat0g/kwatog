import { useCallback } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import {
  scheduleCacheGroupInvalidation,
  scheduleRealtimeSignalInvalidation,
  type RealtimeCacheGroup,
  type RealtimeSignal,
} from '@/lib/realtime/invalidationMap';
import { useRealtimeEvent } from './useRealtimeEvent';

/** Bind an opaque system signal to the central query invalidation map. */
export function useRealtimeSignal(
  signal: RealtimeSignal,
  channel: string,
  event: string,
): void {
  const queryClient = useQueryClient();
  const refresh = useCallback(() => {
    scheduleRealtimeSignalInvalidation(queryClient, signal);
  }, [queryClient, signal]);

  useRealtimeEvent(channel, event, refresh, refresh, refresh);
}

/** Keep active browser query caches aligned with backend generation rotation. */
export function useCacheGroupRealtime(group: RealtimeCacheGroup, enabled: boolean): void {
  const queryClient = useQueryClient();
  const refresh = useCallback(() => {
    scheduleCacheGroupInvalidation(queryClient, group);
  }, [group, queryClient]);

  useRealtimeEvent(
    enabled ? `cache-group.${group}` : '',
    '.cache.group.invalidated',
    refresh,
    refresh,
    refresh,
  );
}

/** Vendor-scoped PO, GRN and Bill chain events refresh only their supplier-visible query families. */
export function useSupplierPortalDataRealtime(vendorHashId: string | undefined): void {
  const queryClient = useQueryClient();
  const catchUp = useCallback(() => {
    scheduleRealtimeSignalInvalidation(queryClient, 'supplier_purchase_orders_changed');
    scheduleRealtimeSignalInvalidation(queryClient, 'supplier_receipts_changed');
    scheduleRealtimeSignalInvalidation(queryClient, 'supplier_bills_changed');
  }, [queryClient]);
  const onChanged = useCallback((payload?: unknown) => {
    const entityType = typeof payload === 'object' && payload !== null && 'entity_type' in payload
      ? (payload as { entity_type?: unknown }).entity_type
      : undefined;

    if (entityType === 'grn') {
      scheduleRealtimeSignalInvalidation(queryClient, 'supplier_receipts_changed');
    } else if (entityType === 'bill') {
      scheduleRealtimeSignalInvalidation(queryClient, 'supplier_bills_changed');
    } else if (entityType === 'purchase_order') {
      scheduleRealtimeSignalInvalidation(queryClient, 'supplier_purchase_orders_changed');
    }
  }, [queryClient]);

  useRealtimeEvent(
    vendorHashId ? `supplier.vendor.${vendorHashId}` : '',
    '.chain.refresh',
    onChanged,
    catchUp,
    catchUp,
  );
}
