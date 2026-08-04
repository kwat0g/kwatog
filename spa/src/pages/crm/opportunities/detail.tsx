import { useState } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { ArrowRight, FileText, Pencil, Trophy, XCircle } from 'lucide-react';
import toast from 'react-hot-toast';
import { opportunitiesApi } from '@/api/crm/opportunities';
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
import type { OpportunityStage } from '@/types/crm';

const variant: Record<OpportunityStage, 'success' | 'neutral' | 'info' | 'warning' | 'danger'> = {
 prospecting: 'neutral',
 needs_analysis: 'info',
 proposal: 'warning',
 negotiation: 'info',
 won: 'success',
 lost: 'danger',
};

const STAGE_ORDER: OpportunityStage[] = ['prospecting', 'needs_analysis', 'proposal', 'negotiation'];

export default function OpportunityDetailPage() {
 const { id } = useParams<{ id: string }>();
 const navigate = useNavigate();
 const qc = useQueryClient();
 const { can } = usePermission();
 const canManage = can('crm.opportunities.manage');
 const canQuote = can('crm.quotes.manage');
 const [loseOpen, setLoseOpen] = useState(false);
 const [winOpen, setWinOpen] = useState(false);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['crm', 'opportunities', 'detail', id],
 queryFn: () => opportunitiesApi.show(id!),
 enabled: !!id,
 });

 const fail = (e: AxiosError<{ message?: string }>) => {
 toast.error(e.response?.data?.message ?? 'Action failed.');
 };

 const invalidate = () => {
 qc.invalidateQueries({ queryKey: ['crm', 'opportunities'] });
 };

 const advance = useMutation({
 mutationFn: () => opportunitiesApi.advance(id!),
 onSuccess: (opp) => {
 invalidate();
 qc.setQueryData(['crm', 'opportunities', 'detail', id], opp);
 toast.success(`Moved to ${opp.stage_label}.`);
 },
 onError: fail,
 });

 const win = useMutation({
 mutationFn: () => opportunitiesApi.win(id!),
 onSuccess: (opp) => {
 invalidate();
 qc.setQueryData(['crm', 'opportunities', 'detail', id], opp);
 setWinOpen(false);
 toast.success('Opportunity won.');
 },
 onError: fail,
 });

 const lose = useMutation({
 mutationFn: (reason: string) => opportunitiesApi.lose(id!, reason),
 onSuccess: (opp) => {
 invalidate();
 qc.setQueryData(['crm', 'opportunities', 'detail', id], opp);
 setLoseOpen(false);
 toast.success('Opportunity marked lost.');
 },
 onError: fail,
 });

 const createQuote = useMutation({
 mutationFn: () => opportunitiesApi.createQuote(id!),
 onSuccess: (quote) => {
 invalidate();
 toast.success(`Quote ${quote.quote_number} created (draft).`);
 },
 onError: fail,
 });

 if (isLoading) return <div><PageHeader title="Opportunity" backTo="/crm/opportunities" backLabel="Opportunities"
 breadcrumbs={[{ label: 'CRM', href: '/crm' }, { label: 'Opportunities', href: '/crm/opportunities' }, { label: 'Loading…' }]} /><SkeletonDetail /></div>;
 if (isError || !data) return (
 <div>
 <PageHeader title="Opportunity" backTo="/crm/opportunities" backLabel="Opportunities"
 breadcrumbs={[{ label: 'CRM', href: '/crm' }, { label: 'Opportunities', href: '/crm/opportunities' }, { label: 'Error' }]} />
 <EmptyState icon="alert-circle" title="Failed to load opportunity"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
 </div>
 );

 const opp = data;
 const stageIdx = STAGE_ORDER.indexOf(opp.stage);
 const canAdvance = !opp.is_terminal && stageIdx >= 0 && stageIdx < STAGE_ORDER.length - 1;

 return (
 <div>
 <PageHeader
 title={
 <div className="flex items-center gap-3">
 <span className="font-mono">{opp.opportunity_number}</span>
 <span className="font-medium">{opp.title}</span>
 <Chip variant={variant[opp.stage]}>{opp.stage_label}</Chip>
 {opp.is_terminal && <Chip variant={opp.stage === 'won' ? 'success' : 'danger'}>Closed</Chip>}
 </div>
 }
 backTo="/crm/opportunities"
 backLabel="Opportunities"
 breadcrumbs={[{ label: 'CRM', href: '/crm' }, { label: 'Opportunities', href: '/crm/opportunities' }, { label: opp.opportunity_number }]}
 actions={canManage ? (
 <div className="flex items-center gap-1.5">
 {canAdvance && (
 <Button variant="secondary" size="sm" icon={<ArrowRight size={14} />}
 onClick={() => advance.mutate()} disabled={advance.isPending}>
 {advance.isPending ? 'Advancing…' : 'Advance stage'}
 </Button>
 )}
 {!opp.is_terminal && (
 <>
 <Button variant="primary" size="sm" icon={<Trophy size={14} />} onClick={() => setWinOpen(true)}>
 Win
 </Button>
 <Button variant="secondary" size="sm" icon={<XCircle size={14} />} onClick={() => setLoseOpen(true)}>
 Lose
 </Button>
 </>
 )}
 {!opp.is_terminal && (
 <Button variant="secondary" size="sm" icon={<Pencil size={14} />}
 onClick={() => navigate(`/crm/opportunities/${opp.id}/edit`)}>
 Edit
 </Button>
 )}
 </div>
 ) : null}
 />

 <div className="px-5 py-4 grid grid-cols-3 gap-4">
 <div className="col-span-2 space-y-4">
 <Panel title="Details">
 <dl className="grid grid-cols-3 gap-y-2 gap-x-3 text-sm">
 <dt className="text-muted">Customer</dt>
 <dd className="col-span-2">{opp.customer
 ? <Link className="text-link hover:underline" to={`/crm/customers/${opp.customer.id}`}>{opp.customer.name}</Link>
 : '—'}</dd>
 <dt className="text-muted">Source Lead</dt>
 <dd className="col-span-2">{opp.lead
 ? <Link className="text-link hover:underline" to={`/crm/leads/${opp.lead.id}`}>{opp.lead.company_name}</Link>
 : '—'}</dd>
 <dt className="text-muted">Estimated Value</dt>
 <dd className="col-span-2 font-mono tabular-nums">{formatPeso(opp.estimated_value)}</dd>
 <dt className="text-muted">Probability</dt>
 <dd className="col-span-2 font-mono tabular-nums">{opp.probability}%</dd>
 <dt className="text-muted">Expected Close</dt>
 <dd className="col-span-2 font-mono tabular-nums">{opp.expected_close_date ? new Date(opp.expected_close_date).toLocaleDateString() : '—'}</dd>
 <dt className="text-muted">Assigned To</dt>
 <dd className="col-span-2">{opp.assignee?.name ?? 'Unassigned'}</dd>
 {opp.lost_reason && (
 <>
 <dt className="text-muted">Lost Reason</dt>
 <dd className="col-span-2">{opp.lost_reason}</dd>
 </>
 )}
 </dl>
 </Panel>

 {opp.notes && (
 <Panel title="Notes">
 <p className="text-sm whitespace-pre-wrap">{opp.notes}</p>
 </Panel>
 )}
 </div>

 <div className="space-y-4">
 <Panel title="Pipeline">
 <div className="space-y-1.5">
 {STAGE_ORDER.map((stage) => {
 const idx = STAGE_ORDER.indexOf(stage);
 const done = stageIdx > idx;
 const current = stage === opp.stage;
 return (
 <div key={stage} className={`flex items-center justify-between rounded px-2 py-1.5 text-sm ${current ? 'bg-subtle' : ''}`}>
 <span className={done || current ? 'font-medium' : 'text-muted'}>{variantLabel(stage)}</span>
 {current && <span className="text-2xs font-medium uppercase tracking-wider text-accent">Current</span>}
 {done && !current && <Chip variant="success">Done</Chip>}
 </div>
 );
 })}
 {opp.is_terminal && (
 <div className="flex items-center justify-between rounded px-2 py-1.5 text-sm bg-subtle">
 <span className="font-medium">{opp.stage === 'won' ? 'Won' : 'Lost'}</span>
 <Chip variant={opp.stage === 'won' ? 'success' : 'danger'}>Closed</Chip>
 </div>
 )}
 </div>
 </Panel>

 <Panel title="Quote">
 {canQuote && !opp.is_terminal ? (
 <Button variant="secondary" size="sm" className="w-full" icon={<FileText size={14} />}
 onClick={() => createQuote.mutate()} disabled={createQuote.isPending}>
 {createQuote.isPending ? 'Creating…' : 'Create draft quote'}
 </Button>
 ) : (
 <p className="text-xs text-muted">Quotes are generated from an open opportunity.</p>
 )}
 </Panel>
 </div>
 </div>

 <ReasonDialog
 isOpen={loseOpen}
 onClose={() => setLoseOpen(false)}
 onConfirm={(reason) => lose.mutate(reason)}
 title="Mark opportunity as lost"
 description="A loss reason is required — it feeds win/loss analysis."
 reasonLabel="Loss reason"
 confirmLabel="Mark lost"
 variant="danger"
 pending={lose.isPending}
 />

 <ConfirmDialog
 isOpen={winOpen}
 onClose={() => setWinOpen(false)}
 onConfirm={() => win.mutate()}
 title="Mark opportunity as won"
 description="Close the opportunity as won. Probability is set to 100%."
 confirmLabel="Mark won"
 variant="primary"
 pending={win.isPending}
 />
 </div>
 );
}

function variantLabel(stage: OpportunityStage): string {
 switch (stage) {
 case 'prospecting': return 'Prospecting';
 case 'needs_analysis': return 'Needs Analysis';
 case 'proposal': return 'Proposal';
 case 'negotiation': return 'Negotiation';
 case 'won': return 'Won';
 case 'lost': return 'Lost';
 }
}
