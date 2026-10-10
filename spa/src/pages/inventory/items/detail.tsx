import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { LuPencil, LuShieldCheck } from '@/lib/icons';
import { itemsApi } from '@/api/inventory/items';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatQuantity } from '@/lib/formatNumber';
import { ItemUomConversions } from './uom-conversions';

const stockChipVariant = {
  ok: 'success' as const,
  low: 'warning' as const,
  critical: 'danger' as const,
};

export default function ItemDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { can } = usePermission();
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'items', id],
    queryFn: () => itemsApi.show(id),
    enabled: !!id,
  });
  const { data: itemOptions } = useQuery({
    queryKey: ['inventory', 'items', 'options'],
    queryFn: itemsApi.options,
    staleTime: 300_000,
  });
  const reorderMethodLabel = itemOptions?.reorder_methods?.find(
    (option) => option.value === data?.reorder_method,
  )?.label;
  const stockStatusLabel = itemOptions?.stock_statuses?.find(
    (option) => option.value === data?.stock_status,
  )?.label;

  if (isLoading) return <SkeletonTable rows={6} columns={4} />;
  if (isError || !data)
    return (
      <EmptyState
        icon="alert-circle"
        title="Failed to load item"
        action={
          <Button variant="secondary" onClick={() => refetch()}>
            Retry
          </Button>
        }
      />
    );

  return (
    <div>
      <PageHeader
        title={
          <span>
            <span className="font-mono">{data.code}</span> · {data.name}
          </span>
        }
        backTo="/inventory/items"
        backLabel="Items"
        actions={
          <div className="flex items-center gap-2">
            <Chip variant={stockChipVariant[data.stock_status]}>
              {stockStatusLabel ?? data.stock_status}
            </Chip>
            {data.is_critical && <Chip variant="danger">Critical</Chip>}
            {!data.is_active && <Chip variant="neutral">Inactive</Chip>}
            <Chip variant={data.quality_plan_ready ? 'success' : 'warning'}>
              {data.quality_plan_ready ? 'Quality plan ready' : 'Quality plan missing'}
            </Chip>
            {can('inventory.items.manage') && (
              <Button
                variant="secondary"
                size="sm"
                icon={<LuPencil size={14} />}
                onClick={() => navigate(`/inventory/items/${data.id}/edit`)}
              >
                Edit
              </Button>
            )}
          </div>
        }
      />
      <div className="px-5 py-4 space-y-4">
        {data.classification_needs_review && (
          <div
            role="alert"
            className="rounded-md border border-warning bg-warning-bg p-4 text-sm text-warning-fg"
          >
            Category classification needs review. Inventory remains {data.item_type_label}; edit the
            item to choose a matching category.
          </div>
        )}
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
          <StatCard
            label="On hand"
            value={formatQuantity(data.on_hand_quantity)}
            helper={data.unit_of_measure}
          />
          <StatCard
            label="Reserved"
            value={formatQuantity(data.reserved_quantity)}
            helper={data.unit_of_measure}
          />
          <StatCard
            label="Usable now"
            value={formatQuantity(data.available_quantity)}
            helper={data.unit_of_measure}
          />
          <StatCard
            label="QC held"
            value={formatQuantity(data.quality_held_quantity ?? 0)}
            helper={data.unit_of_measure}
          />
          <StatCard
            label="Quarantine / scrap"
            value={formatQuantity(data.unusable_zone_quantity ?? 0)}
            helper={data.unit_of_measure}
          />
          {data.approved_free_stock !== undefined && <StatCard label="Approved free for orders" value={formatQuantity(data.approved_free_stock)} helper="QC approved; safety stock retained" />}
          {data.incoming_stock_quantity !== undefined && <StatCard label="Incoming stock production" value={formatQuantity(data.incoming_stock_quantity)} helper="Production and QC remain required" />}
          <StatCard label="Standard cost" value={Number(data.standard_cost).toFixed(4)} />
        </div>
        <Panel title="Specifications">
          <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-y-3 gap-x-6 text-sm">
            <div>
              <dt className="text-2xs uppercase tracking-wider text-muted">Category</dt>
              <dd>{data.category?.name ?? '—'}</dd>
            </div>
            <div>
              <dt className="text-2xs uppercase tracking-wider text-muted">Item type</dt>
              <dd>{data.item_type_label}</dd>
            </div>
            <div>
              <dt className="text-2xs uppercase tracking-wider text-muted">Unit of measure</dt>
              <dd>{data.unit_of_measure}</dd>
            </div>
            <div>
              <dt className="text-2xs uppercase tracking-wider text-muted">Reorder method</dt>
              <dd>{reorderMethodLabel ?? data.reorder_method}</dd>
            </div>
            <div>
              <dt className="text-2xs uppercase tracking-wider text-muted">Reorder point</dt>
              <dd className="font-mono tabular-nums">{formatQuantity(data.reorder_point)}</dd>
            </div>
            <div>
              <dt className="text-2xs uppercase tracking-wider text-muted">Safety stock</dt>
              <dd className="font-mono tabular-nums">{formatQuantity(data.safety_stock)}</dd>
            </div>
            <div>
              <dt className="text-2xs uppercase tracking-wider text-muted">MOQ</dt>
              <dd className="font-mono tabular-nums">
                {formatQuantity(data.minimum_order_quantity)}
              </dd>
            </div>
            <div>
              <dt className="text-2xs uppercase tracking-wider text-muted">Lead time</dt>
              <dd className="font-mono tabular-nums">
                {data.lead_time_days ||
                  itemOptions?.replenishment_basis?.default_lead_time_days ||
                  0}{' '}
                days
                {data.lead_time_days === 0 && <span className="ml-1 text-muted">(default)</span>}
              </dd>
            </div>
            <div>
              <dt className="text-2xs uppercase tracking-wider text-muted">Description</dt>
              <dd>{data.description ?? '—'}</dd>
            </div>
          </dl>
        </Panel>
        {data.reorder_method === 'days_of_supply' && itemOptions?.replenishment_basis && (
          <p className="text-sm text-muted mb-4">
            Replenishment uses average daily issues, deliveries, and scrap over{' '}
            {itemOptions.replenishment_basis.demand_history_days} days, multiplied by effective lead
            time and a {itemOptions.replenishment_basis.coverage_buffer_ratio} coverage buffer.
            Minimum order quantity rounds the result up. Open purchases and pending QC receipts
            reduce the shortage.
          </p>
        )}
        <ItemUomConversions itemId={data.id} baseUnit={data.unit_of_measure} />
        <Panel title="Quick actions">
          <div className="flex flex-wrap gap-2">
            <Link
              to={`/inventory/stock-levels?item_id=${data.id}`}
              className="text-sm text-accent hover:underline"
            >
              View stock levels
            </Link>
            <span className="text-muted">·</span>
            <Link
              to={`/inventory/stock-levels?view=movements&item_id=${data.id}`}
              className="text-sm text-accent hover:underline"
            >
              View movements
            </Link>
            <span className="text-muted">·</span>
            <Link
              to={`/inventory/items/${data.id}/quality-plans`}
              className="text-sm text-accent hover:underline inline-flex items-center gap-1"
            >
              <LuShieldCheck size={12} /> Quality plans
            </Link>
          </div>
        </Panel>
      </div>
    </div>
  );
}
