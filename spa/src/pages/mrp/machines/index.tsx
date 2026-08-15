import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate} from 'react-router-dom';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { LuPlus } from '@/lib/icons';
import { machinesApi, type MachineListParams } from '@/api/mrp/machines';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { Machine, MachineStatus } from '@/types/mrp';

const variant: Record<MachineStatus, 'success' | 'info' | 'warning' | 'danger' | 'neutral'> = {
 running: 'success', idle: 'neutral', maintenance: 'info', breakdown: 'danger', offline: 'neutral' };

export default function MachinesListPage() {
 const navigate = useNavigate();
 const qc = useQueryClient();
 const { can } = usePermission();
 const canManage = can('production.machines.manage');
 const canTransition = can('production.machines.transition');
 const [filters, setFilters] = useState<MachineListParams>({ page: 1, per_page: 25 });

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['mrp', 'machines', filters],
 queryFn: () => machinesApi.list(filters),
 placeholderData: (prev) => prev });
 const { data: machineOptions } = useQuery({
 queryKey: ['mrp', 'machines', 'options'],
 queryFn: machinesApi.options,
 staleTime: 5 * 60 * 1000 });
 const statusLabels = new Map((machineOptions?.statuses ?? []).map((option) => [option.value, option.label]));

 const transition = useMutation({
 mutationFn: ({ id, to }: { id: string; to: MachineStatus }) => machinesApi.transitionStatus(id, to),
 onSuccess: () => {
 qc.invalidateQueries({ queryKey: ['mrp', 'machines'] });
 toast.success('Machine status updated.');
 },
 onError: (e: AxiosError<{ message?: string }>) => {
 toast.error(e.response?.data?.message ?? 'Failed to transition status.');
 } });

 const columns: Column<Machine>[] = [
 {
 key: 'code', header: 'Code',
 cell: (r) => (
 <span className="font-mono">{r.machine_code}</span>
 ) },
 { key: 'name', header: 'Name', cell: (r) => r.name },
 { key: 'tonnage', header: 'Tonnage', align: 'right', cell: (r) => <NumCell>{r.tonnage ?? '—'}{r.tonnage ? ' T' : ''}</NumCell> },
 { key: 'ops', header: 'Operators', align: 'right', cell: (r) => <NumCell>{Number(r.operators_required).toFixed(1)}</NumCell> },
 { key: 'hours', header: 'Hrs / day', align: 'right', cell: (r) => <NumCell>{Number(r.available_hours_per_day).toFixed(1)}</NumCell> },
 { key: 'molds', header: 'Compatible molds', align: 'right', cell: (r) => <NumCell>{r.compatible_molds_count}</NumCell> },
 { key: 'status', header: 'Status', cell: (r) => <Chip variant={variant[r.status]}>{statusLabels.get(r.status) ?? r.status_label}</Chip> },
 ...(canTransition ? [{
 key: 'actions', header: '', align: 'right' as const,
 cell: (r: Machine) => {
 const allowed = (machineOptions?.transitions?.[r.status] ?? []) as MachineStatus[];
 if (allowed.length === 0) return null;
 return (
 <Select
 fieldSize="sm"
 containerClassName="inline-flex min-w-[140px]"
 value=""
 onChange={(e) => {
 const to = e.target.value as MachineStatus;
 if (to) transition.mutate({ id: r.id, to });
 }}
 disabled={transition.isPending}
 aria-label={`Transition ${r.machine_code}`}
 >
 <option value="">Change status…</option>
 {allowed.map((s) => <option key={s} value={s}>→ {statusLabels.get(s) ?? s}</option>)}
 </Select>
 );
 } }] : []),
 ];

 const filterConfig: FilterConfig[] = [
 { key: 'status', label: 'Status', type: 'select', options: [
 { value: '', label: 'All' },
 ...(machineOptions?.statuses ?? []),
 ]},
 ];

 return (
 <div>
 <PageHeader title="Machines"
 subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'machine' : 'machines'}` : undefined}
 actions={canManage ? (
 <Button variant="primary" size="xs" icon={<LuPlus size={14} />} onClick={() => navigate('/mrp/machines/create')}>
 New machine
 </Button>
 ) : null} />
 <FilterBar
 filters={filterConfig}
 values={filters}
 onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
 onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
 searchPlaceholder="Search code or name…"
 />
 {isLoading && !data && <SkeletonTable columns={canTransition ? 8 : 7} rows={8} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load machines"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
 {data && data.data.length === 0 && (
 <EmptyState icon="cog" title="No machines configured" description="Add machine master records to begin production planning." />
 )}
 {data && data.data.length > 0 && (
 <div className="px-5 py-4">
 <DataTable onRowClick={(r) => navigate(`/mrp/machines/${r.id}`)}
 columns={columns} data={data.data} meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))} />
 </div>
 )}
 </div>
 );
}
