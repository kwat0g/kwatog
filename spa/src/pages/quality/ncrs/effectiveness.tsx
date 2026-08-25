import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { ncrsApi } from '@/api/quality/ncrs';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { ListEmptyState } from '@/components/ui/ListEmptyState';
import type { NcrAction } from '@/types/quality';

/** CAPA due/overdue queue. The detail page owns the actual verdict form. */
export default function NcrEffectivenessPage() {
  const navigate = useNavigate();
  const [page, setPage] = useState(1);
  const query = useQuery({
    queryKey: ['quality', 'ncr-effectiveness', page],
    queryFn: () => ncrsApi.due({ page, per_page: 25 }),
    placeholderData: (previous) => previous,
  });

  const columns: Column<NcrAction>[] = [
    {
      key: 'ncr',
      header: 'NCR',
      cell: (action) => action.ncr ? (
        <Link
          to={`/quality/ncrs/${action.ncr.id}`}
          className="font-mono text-accent hover:underline"
          onClick={(event) => event.stopPropagation()}
        >
          {action.ncr.ncr_number}
        </Link>
      ) : <span className="text-muted">—</span>,
    },
    {
      key: 'action_type',
      header: 'Action',
      cell: (action) => <Chip variant="neutral">{action.action_type_label ?? action.action_type}</Chip>,
    },
    {
      key: 'owner',
      header: 'Owner',
      cell: (action) => action.owner?.name ?? <span className="text-danger-fg">Unassigned</span>,
    },
    {
      key: 'due_date',
      header: 'Due',
      cell: (action) => <span className="font-mono tabular-nums">{action.next_effectiveness_check_at ?? action.due_date ?? '—'}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (action) => <Chip variant={action.effectiveness_status === 'ineffective' ? 'danger' : 'warning'}>
        {action.effectiveness_status_label ?? action.effectiveness_status ?? 'Pending'}
      </Chip>,
    },
    {
      key: 'open',
      header: '',
      align: 'right',
      cell: (action) => <Button size="sm" variant="secondary" onClick={() => action.ncr && navigate(`/quality/ncrs/${action.ncr.id}`)}>
        Open NCR
      </Button>,
    },
  ];

  return (
    <div>
      <PageHeader
        title="CAPA effectiveness"
        subtitle={query.data ? `${query.data.meta.total} due check${query.data.meta.total === 1 ? '' : 's'}` : 'Due and overdue verification checks'}
        actions={<Button size="sm" variant="secondary" onClick={() => navigate('/quality/ncrs')}>All NCRs</Button>}
      />
      {query.isLoading && !query.data && <SkeletonTable columns={6} rows={6} />}
      {query.isError && <EmptyState icon="alert-circle" title="Failed to load CAPA checks" action={<Button variant="secondary" onClick={() => query.refetch()}>Retry</Button>} />}
      {query.data && query.data.data.length === 0 && <ListEmptyState />}
      {query.data && query.data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            tableKey="ncr-effectiveness"
            columns={columns}
            data={query.data.data}
            meta={query.data.meta}
            onPageChange={setPage}
            onRowClick={(action) => action.ncr && navigate(`/quality/ncrs/${action.ncr.id}`)}
          />
        </div>
      )}
    </div>
  );
}
