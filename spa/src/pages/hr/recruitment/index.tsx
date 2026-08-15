import { useQuery } from '@tanstack/react-query';
import { useNavigate, Link } from 'react-router-dom';
import { LuPlus, LuArrowRight } from '@/lib/icons';
import { recruitmentApi } from '@/api/recruitment';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { StageBreakdown } from '@/components/ui/StageBreakdown';
import { PageHeader } from '@/components/layout/PageHeader';
import { DashboardBody, KpiGrid } from '@/components/dashboard/DashboardShell';
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

export default function RecruitmentDashboard() {
 const navigate = useNavigate();
 const { can } = usePermission();

 const { data: postingsData, isLoading: postingsLoading } = useQuery({
 queryKey: ['recruitment-postings', { status: 'open', per_page: 5 }],
 queryFn: () => recruitmentApi.listPostings({ status: 'open', per_page: 5 }).then((r) => r.data),
 placeholderData: (prev) => prev,
 });

 const { data: applicationsData, isLoading: appsLoading } = useQuery({
 queryKey: ['recruitment-applications', { per_page: 10 }],
 queryFn: () => recruitmentApi.listApplications({ per_page: 10 }).then((r) => r.data),
 });
 const { data: recruitmentOptions } = useQuery({
 queryKey: ['recruitment', 'options'],
 queryFn: () => recruitmentApi.options().then((r) => r.data.data),
 staleTime: 5 * 60 * 1000,
 });

 const openPostings = postingsData?.data ?? [];
 const applications = applicationsData?.data ?? [];
 const totalApps = applicationsData?.meta?.total ?? 0;
 const stageLabel = new Map((recruitmentOptions?.application_stages ?? []).map((stage) => [stage.value, stage.label]));
 const postingStatusLabel = new Map((recruitmentOptions?.posting_statuses ?? []).map((status) => [status.value, status.label]));
 const pipelineStages = (recruitmentOptions?.application_stages ?? []).filter((stage) => !stage.is_terminal);

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
 { key: 'stage', header: 'Stage', cell: (r) => <Chip variant={STAGE_CHIP[r.stage]}>{stageLabel.get(r.stage) ?? r.stage}</Chip> },
 { key: 'applied_at', header: 'Applied', cell: (r) => <span className="font-mono text-xs tabular-nums">{formatDate(r.applied_at)}</span> },
 ];

 return (
 <div>
 <PageHeader
 title="Recruitment"
 subtitle="Manage job postings and applications"
 breadcrumbs={[
 { label: 'HR', href: '/hr/employees' },
 { label: 'Recruitment' },
 ]}
 actions={
 can('hr.recruitment.manage') ? (
 <Button variant="primary" size="sm" icon={<LuPlus size={14} />} onClick={() => navigate('/hr/recruitment/postings/create')}>
 New Posting
 </Button>
 ) : undefined
 }
 />

 <DashboardBody>
 {/* KPI Cards Row */}
 <KpiGrid count={4}>
 <StatCard label={`${postingStatusLabel.get('open') ?? '—'} postings`} value={openPostings.length} />
 <StatCard label="Total Applications" value={totalApps} />
 <StatCard label={`${stageLabel.get('new') ?? '—'} applications`} value={stageCounts['new'] ?? 0} />
 <StatCard label={`${stageLabel.get('interview') ?? '—'} applications`} value={stageCounts['interview'] ?? 0} />
 </KpiGrid>

 <div className="grid grid-cols-1 lg:grid-cols-3 gap-3">
 {/* Left Column (2/3) */}
 <div className="lg:col-span-2 space-y-3">
 <Panel
 title="Recent Applications"
 actions={
 <Link to="/hr/recruitment/applications" className="inline-flex items-center gap-1 text-xs text-accent hover:underline">
 View all <LuArrowRight size={12} />
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
 onRowClick={(row) => navigate(`/hr/recruitment/applications/${row.id}`)}
 columns={appColumns}
 data={applications}
 />
 )}
 </Panel>

 <Panel
 title="Open Postings"
 actions={
 <Link to="/hr/recruitment/postings" className="inline-flex items-center gap-1 text-xs text-accent hover:underline">
 View all <LuArrowRight size={12} />
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
 onRowClick={(row) => navigate(`/hr/recruitment/postings/${row.id}`)}
 columns={postingColumns}
 data={openPostings}
 />
 )}
 </Panel>
 </div>

 {/* Right Column (1/3) */}
 <div className="space-y-3">
 <StageBreakdown
 title="Application Pipeline"
 stages={pipelineStages.map((stage) => {
 const count = stageCounts[stage.value] ?? 0;
 const percent = totalApps > 0 ? (count / totalApps) * 100 : 0;
 return {
 label: stage.label,
 count,
 percent,
 color: STAGE_CHIP[stage.value as ApplicationStage]
 };
 })}
 />
 </div>
 </div>
 </DashboardBody>
 </div>
 );
}
