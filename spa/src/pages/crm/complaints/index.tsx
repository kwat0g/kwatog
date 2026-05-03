/** Sprint 7 — Task 68 — Customer complaints list. */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { complaintsApi, type ComplaintListParams } from '@/api/crm/complaints';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { CustomerComplaint, ComplaintSeverity, ComplaintStatus } from '@/types/crm';

const STATUS_CHIP: Record<ComplaintStatus, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  open: 'warning', investigating: 'info', resolved: 'info', closed: 'success', cancelled: 'neutral',
};
const SEVERITY_CHIP: Record<ComplaintSeverity, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  low: 'neutral', medium: 'info', high: 'warning', critical: 'danger',
};

export default function ComplaintsListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<ComplaintListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['crm', 'complaints', filters],
    queryFn: () => complaintsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<CustomerComplaint>[] = [
    { key: 'complaint_number', header: 'Complaint',
      cell: (r) => <Link to={`/crm/complaints/${r.id}`} className="font-mono text-accent hover:underline">{r.complaint_number}</Link> },
    { key: 'customer', header: 'Customer', cell: (r) => r.customer?.name ?? '—' },
    { key: 'product', header: 'Product',
      cell: (r) => r.product ? (
        <span><span className="font-mono">{r.product.part_number}</span><span className="ml-2 text-muted">{r.product.name}</span></span>
      ) : <span className="text-muted">—</span> },
    { key: 'severity', header: 'Severity',
      cell: (r) => <Chip variant={SEVERITY_CHIP[r.severity]}>{r.severity}</Chip> },
    { key: 'qty', header: 'Qty', align: 'right',
      cell: (r) => <NumCell>{r.affected_quantity}</NumCell> },
    { key: 'received', header: 'Received', align: 'right',
      cell: (r) => <NumCell>{r.received_date ?? '—'}</NumCell> },
    { key: 'status', header: 'Status',
      cell: (r) => <Chip variant={STATUS_CHIP[r.status]}>{r.status}</Chip> },
  ];

  const filterConfig: FilterConfig[] = [
    { key: 'status', label: 'Status', type: 'select', options: [
      { value: '', label: 'All' },
      { value: 'open', label: 'Open' },
      { value: 'investigating', label: 'Investigating' },
      { value: 'resolved', label: 'Resolved' },
      { value: 'closed', label: 'Closed' },
    ]},
    { key: 'severity', label: 'Severity', type: 'select', options: [
      { value: '', label: 'All' },
      { value: 'low', label: 'Low' },
      { value: 'medium', label: 'Medium' },
      { value: 'high', label: 'High' },
      { value: 'critical', label: 'Critical' },
    ]},
  ];

  return (
    <div>
      <PageHeader
        title="Customer complaints"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'complaint' : 'complaints'}` : undefined}
        actions={can('crm.complaints.manage') ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />}
            onClick={() => navigate('/crm/complaints/new')}>
            New complaint
          </Button>
        ) : undefined}
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by complaint number or description…"
      />
      {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
      {isError && (
        <EmptyState icon="alert-circle" title="Failed to load complaints"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
      )}
      {data && data.data.length === 0 && (
        <EmptyState icon="message-square" title="No complaints yet"
          description="Customer complaints filed by CRM officers will appear here. Each complaint auto-creates an NCR." />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={data.data} meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))} />
        </div>
      )}
    </div>
  );
}
