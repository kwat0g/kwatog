import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { materialIssuesApi } from '@/api/inventory/material-issues';
import { Chip } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { FilterBar } from '@/components/ui/FilterBar';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDate } from '@/lib/formatDate';
import type { ListParams } from '@/types';
import type { MaterialIssueSlip } from '@/types/inventory';
import { formatPeso } from '@/lib/formatNumber';

import { ListEmptyState } from '@/components/ui/ListEmptyState';
interface MaterialIssueListParams extends ListParams {
  search?: string;
  status?: string;
  date?: string;
  from?: string;
  to?: string;
}

const DEFAULT_FILTERS: MaterialIssueListParams = {
  search: '', page: 1, per_page: 25,
};

export default function MaterialIssuesListPage() {
  const navigate = useNavigate();
  const [filters, setFilters] = useUrlFilters<MaterialIssueListParams>(DEFAULT_FILTERS);

  // Dashboard KPI links to ?date=today — expand to a from/to range at mount
  // because the backend filters on issued_date via from/to.
  useEffect(() => {
    if (filters.date === 'today') {
      const now = new Date();
      const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
      setFilters((f) => ({ ...f, date: undefined, from: today, to: today }));
    }
  }, [filters.date, setFilters]);
 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['inventory', 'material-issues', filters],
 queryFn: () => materialIssuesApi.list(filters),
 placeholderData: (prev) => prev,
 });

 const columns: Column<MaterialIssueSlip>[] = [
 { key: 'slip', header: 'Slip', cell: (r) => (
 <span className="font-mono">{r.slip_number}</span>
 ) },
 { key: 'date', header: 'Issued', cell: (r) => <span className="font-mono">{formatDate(r.issued_date)}</span> },
 { key: 'wo', header: 'Work order', cell: (r) => r.work_order_id ? `WO#${r.work_order_id}` : (r.reference_text ?? '—') },
 { key: 'status', header: 'Status', cell: (r) => (
 <Chip variant={r.status === 'issued' ? 'info' : r.status === 'cancelled' ? 'neutral' : 'warning'}>{r.status_label ?? r.status}</Chip>
 ) },
 { key: 'value', header: 'Value', align: 'right', cell: (r) => <span className="font-mono tabular-nums font-medium">{formatPeso(r.total_value)}</span> },
 ];

 return (
 <div>
 <PageHeader title="Material issues" subtitle={data ? `${data.meta.total} slips` : undefined} />
 <div className="px-5 pt-3">
   <FilterBar onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))} searchPlaceholder="Search MIS number..." />
 </div>
 {isLoading && !data && <SkeletonTable rows={6} columns={5} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load" action={<Button onClick={() => refetch()}>Retry</Button>} />}
 {data && data.data.length === 0 && (
 <ListEmptyState />
 )}
 {data && data.data.length > 0 && (
 <div className="px-5 py-4">
  <DataTable
  tableKey="material-issues"
  onRowClick={(r) => navigate(`/inventory/material-issues/${r.id}`)}
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
