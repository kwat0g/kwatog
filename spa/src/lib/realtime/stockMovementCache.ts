import type { QueryClient } from '@tanstack/react-query';
import type { PaginatedResponse } from '@/types';
import type { StockMovement } from '@/types/inventory';

export function applyStockMovementListResponse(
  queryClient: QueryClient,
  movement: StockMovement,
): void {
  queryClient.setQueriesData<PaginatedResponse<StockMovement>>(
    {
      queryKey: ['inventory', 'movements'],
      predicate: (query) => query.queryKey.length === 3 && typeof query.queryKey[2] === 'object',
    },
    (current) => current
      ? { ...current, data: current.data.map((row) => row.id === movement.id ? movement : row) }
      : current,
  );
}

export function canRetryStockMovement(movement: StockMovement): boolean {
  return movement.available_actions?.includes('retry_gl') === true;
}
