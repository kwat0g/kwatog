import { useQuery } from '@tanstack/react-query';
import { useNavigate} from 'react-router-dom';
import { LuPlus } from '@/lib/icons';
import { journalEntriesApi, type JournalEntryListParams } from '@/api/accounting/journal-entries';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import type { JournalEntry } from '@/types/accounting';

import { ListEmptyState } from '@/components/ui/ListEmptyState';
const STATUS_VARIANT: Record<string, ChipVariant> = {
 draft: 'warning',
 posted: 'success',
 reversed: 'neutral' };

const DEFAULT_FILTERS: JournalEntryListParams = {
 page: 1, per_page: 25,
};

export default function JournalEntriesPage() {
 const navigate = useNavigate();
 const { can } = usePermission();
 // Bound to the URL so dashboard drill-downs (?status=draft) arrive
 // pre-filtered and the browser back button restores the previous view.
 const [filters, setFilters] = useUrlFilters<JournalEntryListParams>(DEFAULT_FILTERS);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['accounting', 'journal-entries', filters],
 queryFn: () => journalEntriesApi.list(filters),
 placeholderData: (prev) => prev });
 const { data: options } = useQuery({
 queryKey: ['accounting', 'journal-entry-options'],
 queryFn: journalEntriesApi.options,
 staleTime: 5 * 60 * 1000 });
 const statusLabel = new Map((options?.statuses ?? []).map((status) => [status.value, status.label]));

 const columns: Column<JournalEntry>[] = [
 { key: 'entry_number', header: 'Entry no', cell: (r) => <span className="font-mono">{r.entry_number}</span> },
 { key: 'date', header: 'Date', cell: (r) => <NumCell>{formatDate(r.date)}</NumCell> },
 { key: 'description', header: 'Description', cell: (r) => <span className="truncate inline-block max-w-md">{r.description}</span> },
 { key: 'reference', header: 'Reference', cell: (r) => <span className="text-xs text-muted">{r.reference_label ?? '—'}</span> },
 { key: 'total_debit', header: 'Total', align: 'right', cell: (r) => <NumCell className="font-medium">{formatPeso(r.total_debit)}</NumCell> },
 { key: 'status', header: 'Status', cell: (r) => <Chip variant={STATUS_VARIANT[r.status] ?? 'neutral'}>{statusLabel.get(r.status) ?? r.status}</Chip> },
 ];

 const filterConfig: FilterConfig[] = [
 {
 key: 'status', label: 'Status', type: 'select',
 options: [
 { value: '', label: 'All' },
 ...(options?.statuses ?? []),
 ] },
 ];

 return (
 <div>
 <PageHeader
 title="Journal Entries"
 subtitle={data ? `${data.meta.total} entries` : undefined}
 actions={
 <>
 <Button variant="secondary" size="sm" onClick={() => navigate('/accounting/coa')}>COA</Button>
 <Button variant="secondary" size="sm" onClick={() => navigate('/accounting/vendors')}>Vendors</Button>
 <Button variant="secondary" size="sm" onClick={() => navigate('/accounting/trial-balance')}>Trial Balance</Button>
 <Button variant="secondary" size="sm" onClick={() => navigate('/budgeting')}>Budgets</Button>
 {can('accounting.journal.create') && (
 <Button variant="primary" size="sm" icon={<LuPlus size={14} />} onClick={() => navigate('/accounting/journal-entries/create')}>
 New entry
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
 searchPlaceholder="Search entry no or description…"
 />

 {isLoading && !data && <SkeletonTable columns={6} rows={8} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load journal entries" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
 {data && data.data.length === 0 && (
 <ListEmptyState />
 )}
 {data && data.data.length > 0 && (
  <div className="px-5 py-4"><DataTable
  tableKey="journal-entries"
  onRowClick={(r) => navigate(`/accounting/journal-entries/${r.id}`)} columns={columns} data={data.data} meta={data.meta} onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))} /></div>
 )}
 </div>
 );
}
