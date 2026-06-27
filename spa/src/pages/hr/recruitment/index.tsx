import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Briefcase, Users, FileText, Plus } from 'lucide-react';
import { recruitmentApi } from '@/api/recruitment';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
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

export default function RecruitmentDashboard() {
  const navigate = useNavigate();
  const { can } = usePermission();

  const { data: postingsData, isLoading: postingsLoading } = useQuery({
    queryKey: ['recruitment-postings', { status: 'open' }],
    queryFn: () => recruitmentApi.listPostings({ status: 'open', per_page: 5 }).then((r) => r.data),
  });

  const { data: applicationsData, isLoading: appsLoading } = useQuery({
    queryKey: ['recruitment-applications', { per_page: 10 }],
    queryFn: () => recruitmentApi.listApplications({ per_page: 10 }).then((r) => r.data),
  });

  const applications = applicationsData?.data ?? [];
  const openPostings = postingsData?.meta?.total ?? 0;
  const totalApps = applicationsData?.meta?.total ?? 0;

  const stageCounts = applications.reduce<Record<string, number>>((acc, app: JobApplication) => {
    acc[app.stage] = (acc[app.stage] ?? 0) + 1;
    return acc;
  }, {});

  const isLoading = postingsLoading && appsLoading;

  if (isLoading) return <SkeletonDetail />;

  const columns: Column<JobApplication>[] = [
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
      cell: (r) => <span className="font-mono text-xs tabular-nums">{formatDate(r.applied_at)}</span>,
    },
  ];

  return (
    <div>
      <PageHeader
        title="Recruitment"
        subtitle="Manage job postings and applications"
        breadcrumbs={[
          { label: 'HR', href: '/hr' },
          { label: 'Recruitment' },
        ]}
        actions={
          can('hr.recruitment.manage') ? (
            <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/hr/recruitment/postings/create')}>
              New Posting
            </Button>
          ) : undefined
        }
      />

      <div className="grid gap-4 px-5 py-4 sm:grid-cols-3">
        <button
          onClick={() => navigate('/hr/recruitment/postings')}
          className="rounded-md border border-default bg-canvas p-5 text-left transition-colors hover:bg-elevated"
        >
          <div className="flex items-center gap-3">
            <div className="flex h-9 w-9 items-center justify-center rounded-md bg-elevated">
              <Briefcase size={18} className="text-muted" />
            </div>
            <div>
              <p className="text-2xl font-bold font-mono tabular-nums">{openPostings}</p>
              <p className="text-xs text-muted font-medium uppercase tracking-wider">Open Postings</p>
            </div>
          </div>
        </button>

        <button
          onClick={() => navigate('/hr/recruitment/applications')}
          className="rounded-md border border-default bg-canvas p-5 text-left transition-colors hover:bg-elevated"
        >
          <div className="flex items-center gap-3">
            <div className="flex h-9 w-9 items-center justify-center rounded-md bg-elevated">
              <Users size={18} className="text-muted" />
            </div>
            <div>
              <p className="text-2xl font-bold font-mono tabular-nums">{totalApps}</p>
              <p className="text-xs text-muted font-medium uppercase tracking-wider">Total Applications</p>
            </div>
          </div>
        </button>

        <button
          onClick={() => navigate('/hr/recruitment/applications?stage=new')}
          className="rounded-md border border-default bg-canvas p-5 text-left transition-colors hover:bg-elevated"
        >
          <div className="flex items-center gap-3">
            <div className="flex h-9 w-9 items-center justify-center rounded-md bg-elevated">
              <FileText size={18} className="text-muted" />
            </div>
            <div>
              <p className="text-2xl font-bold font-mono tabular-nums">{stageCounts['new'] ?? 0}</p>
              <p className="text-xs text-muted font-medium uppercase tracking-wider">New Applications</p>
            </div>
          </div>
        </button>
      </div>

      <div className="px-5 pb-4">
        <Panel
          title="Recent Applications"
          actions={
            <Button variant="ghost" size="sm" onClick={() => navigate('/hr/recruitment/applications')}>
              View all
            </Button>
          }
          noPadding
        >
          {applications.length === 0 ? (
            <EmptyState
              icon="inbox"
              title="No applications yet"
              description="Applications will appear here as candidates apply."
            />
          ) : (
            <DataTable
              columns={columns}
              data={applications}
              onRowClick={(row) => navigate(`/hr/recruitment/applications/${row.id}`)}
            />
          )}
        </Panel>
      </div>
    </div>
  );
}
