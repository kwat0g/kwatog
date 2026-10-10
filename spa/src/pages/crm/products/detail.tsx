import { Link, useParams, useNavigate } from 'react-router-dom';
import { useQuery, useMutation } from '@tanstack/react-query';
import { useState } from 'react';
import toast from 'react-hot-toast';
import { Input } from '@/components/ui/Input';
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
import { formatPeso } from '@/lib/formatNumber';
import { formatDateTime } from '@/lib/formatDate';
import { focusRingInset } from '@/lib/focus';

export default function ProductDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { can } = usePermission();
  const canManage = can('crm.products.manage');
  const backTo = can('crm.products.view') ? '/crm/products' : '/dashboard';
  const backLabel = can('crm.products.view') ? 'Products' : 'Dashboard';
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['crm', 'products', 'detail', id],
    queryFn: () => productsApi.show(id!),
    enabled: !!id,
  });
  const [editingPrices, setEditingPrices] = useState(false);
  const [prices, setPrices] = useState({
    standard_cost: '',
    base_selling_price: '',
    minimum_selling_price: '',
  });
  const [notes, setNotes] = useState('');
  const [financialError, setFinancialError] = useState('');
  const financial = useMutation({
    mutationFn: async (decision: 'propose' | 'approve' | 'reject') => {
      setFinancialError('');
      if (decision === 'propose')
        await productsApi.proposeFinancial(id!, {
          ...prices,
          base_selling_price: prices.base_selling_price || null,
          minimum_selling_price: prices.minimum_selling_price || null,
          notes,
        });
      else
        await productsApi.decideFinancial(id!, data!.financial_change_request!.id, decision, notes);
    },
    onSuccess: () => {
      void refetch();
      setEditingPrices(false);
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
  const priceRows = [
    {
      label: 'Standard cost',
      current: data.standard_cost,
      proposed: proposal?.values.standard_cost,
    },
    {
      label: 'Base selling price',
      current: data.base_selling_price,
      proposed: proposal?.values.base_selling_price,
    },
    {
      label: 'Minimum selling price',
      current: data.minimum_selling_price,
      proposed: proposal?.values.minimum_selling_price,
    },
  ];
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
                canManage &&
                !proposal &&
                !editingPrices && (
                  <Button
                    size="sm"
                    disabled={!data.is_active}
                    onClick={() => {
                      setPrices({
                        standard_cost: data.standard_cost,
                        base_selling_price: data.base_selling_price || '',
                        minimum_selling_price: data.minimum_selling_price || '',
                      });
                      setNotes('');
                      setFinancialError('');
                      setEditingPrices(true);
                    }}
                  >
                    Propose financial changes
                  </Button>
                )
              }
            >
              <dl className="grid gap-4 text-sm sm:grid-cols-3">
                {priceRows.map((row) => (
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
              {!data.is_active && canManage && !proposal && (
                <p className="mt-3 text-xs text-muted">
                  Financial changes require an active product.
                </p>
              )}
              {(proposal || editingPrices) && (
                <section
                  aria-labelledby="financial-review-heading"
                  className="mt-4 border-t border-default pt-4"
                >
                  <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 id="financial-review-heading" className="text-sm font-medium">
                      Financial change review
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
                            Current and proposed financial values
                          </caption>
                          <thead>
                            <tr className={theadTrCls}>
                              <Th>Value</Th>
                              <Th align="right">Current</Th>
                              <Th align="right">Proposed</Th>
                            </tr>
                          </thead>
                          <tbody>
                            {priceRows.map((row) => (
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
                            label="Financial decision reason"
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
                              Approve financial changes
                            </Button>
                            <Button
                              disabled={!notes.trim() || financial.isPending}
                              onClick={() => financial.mutate('reject')}
                            >
                              Reject financial changes
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
                        Current values remain until a different Finance reviewer approves.
                      </p>
                      <div className="grid gap-3 sm:grid-cols-3">
                        <Input
                          label="Proposed standard cost"
                          type="number"
                          min="0"
                          step="0.01"
                          required
                          disabled={financial.isPending}
                          value={prices.standard_cost}
                          onChange={(event) =>
                            setPrices({ ...prices, standard_cost: event.target.value })
                          }
                        />
                        <Input
                          label="Proposed base price"
                          type="number"
                          min="0.01"
                          step="0.01"
                          disabled={financial.isPending}
                          value={prices.base_selling_price}
                          onChange={(event) =>
                            setPrices({ ...prices, base_selling_price: event.target.value })
                          }
                        />
                        <Input
                          label="Proposed minimum price"
                          type="number"
                          min="0.01"
                          step="0.01"
                          disabled={financial.isPending}
                          value={prices.minimum_selling_price}
                          onChange={(event) =>
                            setPrices({ ...prices, minimum_selling_price: event.target.value })
                          }
                        />
                      </div>
                      <Textarea
                        label="Financial change reason"
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
                          Submit for Finance approval
                        </Button>
                        <Button
                          type="button"
                          disabled={financial.isPending}
                          onClick={() => {
                            setEditingPrices(false);
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
            <Panel title="Customer portal access">
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
