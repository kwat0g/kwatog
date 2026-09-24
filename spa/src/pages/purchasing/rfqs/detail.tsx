import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { rfqsApi } from '@/api/purchasing/rfqs';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate, formatDateTime } from '@/lib/formatDate';
import { formatPeso, formatQuantity } from '@/lib/formatNumber';


export default function RfqDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [cancelOpen, setCancelOpen] = useState(false);
  const [extensionDeadline, setExtensionDeadline] = useState('');
  const [extensionReason, setExtensionReason] = useState('');
  const [addendumTitle, setAddendumTitle] = useState('');
  const [addendumBody, setAddendumBody] = useState('');
  const [materialChange, setMaterialChange] = useState(false);
  const [extensionDays, setExtensionDays] = useState('2');
  const [requirementFile, setRequirementFile] = useState<File | null>(null);
  const query = useQuery({ queryKey: ['purchasing', 'rfqs', id], queryFn: () => rfqsApi.show(id), enabled: !!id });
  const rfq = query.data;
  const purchaseOrders = useQuery({ queryKey: ['purchasing', 'rfqs', id, 'purchase-orders'], queryFn: () => rfqsApi.purchaseOrders(id), enabled: !!rfq && ['awarded', 'partially_awarded'].includes(rfq.status) });
  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: ['purchasing', 'rfqs', id] });
    await queryClient.invalidateQueries({ queryKey: ['purchasing', 'rfqs'] });
    if (rfq?.purchase_request?.id) await queryClient.invalidateQueries({ queryKey: ['purchasing', 'purchase-requests', rfq.purchase_request.id] });
  };
  const publish = useMutation({ mutationFn: () => rfqsApi.publish(id), onSuccess: async () => { await refresh(); toast.success('RFQ published to invited suppliers.'); }, onError: () => toast.error('RFQ could not be published.') });
  const extend = useMutation({ mutationFn: () => rfqsApi.extend(id, new Date(extensionDeadline).toISOString(), extensionReason), onSuccess: async () => { await refresh(); setExtensionDeadline(''); setExtensionReason(''); toast.success('RFQ deadline extended.'); }, onError: () => toast.error('RFQ could not be extended. Check the deadline and reason.') });
  const addendum = useMutation({ mutationFn: () => rfqsApi.addendum(id, { title: addendumTitle, body: addendumBody, material_change: materialChange, extension_days: materialChange ? Number(extensionDays) : undefined }), onSuccess: async () => { await refresh(); setAddendumTitle(''); setAddendumBody(''); toast.success('Addendum published to invited suppliers.'); }, onError: () => toast.error('Addendum could not be published.') });
  const cancel = useMutation({ mutationFn: (reason: string) => rfqsApi.cancel(id, reason), onSuccess: async () => { await refresh(); setCancelOpen(false); toast.success('RFQ cancelled.'); }, onError: () => toast.error('RFQ could not be cancelled.') });
  const uploadRequirement = useMutation({ mutationFn: async () => { if (!requirementFile) throw new Error('Select a file.'); const form = new FormData(); form.append('file', requirementFile); form.append('document_type', 'requirement_document'); return rfqsApi.uploadDocument(id, form); }, onSuccess: async () => { await refresh(); setRequirementFile(null); toast.success('Requirement document uploaded privately.'); }, onError: () => toast.error('Requirement document could not be uploaded.') });

  if (query.isLoading) return <SkeletonTable columns={5} rows={6} />;
  if (query.isError || !rfq) return <EmptyState icon="alert-circle" title="Failed to load RFQ" action={<Button onClick={() => query.refetch()}>Retry</Button>} />;
  const canManage = can('purchasing.rfq.manage');
  const canCompare = can('purchasing.rfq.evaluate') && ['closed', 'under_evaluation', 'awarded', 'partially_awarded'].includes(rfq.status);
  return <div>
    <PageHeader title={<span className="font-mono">{rfq.rfq_number}</span>} subtitle={rfq.title} backTo="/purchasing/rfqs" backLabel="Supplier RFQs" actions={<div className="flex flex-wrap gap-2"><Chip variant={chipVariantForStatus(rfq.status)}>{rfq.status_label ?? rfq.status.replace(/_/g, ' ')}</Chip>{rfq.status === 'draft' && can('purchasing.rfq.publish') && <Button size="sm" variant="primary" onClick={() => publish.mutate()} loading={publish.isPending}>Publish</Button>}{canCompare && <Button size="sm" variant="primary" onClick={() => navigate(`/purchasing/rfqs/${rfq.id}/compare`)}>Compare quotations</Button>}{canManage && ['draft', 'open', 'closed'].includes(rfq.status) && <Button size="sm" variant="secondary" onClick={() => setCancelOpen(true)}>Cancel RFQ</Button>}</div>} />
    <div className="px-5 py-4 grid lg:grid-cols-3 gap-4">
      <div className="lg:col-span-2 space-y-4">
        <Panel title="Requirements"><p className="text-sm text-muted mb-3">{rfq.instructions || 'No additional instructions.'}</p><div className="overflow-x-auto"><table className="w-full text-sm"><caption className="sr-only">RFQ requirements</caption><thead><tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-muted"><th scope="col" className="py-2">Requirement</th><th scope="col" className="py-2">Quantity</th><th scope="col" className="py-2">Required date</th></tr></thead><tbody>{rfq.items?.map((item) => <tr key={item.id} className="border-b border-subtle"><td className="py-2">{item.description}<div className="text-xs text-muted">{item.item?.code ?? 'Ad hoc line'}</div></td><td className="py-2 font-mono">{item.quantity} {item.unit ?? ''}</td><td className="py-2 font-mono">{item.required_delivery_date ? formatDate(item.required_delivery_date) : '—'}</td></tr>)}</tbody></table></div></Panel>
        <Panel title="Supplier invitations"><div className="space-y-2">{rfq.invitations?.map((invitation) => <div key={invitation.id} className="flex justify-between border-b border-subtle py-2 last:border-0"><span>{invitation.vendor?.name ?? 'Supplier'}</span><Chip variant={chipVariantForStatus(invitation.status)}>{invitation.status.replace(/_/g, ' ')}</Chip></div>)}</div></Panel>
        {rfq.addenda && rfq.addenda.length > 0 && <Panel title="Clarifications and addenda"><div className="space-y-3">{rfq.addenda.map((entry) => <article key={entry.id} className="border-b border-subtle pb-3 last:border-0"><div className="flex justify-between gap-2"><strong>{entry.sequence}. {entry.title}</strong>{entry.material_change && <Chip variant="warning">Material change</Chip>}</div><p className="text-sm text-muted mt-1 whitespace-pre-wrap">{entry.body}</p><time className="text-xs text-muted">{formatDateTime(entry.published_at)}</time></article>)}</div></Panel>}
        {rfq.no_award_reason && <Panel title="No-award outcome"><p className="text-sm text-muted">{rfq.no_award_reason}</p></Panel>}
        {rfq.awards && rfq.awards.length > 0 && <Panel title="Award outcome"><div className="overflow-x-auto"><table className="w-full text-sm"><caption className="sr-only">RFQ awards</caption><thead><tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-muted"><th scope="col" className="py-2">Line</th><th scope="col" className="py-2">Supplier</th><th scope="col" className="py-2">Quantity</th><th scope="col" className="py-2">Line value</th></tr></thead><tbody>{rfq.awards.map((award) => <tr key={award.id} className="border-b border-subtle"><td className="py-2">{award.rfq_item?.description ?? 'Line'}</td><td className="py-2">{award.vendor?.name ?? '—'}</td><td className="py-2 font-mono tabular-nums">{formatQuantity(award.awarded_quantity)}</td><td className="py-2 font-mono tabular-nums">{formatPeso(award.awarded_total_delivered_cost)}</td></tr>)}</tbody></table></div><p className="mt-2 text-2xs text-muted">Line value is goods plus line-level charges. Quote-level freight, other charges and VAT are carried on the generated purchase order.</p></Panel>}
        {purchaseOrders.data && <Panel title="Generated purchase orders"><div className="space-y-2">{purchaseOrders.data.map((po) => <Link key={po.id} className="flex justify-between border-b border-subtle py-2 text-link" to={`/purchasing/purchase-orders/${po.id}`}><span className="font-mono">{po.po_number}</span><span className="flex gap-3"><span className="font-mono tabular-nums">{formatPeso(po.total_amount)}</span><span>{po.status_label ?? po.status.replace(/_/g, ' ')}</span></span></Link>)}</div></Panel>}
      </div>
      <div className="space-y-4">
        <Panel title="Sourcing control"><dl className="space-y-3 text-sm"><div><dt className="text-muted">Source PR</dt><dd><Link className="text-link" to={`/purchasing/purchase-requests/${rfq.purchase_request?.id}`}>{rfq.purchase_request?.pr_number ?? '—'}</Link></dd></div><div><dt className="text-muted">Issued</dt><dd className="font-mono">{formatDateTime(rfq.issued_at)}</dd></div><div><dt className="text-muted">Deadline</dt><dd className="font-mono">{formatDateTime(rfq.closes_at)}</dd></div><div><dt className="text-muted">Prices</dt><dd>{rfq.status === 'open' ? 'Sealed until closure' : 'Available to authorized evaluators'}</dd></div></dl></Panel>
        {canManage && ['draft', 'open'].includes(rfq.status) && <Panel title="Requirement document"><Input label="Private PDF or image" type="file" accept="application/pdf,.pdf,image/png,image/jpeg" onChange={(event) => setRequirementFile(event.target.files?.[0] ?? null)} /><Button className="mt-3" variant="secondary" disabled={!requirementFile} onClick={() => uploadRequirement.mutate()} loading={uploadRequirement.isPending}>Upload requirement</Button></Panel>}
        {canManage && rfq.status === 'open' && <Panel title="Manual supplier response"><p className="text-sm text-muted">Capture a scanned or phone quotation for an invited supplier without portal access.</p><Button className="mt-3" variant="secondary" onClick={() => navigate(`/purchasing/rfqs/${rfq.id}/manual-quote`)}>Capture manual quotation</Button></Panel>}
        {rfq.documents && rfq.documents.length > 0 && <Panel title="RFQ documents"><div className="space-y-2">{rfq.documents.map((document) => <a key={document.id} className="block text-link text-sm" href={`/api/v1/purchasing/rfq-documents/${document.id}/download`}>{document.original_filename}</a>)}</div></Panel>}
        {canManage && rfq.status === 'open' && <Panel title="Extend deadline"><Input label="New deadline" type="datetime-local" value={extensionDeadline} onChange={(event) => setExtensionDeadline(event.target.value)} /><Input className="mt-2" label="Reason" value={extensionReason} onChange={(event) => setExtensionReason(event.target.value)} /><Button className="mt-3" variant="secondary" disabled={!extensionDeadline || extensionReason.trim().length < 5} onClick={() => extend.mutate()} loading={extend.isPending}>Extend RFQ</Button></Panel>}
        {canManage && rfq.status === 'open' && <Panel title="Publish clarification"><Input label="Title" value={addendumTitle} onChange={(event) => setAddendumTitle(event.target.value)} /><label className="block text-sm mt-2">Message<textarea className="mt-1 w-full min-h-20 border border-default rounded-md bg-canvas p-3" value={addendumBody} onChange={(event) => setAddendumBody(event.target.value)} /></label><label className="flex items-center gap-2 text-sm mt-2"><input type="checkbox" checked={materialChange} onChange={(event) => setMaterialChange(event.target.checked)} /> Material change</label>{materialChange && <Input className="mt-2" label="Extension days" inputMode="numeric" value={extensionDays} onChange={(event) => setExtensionDays(event.target.value)} />}<Button className="mt-3" variant="secondary" disabled={!addendumTitle.trim() || !addendumBody.trim()} onClick={() => addendum.mutate()} loading={addendum.isPending}>Publish addendum</Button></Panel>}
        {rfq.budget_warning_message && <Panel title="Budget warning"><p className="text-sm text-warning-fg">{rfq.budget_warning_message}</p></Panel>}
      </div>
    </div>
    <ReasonDialog isOpen={cancelOpen} onClose={() => setCancelOpen(false)} onConfirm={async (reason) => { await cancel.mutateAsync(reason); }} title="Cancel this RFQ?" description="Invited suppliers will no longer be able to submit or revise quotations." confirmLabel="Cancel RFQ" pending={cancel.isPending} />
  </div>;
}
