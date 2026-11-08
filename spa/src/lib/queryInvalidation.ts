import type { QueryClient, QueryKey } from '@tanstack/react-query';
import { invalidateQueryPrefixesExcept } from '@/lib/realtime/invalidationMap';

/**
 * Invalidate a query-key family while preserving exact keys that already hold
 * authoritative mutation responses. This keeps sibling lists fresh without
 * making the actor refetch the detail they just received from the API.
 *
 * The caller supplies exact query keys already reconciled from a response;
 * they are excluded by exact key equality, even if a key is not cached yet.
 */
export async function invalidateQueryPrefixExcept(
  queryClient: QueryClient,
  queryKey: QueryKey,
  preserveExactKeys: readonly QueryKey[],
): Promise<void> {
  await invalidateQueryPrefixesExcept(queryClient, [queryKey], [...preserveExactKeys]);
}
