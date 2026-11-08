import { useMemo } from 'react';
import type { ChainEntityType } from '@/types/chain';
import { useRealtimeRecord } from './useRealtimeRecord';

/** Compatibility hook; record subscriptions and invalidation are centralized. */
export function useChainProgress(
  entityType: ChainEntityType,
  entityId: string | undefined,
  queryKey: ReadonlyArray<unknown>,
  additionalQueryKeys: ReadonlyArray<ReadonlyArray<unknown>> = [],
): void {
  const serializedKeys = JSON.stringify([queryKey, ...additionalQueryKeys]);
  const keys = useMemo(() => JSON.parse(serializedKeys) as unknown[][], [serializedKeys]);

  // RealtimeProvider owns the single visibility-aware degraded-mode catch-up
  // loop for all active queries. A second timer here makes each chain detail
  // poll the same queries again whenever the shared connection is degraded.
  useRealtimeRecord(entityType, entityId, keys);
}
