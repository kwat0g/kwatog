/**
 * Sprint 7 — Task 60 — Inspections list page.
 *
 * Filterable by stage and status. Each row links to the inspection detail
 * page where measurements are recorded and the inspection finalised.
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { inspectionsApi, type InspectionListParams } from '@/api/quality/inspections';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { Inspection, InspectionStatus } from '@/types/quality';

const STATUS_CHIP: Record<InspectionStatus, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  draft: 'neutral',
  in_progress: 'info',
  passed: 'success',
  failed: 'danger',
  cancelled: 'neutral',
};

export default function InspectionsListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<InspectionListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['quality', 'inspections', filters],
    queryFn: () => inspectionsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<Inspection>[] = [
    {
      key: 'inspection_number',
      header: 'Inspection',
      cell: (r) => (
        <Link to={`/quality/inspections/${r.id}`} className="font-mono text-accent hover:underline">
          {r.inspection_number}
        </Link>
      ),
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
        ) : (
          <span className="text-muted">—</span>
        ),
    },
    {
      key: 'stage',
      header: 'Stage',
      cell: (r) => (
        <Chip variant="neutral">
          {r.stage === 'in_process' ? 'In-process' : r.stage === 'incoming' ? 'Incoming' : 'Outgoing'}
        </Chip>
      ),
    },
    {
      key: 'sample',
      header: 'Sample / Batch',
      align: 'right',
      cell: (r) => (
        <NumCell>
          {r.sample_size} / {r.batch_quantity}
          {r.aql_code ? <span className="ml-2 text-muted">[{r.aql_code}]</span> : null}
        </NumCell>
      ),
    },
    {
      key: 'defects',
      header: 'Defects (Ac)',
      align: 'right',
      cell: (r) => (
        <NumCell className={r.defect_count > r.accept_count ? 'text-danger' : ''}>
          {r.defect_count} ({r.accept_count})
        </NumCell>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => <Chip variant={STATUS_CHIP[r.status]}>{r.status.replace('_', ' ')}</Chip>,
    },
    {
      key: 'completed',
      header: 'Completed',
      align: 'right',
      cell: (r) => <NumCell>{r.completed_at?.slice(0, 10) ?? '—'}</NumCell>,
    },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'stage',
      label: 'Stage',
      type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'incoming', label: 'Incoming' },
        { value: 'in_process', label: 'In-process' },
        { value: 'outgoing', label: 'Outgoing' },
      ],
    },
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'draft', label: 'Draft' },
        { value: 'in_progress', label: 'In progress' },
        { value: 'passed', label: 'Passed' },
        { value: 'failed', label: 'Failed' },
        { value: 'cancelled', label: 'Cancelled' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Inspections"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'inspection' : 'inspections'}` : undefined}
        actions={
          can('quality.inspections.manage') ? (
            <Button
              variant="primary"
              size="sm"
              icon={<Plus size={14} />}
              onClick={() => navigate('/quality/inspections/new')}
            >
              New inspection
            </Button>
          ) : undefined
        }
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by inspection number or product…"
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
      {data && data.data.length === 0 && (
        <EmptyState
          icon="clipboard-check"
          title="No inspections yet"
          description="Create one from a GRN, work order, or finished batch to start logging measurements."
        />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
          />
        </div>
      )}
    </div>
  );
}
