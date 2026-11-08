import { useCallback } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { scheduleRealtimeListInvalidation } from '@/lib/realtime/invalidationMap';
import { useRealtimeEvent } from './useRealtimeEvent';

/** Refresh open inventory views after a committed stock-ledger movement. */
export function useInventoryStockRealtime(enabled = true, warehouseDashboardEnabled = false): void {
  const queryClient = useQueryClient();
  const onChanged = useCallback(() => {
    scheduleRealtimeListInvalidation(queryClient, 'inventory_stock');
  }, [queryClient]);

  useRealtimeEvent(
    enabled && !warehouseDashboardEnabled ? 'inventory.dashboard' : '',
    '.InventoryStockChanged',
    onChanged,
    onChanged,
    onChanged,
  );
  useRealtimeEvent(
    warehouseDashboardEnabled ? 'dashboard.warehouse' : '',
    '.InventoryStockChanged',
    onChanged,
    onChanged,
    onChanged,
  );
}
