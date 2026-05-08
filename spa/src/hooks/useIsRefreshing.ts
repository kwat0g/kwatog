// Series X / Task X5 — true while a query is refetching in the background.
//
// Distinguishes between:
//   isLoading      — first fetch, no data yet → page should show a skeleton
//   isFetching     — any in-flight fetch (including the first one)
//   isRefreshing   — fetching but already have data (background refetch)
//
// Pages use this to show a small "Refreshing…" pill in the header instead of
// flashing back to a skeleton.

import { useIsFetching, useQueryClient } from '@tanstack/react-query';

/**
 * Returns true if any query under the given prefix is currently refetching
 * with cached data already present. Pass the same `queryKey` you use in
 * `useQuery({ queryKey: [...] })`.
 */
export function useIsRefreshing(queryKey: readonly unknown[]): boolean {
  const queryClient = useQueryClient();
  const fetching = useIsFetching({ queryKey });
  if (fetching === 0) return false;

  // We only consider it "refreshing" if at least one matching query already
  // has data — otherwise it's a first-time load (a skeleton state).
  const queries = queryClient.getQueryCache().findAll({ queryKey });
  return queries.some((q) => q.state.data !== undefined);
}
