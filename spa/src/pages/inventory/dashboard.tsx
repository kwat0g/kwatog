import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { inventoryDashboardApi } from '@/api/inventory/dashboard';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { formatPeso } from '@/lib/formatNumber';

const movementChip = (t: string): 'success' | 'info' | 'warning' | 'danger' | 'neutral' => {
  if (t === 'grn_receipt' || t === 'production_receipt' || t === 'adjustment_in') return 'success';
  if (t === 'material_issue' || t === 'delivery') return 'info';
  if (t === 'adjustment_out' || t === 'transfer') return 'warning';
  if (t === 'scrap' || t === 'return_to_vendor') return 'danger';
  return 'neutral';
};

export default function InventoryDashboardPage() {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'dashboard'],
    queryFn: () => inventoryDashboardApi.summary(),
    refetchInterval: 30_000,
  });

  return (
    <div>
      <PageHeader title="Inventory" subtitle="Live snapshot · refreshes every 30s" />
      <div className="px-5 py-4 space-y-4">
        {isLoading && !data && <SkeletonTable rows={6} columns={4} />}
        {isError && <EmptyState icon="alert-circle" title="Failed to load dashboard" action={<Button onClick={() => refetch()}>Retry</Button>} />}
        {data && (
          <>
            <div className="grid grid-cols-4 gap-3">
              <StatCard label="Total stock value" value={formatPeso(data.total_stock_value)} />
              <StatCard label="Items below reorder" value={data.items_below_reorder.toString()} />
              <StatCard label="Critical low" value={data.items_critical.toString()}
                         />
              <StatCard label="Pending GRNs" value={data.pending_grns.toString()} />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Panel title="Low stock alerts" className="col-span-1">
                {data.low_stock_alerts.length === 0
                  ? <div className="text-sm text-muted px-1">All items are above reorder point.</div>
                  : (
                    <table className="w-full text-xs">
                      <thead>
                        <tr className="text-2xs uppercase tracking-wider text-muted">
                          <th className="text-left py-1">Item</th>
                          <th className="text-right">Available</th>
                          <th className="text-right">Reorder</th>
                          <th className="text-left">Chain</th>
                        </tr>
                      </thead>
                      <tbody>
                        {data.low_stock_alerts.map((a) => (
                          <tr key={a.code} className="h-8 border-t border-subtle">
                            <td>
                              <div className="font-mono">{a.code}</div>
                              <div className="text-2xs text-muted">{a.name}</div>
                            </td>
                            <td className="text-right font-mono tabular-nums text-danger-fg">{Number(a.available).toFixed(3)}</td>
                            <td className="text-right font-mono tabular-nums">{Number(a.reorder_point).toFixed(3)}</td>
                            <td>
                              {a.open_pr
                                ? <Chip variant="warning">PR {a.open_pr.number}</Chip>
                                : a.open_po
                                  ? <Chip variant="info">PO {a.open_po.number}</Chip>
                                  : <Chip variant="danger">No PR</Chip>}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
              </Panel>
              <Panel title="Recent movements">
                <ul className="text-xs divide-y divide-subtle">
                  {data.recent_movements.slice(0, 10).map((m) => (
                    <li key={m.id} className="py-1.5 flex items-center gap-2">
                      <Chip variant={movementChip(m.movement_type)}>{m.movement_type.replace(/_/g, ' ')}</Chip>
                      <span className="font-mono">{m.item?.code}</span>
                      <span className="text-muted truncate">{m.item?.name}</span>
                      <span className="ml-auto font-mono tabular-nums">{Number(m.quantity).toFixed(3)}</span>
                    </li>
                  ))}
                </ul>
              </Panel>
            </div>
            <Panel title="Top consumed materials (30 days)">
              <table className="w-full text-xs">
                <thead>
                  <tr className="text-2xs uppercase tracking-wider text-muted">
                    <th className="text-left py-1">Item</th>
                    <th className="text-right">Quantity</th>
                    <th className="text-right">Total value</th>
                  </tr>
                </thead>
                <tbody>
                  {data.top_consumed_materials.length === 0 && (
                    <tr><td className="text-muted py-2" colSpan={3}>No issuance in last 30 days.</td></tr>
                  )}
                  {data.top_consumed_materials.map((m) => (
                    <tr key={m.id} className="h-8 border-t border-subtle">
                      <td><Link to={`/inventory/items/${m.id}`} className="font-mono text-accent">{m.code}</Link> {m.name}</td>
                      <td className="text-right font-mono tabular-nums">{Number(m.qty).toFixed(3)} {m.unit_of_measure}</td>
                      <td className="text-right font-mono tabular-nums">{formatPeso(m.total_value)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Panel>
          </>
        )}
      </div>
    </div>
  );
}
