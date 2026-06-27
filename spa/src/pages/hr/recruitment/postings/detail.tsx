import { useParams, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Edit, Trash2 } from 'lucide-react';
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
import { formatPeso } from '@/lib/formatNumber';
import toast from 'react-hot-toast';
import type { JobPostingStatus, JobApplication, ApplicationStage } from '@/types/recruitment';

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

export default function PostingDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { can } = usePermission();

  const { data: posting, isLoading, isError, refetch } = useQuery({
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
    { key: 'stage', header: 'Stage', cell: (r) => <Chip variant={STAGE_CHIP[r.stage]}>{STAGE_LABEL[r.stage]}</Chip> },
    { key: 'applied_at', header: 'Applied', cell: (r) => <span className="font-mono text-xs tabular-nums">{formatDate(r.applied_at)}</span> },
  ];

  if (isLoading) return <SkeletonDetail />;
  if (isError || !posting) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Posting not found"
        description="The record may have been deleted or you don't have access."
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
      />
    );
  }

  return (
    <div>
      <PageHeader
        title={
          <span className="flex items-center gap-2">
            {posting.title}
            <Chip variant={STATUS_CHIP[posting.status]}>{STATUS_LABEL[posting.status]}</Chip>
          </span>
        }
        subtitle={<span className="font-mono">{posting.posting_number} · {posting.department?.name ?? ''}</span>}
        backTo="/hr/recruitment/postings"
        backLabel="Postings"
        breadcrumbs={[
          { label: 'HR', href: '/hr' },
          { label: 'Recruitment', href: '/hr/recruitment' },
          { label: 'Postings', href: '/hr/recruitment/postings' },
          { label: posting.title },
        ]}
        actions={
          can('hr.recruitment.manage') ? (
            <>
              <Button variant="secondary" size="sm" icon={<Edit size={12} />} onClick={() => navigate(`/hr/recruitment/postings/${id}/edit`)}>
                Edit
              </Button>
              {posting.status === 'draft' && (
                <Button size="sm" onClick={() => statusMutation.mutate('open')} disabled={statusMutation.isPending} loading={statusMutation.isPending}>
                  Publish
                </Button>
              )}
              {posting.status === 'open' && (
                <Button variant="secondary" size="sm" onClick={() => statusMutation.mutate('closed')}>
                  Close
                </Button>
              )}
              {posting.status === 'draft' && (
                <Button variant="danger" size="sm" icon={<Trash2 size={12} />} onClick={() => { if (confirm('Delete this posting?')) deleteMutation.mutate(); }}>
                  Delete
                </Button>
              )}
            </>
          ) : undefined
        }
      />

      <div className="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4 px-5 py-4">
        <div className="space-y-4">
          <Panel title="Description">
            <p className="whitespace-pre-line text-sm">{posting.description}</p>
          </Panel>
          <Panel title="Requirements">
            <p className="whitespace-pre-line text-sm">{posting.requirements}</p>
          </Panel>
        </div>

        <div className="space-y-4">
          <Panel title="At a glance">
            <dl className="text-sm space-y-2">
              <DetailItem label="Status">
                <Chip variant={STATUS_CHIP[posting.status]}>{STATUS_LABEL[posting.status]}</Chip>
              </DetailItem>
              <DetailItem label="Employment">
                <span className="capitalize">{posting.employment_type?.replace('_', ' ')}</span>
              </DetailItem>
              <DetailItem label="Slots">
                <span className="font-mono tabular-nums">{posting.slots}</span>
              </DetailItem>
              <DetailItem label="Applications">
                <span className="font-mono tabular-nums">{posting.application_count ?? 0}</span>
              </DetailItem>
              {posting.salary_range_min && (
                <DetailItem label="Salary">
                  <span className="font-mono tabular-nums">
                    {formatPeso(posting.salary_range_min)}
                    {posting.salary_range_max ? ` – ${formatPeso(posting.salary_range_max)}` : ''}
                  </span>
                </DetailItem>
              )}
              {posting.posted_at && (
                <DetailItem label="Posted">
                  <span className="font-mono tabular-nums">{formatDate(posting.posted_at)}</span>
                </DetailItem>
              )}
              {posting.closes_at && (
                <DetailItem label="Closes">
                  <span className="font-mono tabular-nums">{formatDate(posting.closes_at)}</span>
                </DetailItem>
              )}
            </dl>
          </Panel>
        </div>
      </div>

      <div className="px-5 pb-4">
        <Panel
          title={`Applications (${appsData?.data?.length ?? 0})`}
          noPadding
        >
          {appsData?.data?.length ? (
            <DataTable
              columns={appColumns}
              data={appsData.data}
              onRowClick={(row) => navigate(`/hr/recruitment/applications/${row.id}`)}
            />
          ) : (
            <EmptyState
              icon="inbox"
              title="No applications yet"
              description="Applications for this posting will appear here."
            />
          )}
        </Panel>
      </div>
    </div>
  );
}

function DetailItem({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-2xs uppercase tracking-wider text-muted font-medium">{label}</dt>
      <dd className="mt-0.5">{children}</dd>
    </div>
  );
}
