import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Download } from 'lucide-react';
import { payrollsApi, type PayrollListParams } from '@/api/payroll/payrolls';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import type { Payroll } from '@/types/payroll';

/**
 * Self-service payslip list. Backend scopes results to the logged-in
 * employee — they only ever see their own payroll rows.
 */
export default function SelfServicePayslipsPage() {
  const [filters, setFilters] = useState<PayrollListParams>({
    page: 1, per_page: 25, sort: 'created_at', direction: 'desc',
  });
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['my-payslips', filters],
    queryFn: () => payrollsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<Payroll>[] = [
    { key: 'period', header: 'Period',     cell: (r) => <NumCell>{r.computed_at ? formatDate(r.computed_at) : '—'}</NumCell> },
    { key: 'gross',  header: 'Gross',      align: 'right', cell: (r) => <NumCell>{formatPeso(r.gross_pay)}</NumCell> },
    { key: 'ded',    header: 'Deductions', align: 'right', cell: (r) => <NumCell>{formatPeso(r.total_deductions)}</NumCell> },
    { key: 'net',    header: 'Net',        align: 'right', cell: (r) => <NumCell className="font-medium">{formatPeso(r.net_pay)}</NumCell> },
    {
      key: 'status', header: 'Status',
      cell: (r) => r.error_message
        ? <Chip variant="danger">Error</Chip>
        : <Chip variant="success">Ready</Chip>,
    },
    {
      key: 'download', header: '', align: 'right',
      cell: (r) => r.error_message ? null : (
        <a
          href={payrollsApi.payslipUrl(r.id)}
          className="inline-flex items-center gap-1 px-3 h-7 text-xs rounded-md border border-default bg-canvas text-primary hover:bg-elevated"
        >
          <Download size={12} /> PDF
        </a>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        title="My Payslips"
        subtitle={data ? `${data.meta.total} payslips on file` : undefined}
      />
      <div className="px-5 py-4">
        {isLoading && !data && <SkeletonTable columns={6} rows={4} />}
        {isError && (
          <EmptyState icon="alert-circle" title="Failed to load payslips"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
        )}
        {data && data.data.length === 0 && (
          <EmptyState icon="receipt" title="No payslips yet"
            description="Your payslip will appear here after the next payroll run." />
        )}
        {data && data.data.length > 0 && (
          <DataTable columns={columns} data={data.data} meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))} />
        )}
      </div>
    </div>
  );
}
