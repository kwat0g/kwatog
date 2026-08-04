import { useState } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { ArrowRight, CheckCircle2, Pencil, XCircle } from 'lucide-react';
import toast from 'react-hot-toast';
import { leadsApi } from '@/api/crm/leads';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import type { LeadStatus } from '@/types/crm';

const variant: Record<LeadStatus, 'success' | 'neutral' | 'info' | 'danger' | 'warning'> = {
 new: 'info',
 contacted: 'neutral',
 qualified: 'success',
 disqualified: 'danger',
 converted: 'warning',
};

export default function LeadDetailPage() {
 const { id } = useParams<{ id: string }>();
 const navigate = useNavigate();
 const qc = useQueryClient();
 const { can } = usePermission();
 const canManage = can('crm.leads.manage');
 const [disqualifyOpen, setDisqualifyOpen] = useState(false);
 const [convertOpen, setConvertOpen] = useState(false);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['crm', 'leads', 'detail', id],
 queryFn: () => leadsApi.show(id!),
 enabled: !!id,
 });

 const fail = (e: AxiosError<{ message?: string }>) => {
 toast.error(e.response?.data?.message ?? 'Action failed.');
 };

 const qualify = useMutation({
 mutationFn: () => leadsApi.qualify(id!),
 onSuccess: (lead) => {
 qc.invalidateQueries({ queryKey: ['crm', 'leads'] });
 qc.setQueryData(['crm', 'leads', 'detail', id], lead);
 toast.success('Lead qualified.');
 },
 onError: fail,
 });

 const disqualify = useMutation({
 mutationFn: (reason: string) => leadsApi.disqualify(id!, reason),
 onSuccess: (lead) => {
 qc.invalidateQueries({ queryKey: ['crm', 'leads'] });
 qc.setQueryData(['crm', 'leads', 'detail', id], lead);
 setDisqualifyOpen(false);
 toast.success('Lead disqualified.');
 },
 onError: fail,
 });

 const convert = useMutation({
 mutationFn: () => leadsApi.convert(id!),
 onSuccess: (opportunity) => {
 qc.invalidateQueries({ queryKey: ['crm', 'leads'] });
 qc.invalidateQueries({ queryKey: ['crm', 'opportunities'] });
 setConvertOpen(false);
 toast.success(`Converted to ${opportunity.opportunity_number}.`);
 navigate(`/crm/opportunities/${opportunity.id}`);
 },
 onError: fail,
 });

 if (isLoading) return <div><PageHeader title="Lead" backTo="/crm/leads" backLabel="Leads"
 breadcrumbs={[{ label: 'CRM', href: '/crm' }, { label: 'Leads', href: '/crm/leads' }, { label: 'Loading…' }]} /><SkeletonDetail /></div>;
 if (isError || !data) return (
 <div>
 <PageHeader title="Lead" backTo="/crm/leads" backLabel="Leads"
 breadcrumbs={[{ label: 'CRM', href: '/crm' }, { label: 'Leads', href: '/crm/leads' }, { label: 'Error' }]} />
 <EmptyState icon="alert-circle" title="Failed to load lead"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
 </div>
 );

 const lead = data;

 return (
 <div>
 <PageHeader
 title={
 <div className="flex items-center gap-3">
 <span className="font-mono">{lead.lead_number}</span>
 <span className="font-medium">{lead.company_name}</span>
 <Chip variant={variant[lead.status]}>{lead.status_label}</Chip>
 </div>
 }
 backTo="/crm/leads"
 backLabel="Leads"
 breadcrumbs={[{ label: 'CRM', href: '/crm' }, { label: 'Leads', href: '/crm/leads' }, { label: lead.lead_number }]}
 actions={canManage ? (
 <div className="flex items-center gap-1.5">
 {lead.status === 'new' && (
 <Button variant="secondary" size="sm" icon={<CheckCircle2 size={14} />}
 onClick={() => qualify.mutate()} disabled={qualify.isPending}>
 {qualify.isPending ? 'Qualifying…' : 'Qualify'}
 </Button>
 )}
 {lead.status === 'qualified' && (
 <Button variant="primary" size="sm" icon={<ArrowRight size={14} />}
 onClick={() => setConvertOpen(true)} disabled={convert.isPending}>
 {convert.isPending ? 'Converting…' : 'Convert to opportunity'}
 </Button>
 )}
 {lead.status !== 'converted' && lead.status !== 'disqualified' && (
 <Button variant="secondary" size="sm" icon={<XCircle size={14} />}
 onClick={() => setDisqualifyOpen(true)}>
 Disqualify
 </Button>
 )}
 <Button variant="secondary" size="sm" icon={<Pencil size={14} />}
 onClick={() => navigate(`/crm/leads/${lead.id}/edit`)}>
 Edit
 </Button>
 </div>
 ) : null}
 />

 <div className="px-5 py-4 grid grid-cols-3 gap-4">
 <div className="col-span-2 space-y-4">
 <Panel title="Contact">
 <dl className="grid grid-cols-3 gap-y-2 gap-x-3 text-sm">
 <dt className="text-muted">Contact Person</dt>
 <dd className="col-span-2">{lead.contact_person}</dd>
 <dt className="text-muted">Email</dt>
 <dd className="col-span-2">{lead.email ?? '—'}</dd>
 <dt className="text-muted">Phone</dt>
 <dd className="col-span-2 font-mono">{lead.phone ?? '—'}</dd>
 <dt className="text-muted">Source</dt>
 <dd className="col-span-2">{lead.source_label}</dd>
 <dt className="text-muted">Estimated Value</dt>
 <dd className="col-span-2 font-mono tabular-nums">{formatPeso(lead.estimated_value)}</dd>
 <dt className="text-muted">Assigned To</dt>
 <dd className="col-span-2">{lead.assignee?.name ?? 'Unassigned'}</dd>
 <dt className="text-muted">Customer</dt>
 <dd className="col-span-2">{lead.customer
 ? <Link className="text-link hover:underline" to={`/crm/customers/${lead.customer.id}`}>{lead.customer.name}</Link>
 : '—'}</dd>
 </dl>
 </Panel>

 {lead.notes && (
 <Panel title="Notes">
 <p className="text-sm whitespace-pre-wrap">{lead.notes}</p>
 </Panel>
 )}
 </div>

 <div className="space-y-4">
 <Panel title="Pipeline">
 <dl className="text-sm space-y-2">
 <div className="flex justify-between"><dt className="text-muted">Status</dt><dd className="font-mono">{lead.status}</dd></div>
 <div className="flex justify-between"><dt className="text-muted">Created</dt><dd className="font-mono tabular-nums">{new Date(lead.created_at).toLocaleDateString()}</dd></div>
 {lead.converted_to_opportunity_id && (
 <div className="flex justify-between">
 <dt className="text-muted">Converted to</dt>
 <dd><Link className="text-link hover:underline font-mono" to={`/crm/opportunities/${lead.converted_to_opportunity_id}`}>Opportunity</Link></dd>
 </div>
 )}
 </dl>
 </Panel>
 </div>
 </div>

 <ReasonDialog
 isOpen={disqualifyOpen}
 onClose={() => setDisqualifyOpen(false)}
 onConfirm={(reason) => disqualify.mutate(reason)}
 title="Disqualify lead"
 description={`Mark ${lead.company_name} as disqualified. The reason is appended to the lead notes.`}
 confirmLabel="Disqualify"
 pending={disqualify.isPending}
 />

 <ConfirmDialog
 isOpen={convertOpen}
 onClose={() => setConvertOpen(false)}
 onConfirm={() => convert.mutate()}
 title="Convert to opportunity"
 description={`Create an opportunity from ${lead.company_name}? Requires the lead to be linked to a customer and have an estimated value.`}
 confirmLabel="Convert"
 pending={convert.isPending}
 />
 </div>
 );
}
