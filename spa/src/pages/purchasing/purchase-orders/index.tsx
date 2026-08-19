import { useQuery } from '@tanstack/react-query';
import { useNavigate, Link } from 'react-router-dom';
import { LuPlus, LuPrinter } from '@/lib/icons';
import { purchaseOrdersApi } from '@/api/purchasing/purchase-orders';
import { bulkPrint } from '@/api/print';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type BulkAction, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { focusRing } from '@/lib/focus';
import { cn } from '@/lib/cn';
import type { ListParams } from '@/types';
import type { PurchaseOrder, PurchaseOrderStatus } from '@/types/purchasing';

import { ListEmptyState } from '@/components/ui/ListEmptyState';
const variant: Record<PurchaseOrderStatus, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> =
  {
    draft: 'neutral',
    pending_approval: 'info',
    approved: 'success',
    sent: 'info',
    partially_received: 'warning',
    received: 'success',
    closed: 'neutral',
    cancelled: 'danger',
  };

interface PurchaseOrderListParams extends ListParams {
  status?: string;
  vendor_id?: string;
  requires_vp_approval?: boolean | string;
  overdue?: boolean | string;
  from?: string;
  to?: string;
}

const DEFAULT_FILTERS: PurchaseOrderListParams = {
  page: 1,
  per_page: 25,
  status: 'pending_approval',
};

export default function PurchaseOrdersListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useUrlFilters<PurchaseOrderListParams>(DEFAULT_FILTERS);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['purchasing', 'purchase-orders', filters],
    queryFn: ({ signal }) => purchaseOrdersApi.list(filters, signal),
    placeholderData: (prev) => prev,
  });
  const { data: orderOptions } = useQuery({
    queryKey: ['purchasing', 'purchase-orders', 'options'],
    queryFn: purchaseOrdersApi.options,
    staleTime: 5 * 60 * 1000,
  });
  const statusLabels = new Map(
    (orderOptions?.statuses ?? []).map((option) => [option.value, option.label]),
  );

  const columns: Column<PurchaseOrder>[] = [
    {
      key: 'po',
      header: 'PO #',
      cell: (r) => (
        <span className="flex items-center gap-2">
          <span className="font-mono">{r.po_number}</span>
          {r.is_auto_generated && (
            <span title="Auto-generated for critical stock">
              <Chip variant="info">Auto</Chip>
            </span>
          )}
        </span>
      ),
    },
    {
      key: 'date',
      header: 'Date',
      cell: (r) => <span className="font-mono">{formatDate(r.date)}</span>,
    },
    { key: 'vendor', header: 'Vendor', cell: (r) => r.vendor?.name ?? '—' },
    {
      key: 'eta',
      header: 'Expected',
      cell: (r) => (
        <span
          className={
            'font-mono ' +
            (r.expected_delivery_date &&
            new Date(r.expected_delivery_date) < new Date() &&
            r.status !== 'received'
              ? 'text-danger-fg'
              : '')
          }
        >
          {r.expected_delivery_date ? formatDate(r.expected_delivery_date) : '—'}
        </span>
      ),
    },
    {
      key: 'total',
      header: 'Total',
      align: 'right',
      cell: (r) => <NumCell className="font-medium">{formatPeso(r.total_amount)}</NumCell>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => (
        <span className="flex items-center gap-1.5">
          <Chip variant={variant[r.status]}>
            {statusLabels.get(r.status) ?? r.status.replace(/_/g, ' ')}
          </Chip>
          {r.has_overdue_approval && (
            <span
              title={`Approval pending beyond ${orderOptions?.approval_sla_hours ?? 'configured'} hours`}
            >
              <Chip variant="danger">overdue</Chip>
            </span>
          )}
        </span>
      ),
    },
    {
      key: 'rcv',
      header: 'Received',
      align: 'right',
      cell: (r) => <NumCell>{r.quantity_received_pct.toFixed(0)}%</NumCell>,
    },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: [{ value: '', label: 'All' }, ...(orderOptions?.statuses ?? [])],
    },
    {
      key: 'requires_vp_approval',
      label: 'VP threshold',
      type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'true', label: 'Yes' },
        { value: 'false', label: 'No' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Purchase orders"
        subtitle={data ? `${data.meta.total} POs` : undefined}
        actions={
          can('purchasing.po.create') ? (
            <Button
              variant="primary"
              size="sm"
              icon={<LuPlus size={14} />}
              onClick={() => navigate('/purchasing/purchase-orders/create')}
            >
              New PO
            </Button>
          ) : null
        }
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(s) => setFilters((f) => ({ ...f, search: s, page: 1 }))}
        onFilter={(k, v) => setFilters((f) => ({ ...f, [k]: v, page: 1 }))}
        searchPlaceholder="Search PO number…"
      />
      {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load POs"
          action={<Button onClick={() => refetch()}>Retry</Button>}
        />
      )}
      {data && (
        <ApprovalQueue
          rows={data.data}
          onOpen={(po) => navigate(`/purchasing/purchase-orders/${po.id}`)}
        />
      )}

      {data && data.data.length === 0 && (
        <ListEmptyState />
      )}

      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            tableKey="purchase-orders"
            onRowClick={(r) => navigate(`/purchasing/purchase-orders/${r.id}`)}
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
            selectable
            bulkActions={[
              {
                label: 'Print PDFs',
                icon: <LuPrinter size={14} />,
                onClick: (rows: PurchaseOrder[]) =>
                  bulkPrint(
                    'purchase_order',
                    rows.map((r) => r.id),
                  ),
              } as BulkAction<PurchaseOrder>,
            ]}
          />
        </div>
      )}
    </div>
  );
}

/**
 * What is waiting on a person, and how much money is parked behind it.
 *
 * The four StatCards this replaced counted `data.data.filter(...)` over a 25-row
 * page — draft / pending / approved / total value — and presented them as
 * headline KPIs. Three carried a `linkTo` that re-filtered the whole dataset, so
 * the number changed after the click, and "Total Value" summed one page of POs
 * into a peso figure that read as the plant's committed spend.
 *
 * The axis is wrong as well as the scope. `draft` and `approved` are resting
 * states; nobody opens this page to look at them. A purchasing officer is here
 * to clear the queue, so the queue is what gets the space: what needs approval,
 * what is approved but never sent to the supplier (a PO sitting approved is a
 * material shortage waiting to happen, per CLAUDE.md's Chain 2), and what has
 * been sent but is overdue against `expected_delivery_date`.
 *
 * Money is shown per bucket rather than as one total, because ₱2M waiting on a
 * signature and ₱2M already received mean opposite things.
 */
function ApprovalQueue({
  rows,
  onOpen,
}: {
  rows: PurchaseOrder[];
  onOpen: (po: PurchaseOrder) => void;
}) {
  const dayMs = 86_400_000;
  const today = new Date().setHours(0, 0, 0, 0);
  const peso = (list: PurchaseOrder[]) =>
    formatPeso(list.reduce((sum, p) => sum + Number(p.total_amount ?? 0), 0));

  const pending = rows.filter((p) => p.status === 'pending_approval');
  const unsent = rows.filter((p) => p.status === 'approved');
  const overdue = rows.filter(
    (p) =>
      (p.status === 'sent' || p.status === 'partially_received') &&
      p.expected_delivery_date != null &&
      new Date(p.expected_delivery_date).getTime() < today,
  );

  const buckets = [
    {
      key: 'pending',
      label: 'Waiting on approval',
      items: pending,
      note: 'Blocked until someone signs',
      tone: 'text-warning',
      href: '?status=pending_approval',
    },
    {
      key: 'unsent',
      label: 'Approved, not sent',
      items: unsent,
      note: 'Supplier has not been told yet',
      tone: 'text-warning',
      href: '?status=approved',
    },
    {
      key: 'overdue',
      label: 'Overdue delivery',
      items: overdue,
      note: 'Past expected delivery date',
      tone: 'text-danger',
      href: '?status=sent',
    },
  ];

  const oldestDays = (list: PurchaseOrder[]) =>
    list.reduce(
      (max, p) => Math.max(max, Math.floor((today - new Date(p.date).getTime()) / dayMs)),
      0,
    );

  if (buckets.every((b) => b.items.length === 0)) return null;

  return (
    <section className="px-5 py-4 border-b border-default bg-canvas" aria-labelledby="po-queue">
      <div className="flex items-baseline justify-between mb-2.5">
        <h2 id="po-queue" className="text-sm font-medium text-primary">
          Needs action
        </h2>
        <span className="text-xs text-muted">scoped to this page</span>
      </div>
      <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
        {buckets.map((b) => (
          <Link
            key={b.key}
            to={b.href}
            className={cn(
              'block rounded-md border border-default p-3',
              'hover:bg-elevated transition-colors duration-fast',
              b.items.length === 0 && 'opacity-60',
              focusRing,
            )}
          >
            <div
              className={cn(
                'text-2xs uppercase tracking-wider font-medium',
                b.items.length > 0 ? b.tone : 'text-muted',
              )}
            >
              {b.label}
            </div>
            <div className="mt-0.5 flex items-baseline gap-2">
              <span className="font-mono tabular-nums text-2xl font-medium">{b.items.length}</span>
              {b.items.length > 0 && (
                <span className="font-mono tabular-nums text-sm text-secondary">
                  {peso(b.items)}
                </span>
              )}
            </div>
            <p className="mt-1 text-xs text-muted">
              {b.items.length === 0 ? 'nothing here' : `${b.note} · oldest ${oldestDays(b.items)}d`}
            </p>
          </Link>
        ))}
      </div>

      {/* The single most urgent row gets a direct route, so clearing the top of
          the queue does not require reading the table first. */}
      {pending.length > 0 && (
        <button
          type="button"
          onClick={() => onOpen(pending[0])}
          className={cn(
            'mt-3 w-full rounded-md border border-default bg-surface px-3 py-2 text-left text-sm',
            'hover:bg-elevated transition-colors duration-fast cursor-pointer',
            focusRing,
          )}
        >
          <span className="text-muted">Oldest awaiting approval: </span>
          <span className="font-mono tabular-nums">{pending[0].po_number}</span>
          <span className="text-muted"> · {pending[0].vendor?.name ?? 'no vendor'} · </span>
          <span className="font-mono tabular-nums">{formatPeso(pending[0].total_amount)}</span>
        </button>
      )}
    </section>
  );
}
