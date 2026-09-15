import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { supplierRfqsApi } from '@/api/purchasing/rfqs';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';

export default function SupplierRfqQuotePage() {
  const { id = '' } = useParams<{ id: string }>(); const navigate = useNavigate();
  const query = useQuery({ queryKey: ['portal', 'supplier', 'rfqs', id], queryFn: () => supplierRfqsApi.show(id), enabled: !!id });
  const [values, setValues] = useState<Record<string, { status: 'quoted' | 'no_quote'; quantity: string; price: string; lead: string }>>({});
  const [freight, setFreight] = useState('0.00'); const [other, setOther] = useState('0.00'); const [terms, setTerms] = useState(''); const [file, setFile] = useState<File | null>(null);
  const payload = () => ({ freight_amount: freight, other_charges: other, payment_terms: terms, items: (query.data?.items ?? []).map((item) => { const value = values[item.id] ?? { status: 'no_quote', quantity: '', price: '', lead: '' }; return { request_for_quote_item_id: item.id, response_status: value.status, ...(value.status === 'quoted' ? { offered_quantity: value.quantity, unit_price: value.price, lead_time_days: Number(value.lead || 0) } : {}) }; }) });
  const saveDraft = useMutation({ mutationFn: () => supplierRfqsApi.saveQuote(id, payload()), onSuccess: () => toast.success('Draft quotation saved.'), onError: () => toast.error('Could not save the quotation draft.') });
  const save = useMutation({ mutationFn: () => supplierRfqsApi.saveQuote(id, payload()), onSuccess: async (quote) => { if (file) { const form = new FormData(); form.append('file', file); form.append('document_type', 'quotation_pdf'); await supplierRfqsApi.uploadDocument(id, form); } await supplierRfqsApi.submit(id, quote.id); toast.success('Quotation submitted securely.'); navigate(`/portal/supplier/rfqs/${id}`); }, onError: () => toast.error('Could not submit quotation. Check each line and attach a PDF.') });
  if (query.isLoading) return <SkeletonTable columns={4} rows={6} />;
  if (query.isError || !query.data) return <EmptyState icon="alert-circle" title="RFQ unavailable" />;
  const rfq = query.data;
  return <div><PageHeader title="Prepare quotation" subtitle={rfq.rfq_number} backTo={`/portal/supplier/rfqs/${id}`} backLabel="RFQ invitation" /><div className="px-5 py-4 max-w-4xl space-y-4"><Panel title="Commercial response"><div className="grid sm:grid-cols-2 gap-3"><Input label="Freight" value={freight} onChange={(e) => setFreight(e.target.value)} /><Input label="Other charges" value={other} onChange={(e) => setOther(e.target.value)} /><Input label="Payment terms" value={terms} onChange={(e) => setTerms(e.target.value)} /><Input label="Formal quotation PDF" type="file" accept="application/pdf" onChange={(e) => setFile(e.target.files?.[0] ?? null)} /></div></Panel><Panel title="Line response"><div className="space-y-3">{rfq.items?.map((item) => { const value = values[item.id] ?? { status: 'no_quote' as const, quantity: '', price: '', lead: '' }; return <div key={item.id} className="rounded border border-default p-3"><div className="flex justify-between gap-3"><div><div className="font-medium">{item.description}</div><div className="font-mono text-xs text-muted">Requested {item.quantity} {item.unit ?? ''}</div></div><label className="text-sm"><input type="checkbox" checked={value.status === 'no_quote'} onChange={(e) => setValues((current) => ({ ...current, [item.id]: { ...value, status: e.target.checked ? 'no_quote' : 'quoted' } }))} /> No quote</label></div>{value.status === 'quoted' && <div className="grid sm:grid-cols-3 gap-2 mt-3"><Input label="Offered quantity" value={value.quantity} onChange={(e) => setValues((current) => ({ ...current, [item.id]: { ...value, quantity: e.target.value } }))} /><Input label="Unit price" value={value.price} onChange={(e) => setValues((current) => ({ ...current, [item.id]: { ...value, price: e.target.value } }))} /><Input label="Lead time days" value={value.lead} onChange={(e) => setValues((current) => ({ ...current, [item.id]: { ...value, lead: e.target.value } }))} /></div>}</div>; })}</div></Panel><div className="flex justify-between"><Button variant="secondary" onClick={() => navigate(`/portal/supplier/rfqs/${id}`)}>Cancel</Button><div className="flex gap-2"><Button variant="secondary" onClick={() => saveDraft.mutate()} loading={saveDraft.isPending}>Save draft</Button><Button variant="primary" onClick={() => save.mutate()} loading={save.isPending}>Submit sealed quotation</Button></div></div></div></div>;
}
