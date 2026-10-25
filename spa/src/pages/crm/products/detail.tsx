import { Link, useParams, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { LuPencil, LuChevronRight, LuPackage, LuLayers, LuShieldCheck } from '@/lib/icons';
import { productsApi } from '@/api/crm/products';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { usePermission } from '@/hooks/usePermission';
import { useDebounce } from '@/hooks/useDebounce';
import { formatPeso } from '@/lib/formatNumber';
import { formatDateTime } from '@/lib/formatDate';
import { focusRingInset } from '@/lib/focus';
import { ProductPhotoButton, ProductPhotoViewer, type ProductPhotoDetails } from '@/components/crm/ProductPhoto';

export default function ProductDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { can } = usePermission();
  const queryClient = useQueryClient();
  const canManage = can('crm.products.manage');
  const canManageCustomerAccess = can('crm.products.customer_access.manage');
  const canManagePrices = can('crm.products.prices.manage');
  const canViewCosts = can('inventory.costs.review') || can('inventory.costs.approve');
  const backTo = can('crm.products.view') ? '/crm/products' : '/dashboard';
  const backLabel = can('crm.products.view') ? 'Products' : 'Dashboard';
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['crm', 'products', 'detail', id],
    queryFn: () => productsApi.show(id!),
    enabled: !!id,
  });
  const [editingAccess, setEditingAccess] = useState(false);
  const [photoPreview, setPhotoPreview] = useState<ProductPhotoDetails | null>(null);
  const [customerSearch, setCustomerSearch] = useState('');
  const debouncedCustomerSearch = useDebounce(customerSearch, 300);
  const [accessVisibility, setAccessVisibility] = useState<'standard' | 'restricted'>('restricted');
  const [accessCustomerIds, setAccessCustomerIds] = useState<string[]>([]);
  const { data: customerOptions = [], isError: customerOptionsError, refetch: retryCustomerOptions } = useQuery({
    queryKey: ['crm', 'products', 'customer-options', debouncedCustomerSearch],
    queryFn: () => productsApi.customerOptions({ search: debouncedCustomerSearch || undefined }),
    enabled: editingAccess && canManageCustomerAccess,
  });
  const [knownCustomers, setKnownCustomers] = useState<Array<{ id: string; name: string }>>([]);
  useEffect(() => {
    setKnownCustomers((current) => {
      const byId = new Map(current.map((customer) => [customer.id, customer]));
      [...(data?.portal_customers ?? []), ...customerOptions].forEach((customer) => byId.set(customer.id, customer));
      return [...byId.values()];
    });
  }, [data?.portal_customers, customerOptions]);
  const visibleCustomers = knownCustomers.filter((customer) =>
    customer.name.toLowerCase().includes(debouncedCustomerSearch.trim().toLowerCase()),
  );
  const accessMutation = useMutation({
    mutationFn: () => productsApi.updateCustomerAccess(id!, {
      portal_visibility: accessVisibility,
      portal_customer_ids: accessCustomerIds,
    }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'products'] });
      setEditingAccess(false);
      toast.success('Customer access updated.');
    },
    onError: (error: unknown) => {
      const message = (error as { response?: { data?: { message?: string } } }).response?.data?.message
        ?? 'Could not update customer access.';
      toast.error(message);
    },
  });
  const [editingPrices, setEditingPrices] = useState(false);
  const [prices, setPrices] = useState({ base_selling_price: '', minimum_selling_price: '' });
  const [editingCost, setEditingCost] = useState(false);
  const [standardCost, setStandardCost] = useState('');
  const [notes, setNotes] = useState('');
  const [financialError, setFinancialError] = useState('');
  const [priceError, setPriceError] = useState('');
  const financial = useMutation({
    mutationFn: async (decision: 'propose' | 'approve' | 'reject') => {
      setFinancialError('');
      if (decision === 'propose')
        await productsApi.proposeFinancial(id!, { standard_cost: standardCost, notes });
      else
        await productsApi.decideFinancial(id!, data!.financial_change_request!.id, decision, notes);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'products'] });
      void refetch();
      setEditingCost(false);
      setNotes('');
      toast.success('Financial review recorded.');
    },
    onError: (error: unknown) => {
      const message =
        (error as { response?: { data?: { message?: string } } }).response?.data?.message ??
        'Could not record financial review.';
      setFinancialError(message);
      toast.error(message);
    },
  });
  const sellingPrices = useMutation({
    mutationFn: () => productsApi.updateSellingPrices(id!, {
      base_selling_price: prices.base_selling_price || null,
      minimum_selling_price: prices.minimum_selling_price || null,
    }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['crm', 'products'] });
      void refetch();
      setEditingPrices(false);
      toast.success('Selling prices updated.');
    },
    onError: (error: unknown) => {
      const message = (error as { response?: { data?: { message?: string } } }).response?.data?.message
        ?? 'Could not update selling prices.';
      setPriceError(message);
      toast.error(message);
    },
  });
  if (isLoading)
    return (
      <div>
        <PageHeader title="Product" backTo={backTo} backLabel={backLabel} />
        <SkeletonDetail />
      </div>
    );
  if (isError || !data)
    return (
      <div>
        <PageHeader title="Product" backTo={backTo} backLabel={backLabel} />
        <EmptyState
          icon="alert-circle"
          title="Failed to load product"
          action={<Button onClick={() => refetch()}>Retry</Button>}
        />
      </div>
    );

  const proposal = data.financial_change_request;
  const readiness = data.finished_good_readiness;
  const customers = data.portal_customers ?? [];
  const costRow = { label: 'Standard cost', current: data.standard_cost, proposed: proposal?.values.standard_cost };
  const relatedLinks = [
    ...(can('crm.sales_orders.view')
      ? [{ label: 'Sales orders', to: `/crm/sales-orders?product_id=${data.id}` }]
      : []),
    ...(can('crm.price_agreements.view')
      ? [{ label: 'Price agreements', to: `/crm/price-agreements?product_id=${data.id}` }]
      : []),
  ];
  const linkClass = `flex min-h-10 items-center justify-between gap-3 rounded px-3 py-2 text-sm text-accent hover:bg-elevated ${focusRingInset}`;

  return (
    <div>
      <ProductPhotoViewer photo={photoPreview} onClose={() => setPhotoPreview(null)} />
      <PageHeader
        title={
          <span className="flex flex-wrap items-center gap-x-3 gap-y-2">
            <span className="min-w-0 break-words">{data.name}</span>
            <Chip variant={data.is_active ? 'success' : 'neutral'}>
              {data.is_active ? 'Active' : 'Inactive'}
            </Chip>
          </span>
        }
        subtitle={
          <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span className="font-mono text-secondary">{data.part_number}</span>
            <span>Unit of measure: {data.unit_of_measure}</span>
          </span>
        }
        crumbLabel={data.part_number}
        backTo={backTo}
        backLabel={backLabel}
        actions={
          canManage && (
            <Button
              size="sm"
              icon={<LuPencil size={14} />}
              onClick={() => navigate(`/crm/products/${data.id}/edit`)}
            >
              Edit product
            </Button>
          )
        }
      />
      <div className="min-w-0 w-full px-5 py-4">
        <div className="grid min-w-0 gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
          <div className="min-w-0 space-y-5">
            <Panel title="Product details">
              <div className="mb-4 flex items-start gap-4 border-b border-subtle pb-4">
                {data.image_url ? (
                  <ProductPhotoButton photo={{ url: data.image_url, name: data.name, partNumber: data.part_number }} className="h-24 w-24" onView={setPhotoPreview} />
                ) : (
                  <p role="status" className="rounded-md border border-warning/40 bg-warning-bg px-3 py-2 text-sm text-warning-fg">
                    No product photo uploaded. Customers see a placeholder in the order catalog.
                    {canManage && <> <Link to="/crm/products" className="underline">Open Products list to add one.</Link></>}
                  </p>
                )}
              </div>
              <dl className="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                <div className="min-w-0">
                  <dt className="text-xs text-muted">Part number</dt>
                  <dd className="mt-1 break-all font-mono">{data.part_number}</dd>
                </div>
                <div>
                  <dt className="text-xs text-muted">Unit of measure</dt>
                  <dd className="mt-1">{data.unit_of_measure}</dd>
                </div>
                <div className="sm:col-span-2">
                  <dt className="text-xs text-muted">Description</dt>
                  <dd className="mt-1 whitespace-pre-wrap break-words text-secondary">
                    {data.description || 'No description added.'}
                  </dd>
                </div>
              </dl>
            </Panel>
            <Panel
              title="Pricing"
              actions={
                <div className="flex flex-wrap gap-2">
                  {canManagePrices && !editingPrices && (
                    <Button size="sm" disabled={!data.is_active || !!proposal} onClick={() => {
                      setPrices({
                        base_selling_price: data.base_selling_price || '',
                        minimum_selling_price: data.minimum_selling_price || '',
                      });
                      setPriceError('');
                      setEditingPrices(true);
                    }}>
                      Edit selling prices
                    </Button>
                  )}
                  {canManage && canViewCosts && !proposal && !editingCost && (
                    <Button size="sm" disabled={!data.is_active} onClick={() => {
                      setStandardCost(data.standard_cost ?? '');
                      setNotes('');
                      setFinancialError('');
                      setEditingCost(true);
                    }}>
                      Propose standard cost change
                    </Button>
                  )}
                </div>
              }
            >
              <dl className="grid gap-4 text-sm sm:grid-cols-3">
                {[
                  ...(canViewCosts ? [costRow] : []),
                  { label: 'Base selling price', current: data.base_selling_price, proposed: undefined },
                  { label: 'Minimum selling price', current: data.minimum_selling_price, proposed: undefined },
                ].map((row) => (
                  <div key={row.label} className="min-w-0">
                    <dt className="text-xs text-muted">{row.label}</dt>
                    <dd
                      className={`mt-1 break-words ${row.current != null ? 'font-mono tabular-nums font-medium' : 'text-muted'}`}
                    >
                      {row.current != null ? formatPeso(row.current) : 'Not configured'}
                    </dd>
                  </div>
                ))}
              </dl>
              <div className="mt-4 space-y-1 border-t border-subtle pt-3 text-xs text-muted">
                <p>Prices exclude VAT.</p>
                <p>
                  Applicable customer agreements override the base price. A configured minimum
                  blocks lower selling prices.
                </p>
              </div>
              {data.base_selling_price == null && (
                <div role="status" className="mt-3 rounded-md border border-warning/40 bg-warning-bg px-3 py-2 text-sm text-warning-fg">
                  No base selling price. Customers need an active price agreement covering their
                  requested delivery date before they can order this product.
                  {can('crm.price_agreements.view') && (
                    <Link to={`/crm/price-agreements?product_id=${data.id}`} className="ml-1 underline">
                      Review pricing
                    </Link>
                  )}
                </div>
              )}
              {data.minimum_selling_price == null && (
                <div role="status" className="mt-3 rounded-md border border-warning/40 bg-warning-bg px-3 py-2 text-sm text-warning-fg">
                  No minimum selling price. Sales offers require this floor and a complete manufacturing cost.
                </div>
              )}
              {!data.is_active && (canManage || canManagePrices) && !proposal && (
                <p className="mt-3 text-xs text-muted">
                  Selling price and cost changes require an active product.
                </p>
              )}
              {editingPrices && (
                <form className="mt-4 space-y-4 border-t border-default pt-4" onSubmit={(event) => {
                  event.preventDefault();
                  sellingPrices.mutate();
                }}>
                  <h2 className="text-sm font-medium">Selling prices</h2>
                  <div className="grid gap-3 sm:grid-cols-2">
                    <Input
                      label="Base selling price"
                      type="number"
                      min="0.01"
                      step="0.01"
                      value={prices.base_selling_price}
                      disabled={sellingPrices.isPending}
                      onChange={(event) => setPrices({ ...prices, base_selling_price: event.target.value })}
                    />
                    <Input
                      label="Minimum selling price"
                      type="number"
                      min="0.01"
                      step="0.01"
                      value={prices.minimum_selling_price}
                      disabled={sellingPrices.isPending}
                      onChange={(event) => setPrices({ ...prices, minimum_selling_price: event.target.value })}
                    />
                  </div>
                  <p className="text-xs text-muted">Prices must cover the current unit cost. Customer agreements still require customer acceptance.</p>
                  {priceError && <p role="alert" className="text-sm text-danger-fg">{priceError}</p>}
                  <div className="flex flex-wrap gap-2">
                    <Button type="submit" variant="primary" disabled={sellingPrices.isPending} loading={sellingPrices.isPending}>Save prices</Button>
                    <Button type="button" disabled={sellingPrices.isPending} onClick={() => setEditingPrices(false)}>Cancel</Button>
                  </div>
                </form>
              )}
              {(proposal || editingCost) && (
                <section
                  aria-labelledby="financial-review-heading"
                  className="mt-4 border-t border-default pt-4"
                >
                  <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 id="financial-review-heading" className="text-sm font-medium">
                      Standard cost review
                    </h2>
                    {proposal && <Chip variant="warning">Awaiting Finance approval</Chip>}
                  </div>
                  {proposal ? (
                    <div className="space-y-4">
                      <p className="whitespace-pre-wrap break-words text-sm text-secondary">
                        {proposal.notes}
                      </p>
                      <div className="overflow-x-auto">
                        <table className={`${tableCls} min-w-[400px]`}>
                            <caption className="sr-only">
                            Current and proposed standard cost
                          </caption>
                          <thead>
                            <tr className={theadTrCls}>
                              <Th>Value</Th>
                              <Th align="right">Current</Th>
                              <Th align="right">Proposed</Th>
                            </tr>
                          </thead>
                          <tbody>
                            {[costRow].map((row) => (
                              <tr key={row.label} className={trCls}>
                                <Td>{row.label}</Td>
                                <Td align="right" mono={row.current != null}>
                                  {row.current != null ? formatPeso(row.current) : 'Not configured'}
                                </Td>
                                <Td align="right" mono={row.proposed != null}>
                                  {row.proposed != null
                                    ? formatPeso(row.proposed)
                                    : 'Not configured'}
                                </Td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                      {can('inventory.costs.approve') && (
                        <div className="space-y-3">
                          <Textarea
                            label="Standard cost decision reason"
                            rows={2}
                            value={notes}
                            maxLength={1000}
                            disabled={financial.isPending}
                            onChange={(event) => setNotes(event.target.value)}
                          />
                          <div className="flex flex-wrap gap-2">
                            <Button
                              variant="primary"
                              disabled={!notes.trim() || financial.isPending}
                              onClick={() => financial.mutate('approve')}
                            >
                              Approve standard cost change
                            </Button>
                            <Button
                              disabled={!notes.trim() || financial.isPending}
                              onClick={() => financial.mutate('reject')}
                            >
                              Reject standard cost change
                            </Button>
                          </div>
                        </div>
                      )}
                    </div>
                  ) : (
                    <form
                      className="space-y-4"
                      onSubmit={(event) => {
                        event.preventDefault();
                        if (!financial.isPending && notes.trim()) financial.mutate('propose');
                      }}
                    >
                      <p className="text-xs text-muted">
                        Current standard cost remains until a different Finance reviewer approves.
                      </p>
                      <div className="grid gap-3 sm:grid-cols-1 sm:max-w-xs">
                        <Input
                          label="Proposed standard cost"
                          type="number"
                          min="0"
                          step="0.01"
                          required
                          disabled={financial.isPending}
                          value={standardCost}
                          onChange={(event) => setStandardCost(event.target.value)}
                        />
                      </div>
                      <Textarea
                        label="Standard cost change reason"
                        value={notes}
                        rows={2}
                        maxLength={1000}
                        required
                        disabled={financial.isPending}
                        onChange={(event) => setNotes(event.target.value)}
                      />
                      <div className="flex flex-wrap gap-2">
                        <Button
                          type="submit"
                          variant="primary"
                          disabled={!notes.trim()}
                          loading={financial.isPending}
                        >
                          Submit standard cost for Finance review
                        </Button>
                        <Button
                          type="button"
                          disabled={financial.isPending}
                          onClick={() => {
                            setEditingCost(false);
                            setNotes('');
                            setFinancialError('');
                          }}
                        >
                          Cancel
                        </Button>
                      </div>
                    </form>
                  )}
                  {financialError && (
                    <p role="alert" className="mt-3 text-sm text-danger-fg">
                      {financialError}
                    </p>
                  )}
                </section>
              )}
            </Panel>
            <Panel
              title="Customer portal access"
              actions={canManageCustomerAccess && !editingAccess && (
                <Button size="sm" icon={<LuPencil size={14} />} onClick={() => {
                  setAccessVisibility(data.portal_visibility ?? 'restricted');
                  setAccessCustomerIds(customers.map((customer) => customer.id));
                  setCustomerSearch('');
                  setEditingAccess(true);
                }}>
                  Manage access
                </Button>
              )}
            >
              {editingAccess ? (
                <form className="space-y-4" onSubmit={(event) => { event.preventDefault(); accessMutation.mutate(); }}>
                  <Select label="Customer visibility" value={accessVisibility} onChange={(event) => setAccessVisibility(event.target.value as 'standard' | 'restricted')}>
                    <option value="restricted">Private — assigned customers only</option>
                    <option value="standard">Standard — all active customers</option>
                  </Select>
                  <p className="text-xs text-muted">Private products appear only for selected customers. Standard products appear for all active customers.</p>
                  <Input aria-label="Find customers to assign" value={customerSearch} onChange={(event) => setCustomerSearch(event.target.value)} placeholder="Search customers" />
                  {customerOptionsError && (
                    <p role="alert" className="text-sm text-danger-fg">Cannot load customers. <Button type="button" size="sm" onClick={() => retryCustomerOptions()}>Retry</Button></p>
                  )}
                  <div className="max-h-52 overflow-y-auto rounded-md border border-default divide-y divide-default">
                    {visibleCustomers.map((customer) => (
                      <label key={customer.id} className="flex min-h-11 cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-surface">
                        <input type="checkbox" checked={accessCustomerIds.includes(customer.id)} onChange={(event) => setAccessCustomerIds((current) => event.target.checked ? [...current, customer.id] : current.filter((customerId) => customerId !== customer.id))} />
                        {customer.name}
                      </label>
                    ))}
                    {!visibleCustomers.length && <p className="p-3 text-sm text-muted">No customers found.</p>}
                  </div>
                  {accessVisibility === 'restricted' && accessCustomerIds.length === 0 && (
                    <p role="status" className="rounded-md border border-warning/40 bg-warning-bg px-3 py-2 text-sm text-warning-fg">
                      No customers assigned. This private product will not appear in any customer portal.
                    </p>
                  )}
                  <div className="flex gap-2">
                    <Button type="submit" variant="primary" disabled={accessMutation.isPending} loading={accessMutation.isPending}>Save access</Button>
                    <Button type="button" disabled={accessMutation.isPending} onClick={() => setEditingAccess(false)}>Cancel</Button>
                  </div>
                </form>
              ) : <>
              <div className="flex flex-wrap items-center gap-2">
                <Chip variant={data.portal_visibility === 'standard' ? 'info' : 'neutral'}>
                  {data.portal_visibility === 'standard' ? 'Standard' : 'Private'}
                </Chip>
                <p className="text-sm text-secondary">
                  {data.portal_visibility === 'standard'
                    ? 'All active customers'
                    : 'Assigned customers only'}
                </p>
              </div>
              {data.portal_visibility !== 'standard' && customers.length === 0 && (
                <p role="status" className="mt-3 rounded-md border border-warning/40 bg-warning-bg px-3 py-2 text-sm text-warning-fg">
                  No customers assigned. This private product will not appear in any customer portal.
                </p>
              )}
              <div className="mt-4 border-t border-subtle pt-3">
                <h2 className="mb-2 text-xs text-muted">
                  Assigned customers <span className="font-mono">({customers.length})</span>
                </h2>
                {customers.length > 0 ? (
                  <ul className="grid gap-x-6 text-sm sm:grid-cols-2">
                    {customers.map((customer) => (
                      <li
                        key={customer.id}
                        className="break-words border-b border-subtle py-2 text-secondary"
                      >
                        {customer.name}
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="text-sm text-muted">None</p>
                )}
              </div>
              {data.portal_visibility !== 'standard' && customers.length === 0 && (
                <p role="status" className="mt-3 rounded-md border border-warning/40 bg-warning-bg px-3 py-2 text-sm text-warning-fg">
                  No customers assigned. This private product will not appear in any customer portal.
                </p>
              )}
              </>}
            </Panel>
          </div>
          <aside className="min-w-0 space-y-5" aria-label="Product setup and related records">
            <Panel title="Production setup" noPadding>
              <div className="divide-y divide-default">
                <div className="p-4">
                  <div className="mb-2 flex items-center gap-2">
                    <LuPackage size={16} className="shrink-0 text-muted" aria-hidden />
                    <h2 className="text-sm font-medium">Finished-goods inventory</h2>
                  </div>
                  <Chip variant={readiness?.ready ? 'success' : readiness ? 'warning' : 'neutral'}>
                    {readiness?.ready ? 'Ready' : readiness ? 'Needs attention' : 'Unavailable'}
                  </Chip>
                  <p className="mt-2 break-words text-xs text-muted">
                    {readiness?.ready
                      ? 'Ready for production receipts. Product and inventory units match.'
                      : (readiness?.reason ?? 'Mapping readiness unavailable.')}
                  </p>
                  {readiness?.item_id && can('inventory.view') && (
                    <Link
                      to={`/inventory/items/${readiness.item_id}`}
                      className={`${linkClass} mt-2 -mx-3`}
                    >
                      View {readiness.item_code}
                      <LuChevronRight size={14} className="shrink-0" aria-hidden />
                    </Link>
                  )}
                </div>
                <div className="p-4">
                  <div className="mb-2 flex items-center gap-2">
                    <LuLayers size={16} className="shrink-0 text-muted" aria-hidden />
                    <h2 className="text-sm font-medium">Bill of materials</h2>
                  </div>
                  <p className="text-xs text-muted">
                    {data.active_bom
                      ? `Active BOM version ${data.active_bom.version}.`
                      : 'No BOM configured.'}
                  </p>
                  {data.active_bom &&
                    (can('mrp.boms.view') ? (
                      <Link
                        to={`/mrp/boms/${data.active_bom.id}`}
                        className={`${linkClass} mt-2 -mx-3`}
                      >
                        View BOM
                        <LuChevronRight size={14} className="shrink-0" aria-hidden />
                      </Link>
                    ) : (
                      <p className="mt-2 text-xs text-muted">
                        BOM access requires planning permission.
                      </p>
                    ))}
                </div>
                <div className="p-4">
                  <div className="mb-2 flex items-center gap-2">
                    <LuShieldCheck size={16} className="shrink-0 text-muted" aria-hidden />
                    <h2 className="text-sm font-medium">Quality inspection</h2>
                  </div>
                  <p className="text-xs text-muted">
                    {data.inspection_spec
                      ? `Inspection spec version ${data.inspection_spec.version}.`
                      : 'No inspection spec configured.'}
                  </p>
                  {(can('quality.specs.view') || can('quality.specs.manage')) && (
                    <Link
                      to={`/quality/inspection-specs/${data.id}`}
                      className={`${linkClass} mt-2 -mx-3`}
                    >
                      {data.inspection_spec ? 'View inspection spec' : 'Create inspection spec'}
                      <LuChevronRight size={14} className="shrink-0" aria-hidden />
                    </Link>
                  )}
                </div>
                <div className="flex items-start justify-between gap-3 p-4 text-xs">
                  <span className="text-muted">Forecast in MRP</span>
                  <span>{data.include_forecast_in_mrp ? 'Included' : 'Excluded'}</span>
                </div>
              </div>
            </Panel>
            {relatedLinks.length > 0 && (
              <Panel title="Related records" bodyClassName="p-1">
                {relatedLinks.map((link) => (
                  <Link key={link.to} to={link.to} className={linkClass}>
                    {link.label}
                    <LuChevronRight size={14} className="shrink-0" aria-hidden />
                  </Link>
                ))}
              </Panel>
            )}
            <dl className="space-y-2 px-1 text-xs text-muted">
              <div className="flex flex-wrap justify-between gap-x-3">
                <dt>Created</dt>
                <dd>{formatDateTime(data.created_at)}</dd>
              </div>
              <div className="flex flex-wrap justify-between gap-x-3">
                <dt>Last updated</dt>
                <dd>{formatDateTime(data.updated_at)}</dd>
              </div>
            </dl>
          </aside>
        </div>
      </div>
    </div>
  );
}
