// Optimistic status transitions on a detail page.
//
// Three pages had grown their own copy of this — `useApprovalMutation` in
// leaves/detail, `useActionMutation` in attendance/overtime/detail, and
// `useOptimisticAction` in purchasing/purchase-requests/detail — identical
// apart from the name and the invalidation key. Meanwhile `lib/optimistic.ts`
// existed to provide exactly the snapshot/patch/rollback dance and had zero
// consumers.
//
// Approve/reject/cancel is the app's most-repeated interaction and the one
// where the round-trip is most visible: the status chip is the whole point of
// the click. This is the shared version, built on lib/optimistic so there is
// one implementation rather than four.

import { useMutation, useQueryClient, type QueryKey } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { optimisticUpdate, rollback, type OptimisticContext } from '@/lib/optimistic';
import { reportMutationError } from '@/lib/formErrors';
import { invalidateQueryPrefixExcept } from '@/lib/queryInvalidation';
import { invalidateRealtimeChange, type RealtimeChangeRef } from '@/lib/realtime/invalidationMap';

type StatusBearing = { id?: unknown; status?: unknown; [key: string]: unknown } | undefined;

function serverRecord(value: unknown): Exclude<StatusBearing, undefined> | null {
  let candidate = value;
  for (let depth = 0; depth < 3; depth += 1) {
    if (!candidate || typeof candidate !== 'object') return null;
    if ('status' in candidate && 'id' in candidate) return candidate as Exclude<StatusBearing, undefined>;
    candidate = 'data' in candidate ? (candidate as { data?: unknown }).data : null;
  }
  return null;
}

export interface OptimisticStatusActionArgs<TVar> {
  /** queryKey of the record being transitioned. Patched, and rolled back on failure. */
  detailKey: QueryKey;
  /** Invalidated once settled — usually the list this record belongs to. */
  invalidateKey?: QueryKey;
  /** Central record map for linked lists, balances, badges and dashboards. */
  realtimeChange?: RealtimeChangeRef;
  mutationFn: (v: TVar) => Promise<unknown>;
  /** Status to show immediately, before the server confirms it. */
  nextStatus: string;
  successMsg: string;
  errorMsg: string;
  afterSuccess?: () => void;
}

/**
 * A status mutation that patches the cached record immediately and rolls back
 * if the server disagrees.
 *
 *   const approve = useOptimisticStatusAction({
 *     detailKey: ['leaves', 'request', id],
 *     invalidateKey: ['leaves'],
 *     mutationFn: () => leaveRequestsApi.approveHR(id),
 *     nextStatus: 'approved',
 *     successMsg: 'Approved.',
 *     errorMsg: 'Failed to approve.',
 *   });
 */
export function useOptimisticStatusAction<TVar = void>({
  detailKey,
  invalidateKey,
  realtimeChange,
  mutationFn,
  nextStatus,
  successMsg,
  errorMsg,
  afterSuccess,
}: OptimisticStatusActionArgs<TVar>) {
  const queryClient = useQueryClient();

  return useMutation<unknown, unknown, TVar, OptimisticContext<StatusBearing>>({
    mutationFn,
    onMutate: () =>
      optimisticUpdate<StatusBearing>({
        queryClient,
        queryKey: detailKey,
        updater: (current) => (current ? { ...current, status: nextStatus } : current),
      }),
    onError: (err, vars, context) => {
      rollback(err, vars, context, queryClient);
      // Goes through the shared reporter so a 429 or a dropped connection is
      // not relabelled "Failed to approve." — the interceptor already said
      // what actually happened.
      reportMutationError(err, errorMsg);
    },
    onSuccess: (response) => {
      const freshRecord = serverRecord(response);
      if (freshRecord) {
        queryClient.setQueryData<StatusBearing>(detailKey, (current) => ({ ...current, ...freshRecord }));
      }
      toast.success(successMsg);
      afterSuccess?.();
    },
    onSettled: (response) => {
      const hasFreshRecord = serverRecord(response) !== null;
      if (realtimeChange) {
        void invalidateRealtimeChange(
          queryClient,
          realtimeChange,
          [],
          hasFreshRecord ? [detailKey] : [],
        );
        return;
      }
      // A related-family prefix (for example ['leaves']) also matches this
      // exact detail query. The mutation response is already the authoritative
      // snapshot for the actor; don't refetch it and risk replacing that fresh
      // state with an older in-flight/cache result. Refresh other family rows.
      void invalidateQueryPrefixExcept(
        queryClient,
        invalidateKey ?? detailKey,
        hasFreshRecord ? [detailKey] : [],
      );
    },
  });
}
