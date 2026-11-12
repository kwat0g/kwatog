import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useOutletContext, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { LuCheck, LuPackage, LuPlus, LuX, LuSend, LuSearch } from '@/lib/icons';
import { useDebounce } from '@/hooks/useDebounce';
import { customerPortalApi } from '@/api/b2b/customer';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import { fromCentavos, toCentavos } from '@/lib/money';
import type { CustomerPortalUser, PortalCatalogItem } from '@/types/b2b';
import { ProductPhotoButton, ProductPhotoViewer, type ProductPhotoDetails } from '@/components/crm/ProductPhoto';

interface OrderLine {
  product: PortalCatalogItem & { unit_price: string };
  quantity: string;
  warranty_option_id?: string;
}

const PAYMENT_METHODS = [
  { value: 'cash', label: 'Cash' },
  { value: 'check', label: 'Check' },
  { value: 'bank_transfer', label: 'Bank transfer' },
] as const;

export default function CustomerPlaceOrderPage() {
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const portalSession = useOutletContext<CustomerPortalUser>();
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
  const [paymentMethod, setPaymentMethod] = useState<'cash' | 'check' | 'bank_transfer' | ''>('');
  const [paymentTermsDays, setPaymentTermsDays] = useState('');
  const paymentTermsTouchedRef = useRef(false);
  const [lines, setLines] = useState<OrderLine[]>([]);
  const addProductsRef = useRef<HTMLButtonElement>(null);
  const searchInputRef = useRef<HTMLInputElement>(null);
  const orderRequestRef = useRef<{ fingerprint: string; key: string } | null>(null);

  useEffect(() => {
    if (catalogOpen) searchInputRef.current?.focus();
  }, [catalogOpen]);

  const { data: catalog, isLoading, isFetching, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'catalog', portalSession.id, portalSession.customer_id, debouncedSearch, deliveryDate],
    queryFn: ({ signal }) => customerPortalApi.listCatalog({ search: debouncedSearch || undefined, as_of: deliveryDate || undefined }, signal),
  });

  const { data: policies, isError: policyError, refetch: refetchPolicies } = useQuery({
    queryKey: ['portal', 'customer', 'business-policies'],
    queryFn: customerPortalApi.businessPolicies,
  });
  const { data: portalUser } = useQuery({
    queryKey: ['portal', 'customer', 'me'],
    queryFn: customerPortalApi.me,
  });
  const allowedPaymentMethods = portalUser?.customer_terms?.allowed_payment_methods ?? PAYMENT_METHODS.map((method) => method.value);
  const paymentMethodOptions = PAYMENT_METHODS.filter((method) => allowedPaymentMethods.includes(method.value));
  const shippingPayer = portalUser?.customer_terms?.shipping_payer;
  const agreementStatus = portalUser?.customer_terms?.agreement_status;
  const vatRegistered = policies?.vat_status?.trim().toLowerCase() === 'vat registered';
  const rate = policies?.vat_rate != null ? Number(policies.vat_rate) : null;
  useEffect(() => {
    if (!id || !existingOrder || initializedOrderRef.current === id) return;
    initializedOrderRef.current = id;
    setOrderDate(existingOrder.date ?? '');
    setDeliveryDate(existingOrder.requested_delivery_dates?.[0] ?? existingOrder.items?.[0]?.delivery_date ?? '');
    setNotes(existingOrder.notes ?? '');
    setPaymentMethod(existingOrder.payment_method ?? '');
    setPaymentTermsDays(String(existingOrder.payment_terms_days ?? ''));
    setLines((existingOrder.items ?? []).flatMap((item) => item.product ? [{
      product: { ...item.product, part_number: item.product.part_number ?? '', name: item.product.name ?? '', unit_of_measure: item.product.unit_of_measure ?? '', unit_price: item.unit_price, price_source: item.price_source, pricing_method: 'flat' },
      quantity: item.quantity,
      warranty_option_id: item.warranty_terms_snapshot?.warranty_option_id,
    }] : []));
  }, [id, existingOrder]);
  useEffect(() => {
    if (id || !portalUser || paymentTermsTouchedRef.current) return;
    setPaymentTermsDays(String(portalUser.customer_terms?.payment_terms_days ?? ''));
  }, [id, portalUser]);
  const taxReady = !!policies?.vat_status && (!vatRegistered || (rate != null && Number.isFinite(rate) && rate >= 0 && rate <= 1));
  const invalidLines = lines.filter((line) => {
    const quantity = Number(line.quantity);
    const minimum = Number(line.product.minimum_order_quantity ?? 1);
    const maximum = line.product.maximum_order_quantity == null ? null : Number(line.product.maximum_order_quantity);
    const exceedsStock = line.product.availability_mode === 'stock_limited'
      && line.product.available_quantity != null
      && quantity > Number(line.product.available_quantity);
    const belowMinimum = Number.isFinite(minimum) && quantity < minimum;
    const exceedsAgreementMaximum = maximum !== null && Number.isFinite(maximum) && quantity > maximum;
    const warrantyRequired = (line.product.warranty_options?.length ?? 0) > 1 && !line.warranty_option_id;
    return !(Number.isSafeInteger(quantity) && quantity >= 1) || belowMinimum || exceedsAgreementMaximum || exceedsStock || warrantyRequired;
  });
  const paymentTermsValue = Number(paymentTermsDays);
  const paymentTermsValid = /^\d+$/.test(paymentTermsDays) && Number.isSafeInteger(paymentTermsValue)
    && paymentTermsValue >= 0 && paymentTermsValue <= 365;
  const paymentTermsError = paymentTermsValid ? undefined
    : paymentTermsDays === '' ? 'Enter the payment terms requested for this order.' : 'Use a whole number from 0 to 365 days.';
  const deliveryError = !deliveryDate ? 'Choose a delivery date.'
    : deliveryDate < earliestDeliveryDate ? 'Choose a date more than 3 days from today.' : undefined;
  const quotePayload = JSON.stringify({
    sales_order_id: id,
    items: lines.map((line) => ({ product_id: line.product.id, quantity: line.quantity, delivery_date: deliveryDate })),
  });
  const debouncedQuotePayload = useDebounce(quotePayload, 300);
  const quoteQuery = useQuery({
    queryKey: ['portal', 'customer', 'order-quote', portalSession.id, portalSession.customer_id, debouncedQuotePayload],
    queryFn: () => customerPortalApi.quoteOrder(JSON.parse(debouncedQuotePayload)),
    enabled: lines.length > 0 && !deliveryError && invalidLines.length === 0 && debouncedQuotePayload === quotePayload,
    retry: false,
  });
  const quote = debouncedQuotePayload === quotePayload && !quoteQuery.isFetching && !quoteQuery.isError ? quoteQuery.data : undefined;
  const quotedLine = (line: OrderLine) => quote?.items.find((item) => item.product_id === line.product.id);
  const subtotalCentavos = quote ? toCentavos(quote.subtotal) : null;
  const vatCentavos = quote ? toCentavos(quote.vat_amount) : null;
  const appliedVat = quote ? toCentavos(quote.vat_amount) > 0 : vatRegistered;
  const appliedVatRate = quote && subtotalCentavos != null && subtotalCentavos > 0 ? toCentavos(quote.vat_amount) / subtotalCentavos : rate ?? 0;
  const orderSubtotal = subtotalCentavos != null ? fromCentavos(subtotalCentavos) : null;
  const orderTotal = quote ? quote.total_amount : null;
  const quoteError = quoteQuery.error as Error & { response?: { data?: { message?: string; errors?: Record<string, string[]> } } } | null;
  const pricingError = quoteQuery.isError && debouncedQuotePayload === quotePayload
    ? Object.values(quoteError?.response?.data?.errors ?? {}).flat().join(' ') || quoteError?.response?.data?.message || 'Unable to check prices. Try again.' : null;

  const addLine = (product: PortalCatalogItem) => {
    const unitPrice = product.unit_price;
    if (unitPrice === null) return;
    if (lines.some((line) => line.product.id === product.id)) return;
    const minimum = Math.max(1, Math.ceil(Number(product.minimum_order_quantity ?? 1)));
    setLines([...lines, {
      product: { ...product, unit_price: unitPrice },
      quantity: String(minimum),
      warranty_option_id: product.warranty_options?.length === 1 ? product.warranty_options[0].id : undefined,
    }]);
  };

  const removeLine = (idx: number) => setLines(lines.filter((_, i) => i !== idx));

  const updateQuantity = (idx: number, quantity: string) => {
    setLines(lines.map((line, i) => i === idx ? { ...line, quantity } : line));
  };

  const updateWarranty = (idx: number, warranty_option_id: string) => {
    setLines(lines.map((line, i) => i === idx ? { ...line, warranty_option_id: warranty_option_id || undefined } : line));
  };


  const createMut = useMutation({
    mutationFn: () => {
      const payload = {
        notes: notes.trim() || undefined,
        payment_method: paymentMethod || undefined,
        payment_terms_days: paymentTermsValue,
        quote_signature: quote?.signature,
        items: lines.map((line) => ({
          product_id: line.product.id,
          quantity: line.quantity,
          delivery_date: deliveryDate,
          ...(line.warranty_option_id ? { warranty_option_id: line.warranty_option_id } : {}),
        })),
      };
      if (id) return customerPortalApi.updateOrder(id, payload);
      const fingerprint = JSON.stringify({ notes: payload.notes, payment_method: payload.payment_method, payment_terms_days: payload.payment_terms_days, items: payload.items });
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
    (!id || !!existingOrder?.capabilities?.can_edit) && !!portalUser && !!paymentMethod && paymentMethodOptions.some((method) => method.value === paymentMethod) && paymentTermsValid && taxReady && !!quote && !deliveryError && lines.length > 0 && invalidLines.length === 0 && !createMut.isPending;
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
      <form data-portal-guide-target="form" onSubmit={(event) => {
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
              <Select label="Payment method" required value={paymentMethod} onChange={(event) => setPaymentMethod(event.target.value as typeof paymentMethod)}>
                <option value="">Choose payment method</option>
                {paymentMethodOptions.map((method) => <option key={method.value} value={method.value}>{method.label}</option>)}
              </Select>
              <Input fieldSize="lg" containerClassName="col-span-2" label="Requested payment terms (days)" aria-label="Requested payment terms (days)"
                type="number" min={0} max={365} step={1} inputMode="numeric" required value={paymentTermsDays}
                error={paymentTermsDays !== '' && !paymentTermsValid ? paymentTermsError : undefined}
                helper={`Customer default: ${portalUser?.customer_terms?.payment_terms_days ?? '—'} days. Sales reviews your request before confirming the order.`}
                onChange={(event) => { paymentTermsTouchedRef.current = true; setPaymentTermsDays(event.target.value); }} />
              {!paymentTermsValid && paymentTermsDays === '' && <p role="status" className="col-span-2 -mt-2 text-xs text-warning-fg">{paymentTermsError}</p>}
              {paymentMethodOptions.length === 0 && <p role="alert" className="col-span-2 text-sm text-warning-fg">No payment methods are configured for this account. Contact Sales before placing an order.</p>}
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
                      const outOfStock = product.availability_mode === 'stock_limited' && Number(product.available_quantity ?? 0) < 1;
                      const productPhotos = product.images?.length
                        ? product.images.map((image) => ({ url: image.url, name: product.name, partNumber: product.part_number, altText: image.alt_text ?? undefined }))
                        : product.image_url ? [{ url: product.image_url, name: product.name, partNumber: product.part_number }] : [];
                      return <li key={product.id} className={`grid grid-cols-[44px_minmax(0,1fr)_80px] items-center gap-x-3 px-4 py-2 sm:grid-cols-[44px_minmax(0,1fr)_100px_80px] sm:px-5 ${added ? 'bg-surface' : 'hover:bg-surface'}`}>
                        {productPhotos.length > 1 ? (
                          <div aria-label={`Photos of ${product.name}`} className="col-start-1 row-span-2 row-start-1 grid max-h-11 w-11 grid-cols-2 gap-0.5 overflow-y-auto self-center">
                            {productPhotos.map((photo, index) => <ProductPhotoButton key={`${product.id}-photo-${index}`} photo={photo}
                              className="h-5 w-5" onView={setPhotoPreview} />)}
                          </div>
                        ) : productPhotos.length === 1 ? (
                          <ProductPhotoButton photo={productPhotos[0]} className="col-start-1 row-span-2 row-start-1 h-11 w-11" onView={setPhotoPreview} />
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
                          <span className="mt-1 block">{product.availability_mode === 'stock_limited'
                            ? `Available: ${product.available_quantity ?? '0'} ${product.unit_of_measure || 'units'}`
                            : 'Made to order'}</span>
                          {product.specifications && <span className="mt-1 block">{[product.specifications.material, product.specifications.finish].filter(Boolean).join(' · ')}</span>}
                          {product.is_customizable && <span className="mt-1 block text-accent">Customizable · blueprint available</span>}
                        </p>
                        <Button type="button" size="sm" className="col-start-3 row-span-2 row-start-1 min-h-11 disabled:opacity-100 disabled:text-muted sm:col-start-4 sm:row-span-1"
                          icon={added ? <LuCheck size={14} /> : <LuPlus size={14} />}
                          aria-label={added ? `${product.name} added to order` : product.unit_price === null ? `${product.name} cannot be ordered: ${product.pricing_issue ?? 'price unavailable'}` : `Add ${product.name} to order`}
                          disabled={added || product.unit_price === null || outOfStock} onClick={() => addLine(product)}>{added ? 'Added' : product.unit_price === null || outOfStock ? 'Unavailable' : 'Add'}</Button>
                        {outOfStock && <button type="button" className="col-start-2 col-span-2 row-start-3 justify-self-start text-xs font-medium text-accent underline underline-offset-2" onClick={() => navigate(`/portal/customer/pricing?product_id=${encodeURIComponent(product.id)}&quantity=1`)}>Request price and availability</button>}
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
                              {line.product.warranty_options && line.product.warranty_options.length > 0 && <div className="mt-2 max-w-sm">
                                <Select label={`Warranty for ${line.product.name}`} required={line.product.warranty_options.length > 1} value={line.warranty_option_id ?? ''} onChange={(event) => updateWarranty(idx, event.target.value)}>
                                  {line.product.warranty_options.length > 1 && <option value="">Choose warranty</option>}
                                  {line.product.warranty_options.map((option) => <option key={option.id} value={option.id}>{option.label} · {option.warranty_duration_days == null ? 'duration agreed separately' : `${option.warranty_duration_days} days`}</option>)}
                                </Select>
                                {line.product.warranty_options.find((option) => option.id === line.warranty_option_id)?.additional_terms && <p className="mt-1 text-xs text-muted">{line.product.warranty_options.find((option) => option.id === line.warranty_option_id)?.additional_terms}</p>}
                              </div>}
                            </div>
                          </div>
                        </Td>
                        <Td>
                          {(() => {
                            const minimum = Math.max(1, Math.ceil(Number(line.product.minimum_order_quantity ?? 1)));
                            const agreementMaximum = line.product.maximum_order_quantity == null ? null : Number(line.product.maximum_order_quantity);
                            const stockMaximum = line.product.availability_mode === 'stock_limited' && line.product.available_quantity != null
                              ? Number(line.product.available_quantity) : null;
                            const maximum = agreementMaximum == null ? stockMaximum
                              : stockMaximum == null ? agreementMaximum : Math.min(agreementMaximum, stockMaximum);
                            const quantity = Number(line.quantity);
                            const error = !(Number.isSafeInteger(quantity) && quantity >= 1) ? 'Enter a whole number of at least 1.'
                              : quantity < minimum ? `Minimum order quantity is ${line.product.minimum_order_quantity ?? '1.000'}.`
                                : agreementMaximum !== null && quantity > agreementMaximum ? `Maximum order quantity is ${line.product.maximum_order_quantity} per sales order.`
                                  : stockMaximum !== null && quantity > stockMaximum ? `Maximum available is ${line.product.available_quantity}.` : undefined;
                            return <Input fieldSize="lg" aria-label={`Quantity for ${line.product.part_number}`} type="number" min={minimum}
                            max={maximum ?? undefined}
                            step={1} inputMode="numeric" required
                            className="font-mono tabular-nums" value={line.quantity}
                            helper={`Allowed: ${line.product.minimum_order_quantity ?? '1.000'}–${line.product.maximum_order_quantity ?? 'no maximum'}${stockMaximum === null ? '' : ` · Available: ${line.product.available_quantity}`}`}
                            error={error} onChange={(e) => updateQuantity(idx, e.target.value)} />;
                          })()}
                          {line.product.availability_mode === 'stock_limited' && Number(line.quantity) > Number(line.product.available_quantity ?? 0) && <button type="button" className="mt-1 text-xs font-medium text-accent underline underline-offset-2" onClick={() => navigate(`/portal/customer/pricing?product_id=${encodeURIComponent(line.product.id)}&quantity=${encodeURIComponent(line.quantity)}`)}>Request price and availability</button>}
                        </Td>
                        <Td align="right" mono className="whitespace-nowrap text-muted">{quotedLine(line) ? formatPeso(quotedLine(line)!.unit_price) : pricingError ? 'Unavailable' : 'Pending'}<p className="mt-1 font-sans text-xs">{quotedLine(line)?.price_source === 'agreement' ? 'Agreed price' : quotedLine(line)?.price_source === 'base' ? 'Standard price' : quote ? 'Price unavailable' : pricingError ? 'Price check failed' : 'Checking price…'}</p></Td>
                        <Td align="right" mono className="whitespace-nowrap font-medium">{quotedLine(line) ? formatPeso(quotedLine(line)!.total) : pricingError ? 'Unavailable' : 'Pending'}</Td>
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
                <div className="flex items-center justify-between gap-3"><dt className="text-muted">Subtotal</dt><dd data-testid="order-subtotal" className="font-mono tabular-nums">{orderSubtotal != null ? formatPeso(orderSubtotal) : pricingError ? 'Unavailable' : 'Pending'}</dd></div>
                <div className="flex items-center justify-between gap-3"><dt className="text-muted">{taxReady && appliedVat ? `VAT (${Number((appliedVatRate * 100).toFixed(4))}%)` : 'VAT'}</dt>
                  <dd data-testid="order-vat" className="font-mono tabular-nums">{vatCentavos != null ? formatPeso(fromCentavos(vatCentavos)) : 'Pending'}</dd></div>
                <div className="border-t border-default pt-4">
                  <dt className="font-medium">Product total</dt>
                  <dd data-testid="order-total" className="mt-1 font-mono text-2xl font-semibold tabular-nums">{orderTotal != null ? formatPeso(orderTotal) : 'Pending'}</dd>
                  <p className="mt-1 text-xs text-muted">{taxReady ? appliedVat ? 'VAT included' : 'No VAT applied' : 'Loading tax…'}</p>
                  <p className="mt-1 text-xs text-muted">{agreementStatus === 'expired' || agreementStatus === 'not_yet_effective'
                    ? 'Your saved agreement was not in effect on this order date. Sales will confirm freight responsibility and any charge separately; freight is excluded here.'
                    : shippingPayer === 'company'
                      ? 'Freight is paid by Ogami and excluded from this customer total.'
                      : shippingPayer === 'customer'
                        ? 'Freight is excluded. Sales must confirm any customer-paid charge separately.'
                        : 'Freight payer and amount are not recorded. Confirm with Sales before accepting final charges.'}</p>
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
                  : !paymentMethod || paymentMethodOptions.length === 0 ? 'Choose an available payment method to continue.'
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
