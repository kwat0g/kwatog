import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { LuPlus, LuCalendarRange, LuCoins } from '@/lib/icons';
import toast from 'react-hot-toast';
import { periodsApi, type PeriodListParams } from '@/api/payroll/periods';
import { DeMinimisManager } from '@/pages/payroll/de-minimis';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Input } from '@/components/ui/Input';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import type { PayrollPeriod } from '@/types/payroll';

import { useUrlFilters } from '@/hooks/useUrlFilters';
import { ListEmptyState } from '@/components/ui/ListEmptyState';
const periodStatusVariant = (status: string | null | undefined): ChipVariant => {
 switch (status) {
 case 'finalized': return 'success';
 case 'approved': return 'info';
 case 'computed': return 'info';
 case 'processing': return 'info';
 case 'draft': return 'warning';
 default: return 'neutral';
 }
};

export default function PayrollPeriodsPage() {
 const navigate = useNavigate();
 const qc = useQueryClient();
 const { can } = usePermission();
 const [filters, setFilters] = useUrlFilters<PeriodListParams>({
 page: 1, per_page: 25, sort: 'period_start', direction: 'desc',
 });
 const [showThirteenth, setShowThirteenth] = useState(false);
 const [showDeMinimis, setShowDeMinimis] = useState(false);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['payroll-periods', filters],
 queryFn: () => periodsApi.list(filters),
 placeholderData: (prev) => prev,
 });
 const { data: periodOptions } = useQuery({
 queryKey: ['payroll-periods', 'options'],
 queryFn: periodsApi.options,
 staleTime: 5 * 60 * 1000,
 });
 const statusLabels = new Map((periodOptions?.statuses ?? []).map((option) => [option.value, option.label]));

 const columns: Column<PayrollPeriod>[] = [
 {
 key: 'period',
 header: 'Period',
 cell: (r) => (
 <StackedCell
 primary={
 <span className="flex items-center gap-2">
 <span className="font-mono">
 {formatDate(r.period_start)} – {formatDate(r.period_end)}
 </span>
 {r.is_auto_created && (
 <span title="Auto-scheduled by system"><Chip variant="info">Auto</Chip></span>
 )}
 </span>
 }
 secondary={r.label}
 />
 ),
 },
 {
 key: 'half',
 header: 'Half',
 cell: (r) => r.is_thirteenth_month
 ? <Chip variant="info">13th Month</Chip>
 : <Chip variant="neutral">{r.is_first_half ? '1st' : '2nd'}</Chip>,
 },
 {
 key: 'payroll_date',
 header: 'Payroll Date',
 cell: (r) => <NumCell>{formatDate(r.payroll_date)}</NumCell>,
 },
 {
 key: 'employee_count',
 header: 'Employees',
 align: 'right',
 cell: (r) => <NumCell>{r.employee_count}</NumCell>,
 },
 {
 key: 'total_net',
 header: 'Total Net',
 align: 'right',
 cell: (r) => <NumCell className="font-medium">{r.summary ? formatPeso(r.summary.total_net) : '—'}</NumCell>,
 },
 {
 key: 'status',
 header: 'Status',
 cell: (r) => (
 <Chip variant={periodStatusVariant(r.status)}>{statusLabels.get(r.status) ?? r.status_label}</Chip>
 ),
 },
 ];

 const filterConfig: FilterConfig[] = [
 {
 key: 'status', label: 'Status', type: 'select',
 options: [
 { value: '', label: 'All' },
 ...(periodOptions?.statuses ?? []),
 ],
 },
 {
 key: 'is_thirteenth_month', label: 'Type', type: 'select',
 options: [
 { value: '', label: 'All types' },
 ...(periodOptions?.period_types ?? []),
 ],
 },
 ];

 const canCreate = can('payroll.periods.create');
 const canRunThirteenth = can('payroll.thirteenth_month.run') || can('payroll.periods.create');

 return (
 <div>
 <PageHeader
 title="Payroll Periods"
 subtitle={data ? `${data.meta.total} periods` : undefined}
 actions={
 <>
 {can('payroll.adjustments.create') && (
 <Button variant="secondary" size="sm" icon={<LuCoins size={14} />} onClick={() => setShowDeMinimis(true)}>
 De Minimis
 </Button>
 )}
 {canRunThirteenth && (
 <Button
 variant="secondary" size="sm" icon={<LuCalendarRange size={14} />}
 onClick={() => setShowThirteenth(true)}
 >
 Run 13th Month
 </Button>
 )}
 {canCreate && (
 <Button variant="primary" size="sm" icon={<LuPlus size={14} />}
 onClick={() => navigate('/payroll/periods/create')}>
 New Period
 </Button>
 )}
 </>
 }
 />

 <FilterBar
 filters={filterConfig}
 values={filters}
 onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
 onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
 searchPlaceholder="Search periods…"
 />

 {isLoading && !data && <SkeletonTable columns={6} rows={6} />}
 {isError && (
 <EmptyState
 icon="alert-circle"
 title="Failed to load payroll periods"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 )}
 {data && data.data.length === 0 && (
 <ListEmptyState />
 )}
 {data && data.data.length > 0 && (
 <div className="px-5 py-4">
 <DataTable
 onRowClick={(r) => navigate(`/payroll/periods/${r.id}`)}
 columns={columns}
 data={data.data}
 meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 />
 </div>
 )}

 <ThirteenthMonthModal
 open={showThirteenth}
 onClose={() => setShowThirteenth(false)}
 onSuccess={() => qc.invalidateQueries({ queryKey: ['payroll-periods'] })}
 />

 {/* De Minimis — scope cut: standalone page folded into a modal on the Payroll page */}
 <Modal isOpen={showDeMinimis} onClose={() => setShowDeMinimis(false)} size="xl" title="De Minimis Benefits">
 <DeMinimisManager />
 </Modal>
 </div>
 );
}

function ThirteenthMonthModal({
 open, onClose, onSuccess,
}: { open: boolean; onClose: () => void; onSuccess: () => void }) {
 const navigate = useNavigate();
 const [year, setYear] = useState(String(new Date().getFullYear()));
 const [payrollDate, setPayrollDate] = useState('');

 const mutation = useMutation({
 mutationFn: () => periodsApi.runThirteenthMonth(Number(year), payrollDate || undefined),
 onSuccess: (period) => {
 toast.success(`13th-month period for ${year} created.`);
 onSuccess();
 onClose();
 navigate(`/payroll/periods/${period.id}`);
 },
 onError: () => toast.error('Failed to create 13th-month period.'),
 });

 return (
 <Modal isOpen={open} onClose={onClose} size="sm" title="Run 13th Month Pay">
 <div className="py-3 space-y-3">
 <Input
 label="Year"
 value={year}
 onChange={(e) => setYear(e.target.value)}
 placeholder="YYYY"
 />
 <Input
 label="Payroll date (optional)"
 type="date"
 value={payrollDate}
 onChange={(e) => setPayrollDate(e.target.value)}
 />
 <p className="text-xs text-muted">
 Creates a special period that finalizes 13th-month accruals for every active employee with year-to-date earnings.
 </p>
 </div>
 <ModalFooter>
 <Button variant="secondary" onClick={onClose} disabled={mutation.isPending}>Cancel</Button>
 <Button variant="primary" onClick={() => mutation.mutate()}
 disabled={!year || mutation.isPending} loading={mutation.isPending}>
 {mutation.isPending ? 'Creating…' : 'Create period'}
 </Button>
 </ModalFooter>
 </Modal>
 );
}
