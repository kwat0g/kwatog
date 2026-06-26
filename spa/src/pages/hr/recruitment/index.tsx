import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Briefcase, Users, FileText, Plus } from 'lucide-react';
import { recruitmentApi } from '@/api/recruitment';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
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

  const { data: postingsData } = useQuery({
    queryKey: ['recruitment-postings', { status: 'open' }],
    queryFn: () => recruitmentApi.listPostings({ status: 'open', per_page: 5 }).then((r) => r.data),
  });

  const { data: applicationsData } = useQuery({
    queryKey: ['recruitment-applications', { per_page: 10 }],
    queryFn: () => recruitmentApi.listApplications({ per_page: 10 }).then((r) => r.data),
  });

  const applications = applicationsData?.data ?? [];
  const openPostings = postingsData?.meta?.total ?? 0;

  const stageCounts = applications.reduce<Record<string, number>>((acc, app: JobApplication) => {
    acc[app.stage] = (acc[app.stage] ?? 0) + 1;
    return acc;
  }, {});

  return (
    <div>
      <PageHeader
        title="Recruitment"
        subtitle="Manage job postings and applications"
        actions={
          can('hr.recruitment.manage') ? (
            <Button onClick={() => navigate('/hr/recruitment/postings/create')}>
              <Plus size={16} /> New Posting
            </Button>
          ) : undefined
        }
      />

      <div className="mt-6 grid gap-4 sm:grid-cols-3">
        <button
          onClick={() => navigate('/hr/recruitment/postings')}
          className="rounded-lg border border-border bg-surface p-5 text-left transition-colors hover:bg-muted/30"
        >
          <div className="flex items-center gap-3">
            <Briefcase size={20} className="text-muted" />
            <div>
              <p className="text-2xl font-bold font-mono tabular-nums">{openPostings}</p>
              <p className="text-sm text-muted">Open Postings</p>
            </div>
          </div>
        </button>

        <button
          onClick={() => navigate('/hr/recruitment/applications')}
          className="rounded-lg border border-border bg-surface p-5 text-left transition-colors hover:bg-muted/30"
        >
          <div className="flex items-center gap-3">
            <Users size={20} className="text-muted" />
            <div>
              <p className="text-2xl font-bold font-mono tabular-nums">{applicationsData?.meta?.total ?? 0}</p>
              <p className="text-sm text-muted">Total Applications</p>
            </div>
          </div>
        </button>

        <button
          onClick={() => navigate('/hr/recruitment/applications?stage=new')}
          className="rounded-lg border border-border bg-surface p-5 text-left transition-colors hover:bg-muted/30"
        >
          <div className="flex items-center gap-3">
            <FileText size={20} className="text-muted" />
            <div>
              <p className="text-2xl font-bold font-mono tabular-nums">{stageCounts['new'] ?? 0}</p>
              <p className="text-sm text-muted">New Applications</p>
            </div>
          </div>
        </button>
      </div>

      <div className="mt-8">
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-semibold">Recent Applications</h2>
          <Button variant="ghost" size="sm" onClick={() => navigate('/hr/recruitment/applications')}>
            View all
          </Button>
        </div>
        <div className="mt-3 overflow-x-auto rounded-lg border border-border">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border bg-muted/30">
                <th className="px-4 py-2 text-left font-medium">Applicant</th>
                <th className="px-4 py-2 text-left font-medium">Position</th>
                <th className="px-4 py-2 text-left font-medium">Stage</th>
                <th className="px-4 py-2 text-left font-medium">Applied</th>
              </tr>
            </thead>
            <tbody>
              {applications.length === 0 ? (
                <tr>
                  <td colSpan={4} className="px-4 py-8 text-center text-muted">No applications yet.</td>
                </tr>
              ) : (
                applications.map((app: JobApplication) => (
                  <tr
                    key={app.id}
                    onClick={() => navigate(`/hr/recruitment/applications/${app.id}`)}
                    className="cursor-pointer border-b border-border last:border-0 hover:bg-muted/20"
                  >
                    <td className="px-4 py-2 font-medium">{app.full_name}</td>
                    <td className="px-4 py-2">{app.job_posting?.title ?? '—'}</td>
                    <td className="px-4 py-2">
                      <Chip variant={STAGE_CHIP[app.stage]}>{STAGE_LABEL[app.stage]}</Chip>
                    </td>
                    <td className="px-4 py-2 font-mono text-xs tabular-nums">
                      {new Date(app.applied_at).toLocaleDateString()}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
