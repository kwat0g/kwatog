/**
 * Series C — Task C5. Chain Bottleneck Widget.
 *
 * Renders the per-step count of stuck records on the dashboard. Pass
 * `audience` to scope the widget to a single role (Finance only sees
 * its own bottlenecks, etc.). Without `audience`, every group is shown.
 *
 * Mounts inside any dashboard page; talks to GET /chain/bottlenecks.
 */
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { chainApi } from '@/api/chain';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import type { ChainBottleneckGroup, ChainBottleneckRow } from '@/types/chain';

interface Props {
  audience?: string;
  /** Optional title override. Default: "Chain bottlenecks". */
  title?: string;
  /** Hide the widget entirely when there is nothing stuck. */
  hideWhenEmpty?: boolean;
}

export function ChainBottleneckWidget({ audience, title = 'Chain bottlenecks', hideWhenEmpty = false }: Props) {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['chain-bottlenecks', audience ?? 'all'],
    queryFn: () => chainApi.bottlenecks(audience),
    refetchInterval: 60_000,
    staleTime: 60_000,
  });

  // ─── LOADING ───
  if (isLoading) {
    return (
      <Panel title={title} meta="Refreshes every 60s">
        <div className="space-y-2">
          {[0, 1, 2].map((i) => (
            <SkeletonBlock key={i} className="h-8 w-full" />
          ))}
        </div>
      </Panel>
    );
  }

  // ─── ERROR ───
  if (isError) {
    return (
      <Panel title={title}>
        <EmptyState
          icon="alert-circle"
          title="Failed to load bottlenecks"
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      </Panel>
    );
  }

  const groups = (data?.groups ?? []).filter((g): g is ChainBottleneckGroup => g.count > 0);

  // ─── EMPTY (nothing stuck — good news) ───
  if (groups.length === 0) {
    if (hideWhenEmpty) return null;
    return (
      <Panel title={title} meta="Refreshes every 60s">
        <EmptyState
          icon="inbox"
          title="No bottlenecks"
          description="Every chain step is moving within its SLA."
        />
      </Panel>
    );
  }

  // ─── DATA ───
  const total = data?.total ?? 0;
  return (
    <Panel
      title={title}
      meta={`${total} stuck`}
      bodyClassName="p-0"
    >
      <ul>
        {groups.map((g) => (
          <li
            key={g.key}
            className="flex items-center justify-between px-4 py-2.5 border-b border-subtle last:border-b-0"
          >
            <div className="min-w-0 flex-1">
              <div className="text-sm text-primary truncate">{g.label}</div>
              <div className="text-xs text-muted truncate">
                {g.rows
                  .slice(0, 3)
                  .map((r: ChainBottleneckRow) => r.doc_number)
                  .join(', ')}
                {g.rows.length > 3 ? ` +${g.rows.length - 3} more` : ''}
              </div>
            </div>
            <div className="flex items-center gap-2 ml-3">
              <Chip variant={g.count >= 5 ? 'danger' : 'warning'}>
                <span className="font-mono tabular-nums">{g.count}</span>
              </Chip>
              <Link
                to={destinationFor(g.rows[0])}
                className="text-xs text-accent hover:underline"
              >
                View
              </Link>
            </div>
          </li>
        ))}
      </ul>
    </Panel>
  );
}

/**
 * Resolve the destination for the "View" link given the first row in the
 * group. Each entity type has its own list page.
 */
function destinationFor(row: ChainBottleneckRow | undefined): string {
  if (!row) return '#';
  switch (row.entity_type) {
    case 'sales_order':      return `/crm/sales-orders/${row.entity_id}`;
    case 'work_order':       return `/production/work-orders/${row.entity_id}`;
    case 'inspection':       return `/quality/inspections/${row.entity_id}`;
    case 'delivery':         return `/supply-chain/deliveries/${row.entity_id}`;
    case 'invoice':          return `/accounting/invoices/${row.entity_id}`;
    case 'purchase_request': return `/purchasing/purchase-requests/${row.entity_id}`;
    case 'bill':             return `/accounting/bills/${row.entity_id}`;
    default:                 return '#';
  }
}
