import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { opportunitiesApi, type OpportunityListParams } from '@/api/crm/opportunities';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import type { Opportunity, OpportunityStage } from '@/types/crm';

const variant: Record<OpportunityStage, 'success' | 'neutral' | 'info' | 'warning' | 'danger'> = {
 prospecting: 'neutral',
 needs_analysis: 'info',
 proposal: 'warning',
 negotiation: 'info',
 won: 'success',
 lost: 'danger',
};

const STAGES: Array<{ value: OpportunityStage; label: string }> = [
 { value: 'prospecting', label: 'Prospecting' },
 { value: 'needs_analysis', label: 'Needs Analysis' },
 { value: 'proposal', label: 'Proposal' },
 { value: 'negotiation', label: 'Negotiation' },
 { value: 'won', label: 'Won' },
 { value: 'lost', label: 'Lost' },
];

export default function OpportunitiesListPage() {
 const navigate = useNavigate();
 const { can } = usePermission();
 const canManage = can('crm.opportunities.manage');
 const [filters, setFilters] = useState<OpportunityListParams>({ page: 1, per_page: 25 });

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['crm', 'opportunities', filters],
 queryFn: () => opportunitiesApi.list(filters),
 placeholderData: (prev) => prev });

 const columns: Column<Opportunity>[] = [
 {
 key: 'number', header: 'Opportunity #',
 cell: (r) => <span className="font-mono">{r.opportunity_number}</span>,
 },
 { key: 'title', header: 'Title', cell: (r) => (
 <div>
 <div className="font-medium">{r.title}</div>
 <div className="text-xs text-muted">{r.customer?.name}</div>
 </div>
 ) },
 { key: 'stage', header: 'Stage', cell: (r) => <Chip variant={variant[r.stage]}>{r.stage_label}</Chip> },
 { key: 'probability', header: 'Prob.', align: 'right', cell: (r) => <NumCell>{r.probability}%</NumCell> },
 { key: 'value', header: 'Est. value', align: 'right', cell: (r) => <NumCell>{formatPeso(r.estimated_value)}</NumCell> },
 { key: 'close', header: 'Expected close', cell: (r) => (
 <span className="font-mono tabular-nums">{r.expected_close_date ? new Date(r.expected_close_date).toLocaleDateString() : '—'}</span>
 ) },
 { key: 'assignee', header: 'Assigned', cell: (r) => r.assignee?.name ?? '—' },
 ];

 const filterConfig: FilterConfig[] = [
 { key: 'stage', label: 'Stage', type: 'select', options: [
 { value: '', label: 'All' },
 ...STAGES,
 ]},
 ];

 return (
 <div>
 <PageHeader title="Opportunities"
 subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'opportunity' : 'opportunities'}` : undefined}
 actions={canManage ? (
 <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/crm/opportunities/create')}>
 New opportunity
 </Button>
 ) : null} />
 <FilterBar
 filters={filterConfig}
 values={filters}
 onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
 onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
 searchPlaceholder="Search title or opportunity #…"
 />
 {isLoading && !data && <SkeletonTable columns={7} rows={8} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load opportunities"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
 {data && data.data.length === 0 && (
 <EmptyState icon="briefcase" title="No opportunities found"
 description="Create an opportunity or convert a qualified lead." />
 )}
 {data && data.data.length > 0 && (
 <div className="px-5 py-4">
 <DataTable onRowClick={(r) => navigate(`/crm/opportunities/${r.id}`)}
 columns={columns} data={data.data} meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))} />
 </div>
 )}
 </div>
 );
}
