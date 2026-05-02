import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Pencil } from 'lucide-react';
import { itemsApi } from '@/api/inventory/items';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';

const stockChipVariant = { ok: 'success' as const, low: 'warning' as const, critical: 'danger' as const };

export default function ItemDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { can } = usePermission();
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'items', id],
    queryFn: () => itemsApi.show(id),
    enabled: !!id,
  });

  if (isLoading) return <SkeletonTable rows={6} columns={4} />;
  if (isError || !data) return (
    <EmptyState icon="alert-circle" title="Failed to load item"
      action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
  );

  return (
    <div>
      <PageHeader
        title={<span><span className="font-mono">{data.code}</span> · {data.name}</span>}
        backTo="/inventory/items" backLabel="Items"
        actions={
          <div className="flex items-center gap-2">
            <Chip variant={stockChipVariant[data.stock_status]}>{data.stock_status}</Chip>
            {data.is_critical && <Chip variant="danger">Critical</Chip>}
            {!data.is_active && <Chip variant="neutral">Inactive</Chip>}
            {can('inventory.items.manage') && (
              <Button variant="secondary" size="sm" icon={<Pencil size={14} />}
                      onClick={() => navigate(`/inventory/items/${data.id}/edit`)}>
                Edit
              </Button>
            )}
          </div>
        }
      />
      <div className="px-5 py-4 space-y-4">
        <div className="grid grid-cols-4 gap-3">
          <StatCard label="On hand" value={Number(data.on_hand_quantity).toFixed(3)} helper={data.unit_of_measure} />
          <StatCard label="Reserved" value={Number(data.reserved_quantity).toFixed(3)} helper={data.unit_of_measure} />
          <StatCard label="Available" value={Number(data.available_quantity).toFixed(3)} helper={data.unit_of_measure} />
          <StatCard label="Standard cost" value={Number(data.standard_cost).toFixed(4)} helper="₱" />
        </div>
        <Panel title="Specifications">
          <dl className="grid grid-cols-3 gap-y-3 gap-x-6 text-sm">
            <div><dt className="text-2xs uppercase tracking-wider text-muted">Category</dt><dd>{data.category?.name ?? '—'}</dd></div>
            <div><dt className="text-2xs uppercase tracking-wider text-muted">Item type</dt><dd>{data.item_type_label}</dd></div>
            <div><dt className="text-2xs uppercase tracking-wider text-muted">Unit of measure</dt><dd>{data.unit_of_measure}</dd></div>
            <div><dt className="text-2xs uppercase tracking-wider text-muted">Reorder method</dt><dd>{data.reorder_method.replace('_', ' ')}</dd></div>
            <div><dt className="text-2xs uppercase tracking-wider text-muted">Reorder point</dt><dd className="font-mono tabular-nums">{Number(data.reorder_point).toFixed(3)}</dd></div>
            <div><dt className="text-2xs uppercase tracking-wider text-muted">Safety stock</dt><dd className="font-mono tabular-nums">{Number(data.safety_stock).toFixed(3)}</dd></div>
            <div><dt className="text-2xs uppercase tracking-wider text-muted">MOQ</dt><dd className="font-mono tabular-nums">{Number(data.minimum_order_quantity).toFixed(3)}</dd></div>
            <div><dt className="text-2xs uppercase tracking-wider text-muted">Lead time</dt><dd className="font-mono tabular-nums">{data.lead_time_days} days</dd></div>
            <div><dt className="text-2xs uppercase tracking-wider text-muted">Description</dt><dd>{data.description ?? '—'}</dd></div>
          </dl>
        </Panel>
        <Panel title="Quick actions">
          <div className="flex flex-wrap gap-2">
            <Link to={`/inventory/stock-levels?item_id=${data.id}`} className="text-sm text-accent hover:underline">View stock levels</Link>
            <span className="text-muted">·</span>
            <Link to={`/inventory/movements?item_id=${data.id}`} className="text-sm text-accent hover:underline">View movements</Link>
          </div>
        </Panel>
      </div>
    </div>
  );
}
