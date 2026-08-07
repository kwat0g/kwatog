import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import { ArrowUpRight, Mail, Phone, Building2 } from 'lucide-react';
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
 converted: 'success',
 closed: 'neutral',
};

export default function InquiryDetailPage() {
 const { id = '' } = useParams();
 const navigate = useNavigate();
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
 mutationFn: (status: Exclude<ContactInquiryStatus, 'converted'>) => inquiriesApi.updateStatus(id, status),
 onSuccess: () => {
 toast.success('Status updated');
 invalidate();
 },
 onError: () => toast.error('Could not update status'),
 });

 const convertMutation = useMutation({
 mutationFn: () => inquiriesApi.convertToLead(id),
 onSuccess: (lead) => {
 toast.success(`Converted to lead ${lead.lead_number}`);
 invalidate();
 queryClient.invalidateQueries({ queryKey: ['crm', 'leads'] });
 navigate(`/crm/leads/${lead.id}`);
 },
 onError: () => toast.error('Could not convert this inquiry'),
 });

 if (isLoading) return <SkeletonDetail />;
 if (isError || !data) {
 return (
 <EmptyState icon="alert-circle" title="Failed to load inquiry"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
 );
 }

 const isConverted = data.status === 'converted';
 const pending = statusMutation.isPending || convertMutation.isPending;

 return (
 <div>
 <PageHeader
 title={data.full_name}
 subtitle={data.inquiry_no}
 actions={
 <div className="flex items-center gap-2">
 <Chip variant={variant[data.status]}>{data.status_label}</Chip>
 {canManage && !isConverted && (
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
 <Button variant="primary" size="sm" disabled={pending}
 loading={convertMutation.isPending}
 icon={<ArrowUpRight size={14} />}
 onClick={() => convertMutation.mutate()}>
 Convert to lead
 </Button>
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
 <Mail size={14} className="shrink-0 text-muted" />
 <a href={`mailto:${data.email}`} className="text-link hover:text-link-hover">{data.email}</a>
 </div>
 {data.phone && (
 <div className="flex items-center gap-2.5 px-4 py-2.5">
 <Phone size={14} className="shrink-0 text-muted" />
 <span className="font-mono tabular-nums">{data.phone}</span>
 </div>
 )}
 {data.company && (
 <div className="flex items-center gap-2.5 px-4 py-2.5">
 <Building2 size={14} className="shrink-0 text-muted" />
 <span>{data.company}</span>
 </div>
 )}
 </dl>
 </Panel>

 {data.converted_to_lead && (
 <Panel title="Converted">
 <div className="px-4 py-3">
 <button type="button"
 onClick={() => navigate(`/crm/leads/${data.converted_to_lead!.id}`)}
 className="font-mono text-link hover:text-link-hover">
 {data.converted_to_lead.lead_number}
 </button>
 </div>
 </Panel>
 )}

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
 <dt className="shrink-0 text-muted">User agent</dt>
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
