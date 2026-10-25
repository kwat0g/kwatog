import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { LuCheck, LuPackage, LuPlus, LuX, LuSend, LuSearch } from '@/lib/icons';
import { useDebounce } from '@/hooks/useDebounce';
import { customerPortalApi } from '@/api/b2b/customer';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import { fromCentavos, toCentavos } from '@/lib/money';
import type { PortalCatalogItem } from '@/types/b2b';
import { ProductPhotoButton, ProductPhotoViewer, type ProductPhotoDetails } from '@/components/crm/ProductPhoto';

interface OrderLine {
  product: PortalCatalogItem & { unit_price: string };
  quantity: string;
}

export default function CustomerPlaceOrderPage() {
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const initializedOrderRef = useRef<string | null>(null);
  const { data: existingOrder, isLoading: orderLoading, isError: orderError } = useQuery({
    queryKey: ['portal', 'customer', 'order', id],
    queryFn: () => customerPortalApi.getOrder(id!),
    enabled: !!id,
  });
  const [search, setSearch] = useState('');
  const debouncedSearch = useDebounce(search, 300);
  const [orderDate, setOrderDate] = useState(() => new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(new Date()));
  const earliestDeliveryDate = useMemo(() => {
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    const earliest = new Date(`${today}T00:00:00Z`);
    earliest.setUTCDate(earliest.getUTCDate() + 4);
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'UTC', year: 'numeric', month: '2-digit', day: '2-digit' }).format(earliest);
  }, []);
  const [deliveryDate, setDeliveryDate] = useState('');
  const [catalogOpen, setCatalogOpen] = useState(false);
  const [photoPreview, setPhotoPreview] = useState<ProductPhotoDetails | null>(null);
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<OrderLine[]>([]);
  const addProductsRef = useRef<HTMLButtonElement>(null);
  const searchInputRef = useRef<HTMLInputElement>(null);
  const orderRequestRef = useRef<{ fingerprint: string; key: string } | null>(null);

  useEffect(() => {
    if (catalogOpen) searchInputRef.current?.focus();
  }, [catalogOpen]);

  const { data: catalog, isLoading, isFetching, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'catalog', debouncedSearch, deliveryDate],
    queryFn: ({ signal }) => customerPortalApi.listCatalog({ search: debouncedSearch || undefined, as_of: deliveryDate || undefined }, signal),
    placeholderData: (prev) => prev,
  });

  const { data: policies, isError: policyError, refetch: refetchPolicies } = useQuery({
    queryKey: ['portal', 'customer', 'business-policies'],
    queryFn: customerPortalApi.businessPolicies,
  });
  const vatRegistered = policies?.vat_status?.trim().toLowerCase() === 'vat registered';
  const rate = policies?.vat_rate != null ? Number(policies.vat_rate) : null;
  useEffect(() => {
    if (!id || !existingOrder || initializedOrderRef.current === id) return;
    initializedOrderRef.current = id;
    setOrderDate(existingOrder.date ?? '');
    setDeliveryDate(existingOrder.requested_delivery_dates?.[0] ?? existingOrder.items?.[0]?.delivery_date ?? '');
    setNotes(existingOrder.notes ?? '');
    setLines((existingOrder.items ?? []).flatMap((item) => item.product ? [{
      product: { ...item.product, part_number: item.product.part_number ?? '', name: item.product.name ?? '', unit_of_measure: item.product.unit_of_measure ?? '', unit_price: item.unit_price, price_source: item.price_source, pricing_method: 'flat' },
      quantity: item.quantity,
    }] : []));
  }, [id, existingOrder]);
  const taxReady = !!policies?.vat_status && (!vatRegistered || (rate != null && Number.isFinite(rate) && rate >= 0 && rate <= 1));
  const invalidLines = lines.filter((line) => !(Number.isSafeInteger(Number(line.quantity)) && Number(line.quantity) >= 1));
  const deliveryError = !deliveryDate ? 'Choose a delivery date.'
    : deliveryDate < earliestDeliveryDate ? 'Choose a date more than 3 days from today.' : undefined;
  const quotePayload = JSON.stringify({
    sales_order_id: id,
    items: lines.map((line) => ({ product_id: line.product.id, quantity: line.quantity, delivery_date: deliveryDate })),
  });
  const debouncedQuotePayload = useDebounce(quotePayload, 300);
  const quoteQuery = useQuery({
    queryKey: ['portal', 'customer', 'order-quote', debouncedQuotePayload],
    queryFn: () => customerPortalApi.quoteOrder(JSON.parse(debouncedQuotePayload)),
    enabled: lines.length > 0 && !deliveryError && invalidLines.length === 0 && debouncedQuotePayload === quotePayload,
    retry: false,
  });
  const quote = debouncedQuotePayload === quotePayload && !quoteQuery.isFetching && !quoteQuery.isError ? quoteQuery.data : undefined;
  const quotedLine = (line: OrderLine) => quote?.items.find((item) => item.product_id === line.product.id);
  const lineTotalCentavos = (line: OrderLine) => quotedLine(line) ? toCentavos(quotedLine(line)!.total)
    : Math.round(toCentavos(line.product.unit_price) * (Number(line.quantity) || 0));
  const subtotalCentavos = quote ? toCentavos(quote.subtotal) : lines.reduce((sum, line) => sum + lineTotalCentavos(line), 0);
  const vatCentavos = quote ? toCentavos(quote.vat_amount) : taxReady ? vatRegistered ? Math.round(subtotalCentavos * rate!) : 0 : null;
  const appliedVat = quote ? toCentavos(quote.vat_amount) > 0 : vatRegistered;
  const appliedVatRate = quote && subtotalCentavos > 0 ? toCentavos(quote.vat_amount) / subtotalCentavos : rate ?? 0;
  const orderSubtotal = fromCentavos(subtotalCentavos);
  const orderTotal = quote ? quote.total_amount : vatCentavos != null ? fromCentavos(subtotalCentavos + vatCentavos) : null;
  const quoteError = quoteQuery.error as Error & { response?: { data?: { message?: string; errors?: Record<string, string[]> } } } | null;
  const pricingError = quoteQuery.isError && debouncedQuotePayload === quotePayload
    ? Object.values(quoteError?.response?.data?.errors ?? {}).flat().join(' ') || quoteError?.response?.data?.message || 'Unable to check prices. Try again.' : null;

  const addLine = (product: PortalCatalogItem) => {
    const unitPrice = product.unit_price;
    if (unitPrice === null) return;
    if (lines.some((line) => line.product.id === product.id)) return;
    setLines([...lines, { product: { ...product, unit_price: unitPrice }, quantity: '1' }]);
  };

  const removeLine = (idx: number) => setLines(lines.filter((_, i) => i !== idx));

  const updateQuantity = (idx: number, quantity: string) => {
    setLines(lines.map((line, i) => i === idx ? { ...line, quantity } : line));
  };


  const createMut = useMutation({
    mutationFn: () => {
      const payload = {
        notes: notes.trim() || undefined,
        quote_signature: quote?.signature,
        items: lines.map((line) => ({
          product_id: line.product.id,
          quantity: line.quantity,
          delivery_date: deliveryDate,
        })),
      };
      if (id) return customerPortalApi.updateOrder(id, payload);
      const fingerprint = JSON.stringify({ notes: payload.notes, items: payload.items });
      if (orderRequestRef.current?.fingerprint !== fingerprint) {
        orderRequestRef.current = { fingerprint, key: window.crypto.randomUUID() };
      }
      return customerPortalApi.createOrder(payload, orderRequestRef.current.key);
    },
    onSuccess: (res) => {
      toast.success(res.message ?? (id ? 'Order updated.' : 'Order submitted.'));
      queryClient.invalidateQueries({ queryKey: ['portal', 'customer'] });
      navigate(`/portal/customer/orders/${res.data.id}`);
    },
    onError: (e: Error & { response?: { data?: { message?: string; errors?: Record<string, string[]> } } } ) => {
      if (e.response?.data?.errors?.quote_signature) quoteQuery.refetch();
      toast.error(e.response?.data?.message ?? 'Failed to submit order.');
    },
  });

  const canSubmit =
    (!id || !!existingOrder?.capabilities?.can_edit) && taxReady && !!quote && !deliveryError && lines.length > 0 && invalidLines.length === 0 && !createMut.isPending;
  const submissionError = createMut.isError
    ? createMut.error.response?.data?.message ?? 'Unable to submit your order. Your items are saved on this page; please try again.'
    : null;

  if (id && (orderLoading || orderError || !existingOrder?.capabilities?.can_edit)) {
    return <div>
      <PageHeader title="Edit order" backTo={`/portal/customer/orders/${id}`} backLabel="Order" />
      <div className="p-5">{orderLoading ? <SkeletonTable columns={4} rows={3} /> :
        <EmptyState icon="alert-circle" title={orderError ? 'Unable to load order' : 'This order cannot be edited'}
          description="Only your customer-submitted orders awaiting sales confirmation can be edited."
          action={<Button onClick={() => navigate(`/portal/customer/orders/${id}`)}>Return to order</Button>} />}</div>
    </div>;
  }

  return (
    <div className="[color-scheme:light] dark:[color-scheme:dark]">
      <ProductPhotoViewer photo={photoPreview} onClose={() => setPhotoPreview(null)} />
      <PageHeader title={id ? `Edit ${existingOrder?.so_number ?? 'order'}` : 'Place an Order'} subtitle={id ? 'Changes are allowed until sales confirms this order.' : 'Select products and quantities. Prices reflect your delivery date and any customer agreement.'}
        backTo={id ? `/portal/customer/orders/${id}` : '/portal/customer/orders'} backLabel={id ? 'Order' : 'My orders'} />
      <form onSubmit={(event) => {
        event.preventDefault();
        if (canSubmit) createMut.mutate();
      }} className="min-w-0 w-full space-y-5 px-4 py-5 sm:px-5 lg:py-6">
        <fieldset aria-label="Create customer order" disabled={createMut.isPending} className="min-w-0 space-y-5">
          <section aria-labelledby="order-details-heading" className="grid items-start gap-4 rounded-lg border border-default bg-canvas p-4 sm:p-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
            <div>
              <h2 id="order-details-heading" className="text-base font-semibold">Order details</h2>
              <p className="mt-1 text-sm leading-relaxed text-muted">One requested delivery date for all products.</p>
            </div>
            <div className="grid min-w-0 grid-cols-2 items-start gap-3 sm:gap-4">
              <Input fieldSize="lg" label="Order date" aria-label="Order date" type="date" readOnly
                containerClassName="[&>label]:min-h-8 sm:[&>label]:min-h-0"
                value={orderDate} helper={id ? 'Original submission date.' : 'Set automatically when submitted.'} />
              <Input fieldSize="lg" label="Requested delivery date" aria-label="Requested delivery date" type="date" required
                containerClassName="[&>label]:min-h-8 sm:[&>label]:min-h-0"
                min={earliestDeliveryDate} value={deliveryDate} error={deliveryError}
                helper={id ? `Current schedule: ${existingOrder?.delivery_dates?.join(', ') || existingOrder?.items?.[0]?.delivery_date || '—'}. Select a date to save.` : `Earliest available date: ${earliestDeliveryDate}.`}
                onChange={(e) => setDeliveryDate(e.target.value)} />
            </div>
          </section>
          <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_300px]">
            <div className="min-w-0 overflow-hidden rounded-lg border border-default bg-canvas">
              <div className="flex items-center justify-between gap-3 border-b border-default px-4 py-3 sm:px-5">
                <div className="min-w-0">
                  <h2 id="order-items-heading" className="text-base font-semibold">Order items</h2>
                  <p role="status" className="mt-0.5 text-xs text-muted">{lines.length === 0 ? 'Select the products you need.' : `${lines.length} product${lines.length === 1 ? '' : 's'} selected`}</p>
                </div>
                <Button ref={addProductsRef} type="button" className="min-h-11 shrink-0" icon={<LuPlus size={16} />}
                  aria-expanded={catalogOpen} aria-controls="product-picker" onClick={() => setCatalogOpen(!catalogOpen)}>Add products</Button>
              </div>
              {catalogOpen && <div id="product-picker" className="border-b border-default bg-surface">
                <div className="flex items-center gap-2 p-3 sm:px-5">
                  <Input ref={searchInputRef} fieldSize="lg" aria-label="Search products" placeholder="Search part number or name"
                    prefix={<LuSearch size={18} aria-hidden="true" />} containerClassName="flex-1 min-w-0"
                    suffix={search ? <Button type="button" variant="ghost" iconOnly className="min-h-11 min-w-11"
                      aria-label="Clear product search" icon={<LuX size={16} />} onClick={() => setSearch('')} /> : undefined}
                    value={search} onChange={(e) => setSearch(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter') e.preventDefault(); }} />
                  <Button type="button" className="min-h-11 shrink-0" aria-label="Done selecting products" onClick={() => {
                    addProductsRef.current?.focus();
                    setCatalogOpen(false);
                  }}>Done</Button>
                </div>
                {isLoading && !catalog && <SkeletonTable columns={3} rows={3} />}
                {isError && <EmptyState icon="alert-circle" title="Unable to load the catalog" size="compact"
                  description="Your order items are still here. Try again."
                  action={<Button type="button" onClick={() => refetch()}>Retry</Button>} />}
                {!isError && catalog && (catalog.length ? (
                  <ul aria-label="Available products" aria-busy={isFetching}
                    className="max-h-64 overflow-y-auto overscroll-contain divide-y divide-default bg-canvas">
                    {catalog.map((product) => {
                      const added = lines.some((line) => line.product.id === product.id);
                      return <li key={product.id} className={`grid grid-cols-[44px_minmax(0,1fr)_80px] items-center gap-x-3 px-4 py-2 sm:grid-cols-[44px_minmax(0,1fr)_100px_80px] sm:px-5 ${added ? 'bg-surface' : 'hover:bg-surface'}`}>
                        {product.image_url ? (
                          <ProductPhotoButton photo={{ url: product.image_url, name: product.name, partNumber: product.part_number }} className="col-start-1 row-span-2 row-start-1 h-11 w-11" onView={setPhotoPreview} />
                        ) : (
                          <div aria-hidden="true" className="col-start-1 row-span-2 row-start-1 flex h-11 w-11 items-center justify-center rounded border border-default bg-canvas text-muted">
                            <LuPackage size={18} />
                          </div>
                        )}
                        <div className="col-start-2 row-start-1 min-w-0"><p className="font-medium leading-snug break-words">{product.name}</p>
                          <p className="mt-0.5 font-mono text-xs text-muted">{product.part_number}</p></div>
                        <p className="col-start-2 row-start-2 text-xs text-muted sm:col-start-3 sm:row-start-1 sm:text-right">
                          <span className="font-mono tabular-nums">{product.unit_price === null ? 'Price unavailable' : formatPeso(product.unit_price)}</span>{product.unit_price !== null && <> / {product.unit_of_measure || 'unit'}</>}
                          {product.pricing_issue && <span className="mt-1 block text-warning-fg">{product.pricing_issue}</span>}
                        </p>
                        <Button type="button" size="sm" className="col-start-3 row-span-2 row-start-1 min-h-11 disabled:opacity-100 disabled:text-muted sm:col-start-4 sm:row-span-1"
                          icon={added ? <LuCheck size={14} /> : <LuPlus size={14} />}
                          aria-label={added ? `${product.name} added to order` : product.unit_price === null ? `${product.name} cannot be ordered: ${product.pricing_issue ?? 'price unavailable'}` : `Add ${product.name} to order`}
                          disabled={added || product.unit_price === null} onClick={() => addLine(product)}>{added ? 'Added' : product.unit_price === null ? 'Unavailable' : 'Add'}</Button>
                      </li>;
                    })}
                  </ul>
                ) : <EmptyState icon="search" title={search ? 'No matching products' : 'No products available'} size="compact"
                  description={search ? 'Try a different part number or name.' : 'Contact sales to check product access and selling prices.'}
                  action={search ? <Button type="button" onClick={() => setSearch('')}>Clear search</Button> : undefined} />)}
              </div>}
              {lines.length === 0 ? <div className="flex min-h-52 flex-col items-center justify-center gap-2 px-5 py-8 text-center">
                <h3 className="text-base font-medium">Your order starts here</h3>
                <p className="max-w-sm text-sm leading-relaxed text-muted">Use Add products to select from your catalog, then enter a whole-number quantity for each product.</p>
              </div> : <>
                <div role="region" aria-label="Order items" tabIndex={0}
                  className="max-h-[min(46dvh,380px)] overflow-auto overscroll-contain focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent">
                  <table aria-label="Selected order items" className={`${tableCls} min-w-[640px] table-fixed`}>
                    <colgroup><col /><col className="w-28" /><col className="w-24" /><col className="w-28" /><col className="w-16" /></colgroup>
                    <thead className="sticky top-0 z-10 bg-surface">
                      <tr className={theadTrCls}>
                        <Th>Product / Part #</Th><Th>Quantity</Th><Th align="right">Unit price</Th><Th align="right">Amount</Th><Th><span className="sr-only">Remove</span></Th>
                      </tr>
                    </thead>
                    <tbody>
                      {lines.map((line, idx) => <tr key={line.product.id} className={trCls}>
                        <Td>
                          <div className="flex items-center gap-3">
                            {line.product.image_url ? (
                              <ProductPhotoButton photo={{ url: line.product.image_url, name: line.product.name, partNumber: line.product.part_number }} className="h-11 w-11" onView={setPhotoPreview} />
                            ) : (
                              <div aria-hidden="true" className="flex h-11 w-11 shrink-0 items-center justify-center rounded border border-default bg-surface text-muted">
                                <LuPackage size={16} />
                              </div>
                            )}
                            <div className="min-w-0">
                              <p className="font-medium leading-snug break-words">{line.product.name}</p>
                              <p className="mt-0.5 font-mono text-xs text-muted">{line.product.part_number} <span className="font-sans">· {line.product.unit_of_measure || 'unit'}</span></p>
                            </div>
                          </div>
                        </Td>
                        <Td>
                          <Input fieldSize="lg" aria-label={`Quantity for ${line.product.part_number}`} type="number" min={1} step={1} inputMode="numeric" required
                            className="font-mono tabular-nums" value={line.quantity}
                            error={!(Number.isSafeInteger(Number(line.quantity)) && Number(line.quantity) >= 1) ? 'Enter a whole number of at least 1.' : undefined}
                            onChange={(e) => updateQuantity(idx, e.target.value)} />
                        </Td>
                        <Td align="right" mono className="whitespace-nowrap text-muted">{formatPeso(quotedLine(line)?.unit_price ?? line.product.unit_price)}<p className="mt-1 font-sans text-xs">{(quotedLine(line)?.price_source ?? line.product.price_source) === 'agreement' ? 'Agreed price' : (quotedLine(line)?.price_source ?? line.product.price_source) === 'base' ? 'Standard price' : id ? 'Recorded price' : 'Standard price'}</p></Td>
                        <Td align="right" mono className="whitespace-nowrap font-medium">{formatPeso(fromCentavos(lineTotalCentavos(line)))}</Td>
                        <Td>
                          <Button type="button" variant="ghost" iconOnly className="min-h-11 min-w-11"
                            icon={<LuX size={18} />} aria-label={`Remove ${line.product.name}`} onClick={() => removeLine(idx)} />
                        </Td>
                      </tr>)}
                    </tbody>
                  </table>
                </div>
                <p className={`border-t border-default px-4 py-2 text-xs text-muted sm:px-5 ${lines.length <= 6 ? 'lg:hidden' : ''}`}><span className="lg:hidden">Swipe across for prices and amounts. </span>{lines.length > 6 && 'Scroll within the table to review all products.'}</p>
              </>}
            </div>
            <aside aria-labelledby="order-review-heading" className="min-w-0 rounded-lg border border-default bg-surface p-4 sm:p-5 xl:sticky xl:top-16">
              <h2 id="order-review-heading" className="text-base font-semibold">Order summary</h2>
              <dl className="mt-5 space-y-3 text-sm">
                <div className="flex items-center justify-between gap-3"><dt className="text-muted">Subtotal</dt><dd data-testid="order-subtotal" className="font-mono tabular-nums">{formatPeso(orderSubtotal)}</dd></div>
                <div className="flex items-center justify-between gap-3"><dt className="text-muted">{taxReady && appliedVat ? `VAT (${Number((appliedVatRate * 100).toFixed(4))}%)` : 'VAT'}</dt>
                  <dd data-testid="order-vat" className="font-mono tabular-nums">{vatCentavos != null ? formatPeso(fromCentavos(vatCentavos)) : 'Pending'}</dd></div>
                <div className="border-t border-default pt-4">
                  <dt className="font-medium">Total amount</dt>
                  <dd data-testid="order-total" className="mt-1 font-mono text-2xl font-semibold tabular-nums">{orderTotal != null ? formatPeso(orderTotal) : 'Pending'}</dd>
                  <p className="mt-1 text-xs text-muted">{taxReady ? appliedVat ? 'VAT included' : 'No VAT applied' : 'Loading tax…'}</p>
                </div>
              </dl>
              {policyError || (policies && !taxReady) ? <p role="alert" className="mt-4 text-sm text-danger-fg">
                Unable to load tax information. <Button type="button" size="sm" onClick={() => refetchPolicies()}>Retry tax information</Button>
              </p> : null}
              {pricingError && <p role="alert" className="mt-4 text-sm text-danger-fg">{pricingError} <Button type="button" size="sm" onClick={() => quoteQuery.refetch()}>Retry prices</Button></p>}
              {submissionError && <p role="alert" className="mt-4 text-sm text-danger-fg">{submissionError}</p>}
              <Textarea label="Order note (optional)" rows={2} maxLength={1000} containerClassName="mt-5" value={notes}
                onChange={(e) => setNotes(e.target.value)} placeholder="Delivery instructions or notes for our sales team…" />
              <Button type="submit" variant="primary" className="mt-3 min-h-11 w-full" icon={<LuSend size={16} />}
                disabled={!canSubmit} loading={createMut.isPending}>{createMut.isPending ? id ? 'Saving changes…' : 'Submitting order…' : id ? 'Save changes' : 'Submit order for review'}</Button>
              <p role="status" className="mt-3 text-xs leading-relaxed text-muted">
                {lines.length === 0 ? 'Add at least one product to continue.'
                  : deliveryError || invalidLines.length > 0 ? 'Complete the highlighted fields to continue.'
                    : quote ? 'Prices checked. Sales will review and confirm your order.' : 'Checking prices for your quantity and delivery date…'}
              </p>
            </aside>
          </div>
        </fieldset>
      </form>
    </div>
  );
}
