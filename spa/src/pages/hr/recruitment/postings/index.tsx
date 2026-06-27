import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { cn } from '@/lib/cn';
import { recruitmentApi } from '@/api/recruitment';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import type { JobPosting, JobPostingStatus } from '@/types/recruitment';

const STATUS_CHIP: Record<JobPostingStatus, 'neutral' | 'success' | 'warning' | 'info'> = {
  draft: 'neutral',
  open: 'success',
  closed: 'warning',
  filled: 'info',
};

const STATUS_LABEL: Record<JobPostingStatus, string> = {
  draft: 'Draft',
  open: 'Open',
  closed: 'Closed',
  filled: 'Filled',
};

const STATUS_TABS: { label: string; value: string }[] = [
  { label: 'All', value: '' },
  { label: 'Draft', value: 'draft' },
  { label: 'Open', value: 'open' },
  { label: 'Closed', value: 'closed' },
  { label: 'Filled', value: 'filled' },
];

interface PostingFilters {
  [key: string]: unknown;
  page: number;
  per_page: number;
  status?: string;
  search?: string;
  sort?: string;
  direction?: 'asc' | 'desc';
}

export default function PostingsListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [statusFilter, setStatusFilter] = useState('');
  const [filters, setFilters] = useState<PostingFilters>({
    page: 1, per_page: 25, sort: 'created_at', direction: 'desc',
  });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['recruitment-postings', statusFilter, filters],
    queryFn: () =>
      recruitmentApi
        .listPostings({ status: statusFilter || undefined, ...filters })
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
      sortable: true,
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
      cell: (r) => <Chip variant={STATUS_CHIP[r.status]}>{STATUS_LABEL[r.status]}</Chip>,
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
      sortable: true,
      cell: (r) =>
        r.posted_at ? (
          <span className="font-mono text-xs tabular-nums">{formatDate(r.posted_at)}</span>
        ) : (
          <span className="text-muted">—</span>
        ),
    },
  ];


  return (
    <div>
      <PageHeader
        title="Job Postings"
        subtitle={data ? `${data.meta?.total ?? 0} postings` : undefined}
        breadcrumbs={[
          { label: 'HR', href: '/hr' },
          { label: 'Recruitment', href: '/hr/recruitment' },
          { label: 'Postings' },
        ]}
        backTo="/hr/recruitment"
        backLabel="Recruitment"
        actions={
          can('hr.recruitment.manage') ? (
            <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/hr/recruitment/postings/create')}>
              New Posting
            </Button>
          ) : undefined
        }
      />

      <div className="border-b border-default px-5 flex gap-4" role="tablist" aria-label="Posting status">
        {STATUS_TABS.map((tab) => (
          <button
            key={tab.value}
            type="button"
            role="tab"
            aria-selected={statusFilter === tab.value}
            onClick={() => { setStatusFilter(tab.value); setFilters((f) => ({ ...f, page: 1 })); }}
            className={cn(
              'h-10 text-sm border-b-2 -mb-px transition-colors duration-fast cursor-pointer',
              statusFilter === tab.value
                ? 'border-accent text-primary font-medium'
                : 'border-transparent text-muted hover:text-primary',
            )}
          >
            {tab.label}
          </button>
        ))}
      </div>

      <FilterBar
        filters={[]}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={() => {}}
        searchPlaceholder="Search by title or posting number…"
      />

      {isLoading && !data && <SkeletonTable columns={7} rows={10} />}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load postings"
          description="Something went wrong. Please try again."
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {data && !data.data?.length && (
        <EmptyState
          icon="briefcase"
          title="No postings found"
          description={filters.search ? `No matches for "${filters.search}".` : 'Create a new job posting to get started.'}
          action={can('hr.recruitment.manage') ? (
            <Button variant="primary" onClick={() => navigate('/hr/recruitment/postings/create')}>New Posting</Button>
          ) : undefined}
        />
      )}

      {data && data.data?.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            columns={columns}
            data={data.data}
            onRowClick={(row) => navigate(`/hr/recruitment/postings/${row.id}`)}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
            onSort={(sort, direction) => setFilters((f) => ({ ...f, sort, direction, page: 1 }))}
            currentSort={filters.sort}
            currentDirection={filters.direction}
          />
        </div>
      )}
    </div>
  );
}
