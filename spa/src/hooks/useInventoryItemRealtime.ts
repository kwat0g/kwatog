import { useCallback } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { usePermission } from '@/hooks/usePermission';
import { useRealtimeEvent } from '@/hooks/useRealtimeEvent';
import { useRealtimeList } from '@/hooks/useRealtimeRecord';
import { invalidateRealtimeList } from '@/lib/realtime/invalidationMap';

/**
 * One permission-scoped catalog subscription keeps all active item consumers
 * fresh, including item selectors outside the Inventory page.
 */
export function InventoryItemRealtimeBridge() {
  const { canAny } = usePermission();
  const canReadItemCatalog = canAny('inventory.view', 'purchasing.pr.create', 'mrp.boms.manage');
  const queryClient = useQueryClient();

  useRealtimeList('item', canReadItemCatalog ? 'visible' : undefined);

  const onStockChanged = useCallback(() => {
    void invalidateRealtimeList(queryClient, 'inventory_stock');
  }, [queryClient]);

  useRealtimeEvent(
    canReadItemCatalog ? 'entity.item.visible' : '',
    '.InventoryStockChanged',
    onStockChanged,
    onStockChanged,
    onStockChanged,
  );

  return null;
}
