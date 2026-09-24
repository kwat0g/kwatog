import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { supplierRfqsApi } from '@/api/purchasing/rfqs';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDate, formatDateTime, localIsoDate } from '@/lib/formatDate';
import { formatPeso, formatQuantity } from '@/lib/formatNumber';
import { fromCentavos, toCentavos } from '@/lib/money';
import { lineCentavos, quoteTotals, trimQuantity } from '@/lib/quoteTotals';
import type { SupplierQuoteWrite } from '@/types/purchasing';

type VatTreatment = SupplierQuoteWrite['vat_treatment'];
type Line = { quoting: boolean; quantity: string; price: string; deliverBy: string };

const errMsg = (e: unknown, fallback: string) =>
  (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
  (e instanceof Error ? e.message : fallback);

/**
 * The supplier's quotation: a price, quantity and delivery date per line,
 * VAT and freight once, the quotation PDF. Everything else is optional and
 * folded away. Ogami recalculates the totals on submit.
 */
export default function SupplierRfqQuotePage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const rfqQuery = useQuery({
    queryKey: ['portal', 'supplier', 'rfqs', id],
    queryFn: () => supplierRfqsApi.show(id),
    enabled: !!id,
  });
  // Same cache key the portal layout warms.
  const policies = useQuery({
    queryKey: ['portal', 'supplier', 'business-policies'],
    queryFn: () => supplierPortalApi.businessPolicies(),
    staleTime: 300_000,
  });
  const rfq = rfqQuery.data;
  const quote = rfq?.quote ?? null;

  const [lines, setLines] = useState<Record<string, Line>>({});
  const [vat, setVat] = useState<VatTreatment>('exclusive');
  const [freight, setFreight] = useState('0.00');
  const [validUntil, setValidUntil] = useState(() => localIsoDate(new Date(Date.now() + 30 * 86_400_000)));
  const [paymentTerms, setPaymentTerms] = useState('');
  const [notes, setNotes] = useState('');
  const [pdf, setPdf] = useState<File | null>(null);
  const [coa, setCoa] = useState<File | null>(null);
  const [seededFor, setSeededFor] = useState<string | null>(null);

  // Seed once per quote so a background refetch never wipes the supplier's typing.
  useEffect(() => {
    const key = `${rfq?.id ?? ''}:${quote?.id ?? 'new'}`;
    if (!rfq || seededFor === key) return;
    setSeededFor(key);
    setLines(
      Object.fromEntries(
        rfq.items.map((item) => {
          const saved = quote?.items.find((row) => row.request_for_quote_item_id === item.id);
          return [
            item.id,
            saved
              ? {
                  quoting: saved.response_status === 'quoted',
                  quantity: trimQuantity(saved.offered_quantity ?? item.quantity),
                  price: saved.unit_price ?? '',
                  deliverBy: saved.proposed_delivery_date ?? '',
                }
              : { quoting: true, quantity: trimQuantity(item.quantity), price: '', deliverBy: '' },
          ];
        }),
      ),
    );
    if (quote) {
      setVat(quote.vat_treatment);
      setFreight(quote.freight_amount);
      setValidUntil(quote.quote_valid_until ?? '');
      setPaymentTerms(quote.payment_terms ?? '');
      setNotes(quote.notes ?? '');
    }
  }, [rfq, quote, seededFor]);

  const setLine = (itemId: string, patch: Partial<Line>) =>
    setLines((cur) => ({ ...cur, [itemId]: { ...cur[itemId], ...patch } }));

  const totals = useMemo(() => {
    const goods = (rfq?.items ?? []).reduce((sum, item) => {
      const line = lines[item.id];
      return line?.quoting ? sum + lineCentavos(line.quantity || '0', line.price || '0') : sum;
    }, 0);
    return quoteTotals(goods, toCentavos(freight || '0'), vat, policies.data?.vat_rate ?? null);
  }, [rfq?.items, lines, freight, vat, policies.data?.vat_rate]);

  const hasPdf =
    !!pdf || !!quote?.quotation_original_filename || !!rfq?.my_documents?.some((doc) => doc.document_type === 'quotation_pdf');
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
  const quotingSomething = Object.values(lines).some((line) => line.quoting);

  const payload = (): SupplierQuoteWrite => ({
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
  });

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['portal', 'supplier', 'rfqs'] });

  const saveDraft = useMutation({
    mutationFn: () => supplierRfqsApi.saveQuote(id, { ...payload(), submit: false }),
    onSuccess: async () => {
      await refresh();
      toast.success('Draft saved. Ogami cannot see it until you submit.');
    },
    onError: (e) => toast.error(errMsg(e, 'The draft could not be saved.')),
  });

  const submit = useMutation({
    mutationFn: async () => {
      for (const [file, type] of [
        [pdf, 'quotation_pdf'],
        [coa, 'certificate_of_analysis'],
      ] as const) {
        if (!file) continue;
        const form = new FormData();
        form.append('file', file);
        form.append('document_type', type);
        await supplierRfqsApi.uploadDocument(id, form);
      }
      return supplierRfqsApi.saveQuote(id, { ...payload(), submit: true });
    },
    onSuccess: async () => {
      await refresh();
      toast.success('Quotation submitted.');
      navigate(`/portal/supplier/rfqs/${id}`);
    },
    onError: (e) => toast.error(errMsg(e, 'The quotation could not be submitted.')),
  });

  if (rfqQuery.isLoading) return <SkeletonTable columns={4} rows={6} />;
  if (rfqQuery.isError || !rfq) {
    return (
      <EmptyState
        icon="alert-circle"
        title="RFQ unavailable"
        action={<Button onClick={() => rfqQuery.refetch()}>Retry</Button>}
      />
    );
  }
  if (!rfq.can_quote) {
    return (
      <EmptyState
        icon="lock"
        title="This RFQ is no longer accepting quotations"
        action={<Button onClick={() => navigate(`/portal/supplier/rfqs/${id}`)}>Back to RFQ</Button>}
      />
    );
  }

  const pending = saveDraft.isPending || submit.isPending;
  const submitted = quote?.status === 'submitted';

  return (
    <div>
      <PageHeader
        title={submitted ? 'Update quotation' : 'Prepare quotation'}
        subtitle={
          <span>
            <span className="font-mono">{rfq.rfq_number}</span> · closes {formatDateTime(rfq.closes_at)}
          </span>
        }
        backTo={`/portal/supplier/rfqs/${id}`}
        backLabel="RFQ"
      />

      <div className="px-5 py-4 space-y-4 max-w-4xl">
        <Panel title="Your prices">
          <div className="space-y-4">
            {rfq.items.map((item) => {
              const line = lines[item.id];
              if (!line) return null;
              return (
                <div key={item.id} className="border-b border-subtle pb-4 last:border-0 last:pb-0">
                  <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                      <div className="font-medium">
                        {item.description}
                        {item.item?.code && <span className="ml-2 font-mono text-xs text-muted">{item.item.code}</span>}
                      </div>
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
                      {line.quoting ? "Can't supply this item" : 'Quote this item'}
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
                    <p className="mt-2 text-sm text-muted">Not quoting this item.</p>
                  )}
                </div>
              );
            })}
          </div>
        </Panel>

        <Panel title="VAT and freight">
          <div className="grid gap-3 sm:grid-cols-2">
            <Select label="Your prices are" value={vat} onChange={(e) => setVat(e.target.value as VatTreatment)}>
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
          </div>
        </Panel>

        <Panel title="Documents">
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <Input
                label={hasPdf && !pdf ? 'Quotation PDF (replace, optional)' : 'Quotation PDF (required)'}
                type="file"
                accept="application/pdf,image/png,image/jpeg"
                onChange={(e) => setPdf(e.target.files?.[0] ?? null)}
              />
              {hasPdf && !pdf && quote?.quotation_original_filename && (
                <p className="mt-1 text-xs text-muted">On file: {quote.quotation_original_filename}</p>
              )}
            </div>
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
            <Input
              label="Quote valid until"
              type="date"
              value={validUntil}
              onChange={(e) => setValidUntil(e.target.value)}
            />
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
                <span className="font-mono tabular-nums">{formatPeso(fromCentavos(totals.vat))}</span>. Ogami confirms the
                figures on submit.
              </div>
            </div>
            <div className="flex flex-wrap gap-2">
              <Link to={`/portal/supplier/rfqs/${id}`}>
                <Button variant="secondary" disabled={pending}>
                  Cancel
                </Button>
              </Link>
              {!submitted && (
                <Button
                  variant="secondary"
                  disabled={pending}
                  loading={saveDraft.isPending}
                  onClick={() => saveDraft.mutate()}
                >
                  Save draft
                </Button>
              )}
              <Button
                variant="primary"
                disabled={pending || !quotingSomething || problems.length > 0 || !hasPdf}
                loading={submit.isPending}
                onClick={() => submit.mutate()}
              >
                {submit.isPending ? 'Submitting…' : submitted ? 'Update quotation' : 'Submit quotation'}
              </Button>
            </div>
          </div>
          {(problems.length > 0 || !hasPdf || !quotingSomething) && (
            <ul className="mt-2 list-disc pl-5 text-xs text-warning-fg">
              {!quotingSomething && <li>Quote at least one item.</li>}
              {problems.map((problem) => (
                <li key={problem}>{problem}</li>
              ))}
              {!hasPdf && <li>Attach your quotation PDF.</li>}
            </ul>
          )}
        </div>
      </div>
    </div>
  );
}
