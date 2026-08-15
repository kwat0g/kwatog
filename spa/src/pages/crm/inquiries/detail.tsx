import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import { LuMail, LuPhone, LuBuilding2 } from '@/lib/icons';
import toast from 'react-hot-toast';
import { inquiriesApi } from '@/api/crm/inquiries';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/formatDate';
import type { ContactInquiryStatus } from '@/types/crm';

const variant: Record<ContactInquiryStatus, 'info' | 'warning' | 'success' | 'neutral'> = {
 new: 'info',
 in_progress: 'warning',
 closed: 'neutral',
};

export default function InquiryDetailPage() {
 const { id = '' } = useParams();
 const queryClient = useQueryClient();
 const { can } = usePermission();
 const canManage = can('crm.inquiries.manage');

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['crm', 'inquiries', id],
 queryFn: () => inquiriesApi.show(id),
 });

 const invalidate = () => {
 queryClient.invalidateQueries({ queryKey: ['crm', 'inquiries'] });
 };

 const statusMutation = useMutation({
 mutationFn: (status: ContactInquiryStatus) => inquiriesApi.updateStatus(id, status),
 onSuccess: () => {
 toast.success('Status updated');
 invalidate();
 },
 onError: () => toast.error('Could not update status'),
 });

 if (isLoading) return <SkeletonDetail />;
 if (isError || !data) {
 return (
 <EmptyState icon="alert-circle" title="Failed to load inquiry"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
 );
 }

 const pending = statusMutation.isPending;

 return (
 <div>
 <PageHeader
 title={data.full_name}
 subtitle={data.inquiry_no}
 actions={
 <div className="flex items-center gap-2">
 <Chip variant={variant[data.status]}>{data.status_label}</Chip>
 {canManage && (
 <>
 {data.status !== 'in_progress' && (
 <Button variant="secondary" size="sm" disabled={pending}
 onClick={() => statusMutation.mutate('in_progress')}>
 Mark in progress
 </Button>
 )}
 {data.status !== 'closed' && (
 <Button variant="secondary" size="sm" disabled={pending}
 onClick={() => statusMutation.mutate('closed')}>
 Close
 </Button>
 )}
 </>
 )}
 </div>
 }
 />

 <div className="grid gap-4 px-5 py-4 lg:grid-cols-[1.6fr_1fr]">
 <Panel title="Message">
 <p className="whitespace-pre-wrap px-4 py-3 text-base leading-relaxed text-primary">
 {data.message}
 </p>
 </Panel>

 <div className="flex flex-col gap-4">
 <Panel title="Contact">
 <dl className="divide-y divide-subtle">
 <div className="flex items-center gap-2.5 px-4 py-2.5">
 <LuMail size={14} className="shrink-0 text-muted" />
 <a href={`mailto:${data.email}`} className="text-link hover:text-link-hover">{data.email}</a>
 </div>
 {data.phone && (
 <div className="flex items-center gap-2.5 px-4 py-2.5">
 <LuPhone size={14} className="shrink-0 text-muted" />
 <span className="font-mono tabular-nums">{data.phone}</span>
 </div>
 )}
 {data.company && (
 <div className="flex items-center gap-2.5 px-4 py-2.5">
 <LuBuilding2 size={14} className="shrink-0 text-muted" />
 <span>{data.company}</span>
 </div>
 )}
 </dl>
 </Panel>

 <Panel title="Submission">
 <dl className="divide-y divide-subtle text-sm">
 <div className="flex justify-between gap-4 px-4 py-2.5">
 <dt className="text-muted">Received</dt>
 <dd className="font-mono tabular-nums">{formatDateTime(data.created_at)}</dd>
 </div>
 <div className="flex justify-between gap-4 px-4 py-2.5">
 <dt className="text-muted">IP address</dt>
 <dd className="font-mono tabular-nums">{data.ip_address ?? '—'}</dd>
 </div>
 <div className="flex justify-between gap-4 px-4 py-2.5">
 <dt className="shrink-0 text-muted">LuUser agent</dt>
 <dd className="truncate font-mono text-xs" title={data.user_agent ?? undefined}>
 {data.user_agent ?? '—'}
 </dd>
 </div>
 </dl>
 </Panel>
 </div>
 </div>
 </div>
 );
}
