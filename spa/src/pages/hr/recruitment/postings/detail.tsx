import { useParams, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Edit, Trash2 } from 'lucide-react';
import { recruitmentApi } from '@/api/recruitment';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { usePermission } from '@/hooks/usePermission';
import toast from 'react-hot-toast';
import type { JobPostingStatus, JobApplication, ApplicationStage } from '@/types/recruitment';

const STATUS_CHIP: Record<JobPostingStatus, 'neutral' | 'success' | 'warning' | 'info'> = {
  draft: 'neutral',
  open: 'success',
  closed: 'warning',
  filled: 'info',
};

const STAGE_CHIP: Record<ApplicationStage, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
  new: 'neutral',
  screening: 'info',
  interview: 'warning',
  offer: 'info',
  hired: 'success',
  rejected: 'danger',
};

export default function PostingDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { can } = usePermission();

  const { data: posting, isLoading } = useQuery({
    queryKey: ['recruitment-posting', id],
    queryFn: () => recruitmentApi.showPosting(id!).then((r) => r.data.data),
    enabled: !!id,
  });

  const { data: appsData } = useQuery({
    queryKey: ['recruitment-applications', { job_posting_id: id }],
    queryFn: () => recruitmentApi.listApplications({ job_posting_id: id }).then((r) => r.data),
    enabled: !!id,
  });

  const statusMutation = useMutation({
    mutationFn: (status: string) => recruitmentApi.changePostingStatus(id!, status),
    onSuccess: () => {
      toast.success('Status updated.');
      queryClient.invalidateQueries({ queryKey: ['recruitment-posting', id] });
    },
    onError: () => toast.error('Failed to update status.'),
  });

  const deleteMutation = useMutation({
    mutationFn: () => recruitmentApi.deletePosting(id!),
    onSuccess: () => {
      toast.success('Posting deleted.');
      navigate('/hr/recruitment/postings');
    },
  });

  const appColumns: Column<JobApplication>[] = [
    { key: 'full_name', header: 'Applicant', cell: (r) => <span className="font-medium">{r.full_name}</span> },
    { key: 'stage', header: 'Stage', cell: (r) => <Chip variant={STAGE_CHIP[r.stage]}>{r.stage_label}</Chip> },
    { key: 'applied_at', header: 'Applied', cell: (r) => <span className="font-mono text-xs tabular-nums">{new Date(r.applied_at).toLocaleDateString()}</span> },
  ];

  if (isLoading) return <SkeletonTable rows={5} columns={4} />;
  if (!posting) return <p className="text-muted">Posting not found.</p>;

  return (
    <div>
      <PageHeader
        title={posting.title}
        subtitle={`${posting.posting_number} · ${posting.department?.name ?? ''}`}
        actions={
          can('hr.recruitment.manage') ? (
            <div className="flex gap-2">
              <Button variant="secondary" size="sm" onClick={() => navigate(`/hr/recruitment/postings/${id}/edit`)}>
                <Edit size={14} /> Edit
              </Button>
              {posting.status === 'draft' && (
                <Button size="sm" onClick={() => statusMutation.mutate('open')} disabled={statusMutation.isPending}>
                  Publish
                </Button>
              )}
              {posting.status === 'open' && (
                <Button variant="secondary" size="sm" onClick={() => statusMutation.mutate('closed')}>
                  Close
                </Button>
              )}
              {posting.status === 'draft' && (
                <Button variant="danger" size="sm" onClick={() => { if (confirm('Delete this posting?')) deleteMutation.mutate(); }}>
                  <Trash2 size={14} />
                </Button>
              )}
            </div>
          ) : undefined
        }
      />

      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <section>
            <h3 className="text-sm font-semibold text-muted uppercase tracking-wide">Description</h3>
            <p className="mt-1 whitespace-pre-line text-sm">{posting.description}</p>
          </section>
          <section>
            <h3 className="text-sm font-semibold text-muted uppercase tracking-wide">Requirements</h3>
            <p className="mt-1 whitespace-pre-line text-sm">{posting.requirements}</p>
          </section>
        </div>

        <div className="space-y-3 rounded-lg border border-border p-4">
          <div className="flex items-center justify-between">
            <span className="text-sm text-muted">Status</span>
            <Chip variant={STATUS_CHIP[posting.status]}>{posting.status}</Chip>
          </div>
          <div className="flex items-center justify-between">
            <span className="text-sm text-muted">Employment</span>
            <span className="text-sm capitalize">{posting.employment_type?.replace('_', ' ')}</span>
          </div>
          <div className="flex items-center justify-between">
            <span className="text-sm text-muted">Slots</span>
            <span className="text-sm font-mono tabular-nums">{posting.slots}</span>
          </div>
          <div className="flex items-center justify-between">
            <span className="text-sm text-muted">Applications</span>
            <span className="text-sm font-mono tabular-nums">{posting.application_count ?? 0}</span>
          </div>
          {posting.salary_range_min && (
            <div className="flex items-center justify-between">
              <span className="text-sm text-muted">Salary</span>
              <span className="text-sm font-mono tabular-nums">
                ₱{Number(posting.salary_range_min).toLocaleString()}
                {posting.salary_range_max ? ` – ₱${Number(posting.salary_range_max).toLocaleString()}` : ''}
              </span>
            </div>
          )}
          {posting.posted_at && (
            <div className="flex items-center justify-between">
              <span className="text-sm text-muted">Posted</span>
              <span className="text-sm font-mono text-xs tabular-nums">{new Date(posting.posted_at).toLocaleDateString()}</span>
            </div>
          )}
          {posting.closes_at && (
            <div className="flex items-center justify-between">
              <span className="text-sm text-muted">Closes</span>
              <span className="text-sm font-mono text-xs tabular-nums">{new Date(posting.closes_at).toLocaleDateString()}</span>
            </div>
          )}
        </div>
      </div>

      <div className="mt-8">
        <h2 className="text-lg font-semibold">Applications</h2>
        <div className="mt-3">
          {appsData?.data?.length ? (
            <DataTable
              columns={appColumns}
              data={appsData.data}
              onRowClick={(row) => navigate(`/hr/recruitment/applications/${row.id}`)}
            />
          ) : (
            <p className="py-8 text-center text-muted">No applications yet.</p>
          )}
        </div>
      </div>
    </div>
  );
}
