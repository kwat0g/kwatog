import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { rfqsApi } from '@/api/purchasing/rfqs';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';

type Allocation = Record<string, Record<string, string>>;

export default function RfqComparisonPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const query = useQuery({ queryKey: ['purchasing', 'rfqs', id, 'comparison'], queryFn: () => rfqsApi.comparison(id), enabled: !!id });
  const [allocations, setAllocations] = useState<Allocation>({});
  const [reason, setReason] = useState('Best compliant delivered-cost and delivery balance.');
  const [singleJustification, setSingleJustification] = useState('');
  const [qualityStatus, setQualityStatus] = useState<Record<string, 'compliant' | 'exception' | 'blocking'>>({});
  const [qualityNotes, setQualityNotes] = useState<Record<string, string>>({});
  const rfq = query.data;
  const awardRows = useMemo(() => Object.entries(allocations).flatMap(([lineId, suppliers]) => Object.entries(suppliers).filter(([, quantity]) => Number(quantity) > 0).map(([quoteItemId, quantity]) => ({ request_for_quote_item_id: lineId, supplier_quote_item_id: quoteItemId, awarded_quantity: quantity, award_reason: reason, single_response_justification: singleJustification || undefined }))), [allocations, reason, singleJustification]);
  const invalidQuantity = useMemo(() => (rfq?.items ?? []).some((item) => {
    const selected = Object.values(allocations[item.id] ?? {});
    const total = selected.reduce((sum, quantity) => sum + Number(quantity || 0), 0);
    return total > Number(item.quantity);
  }), [allocations, rfq]);
  const award = useMutation({
    mutationFn: () => rfqsApi.award(id, awardRows),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['purchasing', 'rfqs', id] });
      await queryClient.invalidateQueries({ queryKey: ['purchasing', 'rfqs'] });
      toast.success('RFQ awarded and draft POs generated.');
      navigate(`/purchasing/rfqs/${id}`);
    },
    onError: () => toast.error('Award could not be completed. Check quantities, quality exceptions, and justifications.'),
  });
  const qualityReview = useMutation({
    mutationFn: ({ quoteItemId, status }: { quoteItemId: string; status: 'compliant' | 'exception' | 'blocking' }) => rfqsApi.reviewQuality(id, quoteItemId, { compliance_status: status, compliance_notes: qualityNotes[quoteItemId] }),
    onSuccess: async () => { await queryClient.invalidateQueries({ queryKey: ['purchasing', 'rfqs', id, 'comparison'] }); toast.success('Quality review saved.'); },
    onError: () => toast.error('Quality review could not be saved.'),
  });

  if (query.isLoading) return <SkeletonTable columns={5} rows={6} />;
  if (query.isError || !rfq) return <EmptyState icon="alert-circle" title="Comparison unavailable" action={<Button onClick={() => query.refetch()}>Retry</Button>} />;
  const canAward = can('purchasing.rfq.award');
  const canQuality = can('purchasing.rfq.quality_review');
  const canEvaluate = can('purchasing.rfq.evaluate');
  if (!canAward && !canQuality && !canEvaluate) return <EmptyState icon="lock" title="Evaluation authority required" description="This role cannot open commercial or quality quotation evidence." />;
  if (!canAward && canQuality && !canEvaluate) return <div><PageHeader title="Quality evidence review" subtitle={rfq.rfq_number} backTo={`/purchasing/rfqs/${id}`} backLabel="RFQ detail" /><div className="px-5 py-4 space-y-4"><Panel title="Commercial fields redacted"><p className="text-sm text-muted">Supplier prices and charges remain hidden from QC. Review technical evidence and record only the quality disposition.</p>{rfq.quotes?.map((quote) => <div key={quote.id} className="border-b border-subtle py-3 last:border-0"><div className="font-medium">{quote.vendor?.name ?? 'Supplier'} · v{quote.version}</div>{quote.items.map((line) => line.rfq_item && <div key={line.id} className="mt-2 grid sm:grid-cols-[1fr_auto] gap-2 items-end"><div><div className="text-sm">{line.rfq_item.description}</div><Input className="mt-1" label="Evidence notes" value={qualityNotes[line.id] ?? line.compliance_notes ?? ''} onChange={(event) => setQualityNotes((current) => ({ ...current, [line.id]: event.target.value }))} /></div><div><select aria-label={`Quality status for ${line.rfq_item.description}`} className="h-10 border border-default rounded-md bg-canvas px-3" value={qualityStatus[line.id] ?? (line.compliance_status as 'compliant' | 'exception' | 'blocking')} onChange={(event) => setQualityStatus((current) => ({ ...current, [line.id]: event.target.value as 'compliant' | 'exception' | 'blocking' }))}><option value="pending">Pending</option><option value="compliant">Compliant</option><option value="exception">Exception</option><option value="blocking">Blocking</option></select><Button className="mt-2 w-full" size="sm" variant="secondary" onClick={() => qualityReview.mutate({ quoteItemId: line.id, status: qualityStatus[line.id] ?? 'exception' })} loading={qualityReview.isPending}>Save review</Button></div></div>)}</div>)}</Panel></div></div>;
  const singleResponse = (rfq.items ?? []).some((item) => rfq.quotes?.filter((quote) => quote.items.some((line) => line.rfq_item?.id === item.id && line.response_status === 'quoted')).length === 1 && Object.keys(allocations[item.id] ?? {}).length > 0);

  return <div><PageHeader title="Quotation comparison" subtitle={rfq.rfq_number} backTo={`/purchasing/rfqs/${id}`} backLabel="RFQ detail" /><div className="px-5 py-4 space-y-4">
    <Panel title="Sealed bid comparison" meta="Prices are visible only after server-side closure"><div className="overflow-x-auto"><table className="min-w-[980px] w-full text-sm"><caption className="sr-only">Supplier quotation comparison</caption><thead><tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-muted"><th scope="col" className="py-2">Line</th>{rfq.quotes?.map((quote) => <th scope="col" key={quote.id} className="py-2 min-w-[220px]">{quote.vendor?.name}<div className="font-mono normal-case">v{quote.version} · {formatPeso(quote.total_delivered_cost ?? '0')}</div>{quote.supplier_performance && <div className="text-xs normal-case text-muted">History: Tier {quote.supplier_performance.tier ?? '—'} · Score {quote.supplier_performance.overall_score ?? '—'} · On-time {quote.supplier_performance.on_time_delivery_rate ?? '—'}%</div>}</th>)}</tr></thead><tbody>{rfq.items?.map((item) => <tr key={item.id} className="border-b border-subtle align-top"><th scope="row" className="py-3 pr-4 text-left"><div>{item.description}</div><div className="font-mono text-xs text-muted">Requested {item.quantity} {item.unit ?? ''}</div></th>{rfq.quotes?.map((quote) => { const line = quote.items.find((row) => row.rfq_item?.id === item.id); const checked = !!line && !!allocations[item.id]?.[line.id]; const blocked = line?.compliance_status === 'blocking'; return <td key={quote.id} className="py-3 pr-3">{line?.response_status === 'quoted' ? <div className={`space-y-2 ${blocked ? 'opacity-60' : ''}`}><label className="flex gap-2"><input type="checkbox" disabled={blocked} checked={checked} onChange={(event) => setAllocations((current) => { const next = { ...current, [item.id]: { ...(current[item.id] ?? {}) } }; if (event.target.checked) next[item.id][line.id] = line.offered_quantity ?? '0'; else delete next[item.id][line.id]; return next; })} /><span><span className="block font-mono">{formatPeso(line.unit_price ?? '0')} / {item.unit ?? 'unit'}</span><span className="text-xs text-muted">{line.offered_quantity} offered · {line.lead_time_days ?? '—'} days · {line.proposed_delivery_date ?? 'no date'}</span><span className="block text-xs text-muted">Delivered {formatPeso(line.line_total_delivered_cost ?? '0')} · {line.compliance_status}</span></span></label>{blocked && <div className="text-xs text-danger-fg">Blocking QC exception</div>}{checked && <Input aria-label={`Award quantity from ${quote.vendor?.name ?? 'supplier'}`} inputMode="decimal" value={allocations[item.id][line.id]} onChange={(event) => setAllocations((current) => ({ ...current, [item.id]: { ...(current[item.id] ?? {}), [line.id]: event.target.value } }))} />}</div> : <span className="text-muted">No quote</span>}</td>; })}</tr>)}</tbody></table></div></Panel>
    {canAward ? <Panel title="Award rationale"><div className="space-y-3"><label className="block text-sm">Award reason<textarea className="mt-1 w-full min-h-20 border border-default rounded-md bg-canvas p-3" value={reason} onChange={(event) => setReason(event.target.value)} aria-label="Award reason" /></label>{singleResponse && <label className="block text-sm">Single-response justification<textarea className="mt-1 w-full min-h-20 border border-default rounded-md bg-canvas p-3" value={singleJustification} onChange={(event) => setSingleJustification(event.target.value)} aria-label="Single-response justification" /></label>}<div className="flex flex-wrap justify-between gap-3"><div className="text-sm text-muted">{invalidQuantity ? 'Award quantities exceed an RFQ line.' : awardRows.length ? `${awardRows.length} allocation(s) selected.` : 'Select at least one supplier allocation.'}</div><Button variant="primary" disabled={!awardRows.length || invalidQuantity || !reason.trim() || (singleResponse && !singleJustification.trim())} onClick={() => award.mutate()} loading={award.isPending}>Award selected lines</Button></div></div></Panel> : <Panel title="Review only"><p className="text-sm text-muted">Finance may review the sealed quotations. Award decisions remain with Purchasing.</p></Panel>}
  </div></div>;
}
