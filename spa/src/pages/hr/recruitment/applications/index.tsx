import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { recruitmentApi } from '@/api/recruitment';
import { Chip } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
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

export default function ApplicationsListPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [stageFilter, setStageFilter] = useState(searchParams.get('stage') ?? '');
  const [page, setPage] = useState(1);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['recruitment-applications', stageFilter, page],
    queryFn: () =>
      recruitmentApi
        .listApplications({ stage: stageFilter || undefined, page })
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
      cell: (r) => <span className="font-mono text-xs tabular-nums">{new Date(r.applied_at).toLocaleDateString()}</span>,
    },
  ];

  return (
    <div>
      <PageHeader title="Applications" subtitle="All job applications" />

      <div className="mt-4 flex gap-1 border-b border-border">
        {STAGE_TABS.map((tab) => (
          <button
            key={tab.value}
            onClick={() => { setStageFilter(tab.value); setPage(1); }}
            className={`px-3 py-2 text-sm font-medium transition-colors ${
              stageFilter === tab.value
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
          <SkeletonTable rows={5} cols={5} />
        ) : isError ? (
          <EmptyState title="Error loading applications" action={<Button onClick={() => refetch()}>Retry</Button>} />
        ) : !data?.data?.length ? (
          <EmptyState title="No applications found" description="Applications will appear here as candidates apply." />
        ) : (
          <DataTable
            columns={columns}
            data={data.data}
            onRowClick={(row) => navigate(`/hr/recruitment/applications/${row.id}`)}
            pagination={data.meta ? {
              currentPage: data.meta.current_page,
              lastPage: data.meta.last_page,
              total: data.meta.total,
              onPageChange: setPage,
            } : undefined}
          />
        )}
      </div>
    </div>
  );
}
