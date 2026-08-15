/** Sprint 8 — Task 74 + Sprint P5. Self-service payslips, web table layout. */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { LuCircleCheck, LuClock } from '@/lib/icons';
import { downloadAuthenticatedFile } from '@/api/download';
import { payrollsApi, type PayrollListParams } from '@/api/payroll/payrolls';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import type { Payroll } from '@/types/payroll';

/**
 * Self-service payslip list. Backend scopes results to the logged-in
 * employee — they only ever see their own payroll rows.
 */
const columns: Column<Payroll>[] = [
 {
 key: 'period',
 header: 'Period',
 cell: (p) => (
 <NumCell className="font-medium">
 {p.computed_at ? formatDate(p.computed_at) : '—'}
 </NumCell>
 ),
 },
 {
 key: 'gross_pay',
 header: 'Gross',
 align: 'right',
 cell: (p) => <NumCell>{formatPeso(p.gross_pay)}</NumCell>,
 },
 {
 key: 'total_deductions',
 header: 'Deductions',
 align: 'right',
 cell: (p) => <NumCell>{formatPeso(p.total_deductions)}</NumCell>,
 },
 {
 key: 'net_pay',
 header: 'Net pay',
 align: 'right',
 cell: (p) => <NumCell className="font-medium">{formatPeso(p.net_pay)}</NumCell>,
 },
 {
 key: 'status',
 header: 'Status',
 cell: (p) => (
 <div className="flex items-center gap-2">
 {p.error_message ? (
 <Chip variant="danger">Error</Chip>
 ) : (
 <Chip variant="success">Computed</Chip>
 )}
 {/* ADV1 — Show disbursement status if available */}
 {p.period_disbursement_status === 'disbursed' && (
 <span className="inline-flex items-center gap-1 text-xs text-success-fg">
 <LuCircleCheck size={12} /> Disbursed
 </span>
 )}
 {p.period_disbursement_status === 'pending' && !p.error_message && (
 <span className="inline-flex items-center gap-1 text-xs text-muted">
 <LuClock size={12} /> Awaiting disbursement
 </span>
 )}
 </div>
 ),
 },
];

export default function SelfServicePayslipsPage() {
 const [filters, setFilters] = useState<PayrollListParams>({
 page: 1, per_page: 25, sort: 'created_at', direction: 'desc',
 });
 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['my-payslips', filters],
 queryFn: () => payrollsApi.list(filters),
 placeholderData: (prev) => prev,
 });

 return (
 <div>
 <PageHeader
 title="My Payslips"
 subtitle={data ? `${data.meta.total} total` : undefined}
 />
 <div className="px-5 py-4">
 {isLoading && !data && <SkeletonTable columns={6} rows={8} />}

 {isError && (
 <EmptyState
 icon="alert-circle"
 title="Failed to load payslips"
 description="An error occurred while loading your payslips. Please try again."
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 )}

 {data && data.data.length === 0 && (
 <EmptyState
 icon="receipt"
 title="No payslips yet"
 description="Your payslip will appear here after the next payroll run."
 />
 )}

 {data && data.data.length > 0 && (
 <div className="px-5 py-4">
 <DataTable
 columns={columns}
 data={data.data}
 meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 onRowClick={(p) => {
 if (!p.error_message) {
 void downloadAuthenticatedFile(payrollsApi.payslipUrl(p.id), {
 openInNewTab: true,
 errorMessage: 'Failed to generate the payslip.',
 });
 }
 }}
 />
 </div>
 )}
 </div>
 </div>
 );
}
