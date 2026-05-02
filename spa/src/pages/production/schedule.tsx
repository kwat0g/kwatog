/**
 * Sprint 6 — Tasks 53 + 54. Production schedule (Gantt).
 *
 * GET /mrp/scheduler/snapshot fills the chart; POST /mrp/scheduler/run
 * proposes new schedules and POST /mrp/scheduler/confirm persists them.
 */
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { Play, Check, AlertTriangle } from 'lucide-react';
import toast from 'react-hot-toast';
import { schedulerApi } from '@/api/mrp/scheduler';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { GanttChart } from '@/components/production/GanttChart';
import { usePermission } from '@/hooks/usePermission';
import type { SchedulerConflict, SchedulerProposalRow } from '@/types/mrp';

export default function ProductionSchedulePage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const canRun = can('mrp.schedule');
  const canConfirm = can('production.schedule.confirm');

  const [viewMode, setViewMode] = useState<'Day' | 'Week' | 'Month'>('Week');
  const [latestProposal, setLatestProposal] = useState<SchedulerProposalRow[]>([]);
  const [latestConflicts, setLatestConflicts] = useState<SchedulerConflict[]>([]);

  const snapshot = useQuery({
    queryKey: ['mrp', 'scheduler', 'snapshot'],
    queryFn: () => schedulerApi.snapshot(),
    refetchInterval: 60_000, // 60s polling fallback
    placeholderData: (prev) => prev,
  });

  const run = useMutation({
    mutationFn: () => schedulerApi.run(),
    onSuccess: (data) => {
      setLatestProposal(data.scheduled);
      setLatestConflicts(data.conflicts);
      qc.invalidateQueries({ queryKey: ['mrp', 'scheduler', 'snapshot'] });
      toast.success(`Scheduler proposed ${data.scheduled.length} schedules (${data.conflicts.length} conflicts).`);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Scheduler run failed.');
    },
  });

  const confirm = useMutation({
    mutationFn: (ids: string[]) => schedulerApi.confirm(ids),
    onSuccess: (res) => {
      toast.success(`Confirmed ${res.confirmed_count} schedules.`);
      setLatestProposal([]);
      qc.invalidateQueries({ queryKey: ['mrp', 'scheduler', 'snapshot'] });
      qc.invalidateQueries({ queryKey: ['production', 'work-orders'] });
    },
  });

  return (
    <div>
      <PageHeader
        title="Production schedule"
        actions={
          <div className="flex items-center gap-1.5">
            <select
              value={viewMode}
              onChange={(e) => setViewMode(e.target.value as 'Day' | 'Week' | 'Month')}
              className="h-8 px-2 text-xs rounded-md border border-default bg-canvas"
              aria-label="View mode"
            >
              <option value="Day">Day</option>
              <option value="Week">Week</option>
              <option value="Month">Month</option>
            </select>
            {canRun && (
              <Button size="sm" variant="secondary" icon={<Play size={14} />}
                onClick={() => run.mutate()} loading={run.isPending}>
                Run scheduler
              </Button>
            )}
            {canConfirm && latestProposal.length > 0 && (
              <Button size="sm" variant="primary" icon={<Check size={14} />}
                onClick={() => confirm.mutate(latestProposal.map((p) => p.id))} loading={confirm.isPending}>
                Confirm {latestProposal.length} schedule{latestProposal.length === 1 ? '' : 's'}
              </Button>
            )}
          </div>
        }
      />

      <div className="px-5 py-4 space-y-4">
        {latestConflicts.length > 0 && (
          <Panel
            title="Unscheduled work orders"
            meta={`${latestConflicts.length} conflict${latestConflicts.length === 1 ? '' : 's'}`}
          >
            <div className="space-y-2">
              {latestConflicts.map((c) => (
                <div key={c.work_order_id} className="flex items-start gap-2 text-xs">
                  <AlertTriangle size={14} className="text-danger mt-0.5" />
                  <div>
                    <span className="font-mono">{c.wo_number}</span>
                    <span className="ml-2 text-muted">— {c.reasons.join('; ')}</span>
                  </div>
                  <Chip variant="danger">stuck</Chip>
                </div>
              ))}
            </div>
          </Panel>
        )}

        <Panel title="Gantt" noPadding>
          {snapshot.isLoading && !snapshot.data && <SkeletonTable columns={4} rows={6} />}
          {snapshot.isError && (
            <EmptyState icon="alert-circle" title="Failed to load schedule"
              action={<Button variant="secondary" onClick={() => snapshot.refetch()}>Retry</Button>} />
          )}
          {snapshot.data && (
            <GanttChart
              rows={snapshot.data.rows}
              viewMode={viewMode}
              onBarClick={(woId) => navigate(`/production/work-orders/${woId}`)}
            />
          )}
        </Panel>
      </div>
    </div>
  );
}
