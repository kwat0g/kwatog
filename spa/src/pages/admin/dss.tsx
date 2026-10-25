/** DSS Wave 0-6 — admin console: detector fleet, kill switch, run-now. Display + controls only. */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { reportMutationError } from '@/lib/formErrors';
import { dssApi, type DssActivitySummary, type DssDetectorState } from '@/api/dss';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/formatDate';
import { detectorLabel } from '@/pages/decision-center/presentation';

const modeVariant = (mode: DssDetectorState['mode']): 'neutral' | 'info' | 'warning' =>
  mode === 'shadow' ? 'neutral' : 'info';
const domainLabels: Record<string, string> = {
  cross_module: 'Connected workflows',
  approvals: 'Approvals',
  production: 'Production',
  accounting: 'Finance',
  quality: 'Quality',
  maintenance: 'Maintenance',
  payroll: 'Payroll',
  human_resources: 'People and training',
  platform: 'Master data',
};

function EngineCard() {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const mayKill = can('dss.admin');
  const { data: engine, isLoading } = useQuery({
    queryKey: ['dss', 'admin', 'engine'],
    queryFn: () => dssApi.engine(),
  });

  const update = useMutation({
    mutationFn: (data: { enabled?: boolean; default_mode?: 'shadow' }) => dssApi.updateEngine(data),
    onSuccess: (state) => {
      toast.success(state.enabled ? 'DSS engine updated' : 'DSS engine stopped — detectors will not run');
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
        <div>
          {engine.legacy_global_advisory_ignored && (
            <div role="status" className="mb-2 flex items-center gap-2 rounded border border-warning/40 bg-warning-bg/10 px-2 py-1.5 text-xs text-warning-fg">
              <span>Legacy global advisory mode is ignored. Detector modes stay unchanged.</span>
              {mayKill && (
                <Button
                  size="xs"
                  variant="secondary"
                  disabled={update.isPending}
                  onClick={() => update.mutate({ default_mode: 'shadow' })}
                >
                  Set global default to shadow
                </Button>
              )}
            </div>
          )}
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
      toast.success(`${detectorLabel(detector.id)} updated`);
      invalidate();
    },
    onError: (error) => reportMutationError(error, 'Failed to update detector'),
  });

  const run = useMutation({
    mutationFn: () => dssApi.runDetector(detector.id),
    onSuccess: (result) => {
      const status = result.detectors[detector.id]?.status;
      if (status === 'queued') toast.success(`${detectorLabel(detector.id)} run queued`);
      else if (status === 'completed') toast.success(`${detectorLabel(detector.id)} ran`);
      else if (status === 'failed') toast.error(`${detectorLabel(detector.id)} run failed`);
      else toast(`${detectorLabel(detector.id)} run ${status ?? 'skipped'}`, { icon: '⚠️' });
    },
    onError: (error) => reportMutationError(error, 'Detector run failed'),
    onSettled: () => {
      invalidate();
      queryClient.invalidateQueries({ queryKey: ['dss', 'coverage'] });
    },
  });

  return (
    <div className="px-3 py-2.5">
      <div className="flex items-center gap-2 flex-wrap">
        <span className="text-sm font-medium">{detectorLabel(detector.id)}</span>
        <Chip variant="neutral">{domainLabels[detector.domain] ?? 'Business operations'}</Chip>
        <Chip variant={modeVariant(detector.mode)}>{detector.mode}</Chip>
        {!detector.enabled && <Chip variant="warning">disabled</Chip>}
        <span className="font-mono tabular-nums text-2xs text-text-subtle">
          {detector.active_count} active
        </span>
        {detector.last_run && (
          <span className="text-2xs text-text-subtle">
            last {detector.last_run.status}{detector.last_run.stale ? ' (stale)' : ''} · {detector.last_run.rows_scanned} scanned ·{' '}
            {detector.last_run.emitted} emitted · {detector.last_run.duration_ms ?? '—'}ms
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
        {(['shadow', 'advisory'] as const).map((mode) => (
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
    refetchInterval: 5000,
    refetchIntervalInBackground: false,
  });
  const {
    data: activity,
    isLoading: activityLoading,
    isError: activityError,
  } = useQuery({
    queryKey: ['dss', 'admin', 'activity-summary'],
    queryFn: () => dssApi.activitySummary(),
  });

  return (
    <div>
      <PageHeader title="DSS Admin" subtitle="Detectors, kill switch, run-now" />
      <div className="px-5 pb-6 space-y-4 max-w-4xl">
        <EngineCard />
        <section className="rounded-md border border-subtle bg-canvas px-3 py-2.5">
          <h2 className="text-xs font-medium mb-2">Activity · last 30 days</h2>
          {activityLoading && <p className="text-xs text-text-subtle">Loading activity…</p>}
          {activityError && <p className="text-xs text-danger">Failed to load activity summary.</p>}
          {activity && <ActivityCounts activity={activity} />}
          <p className="mt-2 text-2xs text-text-subtle">
            Activity counts do not measure detector accuracy or realized savings.
          </p>
        </section>
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

function ActivityCounts({ activity }: { activity: DssActivitySummary }) {
  const rows = [
    ['Tracked recommendation opens', activity.recommendation_opens],
    ['Follow-ups started', activity.follow_ups_started],
    ['Source-verified resolutions', activity.source_verified_resolutions],
    ['Recurring recommendations', activity.recurring_recommendations],
  ] as const;

  return (
    <dl className="grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-4">
      {rows.map(([label, count]) => (
        <div key={label}>
          <dt className="text-2xs text-text-subtle">{label}</dt>
          <dd className="font-mono tabular-nums text-sm">{count}</dd>
        </div>
      ))}
    </dl>
  );
}
