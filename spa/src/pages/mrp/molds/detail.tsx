import { useParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { moldsApi } from '@/api/mrp/molds';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import type { MoldStatus } from '@/types/mrp';

const variant: Record<MoldStatus, 'success' | 'neutral' | 'info' | 'danger' | 'warning'> = {
  available: 'success',
  in_use: 'info',
  maintenance: 'warning',
  retired: 'neutral',
};

export default function MoldDetailPage() {
  const { id } = useParams<{ id: string }>();
  const detail = useQuery({
    queryKey: ['mrp', 'molds', 'detail', id],
    queryFn: () => moldsApi.show(id!),
    enabled: !!id,
  });
  const history = useQuery({
    queryKey: ['mrp', 'molds', 'history', id],
    queryFn: () => moldsApi.history(id!),
    enabled: !!id,
  });

  if (detail.isLoading) return <div><PageHeader title="Mold" backTo="/mrp/molds" backLabel="Molds" /><SkeletonDetail /></div>;
  if (detail.isError || !detail.data) return (
    <div>
      <PageHeader title="Mold" backTo="/mrp/molds" backLabel="Molds" />
      <EmptyState
        icon="alert-circle"
        title="Failed to load mold"
        action={<Button variant="secondary" onClick={() => detail.refetch()}>Retry</Button>}
      />
    </div>
  );

  const m = detail.data;
  const pct = Math.min(100, Math.max(0, m.shot_percentage));
  const barColor = pct >= 80 ? 'bg-danger' : pct >= 60 ? 'bg-warning' : 'bg-success';

  return (
    <div>
      <PageHeader
        title={
          <div className="flex items-center gap-3">
            <span className="font-mono">{m.mold_code}</span>
            <span>{m.name}</span>
            <Chip variant={variant[m.status]}>{m.status_label}</Chip>
            {m.nearing_limit && <Chip variant="warning">Near shot limit</Chip>}
          </div>
        }
        backTo="/mrp/molds"
        backLabel="Molds"
      />

      <div className="px-5 py-4 grid grid-cols-3 gap-4">
        <div className="col-span-2 space-y-4">
          <Panel title="Specifications">
            <dl className="grid grid-cols-3 gap-y-2 gap-x-3 text-sm">
              <dt className="text-muted">Code</dt>
              <dd className="col-span-2 font-mono">{m.mold_code}</dd>
              <dt className="text-muted">Product</dt>
              <dd className="col-span-2">
                {m.product
                  ? <Link to={`/crm/products/${m.product.id}`} className="font-mono text-accent hover:underline">{m.product.part_number}</Link>
                  : <span className="text-muted">—</span>}
                {m.product && <span className="text-muted ml-2">{m.product.name}</span>}
              </dd>
              <dt className="text-muted">Cavity count</dt>
              <dd className="col-span-2 font-mono tabular-nums">{m.cavity_count}</dd>
              <dt className="text-muted">Cycle time</dt>
              <dd className="col-span-2 font-mono tabular-nums">{m.cycle_time_seconds}s</dd>
              <dt className="text-muted">Output / hr</dt>
              <dd className="col-span-2 font-mono tabular-nums">{m.output_rate_per_hour.toLocaleString()}</dd>
              <dt className="text-muted">Setup time</dt>
              <dd className="col-span-2 font-mono tabular-nums">{m.setup_time_minutes} min</dd>
              <dt className="text-muted">Location</dt>
              <dd className="col-span-2">{m.location ?? '—'}</dd>
            </dl>
          </Panel>

          <Panel title="Compatible machines" meta={`${m.compatible_machines?.length ?? 0} machines`}>
            {(m.compatible_machines?.length ?? 0) === 0 ? (
              <div className="text-sm text-muted py-2">No machine compatibility configured.</div>
            ) : (
              <ul className="text-sm space-y-1">
                {m.compatible_machines!.map((machine) => (
                  <li key={machine.id} className="flex items-center gap-2">
                    <Link to={`/mrp/machines/${machine.id}`} className="font-mono text-accent hover:underline">{machine.machine_code}</Link>
                    <span className="text-muted">{machine.name}</span>
                    {machine.tonnage && <span className="text-muted text-xs">· {machine.tonnage} T</span>}
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          <Panel title="History" meta={`${history.data?.length ?? 0} events`} noPadding>
            {history.isLoading ? (
              <div className="px-3 py-3 text-sm text-muted">Loading history…</div>
            ) : (history.data?.length ?? 0) === 0 ? (
              <div className="px-3 py-3 text-sm text-muted">No history events recorded.</div>
            ) : (
              <table className="w-full text-xs">
                <thead className="bg-subtle">
                  <tr>
                    <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Date</th>
                    <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Event</th>
                    <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Description</th>
                    <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Shot count</th>
                  </tr>
                </thead>
                <tbody>
                  {history.data!.map((h) => (
                    <tr key={h.id} className="border-t border-subtle">
                      <td className="px-2.5 py-2 font-mono">{h.event_date}</td>
                      <td className="px-2.5 py-2 font-mono">{h.event_type}</td>
                      <td className="px-2.5 py-2 text-muted">{h.description ?? '—'}</td>
                      <td className="px-2.5 py-2 text-right font-mono tabular-nums">{h.shot_count_at_event.toLocaleString()}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Panel>
        </div>

        <div className="space-y-4">
          <Panel title="Shot count">
            <div className="space-y-3">
              <div className="text-2xl font-medium font-mono tabular-nums">
                {m.current_shot_count.toLocaleString()}
                <span className="text-sm text-muted"> / {m.max_shots_before_maintenance.toLocaleString()}</span>
              </div>
              <div className="h-1.5 w-full bg-subtle rounded-full overflow-hidden">
                <div className={`h-full ${barColor}`} style={{ width: `${pct}%` }} />
              </div>
              <div className="text-xs font-mono tabular-nums text-muted">{pct.toFixed(1)}% of cycle limit</div>
              <div className="text-xs font-mono tabular-nums text-muted pt-2 border-t border-subtle">
                Lifetime: {m.lifetime_total_shots.toLocaleString()} / {m.lifetime_max_shots.toLocaleString()}
              </div>
            </div>
          </Panel>
        </div>
      </div>
    </div>
  );
}
