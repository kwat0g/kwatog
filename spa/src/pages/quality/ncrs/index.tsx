/**
 * Sprint 7 — Task 64 — NCR list page.
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { ncrsApi, type NcrListParams } from '@/api/quality/ncrs';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { Ncr, NcrSeverity, NcrStatus } from '@/types/quality';

const STATUS_CHIP: Record<NcrStatus, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  open: 'warning',
  in_progress: 'info',
  closed: 'success',
  cancelled: 'neutral',
};

const SEVERITY_CHIP: Record<NcrSeverity, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  low: 'neutral',
  medium: 'info',
  high: 'warning',
  critical: 'danger',
};

export default function NcrsListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<NcrListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['quality', 'ncrs', filters],
    queryFn: () => ncrsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<Ncr>[] = [
    {
      key: 'ncr_number',
      header: 'NCR',
      cell: (r) => (
        <Link to={`/quality/ncrs/${r.id}`} className="font-mono text-accent hover:underline">
          {r.ncr_number}
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
      key: 'source',
      header: 'Source',
      cell: (r) => <Chip variant="neutral">{r.source.replace('_', ' ')}</Chip>,
    },
    {
      key: 'severity',
      header: 'Severity',
      cell: (r) => <Chip variant={SEVERITY_CHIP[r.severity]}>{r.severity}</Chip>,
    },
    {
      key: 'affected_quantity',
      header: 'Qty',
      align: 'right',
      cell: (r) => <NumCell>{r.affected_quantity}</NumCell>,
    },
    {
      key: 'disposition',
      header: 'Disposition',
      cell: (r) =>
        r.disposition ? (
          <Chip variant="neutral">{r.disposition.replace('_', ' ')}</Chip>
        ) : (
          <span className="text-muted">—</span>
        ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => <Chip variant={STATUS_CHIP[r.status]}>{r.status.replace('_', ' ')}</Chip>,
    },
    {
      key: 'closed',
      header: 'Closed',
      align: 'right',
      cell: (r) => <NumCell>{r.closed_at?.slice(0, 10) ?? '—'}</NumCell>,
    },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'open', label: 'Open' },
        { value: 'in_progress', label: 'In progress' },
        { value: 'closed', label: 'Closed' },
        { value: 'cancelled', label: 'Cancelled' },
      ],
    },
    {
      key: 'severity',
      label: 'Severity',
      type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'low', label: 'Low' },
        { value: 'medium', label: 'Medium' },
        { value: 'high', label: 'High' },
        { value: 'critical', label: 'Critical' },
      ],
    },
    {
      key: 'source',
      label: 'Source',
      type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'inspection_fail', label: 'Inspection fail' },
        { value: 'customer_complaint', label: 'Customer complaint' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Non-conformance reports"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'NCR' : 'NCRs'}` : undefined}
        actions={
          can('quality.ncr.manage') ? (
            <Button
              variant="primary"
              size="sm"
              icon={<Plus size={14} />}
              onClick={() => navigate('/quality/ncrs/new')}
            >
              New NCR
            </Button>
          ) : undefined
        }
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by NCR number or description…"
      />
      {isLoading && !data && <SkeletonTable columns={8} rows={6} />}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load NCRs"
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      )}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="alert-triangle"
          title="No NCRs"
          description="When an inspection fails or a customer complaint is filed, a non-conformance report will appear here."
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
