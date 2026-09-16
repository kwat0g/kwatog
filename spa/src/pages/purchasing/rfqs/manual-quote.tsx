import { useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { rfqsApi, type ManualRfqQuoteData } from '@/api/purchasing/rfqs';
import { businessPoliciesApi } from '@/api/businessPolicies';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { toCentavos, fromCentavos } from '@/lib/money';

type LineValue = { status: 'quoted' | 'no_quote'; quantity: string; price: string; lead: string };

/**
 * Same VAT treatments the supplier portal declares. Manual capture enters the
 * supplier's paper quotation, so the VAT figure is still derived from the
 * lines here — purchasing must not hand-key a tax number the system can
 * compute, or the derived value and the paper PDF drift apart.
 */
type VatTreatment = 'exclusive' | 'inclusive' | 'none';

export default function ManualRfqQuotePage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const query = useQuery({ queryKey: ['purchasing', 'rfqs', id], queryFn: () => rfqsApi.show(id), enabled: !!id });
  const [vendorId, setVendorId] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [documentId, setDocumentId] = useState('');
  const [values, setValues] = useState<Record<string, LineValue>>({});
  const [treatment, setTreatment] = useState<VatTreatment>('exclusive');
  const [freight, setFreight] = useState('0.00');
  const [other, setOther] = useState('0.00');
  const [notes, setNotes] = useState('');
  const policies = useQuery({
    queryKey: ['business-policies'],
    queryFn: () => businessPoliciesApi.get(),
    staleTime: 300_000,
  });
  const vatRate = policies.data?.vat_rate != null ? Number(policies.data.vat_rate) : null;
  const vatPercent = vatRate != null ? `${Math.round(vatRate * 10000) / 100}%` : null;

  const itemsTotal = useMemo(() => fromCentavos(Object.values(values).reduce((total, value) => (
    value.status === 'quoted' ? total + Math.round((Number(value.quantity) || 0) * (Number(value.price) || 0) * 100) : total
  ), 0)), [values]);

  const vatAmount = useMemo(() => {
    if (treatment === 'none') return '0.00';
    if (vatRate == null || toCentavos(itemsTotal) === 0) return null;
    const baseCentavos = toCentavos(itemsTotal);
    return fromCentavos(treatment === 'inclusive'
      ? Math.round(baseCentavos * (vatRate / (1 + vatRate)))
      : Math.round(baseCentavos * vatRate));
  }, [treatment, itemsTotal, vatRate]);

  const totalDelivered = useMemo(() => fromCentavos(
    toCentavos(itemsTotal) + toCentavos(treatment === 'none' ? '0.00' : vatAmount ?? '0.00') + toCentavos(freight) + toCentavos(other),
  ), [itemsTotal, vatAmount, treatment, freight, other]);
  const upload = useMutation({
    mutationFn: async () => {
      if (!file || !vendorId) throw new Error('Select a supplier and quotation PDF.');
      const form = new FormData();
      form.append('file', file);
      form.append('document_type', 'quotation_pdf');
      form.append('vendor_id', vendorId);
      return rfqsApi.uploadDocument(id, form);
    },
    onSuccess: (document) => { setDocumentId(document.id); toast.success('Quotation PDF uploaded privately.'); },
    onError: () => toast.error('The quotation PDF could not be uploaded.'),
  });
  const capture = useMutation({
    mutationFn: () => {
      const data: ManualRfqQuoteData = {
        vendor_id: vendorId,
        quotation_document_id: documentId,
        vat_inclusive: treatment === 'inclusive',
        ...(treatment === 'none' ? { vat_amount: '0.00' } : {}),
        freight_amount: freight,
        other_charges: other,
        notes: notes || undefined,
        items: (query.data?.items ?? []).map((item) => {
          const value = values[item.id] ?? { status: 'no_quote' as const, quantity: '', price: '', lead: '' };
          return { request_for_quote_item_id: item.id, response_status: value.status, ...(value.status === 'quoted' ? { offered_quantity: value.quantity, unit_price: value.price, lead_time_days: value.lead ? Number(value.lead) : undefined } : {}) };
        }),
      };
      return rfqsApi.manualQuote(id, data);
    },
    onSuccess: () => { toast.success('Manual supplier quotation captured and submitted.'); navigate(`/purchasing/rfqs/${id}`); },
    onError: () => toast.error('The manual quotation could not be captured. Check each line and the uploaded PDF.'),
  });
  if (query.isLoading) return <SkeletonTable columns={4} rows={6} />;
  if (query.isError || !query.data) return <EmptyState icon="alert-circle" title="RFQ unavailable" action={<Button onClick={() => query.refetch()}>Retry</Button>} />;
  const rfq = query.data;
  return <div><PageHeader title="Capture supplier quotation" subtitle={rfq.rfq_number} backTo={`/purchasing/rfqs/${id}`} backLabel="RFQ detail" /><div className="px-5 py-4 max-w-4xl space-y-4"><Panel title="Supplier and original quotation"><div className="grid sm:grid-cols-2 gap-3"><label className="block text-sm">Invited supplier<select className="mt-1 w-full h-10 border border-default rounded-md bg-canvas px-3" value={vendorId} onChange={(event) => { setVendorId(event.target.value); setDocumentId(''); }}><option value="">Select supplier</option>{rfq.invitations?.map((invitation) => <option key={invitation.vendor?.id} value={invitation.vendor?.id}>{invitation.vendor?.name}</option>)}</select></label><Input label="Quotation PDF" type="file" accept="application/pdf,.pdf" onChange={(event) => { setFile(event.target.files?.[0] ?? null); setDocumentId(''); }} /></div><div className="flex items-center gap-3 mt-3"><Button variant="secondary" onClick={() => upload.mutate()} disabled={!vendorId || !file} loading={upload.isPending}>Upload private PDF</Button>{documentId && <span className="text-sm text-success-fg">PDF linked to this capture.</span>}</div></Panel><Panel title="Prices and VAT"><p className="text-sm text-muted mb-3">Record how the supplier's paper quotation states VAT. The VAT amount is derived from the lines below — mirror the PDF, do not estimate.</p><fieldset className="flex flex-wrap items-center gap-x-5 gap-y-2">{([['exclusive', 'VAT-exclusive'], ['inclusive', 'VAT-inclusive'], ['none', 'No VAT']] as Array<[VatTreatment, string]>).map(([optionValue, label]) => (<label key={optionValue} className="flex items-center gap-1.5 text-sm cursor-pointer"><input type="radio" name="manual_vat_treatment" checked={treatment === optionValue} onChange={() => setTreatment(optionValue)} />{label}</label>))}</fieldset><p className="text-xs text-muted mt-1">{treatment === 'exclusive' ? `VAT at ${vatPercent ?? 'the prevailing rate'} applies on top of the quoted amounts.` : treatment === 'inclusive' ? 'The quoted amounts already include VAT; the VAT component is extracted from them.' : 'Supplier is not VAT-registered or the sale is VAT-exempt.'}</p><div className="grid sm:grid-cols-2 gap-3 mt-3"><Input label="VAT (computed)" prefix="₱" readOnly value={vatAmount ?? '—'} helper={treatment === 'inclusive' ? `Extracted from gross prices at ${vatPercent ?? 'the prevailing rate'} (12/112 rule).` : treatment === 'none' ? 'Declared without VAT, matching the paper quotation.' : `Computed at ${vatPercent ?? 'the prevailing rate'} on the quoted lines.`} /><Input label="Delivery / freight charges" inputMode="decimal" prefix="₱" value={freight} onChange={(event) => setFreight(event.target.value)} helper="Delivery charge stated on the quotation. 0.00 if none." /><Input label="Other charges" inputMode="decimal" prefix="₱" value={other} onChange={(event) => setOther(event.target.value)} helper="Packing, testing, or other billable extras. 0.00 if none." /></div></Panel><Panel title="Structured response"><p className="text-sm text-muted mb-3">Captured responses are immutable submitted quote versions and remain sealed until the RFQ closes.</p><div className="space-y-3">{rfq.items?.map((item) => { const value = values[item.id] ?? { status: 'no_quote' as const, quantity: '', price: '', lead: '' }; return <div key={item.id} className="border border-default rounded-md p-3"><div className="flex justify-between gap-3"><div><strong>{item.description}</strong><div className="font-mono text-xs text-muted">Requested {item.quantity} {item.unit ?? ''}</div></div><label className="text-sm"><input type="checkbox" checked={value.status === 'no_quote'} onChange={(event) => setValues((current) => ({ ...current, [item.id]: { ...value, status: event.target.checked ? 'no_quote' : 'quoted' } }))} /> No quote</label></div>{value.status === 'quoted' && <div className="grid sm:grid-cols-3 gap-2 mt-3"><Input label="Offered quantity" value={value.quantity} onChange={(event) => setValues((current) => ({ ...current, [item.id]: { ...value, quantity: event.target.value } }))} /><Input label="Unit price" value={value.price} onChange={(event) => setValues((current) => ({ ...current, [item.id]: { ...value, price: event.target.value } }))} /><Input label="Lead time days" value={value.lead} onChange={(event) => setValues((current) => ({ ...current, [item.id]: { ...value, lead: event.target.value } }))} /></div>}</div>; })}</div><label className="block text-sm mt-3">Capture notes<textarea className="mt-1 w-full min-h-20 border border-default rounded-md bg-canvas p-3" value={notes} onChange={(event) => setNotes(event.target.value)} /></label></Panel><Panel title="Quotation summary"><dl className="space-y-1 text-sm"><div className="flex justify-between"><dt className="text-muted">Items total</dt><dd className="font-mono tabular-nums">₱{itemsTotal}</dd></div><div className="flex justify-between"><dt className="text-muted">VAT</dt><dd className="font-mono tabular-nums">{vatAmount === null ? '—' : `₱${vatAmount}`}</dd></div><div className="flex justify-between"><dt className="text-muted">Delivery / freight</dt><dd className="font-mono tabular-nums">₱{freight}</dd></div><div className="flex justify-between"><dt className="text-muted">Other charges</dt><dd className="font-mono tabular-nums">₱{other}</dd></div><div className="flex justify-between border-t border-default pt-2 mt-2 font-medium"><dt>Total delivered cost</dt><dd className="font-mono tabular-nums">₱{totalDelivered}</dd></div></dl></Panel><div className="flex justify-between"><Button variant="secondary" onClick={() => navigate(`/purchasing/rfqs/${id}`)}>Cancel</Button><Button variant="primary" disabled={!vendorId || !documentId || capture.isPending} onClick={() => capture.mutate()} loading={capture.isPending}>Capture and submit</Button></div></div></div>;
}
