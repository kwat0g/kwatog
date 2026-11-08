import type { QueryClient } from '@tanstack/react-query';
import type { WoOperation } from '@/types/production/routing';

export const workOrderOperationsQueryKey = (workOrderId: string) =>
  ['production', 'work-orders', 'operations', workOrderId] as const;

/** Reconcile an operation from its mutation response without inventing a partial list. */
export function reconcileWorkOrderOperation(
  queryClient: Pick<QueryClient, 'setQueryData'>,
  workOrderId: string,
  updatedOperation: WoOperation,
): void {
  queryClient.setQueryData<WoOperation[]>(workOrderOperationsQueryKey(workOrderId), (current) => {
    if (!current) return current;

    return current.map((operation) => operation.id === updatedOperation.id
      ? { ...operation, ...updatedOperation }
      : operation);
  });
}
