import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { leadsApi, type LeadListParams } from '@/api/crm/leads';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import type { Lead, LeadStatus } from '@/types/crm';

const variant: Record<LeadStatus, 'success' | 'neutral' | 'info' | 'danger' | 'warning'> = {
 new: 'info',
 contacted: 'neutral',
 qualified: 'success',
 disqualified: 'danger',
 converted: 'warning',
};

export default function LeadsListPage() {
 const navigate = useNavigate();
 const { can } = usePermission();
 const canManage = can('crm.leads.manage');
 const [filters, setFilters] = useState<LeadListParams>({ page: 1, per_page: 25 });

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['crm', 'leads', filters],
 queryFn: () => leadsApi.list(filters),
 placeholderData: (prev) => prev });

 const columns: Column<Lead>[] = [
 {
 key: 'number', header: 'Lead #',
 cell: (r) => <span className="font-mono">{r.lead_number}</span>,
 },
 { key: 'company', header: 'Company', cell: (r) => (
 <div>
 <div className="font-medium">{r.company_name}</div>
 <div className="text-xs text-muted">{r.contact_person}</div>
 </div>
 ) },
 { key: 'source', header: 'Source', cell: (r) => r.source_label },
 { key: 'value', header: 'Est. value', align: 'right', cell: (r) => <NumCell>{formatPeso(r.estimated_value)}</NumCell> },
 { key: 'assignee', header: 'Assigned', cell: (r) => r.assignee?.name ?? '—' },
 { key: 'status', header: 'Status', cell: (r) => <Chip variant={variant[r.status]}>{r.status_label}</Chip> },
 ];

 const filterConfig: FilterConfig[] = [
 { key: 'status', label: 'Status', type: 'select', options: [
 { value: '', label: 'All' },
 ...(['new', 'contacted', 'qualified', 'disqualified', 'converted'] as const).map((s) => ({
 value: s, label: s.charAt(0).toUpperCase() + s.slice(1),
 })),
 ]},
 { key: 'source', label: 'Source', type: 'select', options: [
 { value: '', label: 'All' },
 ...(['referral', 'website', 'trade_show', 'cold_call', 'existing_customer', 'other'] as const).map((s) => ({
 value: s, label: s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()),
 })),
 ]},
 ];

 return (
 <div>
 <PageHeader title="Leads"
 subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'lead' : 'leads'}` : undefined}
 actions={canManage ? (
 <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/crm/leads/create')}>
 New lead
 </Button>
 ) : null} />
 <FilterBar
 filters={filterConfig}
 values={filters}
 onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
 onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
 searchPlaceholder="Search company, contact, or lead #…"
 />
 {isLoading && !data && <SkeletonTable columns={6} rows={8} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load leads"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
 {data && data.data.length === 0 && (
 <EmptyState icon="users" title="No leads found"
 description="Add a lead to start the sales pipeline." />
 )}
 {data && data.data.length > 0 && (
 <div className="px-5 py-4">
 <DataTable onRowClick={(r) => navigate(`/crm/leads/${r.id}`)}
 columns={columns} data={data.data} meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))} />
 </div>
 )}
 </div>
 );
}
