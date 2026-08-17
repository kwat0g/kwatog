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

type StatusBearing = { status?: unknown } | undefined;

export interface OptimisticStatusActionArgs<TVar> {
  /** queryKey of the record being transitioned. Patched, and rolled back on failure. */
  detailKey: QueryKey;
  /** Invalidated once settled — usually the list this record belongs to. */
  invalidateKey?: QueryKey;
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
    onSuccess: () => {
      toast.success(successMsg);
      afterSuccess?.();
    },
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: invalidateKey ?? detailKey });
    },
  });
}
