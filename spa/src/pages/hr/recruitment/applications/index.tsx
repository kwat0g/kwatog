import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { recruitmentApi } from '@/api/recruitment';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Tabs } from '@/components/ui/Tabs';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDate } from '@/lib/formatDate';
import type { JobApplication, ApplicationStage } from '@/types/recruitment';

const STAGE_CHIP: Record<ApplicationStage, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
  new: 'neutral',
  screening: 'info',
  interview: 'warning',
  offer: 'info',
  hired: 'success',
  rejected: 'danger',
};

const STAGE_LABEL: Record<ApplicationStage, string> = {
  new: 'New',
  screening: 'Screening',
  interview: 'Interview',
  offer: 'Offer',
  hired: 'Hired',
  rejected: 'Rejected',
};

const STAGE_TABS: { label: string; value: string }[] = [
  { label: 'All', value: '' },
  { label: 'New', value: 'new' },
  { label: 'Screening', value: 'screening' },
  { label: 'Interview', value: 'interview' },
  { label: 'Offer', value: 'offer' },
  { label: 'Hired', value: 'hired' },
  { label: 'Rejected', value: 'rejected' },
];

interface AppFilters {
  [key: string]: unknown;
  page: number;
  per_page: number;
  search?: string;
  sort?: string;
  direction?: 'asc' | 'desc';
}

export default function ApplicationsListPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [stageFilter, setStageFilter] = useState(searchParams.get('stage') ?? '');
  const [filters, setFilters] = useState<AppFilters>({
    page: 1, per_page: 25, sort: 'applied_at', direction: 'desc',
  });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['recruitment-applications', stageFilter, filters],
    queryFn: () =>
      recruitmentApi
        .listApplications({ stage: stageFilter || undefined, ...filters })
        .then((r) => r.data),
    placeholderData: (prev) => prev,
  });

  const columns: Column<JobApplication>[] = [
    {
      key: 'application_number',
      header: 'Number',
      cell: (r) => <span className="font-mono text-xs tabular-nums">{r.application_number}</span>,
    },
    {
      key: 'full_name',
      header: 'Applicant',
      sortable: true,
      cell: (r) => <span className="font-medium">{r.full_name}</span>,
    },
    {
      key: 'position',
      header: 'Position',
      cell: (r) => r.job_posting?.title ?? '—',
    },
    {
      key: 'stage',
      header: 'Stage',
      cell: (r) => <Chip variant={STAGE_CHIP[r.stage]}>{STAGE_LABEL[r.stage]}</Chip>,
    },
    {
      key: 'applied_at',
      header: 'Applied',
      sortable: true,
      cell: (r) => <span className="font-mono text-xs tabular-nums">{formatDate(r.applied_at)}</span>,
    },
  ];

  return (
    <div>
      <PageHeader
        title="Applications"
        subtitle={data ? `${data.meta?.total ?? 0} applications` : undefined}
        breadcrumbs={[
          { label: 'HR', href: '/hr' },
          { label: 'Recruitment', href: '/hr/recruitment' },
          { label: 'Applications' },
        ]}
        backTo="/hr/recruitment"
        backLabel="Recruitment"
      />

      <Tabs
        className="px-5"
        label="Application stage"
        value={stageFilter}
        onChange={(value) => { setStageFilter(value); setFilters((f) => ({ ...f, page: 1 })); }}
        items={STAGE_TABS.map((tab) => ({ key: tab.value, label: tab.label }))}
      />

      <FilterBar
        filters={[]}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={() => {}}
        searchPlaceholder="Search name or application number…"
      />

      {isLoading && !data && <SkeletonTable columns={5} rows={10} />}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load applications"
          description="Something went wrong. Please try again."
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {data && !data.data?.length && (
        <EmptyState
          icon="users"
          title="No applications found"
          description={filters.search ? `No matches for "${filters.search}".` : 'Applications will appear here as candidates apply.'}
        />
      )}

      {data && data.data?.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            columns={columns}
            data={data.data}
            onRowClick={(row) => navigate(`/hr/recruitment/applications/${row.id}`)}
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
