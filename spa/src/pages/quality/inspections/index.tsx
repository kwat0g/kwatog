/**
 * Sprint 7 — Task 60 — Inspections list page.
 *
 * Filterable by stage and status. Each row links to the inspection detail
 * page where PASS / FAIL records the result and Details holds the evidence.
 */
import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { inspectionsApi, type InspectionListParams } from '@/api/quality/inspections';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import type { Inspection, InspectionStatus } from '@/types/quality';
import { formatDateIso } from '@/lib/formatDate';

import { ListEmptyState } from '@/components/ui/ListEmptyState';
const STATUS_CHIP: Record<InspectionStatus, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> =
  {
    draft: 'neutral',
    in_progress: 'info',
    awaiting_review: 'warning',
    passed: 'success',
    failed: 'danger',
    cancelled: 'neutral',
  };

const DEFAULT_FILTERS: InspectionListParams = {
  page: 1,
  per_page: 25,
  status: '',
};

export default function InspectionsListPage() {
  const navigate = useNavigate();
  const [filters, setFilters] = useUrlFilters<InspectionListParams>(DEFAULT_FILTERS);

  // Dashboard KPI links to ?date=today — expand to a from/to range at mount
  // because the backend filters on created_at via from/to.
  useEffect(() => {
    if (filters.date === 'today') {
      const now = new Date();
      const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
      setFilters((f) => ({ ...f, date: undefined, from: today, to: today }));
    }
  }, [filters.date, setFilters]);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['quality', 'inspections', filters],
    queryFn: () => inspectionsApi.list(filters),
    placeholderData: (prev) => prev,
  });
  const { data: inspectionOptions } = useQuery({
    queryKey: ['quality', 'inspection-options'],
    queryFn: inspectionsApi.options,
    staleTime: 5 * 60 * 1000,
  });
  const labels = new Map(
    [...(inspectionOptions?.stages ?? []), ...(inspectionOptions?.statuses ?? [])].map((option) => [
      option.value,
      option.label,
    ]),
  );

  const columns: Column<Inspection>[] = [
    {
      key: 'inspection_number',
      header: 'Inspection',
      cell: (r) => <span className="font-mono">{r.inspection_number}</span>,
    },
    {
      key: 'product',
      header: 'Product',
      cell: (r) =>
        r.product ? (
          <span>
            <span className="font-mono">{r.product.part_number}</span>
            <span className="ml-2 text-muted">{r.product.name}</span>
          </span>
        ) : r.item ? (
          <span>
            <span className="font-mono">{r.item.code}</span>
            <span className="ml-2 text-muted">{r.item.name}</span>
          </span>
        ) : (
          <span className="text-muted">—</span>
        ),
    },
    {
      key: 'stage',
      header: 'Stage',
      cell: (r) => <Chip variant="neutral">{labels.get(r.stage) ?? r.stage}</Chip>,
    },
    {
      key: 'batch_quantity',
      header: 'Quantity / coverage',
      align: 'right',
      cell: (r) => (
        <div className="text-right">
          <NumCell>
            {r.work_order_quality?.produced_quantity ?? r.source_quantity ?? r.batch_quantity} {r.source_uom_code ?? 'pieces'}
          </NumCell>
          <p className="text-xs text-muted">
            {r.work_order_quality
              ? `${r.work_order_quality.accepted_quantity} approved${r.work_order_quality.repaired_accepted_quantity ? ` · ${r.work_order_quality.repaired_accepted_quantity} repaired` : ''}`
              : `${r.sample_size} ${r.sampling_basis === 'lot' ? 'lot' : 'pieces'} to inspect`}
          </p>
        </div>
      ),
    },
    {
      key: 'problem_description',
      header: 'Problem',
      cell: (r) => (
        <span
          className="block max-w-xs truncate text-sm"
          title={r.problem_description ?? undefined}
        >
          {r.problem_description ?? (r.status === 'failed' ? 'Open inspection for details' : '—')}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => (
        <div className="space-y-1">
          <Chip variant={r.work_order_quality?.state === 'released' ? 'success' : STATUS_CHIP[r.status]}>
            {r.work_order_quality?.state === 'released' ? 'Quality released' : r.status_label ?? labels.get(r.status) ?? r.status}
          </Chip>
          {r.work_order_quality?.state === 'released' && r.status === 'failed' && (
            <p className="text-xs text-muted">Original QC failed</p>
          )}
        </div>
      ),
    },
    {
      key: 'completed',
      header: 'Completed',
      align: 'right',
      cell: (r) => <NumCell>{formatDateIso(r.completed_at)}</NumCell>,
    },
  ];

  const counts = data?.meta.status_counts;
  const queues = [
    { label: 'To inspect', count: counts?.to_inspect, status: '', work_queue: 'to_inspect' },
    {
      label: filters.signal === 'awaiting_my_review' ? 'Awaiting my review' : 'Awaiting review',
      count: counts?.awaiting_review,
      status: 'awaiting_review',
      work_queue: '',
    },
    { label: 'Failed inspections', count: counts?.failed, status: 'failed', work_queue: '' },
    { label: 'All', count: counts?.all, status: '', work_queue: '' },
  ] as const;
  const filterConfig: FilterConfig[] = [
    {
      key: 'stage',
      label: 'Stage',
      type: 'select',
      options: [{ value: '', label: 'All' }, ...(inspectionOptions?.stages ?? [])],
    },
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: [{ value: '', label: 'All' }, ...(inspectionOptions?.statuses ?? [])],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Inspections"
        subtitle={
          data
            ? `${data.meta.total} ${data.meta.total === 1 ? 'inspection' : 'inspections'}`
            : undefined
        }
      />
      <nav
        aria-label="Inspection work queue"
        className="flex flex-wrap gap-2 border-b border-default px-5 py-3"
      >
        {queues.map((queue) => (
          <Button
            key={queue.label}
            variant={
              filters.status === queue.status && (filters.work_queue ?? '') === queue.work_queue
                ? 'primary'
                : 'secondary'
            }
            size="sm"
            aria-pressed={
              filters.status === queue.status && (filters.work_queue ?? '') === queue.work_queue
            }
            onClick={() =>
              setFilters((f) => ({
                ...f,
                page: 1,
                status: queue.status,
                work_queue: queue.work_queue,
                signal: '',
              }))
            }
          >
            {queue.label} {queue.count != null ? `(${queue.count})` : ''}
          </Button>
        ))}
      </nav>
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) =>
          setFilters((f) => ({
            ...f,
            [key]: value,
            ...(key === 'status' ? { work_queue: '' } : {}),
            page: 1,
          }))
        }
        searchPlaceholder="Search inspection, product, WO, GRN, or return…"
        dateRange={{ fromKey: 'from', toKey: 'to', label: 'Date' }}
      />
      {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load inspections"
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      )}

      {data && data.data.length === 0 && <ListEmptyState />}

      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            tableKey="inspections"
            onRowClick={(r) => navigate(r.final_work_order_id ? `/quality/work-orders/${r.final_work_order_id}/final-inspection` : `/quality/inspections/${r.id}`)}
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
            onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
          />
        </div>
      )}
    </div>
  );
}
