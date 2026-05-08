// Series X / Task X5 — optimistic update helpers for TanStack Query.
//
// Wraps the snapshot → apply → rollback dance the docs recommend so pages
// don't have to write it from scratch on every status-toggle mutation.
//
// Usage:
//
//   const mutation = useMutation({
//     mutationFn: (id: string) => api.toggle(id),
//     onMutate: async (id) =>
//       optimisticUpdateList<Supplier>({
//         queryClient,
//         queryKey: ['suppliers', filters],
//         updater: (rows) =>
//           rows.map((r) => (r.id === id ? { ...r, is_active: !r.is_active } : r)),
//       }),
//     onError: rollback,
//     onSettled: () => queryClient.invalidateQueries({ queryKey: ['suppliers'] }),
//   });

import type { QueryClient, QueryKey } from '@tanstack/react-query';

export interface OptimisticContext<T> {
  /** Previous data to restore if the mutation fails. */
  previous: T | undefined;
  /** queryKey that was patched. */
  queryKey: QueryKey;
}

interface BaseArgs<T> {
  queryClient: QueryClient;
  queryKey: QueryKey;
  updater: (current: T) => T;
}

/**
 * Generic optimistic update — snapshot the current cached value, apply the
 * updater, and return the rollback context to use in `onError`.
 */
export async function optimisticUpdate<T>({
  queryClient,
  queryKey,
  updater,
}: BaseArgs<T>): Promise<OptimisticContext<T>> {
  await queryClient.cancelQueries({ queryKey });
  const previous = queryClient.getQueryData<T>(queryKey);
  if (previous !== undefined) {
    queryClient.setQueryData<T>(queryKey, updater(previous));
  }
  return { previous, queryKey };
}

/**
 * Optimistic update for paginated list queries (TanStack Query default
 * shape: `{ data: T[], meta }`). Pass an updater for the row array.
 */
export async function optimisticUpdateList<TRow>({
  queryClient,
  queryKey,
  updater,
}: {
  queryClient: QueryClient;
  queryKey: QueryKey;
  updater: (rows: TRow[]) => TRow[];
}): Promise<OptimisticContext<{ data: TRow[] }>> {
  return optimisticUpdate<{ data: TRow[] }>({
    queryClient,
    queryKey,
    updater: (current) => ({ ...current, data: updater(current.data) }),
  });
}

/** `onError` callback — rolls the snapshot back into the cache. */
export function rollback<T>(
  _err: unknown,
  _vars: unknown,
  context: OptimisticContext<T> | undefined,
  queryClient?: QueryClient,
): void {
  if (!context) return;
  if (context.previous === undefined) return;
  queryClient?.setQueryData(context.queryKey, context.previous);
}

/**
 * Convenience: returns an `onError` handler bound to the queryClient. Use
 * this when you don't want to write the rollback yourself.
 */
export function makeRollback(queryClient: QueryClient) {
  return <T,>(_err: unknown, _vars: unknown, context: OptimisticContext<T> | undefined) =>
    rollback(_err, _vars, context, queryClient);
}
