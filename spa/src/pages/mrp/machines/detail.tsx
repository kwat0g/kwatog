import { useParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { machinesApi } from '@/api/mrp/machines';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import type { MachineStatus } from '@/types/mrp';

const variant: Record<MachineStatus, 'success' | 'neutral' | 'info' | 'danger' | 'warning'> = {
  running: 'success',
  idle: 'neutral',
  maintenance: 'info',
  breakdown: 'danger',
  offline: 'neutral',
};

export default function MachineDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['mrp', 'machines', 'detail', id],
    queryFn: () => machinesApi.show(id!),
    enabled: !!id,
  });

  if (isLoading) return <div><PageHeader title="Machine" backTo="/mrp/machines" backLabel="Machines" /><SkeletonDetail /></div>;
  if (isError || !data) return (
    <div>
      <PageHeader title="Machine" backTo="/mrp/machines" backLabel="Machines" />
      <EmptyState
        icon="alert-circle"
        title="Failed to load machine"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
      />
    </div>
  );

  return (
    <div>
      <PageHeader
        title={
          <div className="flex items-center gap-3">
            <span className="font-mono">{data.machine_code}</span>
            <span>{data.name}</span>
            <Chip variant={variant[data.status]}>{data.status_label}</Chip>
          </div>
        }
        backTo="/mrp/machines"
        backLabel="Machines"
      />

      <div className="px-5 py-4 grid grid-cols-3 gap-4">
        <div className="col-span-2 space-y-4">
          <Panel title="Specifications">
            <dl className="grid grid-cols-3 gap-y-2 gap-x-3 text-sm">
              <dt className="text-muted">Code</dt>
              <dd className="col-span-2 font-mono">{data.machine_code}</dd>
              <dt className="text-muted">Name</dt>
              <dd className="col-span-2">{data.name}</dd>
              <dt className="text-muted">Tonnage</dt>
              <dd className="col-span-2 font-mono tabular-nums">{data.tonnage ? `${data.tonnage} T` : '—'}</dd>
              <dt className="text-muted">Type</dt>
              <dd className="col-span-2 font-mono">{data.machine_type}</dd>
              <dt className="text-muted">Operators</dt>
              <dd className="col-span-2 font-mono tabular-nums">{Number(data.operators_required).toFixed(1)}</dd>
              <dt className="text-muted">Hours / day</dt>
              <dd className="col-span-2 font-mono tabular-nums">{Number(data.available_hours_per_day).toFixed(1)}</dd>
              <dt className="text-muted">Compatible molds</dt>
              <dd className="col-span-2 font-mono tabular-nums">{data.compatible_molds_count}</dd>
            </dl>
          </Panel>

          <Panel title="Compatible molds" meta={`${data.compatible_molds?.length ?? 0} molds`}>
            {(data.compatible_molds?.length ?? 0) === 0 ? (
              <div className="text-sm text-muted py-2">No mold compatibility configured.</div>
            ) : (
              <ul className="text-sm space-y-1">
                {data.compatible_molds!.map((m) => (
                  <li key={m.id} className="flex items-center gap-2">
                    <Link to={`/mrp/molds/${m.id}`} className="font-mono text-accent hover:underline">{m.mold_code}</Link>
                    <span className="text-muted">{m.name}</span>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        </div>

        <div className="space-y-4">
          <Panel title="Status">
            <dl className="grid grid-cols-2 gap-y-2 text-sm">
              <dt className="text-muted">Available now</dt>
              <dd className="font-mono">{data.is_available_now ? 'Yes' : 'No'}</dd>
              <dt className="text-muted">Status</dt>
              <dd><Chip variant={variant[data.status]}>{data.status_label}</Chip></dd>
            </dl>
          </Panel>
          <Panel title="OEE">
            <div className="text-sm text-muted">
              Aggregate metrics live on the production dashboard.{' '}
              <Link to="/production/dashboard" className="text-accent hover:underline">Open dashboard</Link>.
            </div>
          </Panel>
        </div>
      </div>
    </div>
  );
}
