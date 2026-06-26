import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { recruitmentApi } from '@/api/recruitment';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { JobPosting, JobPostingStatus } from '@/types/recruitment';

const STATUS_CHIP: Record<JobPostingStatus, 'neutral' | 'success' | 'warning' | 'info'> = {
  draft: 'neutral',
  open: 'success',
  closed: 'warning',
  filled: 'info',
};

const STATUS_TABS: { label: string; value: string }[] = [
  { label: 'All', value: '' },
  { label: 'Draft', value: 'draft' },
  { label: 'Open', value: 'open' },
  { label: 'Closed', value: 'closed' },
  { label: 'Filled', value: 'filled' },
];

export default function PostingsListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [statusFilter, setStatusFilter] = useState('');
  const [page, setPage] = useState(1);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['recruitment-postings', statusFilter, page],
    queryFn: () =>
      recruitmentApi
        .listPostings({ status: statusFilter || undefined, page })
        .then((r) => r.data),
    placeholderData: (prev) => prev,
  });

  const columns: Column<JobPosting>[] = [
    {
      key: 'posting_number',
      header: 'Number',
      cell: (r) => <span className="font-mono text-xs tabular-nums">{r.posting_number}</span>,
    },
    {
      key: 'title',
      header: 'Title',
      cell: (r) => <span className="font-medium">{r.title}</span>,
    },
    {
      key: 'department',
      header: 'Department',
      cell: (r) => r.department?.name ?? '—',
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => <Chip variant={STATUS_CHIP[r.status]}>{r.status}</Chip>,
    },
    {
      key: 'slots',
      header: 'Slots',
      cell: (r) => <span className="font-mono tabular-nums">{r.slots}</span>,
    },
    {
      key: 'application_count',
      header: 'Applications',
      cell: (r) => <span className="font-mono tabular-nums">{r.application_count ?? 0}</span>,
    },
    {
      key: 'posted_at',
      header: 'Posted',
      cell: (r) =>
        r.posted_at ? (
          <span className="font-mono text-xs tabular-nums">{new Date(r.posted_at).toLocaleDateString()}</span>
        ) : (
          <span className="text-muted">—</span>
        ),
    },
  ];

  return (
    <div>
      <PageHeader
        title="Job Postings"
        subtitle="Manage open positions"
        actions={
          can('hr.recruitment.manage') ? (
            <Button onClick={() => navigate('/hr/recruitment/postings/create')}>
              <Plus size={16} /> New Posting
            </Button>
          ) : undefined
        }
      />

      <div className="mt-4 flex gap-1 border-b border-border">
        {STATUS_TABS.map((tab) => (
          <button
            key={tab.value}
            onClick={() => { setStatusFilter(tab.value); setPage(1); }}
            className={`px-3 py-2 text-sm font-medium transition-colors ${
              statusFilter === tab.value
                ? 'border-b-2 border-foreground text-foreground'
                : 'text-muted hover:text-foreground'
            }`}
          >
            {tab.label}
          </button>
        ))}
      </div>

      <div className="mt-4">
        {isLoading ? (
          <SkeletonTable rows={5} columns={7} />
        ) : isError ? (
          <EmptyState title="Error loading postings" action={<Button onClick={() => refetch()}>Retry</Button>} />
        ) : !data?.data?.length ? (
          <EmptyState title="No postings found" description="Create a new job posting to get started." />
        ) : (
          <DataTable
            columns={columns}
            data={data.data}
            onRowClick={(row) => navigate(`/hr/recruitment/postings/${row.id}`)}
            meta={data.meta}
            onPageChange={setPage}
          />
        )}
      </div>
    </div>
  );
}
