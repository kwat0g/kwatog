import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { rfqsApi } from '@/api/purchasing/rfqs';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import type { RequestForQuote } from '@/types/purchasing';

export default function RfqDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate(); const qc = useQueryClient();
  const { can } = usePermission();
  const query = useQuery({ queryKey: ['purchasing', 'rfqs', id], queryFn: () => rfqsApi.show(id), enabled: !!id });
  const refresh = () => qc.invalidateQueries({ queryKey: ['purchasing', 'rfqs', id] });
  const publish = useMutation({ mutationFn: () => rfqsApi.publish(id), onSuccess: () => { refresh(); toast.success('RFQ published to invited suppliers.'); }, onError: () => toast.error('RFQ could not be published.') });
  if (query.isLoading) return <SkeletonTable columns={5} rows={6} />;
  if (query.isError || !query.data) return <EmptyState icon="alert-circle" title="Failed to load RFQ" action={<Button onClick={() => query.refetch()}>Retry</Button>} />;
  const rfq: RequestForQuote = query.data;
  return <div><PageHeader title={<span className="font-mono">{rfq.rfq_number}</span>} subtitle={rfq.title} backTo="/purchasing/rfqs" backLabel="Supplier RFQs" actions={<div className="flex gap-2"><Chip variant={chipVariantForStatus(rfq.status)}>{rfq.status_label ?? rfq.status.replace(/_/g, ' ')}</Chip>{rfq.status === 'draft' && can('purchasing.rfq.publish') && <Button size="sm" variant="primary" onClick={() => publish.mutate()} loading={publish.isPending}>Publish</Button>}{['closed', 'under_evaluation'].includes(rfq.status) && can('purchasing.rfq.evaluate') && <Button size="sm" variant="primary" onClick={() => navigate(`/purchasing/rfqs/${rfq.id}/compare`)}>Compare quotations</Button>}</div>} />
    <div className="px-5 py-4 grid lg:grid-cols-3 gap-4"><div className="lg:col-span-2 space-y-4"><Panel title="Requirements"><p className="text-sm text-muted mb-3">{rfq.instructions || 'No additional instructions.'}</p><div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-muted"><th className="py-2">Requirement</th><th className="py-2">Quantity</th><th className="py-2">Required date</th></tr></thead><tbody>{rfq.items?.map((item) => <tr key={item.id} className="border-b border-subtle"><td className="py-2">{item.description}<div className="text-xs text-muted">{item.item?.code ?? 'Ad hoc line'}</div></td><td className="py-2 font-mono">{item.quantity} {item.unit ?? ''}</td><td className="py-2 font-mono">{item.required_delivery_date ? formatDate(item.required_delivery_date) : '—'}</td></tr>)}</tbody></table></div></Panel><Panel title="Supplier invitations"><div className="space-y-2">{rfq.invitations?.map((invitation) => <div key={invitation.id} className="flex justify-between border-b border-subtle py-2 last:border-0"><span>{invitation.vendor?.name ?? 'Supplier'}</span><Chip variant={chipVariantForStatus(invitation.status)}>{invitation.status.replace(/_/g, ' ')}</Chip></div>)}</div></Panel></div><div className="space-y-4"><Panel title="Sourcing control"><dl className="space-y-3 text-sm"><div><dt className="text-muted">Source PR</dt><dd><Link className="text-link" to={`/purchasing/purchase-requests/${rfq.purchase_request?.id}`}>{rfq.purchase_request?.pr_number ?? '—'}</Link></dd></div><div><dt className="text-muted">Deadline</dt><dd className="font-mono">{formatDate(rfq.closes_at)}</dd></div><div><dt className="text-muted">Prices</dt><dd>{rfq.status === 'open' ? 'Sealed until closure' : 'Available to authorized evaluators'}</dd></div></dl></Panel><Panel title="Award outcome"><p className="text-sm text-muted">{rfq.awards?.length ? `${rfq.awards.length} line award(s) recorded.` : rfq.no_award_reason ?? 'No awards recorded yet.'}</p></Panel></div></div>
  </div>;
}
