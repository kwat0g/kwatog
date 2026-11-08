import { partialMatchKey, type QueryKey } from '@tanstack/react-query';
import { isRealtimeManagedQueryKey as isMappedRealtimeQueryKey } from './invalidationMap';

const referenceQueryPrefixes: QueryKey[] = [
  ['landing', 'contact'],
  ['landing', 'content'],
  ['auth', 'password-policy'],
  ['business-policies'],
  ['portal', 'supplier', 'business-policies'],
  ['production', 'downtime-categories'],
  ['admin', 'permissions', 'matrix'],
  ['dashboard', 'widgets'],
];

export function isStaticReferenceQueryKey(queryKey: QueryKey): boolean {
  if (queryKey.some((part) => typeof part === 'string' && /(?:^|[-_/])options?(?:$|[-_/])/i.test(part))) {
    return true;
  }

  return referenceQueryPrefixes.some((prefix) =>
    prefix.length <= queryKey.length && partialMatchKey(queryKey, prefix),
  );
}

/** Realtime-managed API data refetches on mount even with a legacy staleTime. */
export function isRealtimeManagedQueryKey(queryKey: QueryKey): boolean {
  return isMappedRealtimeQueryKey(queryKey);
}
