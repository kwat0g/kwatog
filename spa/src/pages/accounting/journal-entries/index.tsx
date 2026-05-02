import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { journalEntriesApi, type JournalEntryListParams } from '@/api/accounting/journal-entries';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import type { JournalEntry } from '@/types/accounting';

const STATUS_VARIANT: Record<string, ChipVariant> = {
  draft: 'warning',
  posted: 'success',
  reversed: 'neutral',
};

export default function JournalEntriesPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<JournalEntryListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['accounting', 'journal-entries', filters],
    queryFn: () => journalEntriesApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<JournalEntry>[] = [
    { key: 'entry_number', header: 'Entry no', cell: (r) => <Link to={`/accounting/journal-entries/${r.id}`} className="font-mono text-accent hover:underline">{r.entry_number}</Link> },
    { key: 'date', header: 'Date', cell: (r) => <NumCell>{formatDate(r.date)}</NumCell> },
    { key: 'description', header: 'Description', cell: (r) => <span className="truncate inline-block max-w-md">{r.description}</span> },
    { key: 'reference', header: 'Reference', cell: (r) => <span className="text-xs text-muted">{r.reference_label ?? '—'}</span> },
    { key: 'total_debit', header: 'Total', align: 'right', cell: (r) => <NumCell className="font-medium">{formatPeso(r.total_debit)}</NumCell> },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={STATUS_VARIANT[r.status] ?? 'neutral'}>{r.status}</Chip> },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'status', label: 'Status', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'draft', label: 'Draft' },
        { value: 'posted', label: 'Posted' },
        { value: 'reversed', label: 'Reversed' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Journal Entries"
        subtitle={data ? `${data.meta.total} entries` : undefined}
        actions={can('accounting.journal.create') ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/accounting/journal-entries/create')}>
            New entry
          </Button>
        ) : null}
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
        <EmptyState
          icon="inbox"
          title="No journal entries"
          description={can('accounting.journal.create') ? 'Create the first entry to get started.' : 'Nothing here yet.'}
          action={can('accounting.journal.create') ? <Button variant="primary" onClick={() => navigate('/accounting/journal-entries/create')}>New entry</Button> : undefined}
        />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4"><DataTable columns={columns} data={data.data} meta={data.meta} onPageChange={(page) => setFilters((f) => ({ ...f, page }))} /></div>
      )}
    </div>
  );
}
