import {
 MutationCache,
 QueryClient,
 type Mutation,
 type MutationOptions,
 type MutationState,
} from '@tanstack/react-query';
import { isRealtimeManagedQueryKey } from './realtime/queryFreshness';
import {
 isClientHttpError,
 refetchUnlessClientHttpError,
 shouldRetryQuery,
} from './httpErrorPolicy';

class SessionScopedQueryClient extends QueryClient {
 #sessionScopeRevision = 0;

 sessionScopeRevision(): number {
  return this.#sessionScopeRevision;
 }

 clear(): void {
  this.#sessionScopeRevision += 1;
  super.clear();
 }
}

class SessionScopedMutationCache extends MutationCache {
 override build<TData, TError, TVariables, TOnMutateResult>(
  client: QueryClient,
  options: MutationOptions<TData, TError, TVariables, TOnMutateResult>,
  state?: MutationState<TData, TError, TVariables, TOnMutateResult>,
 ): Mutation<TData, TError, TVariables, TOnMutateResult> {
  const scopedClient = client instanceof SessionScopedQueryClient ? client : null;
  const sessionRevision = scopedClient?.sessionScopeRevision();
  const sessionIsCurrent = () =>
   scopedClient === null || sessionRevision === scopedClient.sessionScopeRevision();
  const assertSessionCurrent = () => {
   if (!sessionIsCurrent()) {
    throw new Error('The authenticated session changed before this action could be sent.');
   }
  };
  const mutationFn = options.mutationFn;
  const onMutate = options.onMutate;
  const onSuccess = options.onSuccess;
  const scopedOptions: MutationOptions<TData, TError, TVariables, TOnMutateResult> = {
   ...options,
   mutationFn: mutationFn
    ? async (variables, context) => {
      assertSessionCurrent();
      const data = await mutationFn(variables, context);
      assertSessionCurrent();
      return data;
     }
    : undefined,
   onMutate: onMutate
    ? (variables, context) => {
      assertSessionCurrent();
      return onMutate(variables, context);
     }
    : undefined,
   onSuccess: onSuccess
    ? (data, variables, result, context) => {
      assertSessionCurrent();
      return onSuccess(data, variables, result, context);
     }
    : undefined,
   onError: options.onError
    ? (error, variables, result, context) => sessionIsCurrent()
      ? options.onError?.(error, variables, result, context)
      : undefined
    : undefined,
   onSettled: options.onSettled
    ? (data, error, variables, result, context) => sessionIsCurrent()
      ? options.onSettled?.(data, error, variables, result, context)
      : undefined
    : undefined,
  };

  return super.build(client, scopedOptions, state);
 }
}

/**
 * Shared TanStack Query client.
 *
 * Has no React component dependency, so it can be imported from non-React
 * code — notably the auth store's `logout` action and the Axios 401 handler,
 * both of which call `queryClient.clear()` to prevent cross-user cache leaks
 * on shared terminals.
 */
export const queryClient = new SessionScopedQueryClient({
 mutationCache: new SessionScopedMutationCache(),
 defaultOptions: {
 queries: {
 // Server snapshots are stale by default. Realtime-managed keys also force a
 // mount refetch when legacy hooks specify a positive staleTime. Reference
 // and analytics queries can keep their explicit lifetimes.
 staleTime: 0,
 // A realtime event can be missed while no screen is mounted. Revalidate
 // mapped record/aggregate families on mount even where an older hook still
 // declares a longer staleTime. Unmapped reference/option keys retain their TTL.
 refetchOnMount: (query) => {
  if (isClientHttpError(query.state.error)) return false;
  return isRealtimeManagedQueryKey(query.queryKey) ? 'always' : true;
 },
 retry: shouldRetryQuery,
 refetchOnWindowFocus: false,
 refetchOnReconnect: refetchUnlessClientHttpError,
 networkMode: 'offlineFirst',
 },
 mutations: {
 networkMode: 'offlineFirst',
 },
 },
});
