import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { materialIssuesApi } from '@/api/inventory/material-issues';
import { Chip } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDate } from '@/lib/formatDate';
import type { MaterialIssueSlip } from '@/types/inventory';

export default function MaterialIssuesListPage() {
  const [filters, setFilters] = useState<any>({ page: 1, per_page: 25 });
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'material-issues', filters],
    queryFn: () => materialIssuesApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<MaterialIssueSlip>[] = [
    { key: 'slip', header: 'Slip', cell: (r) => (
      <Link to={`/inventory/material-issues/${r.id}`} className="font-mono text-accent">{r.slip_number}</Link>
    ) },
    { key: 'date', header: 'Issued', cell: (r) => <span className="font-mono">{formatDate(r.issued_date)}</span> },
    { key: 'wo', header: 'Work order', cell: (r) => r.work_order_id ? `WO#${r.work_order_id}` : (r.reference_text ?? '—') },
    { key: 'status', header: 'Status', cell: (r) => (
      <Chip variant={r.status === 'issued' ? 'info' : r.status === 'cancelled' ? 'neutral' : 'warning'}>{r.status}</Chip>
    ) },
    { key: 'value', header: 'Value', align: 'right', cell: (r) => <span className="font-mono tabular-nums font-medium">₱ {Number(r.total_value).toFixed(2)}</span> },
  ];

  return (
    <div>
      <PageHeader title="Material issues" subtitle={data ? `${data.meta.total} slips` : undefined} />
      {isLoading && !data && <SkeletonTable rows={6} columns={5} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load" action={<Button onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState icon="inbox" title="No issuance yet"
          description="Material issuance is wired in Sprint 6 once work orders exist." />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={data.data} meta={data.meta} onPageChange={(page) => setFilters((f: any) => ({ ...f, page }))} />
        </div>
      )}
    </div>
  );
}
