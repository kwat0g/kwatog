import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { loansApi, type LoanListParams } from '@/api/loans';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import type { EmployeeLoan } from '@/types/loans';

export default function LoansPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<LoanListParams>({ page: 1, per_page: 25, sort: 'created_at', direction: 'desc' });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['loans', filters],
    queryFn: () => loansApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<EmployeeLoan>[] = [
    { key: 'loan_no', header: 'Loan no', cell: (r) => <Link to={`/loans/${r.id}`} className="font-mono text-accent hover:underline">{r.loan_no}</Link> },
    { key: 'employee', header: 'Employee', cell: (r) => <StackedCell primary={r.employee?.full_name ?? '—'} secondary={<span className="font-mono">{r.employee?.employee_no}</span>} /> },
    { key: 'loan_type', header: 'Type', cell: (r) => <Chip variant="neutral">{r.loan_type === 'company_loan' ? 'Company' : 'Cash Advance'}</Chip> },
    { key: 'principal', header: 'Principal', align: 'right', cell: (r) => <NumCell>{formatPeso(r.principal)}</NumCell> },
    { key: 'balance', header: 'Balance', align: 'right', cell: (r) => <NumCell className="font-medium">{formatPeso(r.balance)}</NumCell> },
    { key: 'pay_periods', header: 'Periods', align: 'right', cell: (r) => <NumCell>{r.pay_periods_remaining}/{r.pay_periods_total}</NumCell> },
    { key: 'start_date', header: 'Start', align: 'left', cell: (r) => <NumCell>{r.start_date ? formatDate(r.start_date) : '—'}</NumCell> },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={chipVariantForStatus(r.status)}>{r.status}</Chip> },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'loan_type', label: 'Type', type: 'select',
      options: [
        { value: '', label: 'All types' },
        { value: 'company_loan', label: 'Company loan' },
        { value: 'cash_advance', label: 'Cash advance' },
      ],
    },
    {
      key: 'status', label: 'Status', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'pending', label: 'Pending' },
        { value: 'active', label: 'Active' },
        { value: 'paid', label: 'Paid' },
        { value: 'cancelled', label: 'Cancelled' },
        { value: 'rejected', label: 'Rejected' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Loans & Cash Advance"
        subtitle={data ? `${data.meta.total} records` : undefined}
        actions={can('loans.create') ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/loans/create')}>
            New request
          </Button>
        ) : null}
      />

      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by loan no or employee…"
      />

      {isLoading && !data && <SkeletonTable columns={8} rows={6} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load loans" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="inbox"
          title="No loan records"
          description={can('loans.create') ? 'Submit a request to get started.' : 'Nothing here yet.'}
          action={can('loans.create') ? <Button variant="primary" onClick={() => navigate('/loans/create')}>New request</Button> : undefined}
        />
      )}
      {data && data.data.length > 0 && (
        <DataTable columns={columns} data={data.data} meta={data.meta} onPageChange={(page) => setFilters((f) => ({ ...f, page }))} />
      )}
    </div>
  );
}
