import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { rfqsApi } from '@/api/purchasing/rfqs';
import { businessPoliciesApi } from '@/api/businessPolicies';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDate, localIsoDate } from '@/lib/formatDate';
import { formatPeso, formatQuantity } from '@/lib/formatNumber';
import { fromCentavos, toCentavos } from '@/lib/money';
import { lineCentavos, quoteTotals, trimQuantity, type QuoteVatTreatment } from '@/lib/quoteTotals';
import type { SupplierQuoteWrite } from '@/types/purchasing';

type Line = { quoting: boolean; quantity: string; price: string; deliverBy: string };

const errMsg = (e: unknown, fallback: string) =>
  (e instanceof AxiosError ? e.response?.data?.message : undefined) ??
  (e instanceof Error ? e.message : fallback);

/**
 * The buyer types in a quotation a supplier gave by phone or email. Same
 * shape as the supplier's own portal form, plus which supplier it is. A
 * supplier that submitted in the portal cannot be chosen: its own quotation
 * is never replaced by a manual entry.
 */
export default function ManualRfqQuotePage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();

  const query = useQuery({ queryKey: ['purchasing', 'rfqs', id], queryFn: () => rfqsApi.show(id), enabled: !!id });
  const policies = useQuery({ queryKey: ['business-policies'], queryFn: businessPoliciesApi.get, staleTime: 300_000 });
  const rfq = query.data;

  const [vendorId, setVendorId] = useState('');
  const [lines, setLines] = useState<Record<string, Line>>({});
  const [vat, setVat] = useState<QuoteVatTreatment>('exclusive');
  const [freight, setFreight] = useState('0.00');
  const [validUntil, setValidUntil] = useState(() => localIsoDate(new Date(Date.now() + 30 * 86_400_000)));
  const [paymentTerms, setPaymentTerms] = useState('');
  const [notes, setNotes] = useState('');
  const [pdf, setPdf] = useState<File | null>(null);
  const [coa, setCoa] = useState<File | null>(null);
  const [seeded, setSeeded] = useState(false);

  useEffect(() => {
    if (seeded || !rfq) return;
    setLines(
      Object.fromEntries(
        rfq.items.map((item) => [item.id, { quoting: true, quantity: trimQuantity(item.quantity), price: '', deliverBy: '' }]),
      ),
    );
    setSeeded(true);
  }, [seeded, rfq]);

  const setLine = (itemId: string, patch: Partial<Line>) =>
    setLines((cur) => ({ ...cur, [itemId]: { ...cur[itemId], ...patch } }));

  const totals = useMemo(() => {
    const goods = (rfq?.items ?? []).reduce((sum, item) => {
      const line = lines[item.id];
      return line?.quoting ? sum + lineCentavos(line.quantity || '0', line.price || '0') : sum;
    }, 0);
    return quoteTotals(goods, toCentavos(freight || '0'), vat, policies.data?.vat_rate ?? null);
  }, [rfq?.items, lines, freight, vat, policies.data?.vat_rate]);

  const problems = (rfq?.items ?? []).flatMap((item) => {
    const line = lines[item.id];
    if (!line?.quoting) return [];
    const issues: string[] = [];
    if (!(Number(line.price) > 0)) issues.push(`Enter a unit price for ${item.description}.`);
    if (!(Number(line.quantity) > 0) || Number(line.quantity) > Number(item.quantity)) {
      issues.push(`${item.description}: quantity must be more than 0 and at most ${formatQuantity(item.quantity)}.`);
    }
    return issues;
  });
  if (!vendorId) problems.unshift('Choose the supplier.');
  if (!pdf) problems.push('Attach the quotation (PDF or photo).');
  if (!Object.values(lines).some((line) => line.quoting)) problems.push('Quote at least one item.');

  const save = useMutation({
    mutationFn: async () => {
      for (const [file, type] of [
        [pdf, 'quotation_pdf'],
        [coa, 'certificate_of_analysis'],
      ] as const) {
        if (!file) continue;
        const form = new FormData();
        form.append('file', file);
        form.append('document_type', type);
        form.append('vendor_id', vendorId);
        await rfqsApi.uploadDocument(id, form);
      }
      const payload: SupplierQuoteWrite & { vendor_id: string } = {
        vendor_id: vendorId,
        vat_treatment: vat,
        freight_amount: freight || '0.00',
        quote_valid_until: validUntil || null,
        payment_terms: paymentTerms.trim() || null,
        notes: notes.trim() || null,
        items: (rfq?.items ?? []).map((item) => {
          const line = lines[item.id];
          return line?.quoting
            ? {
                request_for_quote_item_id: item.id,
                response_status: 'quoted',
                offered_quantity: line.quantity,
                unit_price: line.price,
                proposed_delivery_date: line.deliverBy || null,
              }
            : { request_for_quote_item_id: item.id, response_status: 'no_quote' };
        }),
      };
      return rfqsApi.manualQuote(id, payload);
    },
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: ['purchasing', 'rfqs'] });
      toast.success('Quotation recorded.');
      navigate(`/purchasing/rfqs/${id}`);
    },
    onError: (e) => toast.error(errMsg(e, 'The quotation could not be saved.')),
  });

  if (query.isLoading) return <SkeletonTable columns={4} rows={6} />;
  if (query.isError || !rfq) {
    return (
      <EmptyState icon="alert-circle" title="RFQ unavailable" action={<Button onClick={() => query.refetch()}>Retry</Button>} />
    );
  }
  if (!rfq.actions.can_capture_quote) {
    return (
      <EmptyState
        icon="lock"
        title="Quotations can only be entered while the RFQ is open"
        action={<Button onClick={() => navigate(`/purchasing/rfqs/${id}`)}>Back to RFQ</Button>}
      />
    );
  }

  return (
    <div>
      <PageHeader
        title="Enter a supplier's quotation"
        subtitle={
          <span>
            <span className="font-mono">{rfq.rfq_number}</span> · for quotes received by phone or email
          </span>
        }
        backTo={`/purchasing/rfqs/${id}`}
        backLabel="RFQ"
      />

      <div className="px-5 py-4 space-y-4 max-w-4xl">
        <Panel title="Supplier">
          <Select label="Invited supplier" value={vendorId} onChange={(e) => setVendorId(e.target.value)}>
            <option value="">Choose a supplier</option>
            {rfq.invitations.map((inv) => {
              // A supplier's own portal quotation is never replaced by a manual entry.
              const ownQuote = inv.reach === 'portal' && inv.status === 'submitted';
              return (
                <option key={inv.id} value={inv.vendor?.id ?? ''} disabled={ownQuote}>
                  {inv.vendor?.name ?? 'Supplier'}
                  {ownQuote ? ' — submitted in the portal' : ''}
                </option>
              );
            })}
          </Select>
        </Panel>

        <Panel title="Prices">
          <div className="space-y-4">
            {rfq.items.map((item) => {
              const line = lines[item.id];
              if (!line) return null;
              return (
                <div key={item.id} className="border-b border-subtle pb-4 last:border-0 last:pb-0">
                  <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                      <div className="font-medium">{item.description}</div>
                      <div className="text-xs text-muted">
                        Need{' '}
                        <span className="font-mono tabular-nums">
                          {formatQuantity(item.quantity)} {item.unit ?? ''}
                        </span>
                        {item.required_delivery_date && <> by {formatDate(item.required_delivery_date)}</>}
                        {item.specification && <> · {item.specification}</>}
                      </div>
                    </div>
                    <button
                      type="button"
                      className="text-xs text-link hover:underline"
                      onClick={() => setLine(item.id, { quoting: !line.quoting })}
                    >
                      {line.quoting ? 'Supplier did not quote this' : 'Quote this item'}
                    </button>
                  </div>
                  {line.quoting ? (
                    <div className="mt-2 grid gap-3 sm:grid-cols-3">
                      <Input
                        label={`Unit price (₱ per ${item.unit ?? 'unit'})`}
                        inputMode="decimal"
                        value={line.price}
                        onChange={(e) => setLine(item.id, { price: e.target.value })}
                      />
                      <Input
                        label="Quantity"
                        inputMode="decimal"
                        value={line.quantity}
                        onChange={(e) => setLine(item.id, { quantity: e.target.value })}
                      />
                      <Input
                        label="Can deliver by"
                        type="date"
                        value={line.deliverBy}
                        onChange={(e) => setLine(item.id, { deliverBy: e.target.value })}
                      />
                    </div>
                  ) : (
                    <p className="mt-2 text-sm text-muted">Not quoted.</p>
                  )}
                </div>
              );
            })}
          </div>
        </Panel>

        <Panel title="VAT, freight and documents">
          <div className="grid gap-3 sm:grid-cols-2">
            <Select label="Their prices are" value={vat} onChange={(e) => setVat(e.target.value as QuoteVatTreatment)}>
              <option value="exclusive">VAT exclusive — add VAT on top</option>
              <option value="inclusive">VAT inclusive — VAT already in the price</option>
              <option value="none">No VAT — not VAT-registered</option>
            </Select>
            <Input
              label="Freight to Ogami (₱, total)"
              inputMode="decimal"
              value={freight}
              onChange={(e) => setFreight(e.target.value)}
            />
            <Input
              label="Quotation (PDF or photo, required)"
              type="file"
              accept="application/pdf,image/png,image/jpeg"
              onChange={(e) => setPdf(e.target.files?.[0] ?? null)}
            />
            <Input
              label="Certificate of analysis (optional)"
              type="file"
              accept="application/pdf,image/png,image/jpeg"
              onChange={(e) => setCoa(e.target.files?.[0] ?? null)}
            />
          </div>
        </Panel>

        <details className="rounded-md border border-default bg-surface px-4 py-3">
          <summary className="cursor-pointer text-sm font-medium">More details (optional)</summary>
          <div className="mt-3 grid gap-3 sm:grid-cols-2">
            <Input label="Quote valid until" type="date" value={validUntil} onChange={(e) => setValidUntil(e.target.value)} />
            <Input
              label="Payment terms"
              value={paymentTerms}
              onChange={(e) => setPaymentTerms(e.target.value)}
              placeholder="e.g. Net 30"
            />
            <label className="block text-sm sm:col-span-2">
              <span className="block text-2xs uppercase tracking-wider text-muted mb-1">Notes</span>
              <textarea
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                className="w-full min-h-16 border border-default rounded-md bg-canvas p-3 text-sm"
              />
            </label>
          </div>
        </details>

        <div className="rounded-md border border-default bg-surface px-4 py-3">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <div className="text-xs text-muted">Total delivered cost</div>
              <div className="font-mono tabular-nums text-lg">{formatPeso(fromCentavos(totals.total))}</div>
              <div className="text-xs text-muted">
                Goods <span className="font-mono tabular-nums">{formatPeso(fromCentavos(totals.goods))}</span> · Freight{' '}
                <span className="font-mono tabular-nums">{formatPeso(freight || '0')}</span> · VAT{' '}
                <span className="font-mono tabular-nums">{formatPeso(fromCentavos(totals.vat))}</span>
              </div>
            </div>
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" disabled={save.isPending} onClick={() => navigate(`/purchasing/rfqs/${id}`)}>
                Cancel
              </Button>
              <Button
                variant="primary"
                disabled={save.isPending || problems.length > 0}
                loading={save.isPending}
                onClick={() => save.mutate()}
              >
                {save.isPending ? 'Saving…' : 'Save quotation'}
              </Button>
            </div>
          </div>
          {problems.length > 0 && (
            <ul className="mt-2 list-disc pl-5 text-xs text-warning-fg">
              {problems.map((problem) => (
                <li key={problem}>{problem}</li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </div>
  );
}
