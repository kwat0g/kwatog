import { useQuery } from '@tanstack/react-query';
import { useNavigate, Link } from 'react-router-dom';
import { Plus, ArrowRight } from 'lucide-react';
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
import type { JobPosting, JobApplication, ApplicationStage } from '@/types/recruitment';

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

const PIPELINE_STAGES: ApplicationStage[] = ['new', 'screening', 'interview', 'offer', 'hired'];

export default function RecruitmentDashboard() {
  const navigate = useNavigate();
  const { can } = usePermission();

  const { data: postingsData, isLoading: postingsLoading } = useQuery({
    queryKey: ['recruitment-postings', { status: 'open', per_page: 5 }],
    queryFn: () => recruitmentApi.listPostings({ status: 'open', per_page: 5 }).then((r) => r.data),
  });

  const { data: applicationsData, isLoading: appsLoading } = useQuery({
    queryKey: ['recruitment-applications', { per_page: 10 }],
    queryFn: () => recruitmentApi.listApplications({ per_page: 10 }).then((r) => r.data),
  });

  const openPostings = postingsData?.data ?? [];
  const applications = applicationsData?.data ?? [];
  const totalApps = applicationsData?.meta?.total ?? 0;

  const stageCounts = applications.reduce<Record<string, number>>((acc, app: JobApplication) => {
    acc[app.stage] = (acc[app.stage] ?? 0) + 1;
    return acc;
  }, {});

  const isLoading = postingsLoading && appsLoading;

  if (isLoading) return <SkeletonDetail />;

  const postingColumns: Column<JobPosting>[] = [
    { key: 'title', header: 'Position', cell: (r) => <span className="font-medium">{r.title}</span> },
    { key: 'department', header: 'Department', cell: (r) => r.department?.name ?? '—' },
    { key: 'slots', header: 'Slots', cell: (r) => <span className="font-mono tabular-nums">{r.slots}</span> },
    { key: 'application_count', header: 'Applicants', cell: (r) => <span className="font-mono tabular-nums">{r.application_count ?? 0}</span> },
  ];

  const appColumns: Column<JobApplication>[] = [
    { key: 'full_name', header: 'Applicant', cell: (r) => <span className="font-medium">{r.full_name}</span> },
    { key: 'position', header: 'Position', cell: (r) => r.job_posting?.title ?? '—' },
    { key: 'stage', header: 'Stage', cell: (r) => <Chip variant={STAGE_CHIP[r.stage]}>{STAGE_LABEL[r.stage]}</Chip> },
    { key: 'applied_at', header: 'Applied', cell: (r) => <span className="font-mono text-xs tabular-nums">{formatDate(r.applied_at)}</span> },
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

      {/* Stats strip */}
      <div className="flex items-center gap-6 px-5 py-3 border-b border-default">
        <div>
          <p className="text-2xl font-bold font-mono tabular-nums">{openPostings.length}</p>
          <p className="text-2xs text-muted font-medium uppercase tracking-wider">Open Postings</p>
        </div>
        <div className="h-8 w-px bg-border" />
        <div>
          <p className="text-2xl font-bold font-mono tabular-nums">{totalApps}</p>
          <p className="text-2xs text-muted font-medium uppercase tracking-wider">Total Applications</p>
        </div>
        <div className="h-8 w-px bg-border" />
        {PIPELINE_STAGES.map((stage) => (
          <div key={stage}>
            <p className="text-lg font-bold font-mono tabular-nums">{stageCounts[stage] ?? 0}</p>
            <p className="text-2xs text-muted font-medium uppercase tracking-wider">{STAGE_LABEL[stage]}</p>
          </div>
        ))}
      </div>

      {/* Two-column: Open Postings + Pipeline */}
      <div className="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4 px-5 py-4">
        <Panel
          title="Open Postings"
          actions={
            <Link to="/hr/recruitment/postings" className="inline-flex items-center gap-1 text-xs text-accent hover:underline">
              View all <ArrowRight size={12} />
            </Link>
          }
          noPadding
        >
          {openPostings.length === 0 ? (
            <EmptyState
              icon="briefcase"
              title="No open postings"
              description="Create a job posting to start receiving applications."
              action={
                can('hr.recruitment.manage') ? (
                  <Button variant="primary" size="sm" onClick={() => navigate('/hr/recruitment/postings/create')}>New Posting</Button>
                ) : undefined
              }
            />
          ) : (
            <DataTable
              columns={postingColumns}
              data={openPostings}
              onRowClick={(row) => navigate(`/hr/recruitment/postings/${row.id}`)}
            />
          )}
        </Panel>

        <Panel title="Application Pipeline">
          <ul className="space-y-2">
            {PIPELINE_STAGES.map((stage) => {
              const count = stageCounts[stage] ?? 0;
              return (
                <li key={stage}>
                  <Link
                    to={`/hr/recruitment/applications?stage=${stage}`}
                    className="flex items-center justify-between rounded-md px-3 py-2 transition-colors hover:bg-elevated group"
                  >
                    <div className="flex items-center gap-2">
                      <Chip variant={STAGE_CHIP[stage]}>{STAGE_LABEL[stage]}</Chip>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="font-mono tabular-nums text-sm font-medium">{count}</span>
                      <ArrowRight size={14} className="text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                    </div>
                  </Link>
                </li>
              );
            })}
          </ul>
        </Panel>
      </div>

      {/* Recent Applications */}
      <div className="px-5 pb-4">
        <Panel
          title="Recent Applications"
          actions={
            <Link to="/hr/recruitment/applications" className="inline-flex items-center gap-1 text-xs text-accent hover:underline">
              View all <ArrowRight size={12} />
            </Link>
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
              columns={appColumns}
              data={applications}
              onRowClick={(row) => navigate(`/hr/recruitment/applications/${row.id}`)}
            />
          )}
        </Panel>
      </div>
    </div>
  );
}
