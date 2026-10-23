/** DSS Wave 0-6 — admin console: detector fleet, kill switch, run-now. Display + controls only. */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { reportMutationError } from '@/lib/formErrors';
import { dssApi, type DssDetectorState } from '@/api/dss';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/formatDate';

const modeVariant = (mode: DssDetectorState['mode']): 'neutral' | 'info' | 'warning' =>
  mode === 'shadow' ? 'neutral' : mode === 'advisory' ? 'info' : 'warning';

function EngineCard() {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const mayKill = can('dss.admin');
  const { data: engine, isLoading } = useQuery({
    queryKey: ['dss', 'admin', 'engine'],
    queryFn: () => dssApi.engine(),
  });

  const update = useMutation({
    mutationFn: (data: { enabled?: boolean }) => dssApi.updateEngine(data),
    onSuccess: (state) => {
      toast.success(
        state.enabled ? 'DSS engine enabled' : 'DSS engine stopped — detectors will not run',
      );
      queryClient.invalidateQueries({ queryKey: ['dss'] });
    },
    onError: (error) => reportMutationError(error, 'Failed to update engine state'),
  });

  return (
    <section className="rounded-md border border-subtle bg-canvas px-3 py-2.5">
      <h2 className="text-xs font-medium mb-2">Engine</h2>
      {isLoading || !engine ? (
        <span className="text-xs text-text-subtle">Loading…</span>
      ) : (
        <div className="flex items-center gap-2 flex-wrap">
          <Chip variant={engine.enabled ? 'info' : 'danger'}>
            {engine.enabled ? 'running' : 'STOPPED (kill switch)'}
          </Chip>
          <span className="text-2xs text-text-subtle">default mode {engine.default_mode}</span>
          {mayKill && (
            <Button
              size="xs"
              variant={engine.enabled ? 'danger' : 'primary'}
              disabled={update.isPending}
              onClick={() => update.mutate({ enabled: !engine.enabled })}
            >
              {engine.enabled ? 'Stop everything' : 'Restart engine'}
            </Button>
          )}
        </div>
      )}
    </section>
  );
}

function DetectorRow({ detector }: { detector: DssDetectorState }) {
  const queryClient = useQueryClient();
  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['dss', 'admin', 'detectors'] });

  const update = useMutation({
    mutationFn: (data: { enabled?: boolean; mode?: DssDetectorState['mode'] }) =>
      dssApi.updateDetector(detector.id, data),
    onSuccess: () => {
      toast.success(`${detector.id} updated`);
      invalidate();
    },
    onError: (error) => reportMutationError(error, 'Failed to update detector'),
  });

  const run = useMutation({
    mutationFn: () => dssApi.runDetector(detector.id),
    onSuccess: () => {
      toast.success(`${detector.id} ran`);
      invalidate();
    },
    onError: (error) => reportMutationError(error, 'Detector run failed'),
  });

  return (
    <div className="px-3 py-2.5">
      <div className="flex items-center gap-2 flex-wrap">
        <span className="font-mono tabular-nums text-sm">{detector.id}</span>
        <Chip variant="neutral">{detector.domain}</Chip>
        <Chip variant={modeVariant(detector.mode)}>{detector.mode}</Chip>
        {!detector.enabled && <Chip variant="warning">disabled</Chip>}
        <span className="font-mono tabular-nums text-2xs text-text-subtle">
          {detector.open_count} open
        </span>
        {detector.last_run && (
          <span className="text-2xs text-text-subtle">
            last {detector.last_run.status} · {detector.last_run.emitted} emitted ·{' '}
            {detector.last_run.duration_ms ?? '—'}ms
            {detector.last_run.finished_at
              ? ` · ${formatDateTime(detector.last_run.finished_at)}`
              : ''}
          </span>
        )}
      </div>
      <div className="mt-2 flex items-center gap-2 flex-wrap">
        <Button size="xs" variant="secondary" disabled={run.isPending} onClick={() => run.mutate()}>
          {run.isPending ? 'Running…' : 'Run now'}
        </Button>
        <Button
          size="xs"
          variant="secondary"
          disabled={update.isPending}
          onClick={() => update.mutate({ enabled: !detector.enabled })}
        >
          {detector.enabled ? 'Disable' : 'Enable'}
        </Button>
        {(['shadow', 'advisory', 'assisted'] as const).map((mode) => (
          <Button
            key={mode}
            size="xs"
            variant={detector.mode === mode ? 'primary' : 'ghost'}
            disabled={update.isPending}
            onClick={() => update.mutate({ mode })}
          >
            {mode}
          </Button>
        ))}
      </div>
    </div>
  );
}

export default function DssAdminPage() {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['dss', 'admin', 'detectors'],
    queryFn: () => dssApi.detectors(),
  });

  return (
    <div>
      <PageHeader title="DSS Admin" subtitle="Detectors, kill switch, run-now" />
      <div className="px-5 pb-6 space-y-4 max-w-4xl">
        <EngineCard />
        {isLoading && !data && <SkeletonTable columns={1} rows={4} />}
        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load detectors"
            action={
              <Button variant="secondary" onClick={() => refetch()}>
                Retry
              </Button>
            }
          />
        )}
        {data && data.length === 0 && <EmptyState icon="inbox" title="No detectors registered" />}
        {data && data.length > 0 && (
          <section className="rounded-md border border-subtle divide-y divide-subtle bg-canvas">
            {data.map((detector) => (
              <DetectorRow key={detector.id} detector={detector} />
            ))}
          </section>
        )}
      </div>
    </div>
  );
}
