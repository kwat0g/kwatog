import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { workOrdersApi } from '@/api/production/workOrders';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Textarea } from '@/components/ui/Textarea';
import { MaterialHandoverNotice } from './MaterialHandoverNotice';
import { productionDateTime } from '@/components/production/scheduleDates';
import type { RecordTrialData, StartupTrial, WorkOrderOutput } from '@/types/production';
import type { Crew, OutputReceipt } from '@/types/production-crew';
import { useAuthStore } from '@/stores/authStore';
import { usePendingProductionSubmission } from '@/hooks/usePendingProductionSubmission';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import { createRequestKey } from '@/lib/requestKey';

export function TrialProductionPanel({
  workOrderId,
  trial,
  crew,
  manager = false,
  canRecord = false,
  handoverPending = 0,
  canAcknowledge = false,
  blocked = false,
}: {
  workOrderId: string;
  trial: StartupTrial;
  crew?: Crew | null;
  manager?: boolean;
  canRecord?: boolean;
  handoverPending?: number;
  canAcknowledge?: boolean;
  blocked?: boolean;
}) {
  const queryClient = useQueryClient();
  const [confirmed, setConfirmed] = useState(false);
  const [reason, setReason] = useState('');
  const [acknowledged, setAcknowledged] = useState(false);
  const [handoverReason, setHandoverReason] = useState('');
  const userId = useAuthStore((state) => state.user?.id);
  const recovery = usePendingProductionSubmission<RecordTrialData>(
    `${userId}:${workOrderId}:${trial.inspection_id}:trial`,
  );
  const { pending } = recovery;
  const [uncertain, setUncertain] = useState(!!pending);
  useUnsavedChangesGuard(!!pending);
  const [message, setMessage] = useState('');
  const [recorded, setRecorded] = useState(false);
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['production', 'work-orders'] });
    void queryClient.invalidateQueries({ queryKey: ['my-work-orders'] });
    void queryClient.invalidateQueries({ queryKey: ['my-outputs'] });
    void queryClient.invalidateQueries({ queryKey: ['quality', 'inspections'] });
  };
  const completed = () => {
    recovery.clear();
    setUncertain(false);
    setConfirmed(false);
    setRecorded(true);
    setMessage('Trial pieces recorded. Waiting for QC.');
    refresh();
  };
  const save = useMutation<
    OutputReceipt | WorkOrderOutput,
    unknown,
    { data: RecordTrialData; key: string }
  >({
    mutationFn: (submission: { data: RecordTrialData; key: string }) =>
      manager
        ? workOrdersApi.recordTrial(workOrderId, submission.data, submission.key)
        : workOrdersApi.recordMyTrial(workOrderId, submission.data, submission.key),
    onSuccess: completed,
    onError: (error) => {
      refresh();
      if (!(error instanceof AxiosError) || !error.response || error.response.status >= 500)
        setUncertain(true);
      else if (!uncertain) recovery.clear();
    },
  });
  const check = useMutation({
    mutationFn: () => workOrdersApi.myOutputReceipt(workOrderId, pending!.key),
    onSuccess: (receipt) =>
      receipt
        ? completed()
        : setMessage('No receipt found yet. Retry the same trial to resolve it.'),
    onError: () => setMessage('Receipt check failed. Try again.'),
  });
  const eligible = trial.state === 'awaiting_samples' && !recorded;
  const handoverBlocked =
    handoverPending > 0 &&
    !(
      canAcknowledge &&
      acknowledged &&
      handoverReason.trim() &&
      handoverReason.trim().length <= 500
    );
  const disabled =
    blocked ||
    !canRecord ||
    !crew ||
    !confirmed ||
    handoverBlocked ||
    (manager && (!reason.trim() || reason.trim().length > 500));
  const nextStep =
    recorded && trial.state === 'awaiting_samples'
      ? 'Waiting for QC'
      : handoverPending > 0 && eligible
        ? 'Confirm material handover'
        : {
            awaiting_samples: `Record ${trial.sample_size} trial pieces`,
            awaiting_qc: 'Waiting for QC',
            failed: 'Trial failed — QC recovery required',
            released: 'Ready for production',
          }[trial.state];
  return (
    <Panel title="Startup production">
      <p role="status" className="text-sm font-medium">
        {nextStep}
      </p>
      {trial.recorded_quantity > 0 && (
        <p className="mt-2 text-sm text-muted">
          {trial.recorded_quantity} trial pieces recorded ·{' '}
          {trial.captured_at ? productionDateTime(trial.captured_at) : '—'}
        </p>
      )}
      {eligible && canRecord && (
        <form
          className="mt-4 space-y-3"
          onSubmit={(event) => {
            event.preventDefault();
            if (disabled || pending) return;
            const submission = {
              key: createRequestKey(),
              data: {
                trial_quantity: trial.sample_size,
                crew_id: crew!.id,
                ...(manager ? { recording_reason: reason.trim() } : {}),
                ...(handoverPending > 0
                  ? {
                      material_handover_acknowledged: acknowledged,
                      material_handover_reason: handoverReason.trim(),
                    }
                  : {}),
              },
            };
            if (recovery.remember(submission)) {
              setMessage('');
              save.mutate(submission);
            }
          }}
        >
          <fieldset disabled={!!pending} className="min-w-0 space-y-3">
            <p className="text-sm text-muted">
              QC checks these {trial.sample_size} setup pieces before normal production.
            </p>
            <MaterialHandoverNotice
              pending={handoverPending}
              canAcknowledge={canAcknowledge}
              acknowledged={acknowledged}
              reason={handoverReason}
              onAcknowledged={setAcknowledged}
              onReason={setHandoverReason}
              disabled={!!pending}
            />
            {manager && (
              <Textarea
                label="Reason for recording for the worker"
                required
                maxLength={500}
                value={reason}
                onChange={(event) => setReason(event.target.value)}
              />
            )}
            <Checkbox
              className="min-h-11"
              label={`Samples ready for QC: ${trial.sample_size} pieces physically produced`}
              checked={confirmed}
              onChange={(event) => setConfirmed(event.target.checked)}
            />
          </fieldset>
          <Button type="submit" disabled={disabled || !!pending} loading={save.isPending}>
            Samples ready for QC
          </Button>
        </form>
      )}
      {recovery.storageError && (
        <p role="alert" className="mt-3 text-sm text-danger-fg">
          {recovery.storageError}
        </p>
      )}
      {uncertain && pending && (
        <p role="alert" className="mt-3 text-sm text-warning-fg">
          Trial recording outcome unknown. Check the receipt or retry this same trial before
          recording new pieces.
        </p>
      )}
      {save.isError && !uncertain && (
        <p role="alert" className="mt-3 text-sm text-danger-fg">
          {save.error instanceof AxiosError
            ? (save.error.response?.data?.message ??
              'Trial recording failed. Refresh the work order and try again.')
            : 'Trial recording failed.'}
        </p>
      )}
      {uncertain && pending && (
        <div className="mt-3 flex flex-wrap gap-2">
          <Button
            variant="secondary"
            disabled={check.isPending || save.isPending}
            onClick={() => check.mutate()}
          >
            Check trial receipt
          </Button>
          <Button disabled={check.isPending || save.isPending} onClick={() => save.mutate(pending)}>
            Retry same trial
          </Button>
        </div>
      )}
      {message && message !== 'Trial pieces recorded. Waiting for QC.' && (
        <p role="status" className="mt-3 text-sm">
          {message}
        </p>
      )}
    </Panel>
  );
}
