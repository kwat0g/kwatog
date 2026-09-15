import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { rfqsApi } from '@/api/purchasing/rfqs';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';

export default function RfqComparisonPage() {
  const { id = '' } = useParams<{ id: string }>(); const navigate = useNavigate(); const qc = useQueryClient();
  const query = useQuery({ queryKey: ['purchasing', 'rfqs', id, 'comparison'], queryFn: () => rfqsApi.comparison(id), enabled: !!id });
  const [selected, setSelected] = useState<Record<string, string>>({}); const [reason, setReason] = useState('Best compliant delivered-cost and delivery balance.');
  const award = useMutation({ mutationFn: () => rfqsApi.award(id, Object.entries(selected).map(([request_for_quote_item_id, supplier_quote_item_id]) => ({ request_for_quote_item_id, supplier_quote_item_id, awarded_quantity: query.data?.items?.find((item) => item.id === request_for_quote_item_id)?.quantity ?? '0', award_reason: reason }))), onSuccess: () => { qc.invalidateQueries({ queryKey: ['purchasing', 'rfqs', id] }); toast.success('RFQ awarded and draft POs generated.'); navigate(`/purchasing/rfqs/${id}`); }, onError: () => toast.error('Award could not be completed. Check the reasons and quality exceptions.') });
  if (query.isLoading) return <SkeletonTable columns={5} rows={6} />;
  if (query.isError || !query.data) return <EmptyState icon="alert-circle" title="Comparison unavailable" action={<Button onClick={() => query.refetch()}>Retry</Button>} />;
  const rfq = query.data;
  return <div><PageHeader title="Quotation comparison" subtitle={rfq.rfq_number} backTo={`/purchasing/rfqs/${id}`} backLabel="RFQ detail" /><div className="px-5 py-4 space-y-4"><Panel title="Sealed bid comparison"><div className="overflow-x-auto"><table className="min-w-[760px] w-full text-sm"><thead><tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-muted"><th className="py-2">Line</th>{rfq.quotes?.map((quote) => <th key={quote.id} className="py-2 min-w-[180px]">{quote.vendor?.name}<div className="font-mono normal-case">v{quote.version} · {formatPeso(quote.total_delivered_cost)}</div></th>)}</tr></thead><tbody>{rfq.items?.map((item) => <tr key={item.id} className="border-b border-subtle align-top"><td className="py-3 pr-4"><div>{item.description}</div><div className="font-mono text-xs text-muted">{item.quantity} {item.unit ?? ''}</div></td>{rfq.quotes?.map((quote) => { const line = quote.items.find((row) => row.rfq_item?.id === item.id); return <td key={quote.id} className="py-3 pr-3">{line?.response_status === 'quoted' ? <label className="flex gap-2"><input type="radio" name={`line-${item.id}`} checked={selected[item.id] === line.id} onChange={() => setSelected((current) => ({ ...current, [item.id]: line.id }))} /><span><span className="block font-mono">{formatPeso(line.unit_price ?? '0')} / {item.unit ?? 'unit'}</span><span className="text-xs text-muted">{line.offered_quantity} offered · {line.lead_time_days ?? '—'} days</span><span className="block text-xs text-muted">{line.compliance_status}</span></span></label> : <span className="text-muted">No quote</span>}</td>; })}</tr>)}</tbody></table></div></Panel><Panel title="Award reason"><textarea className="w-full min-h-24 border border-default rounded-md bg-canvas p-3 text-sm" value={reason} onChange={(e) => setReason(e.target.value)} aria-label="Award reason" /><div className="flex justify-end mt-3"><Button variant="primary" onClick={() => award.mutate()} loading={award.isPending} disabled={Object.keys(selected).length === 0}>Award selected lines</Button></div></Panel></div></div>;
}
