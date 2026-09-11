import { useQuery, useMutation } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { LuPlus, LuX, LuSend, LuSearch } from '@/lib/icons';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import { CompanyName } from '@/components/brand/CompanyName';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import type { PortalCatalogItem } from '@/types/b2b';

interface OrderLine {
  product: PortalCatalogItem;
  quantity: string;
  delivery_date: string;
}

const today = () => new Date().toISOString().slice(0, 10);

export default function CustomerPlaceOrderPage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [orderDate, setOrderDate] = useState(today());
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<OrderLine[]>([]);

  const { data: catalog, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'catalog', search],
    queryFn: () => customerPortalApi.listCatalog({ search: search || undefined }),
    placeholderData: (prev) => prev,
  });

  const lineTotal = (line: OrderLine) => {
    const qty = parseFloat(line.quantity) || 0;
    const price = parseFloat(line.product.unit_price) || 0;
    return qty * price;
  };
  const orderSubtotal = useMemo(
    () => lines.reduce((sum, line) => sum + lineTotal(line), 0),
    [lines],
  );

  const addLine = (product: PortalCatalogItem) => {
    if (lines.some((line) => line.product.id === product.id)) {
      toast('This product is already in your order.');
      return;
    }
    setLines([...lines, { product, quantity: '1', delivery_date: orderDate }]);
  };

  const removeLine = (idx: number) => setLines(lines.filter((_, i) => i !== idx));

  const updateLine = (idx: number, field: 'quantity' | 'delivery_date', value: string) => {
    const updated = [...lines];
    updated[idx] = { ...updated[idx], [field]: value };
    setLines(updated);
  };

  const invalidLines = lines.filter(
    (line) =>
      !(parseFloat(line.quantity) > 0) ||
      !line.delivery_date ||
      line.delivery_date < orderDate,
  );

  const createMut = useMutation({
    mutationFn: () =>
      customerPortalApi.createOrder({
        date: orderDate,
        notes: notes.trim() || undefined,
        items: lines.map((line) => ({
          product_id: line.product.id,
          quantity: line.quantity,
          delivery_date: line.delivery_date,
        })),
      }),
    onSuccess: (res) => {
      toast.success(res.message ?? 'Order submitted.');
      navigate(`/portal/customer/orders/${res.data.id}`);
    },
    onError: (e: Error & { response?: { data?: { message?: string } } }) =>
      toast.error(e.response?.data?.message ?? 'Failed to submit order.'),
  });

  const canSubmit =
    lines.length > 0 && invalidLines.length === 0 && !createMut.isPending;

  return (
    <div>
      <PageHeader
        title="Place an Order"
        subtitle={
          catalog
            ? <>Choose from the products available to your account and submit for review by <CompanyName />'s sales team.</>
            : <>Order products at your agreed prices and submit for review.</>
        }
        backTo="/portal/customer/orders"
        backLabel="Orders"
      />

      <div className="px-5 py-4 grid grid-cols-1 lg:grid-cols-3 gap-4 items-start">
        {/* ── Catalog ── */}
        <Panel
          title="Product catalog"
          meta={
            catalog
              ? `${catalog.length} available`
              : '…'
          }
          className="lg:col-span-2"
          noPadding
        >
          <div className="px-4 pt-3 pb-2 border-b border-default">
            <div className="relative max-w-sm">
              <LuSearch className="absolute left-2.5 top-1/2 -translate-y-1/2 size-4 text-muted" />
              <Input
                fieldSize="sm"
                className="pl-8"
                placeholder="Search part number or name…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
          </div>

          {isLoading && !catalog && <SkeletonTable columns={5} rows={6} />}

          {isError && (
            <EmptyState
              icon="alert-circle"
              title="Failed to load the catalog"
              action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
            />
          )}

          {catalog && (
            <div className="overflow-x-auto">
              <table className={tableCls}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>Part #</Th>
                    <Th>Description</Th>
                    <Th>UOM</Th>
                    <Th align="right">Unit Price</Th>
                    <Th align="right">Add</Th>
                  </tr>
                </thead>
                <tbody>
                  {catalog.map((product) => (
                    <tr key={product.id} className={trCls}>
                      <Td mono className="text-muted">{product.part_number}</Td>
                      <Td>{product.name}</Td>
                      <Td className="text-muted">{product.unit_of_measure ?? '—'}</Td>
                      <Td align="right" mono className="font-medium">
                        {formatPeso(product.unit_price)}
                      </Td>
                      <Td align="right">
                        <Button
                          variant="ghost"
                          size="sm"
                          iconOnly
                          icon={<LuPlus size={14} />}
                          aria-label={`Add ${product.name} to order`}
                          disabled={lines.some((line) => line.product.id === product.id)}
                          onClick={() => addLine(product)}
                        />
                      </Td>
                    </tr>
                  ))}
                  {catalog.length === 0 && (
                    <tr>
                      <Td colSpan={5} className="text-center text-muted py-8">
                        {search
                          ? 'No catalog products match your search.'
                          : 'No products are currently available for your account. Contact sales to set up a price agreement.'}
                      </Td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          )}
        </Panel>

        {/* ── Order summary ── */}
        <Panel title="Your order" noPadding>
          {lines.length === 0 ? (
            <EmptyState
              icon="shopping-cart"
              title="No items yet"
              description="Add products from the catalog to build your order."
            />
          ) : (
            <div className="divide-y divide-default">
              {lines.map((line, idx) => (
                <div key={line.product.id} className="p-3 space-y-2">
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="text-xs font-medium truncate">{line.product.name}</p>
                      <p className="text-2xs text-muted font-mono">{line.product.part_number}</p>
                    </div>
                    <div className="text-right shrink-0">
                      <p className="text-xs font-mono tabular-nums">{formatPeso(lineTotal(line))}</p>
                      <p className="text-2xs text-muted">@ {formatPeso(line.product.unit_price)}</p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <Input
                      fieldSize="sm"
                      type="number"
                      min={0.01}
                      step={0.01}
                      aria-label="Quantity"
                      className="font-mono tabular-nums"
                      containerClassName="w-24"
                      value={line.quantity}
                      onChange={(e) => updateLine(idx, 'quantity', e.target.value)}
                    />
                    <Input
                      fieldSize="sm"
                      type="date"
                      aria-label="Delivery date"
                      min={orderDate}
                      containerClassName="flex-1"
                      value={line.delivery_date}
                      onChange={(e) => updateLine(idx, 'delivery_date', e.target.value)}
                    />
                    <Button
                      variant="ghost"
                      size="sm"
                      iconOnly
                      icon={<LuX size={14} />}
                      aria-label={`Remove ${line.product.name}`}
                      onClick={() => removeLine(idx)}
                    />
                  </div>
                  {line.delivery_date && line.delivery_date < orderDate && (
                    <p className="text-2xs text-danger-fg">Delivery date must be on or after the order date.</p>
                  )}
                </div>
              ))}
            </div>
          )}

          <div className="p-3 border-t border-default space-y-3">
            <Input
              fieldSize="sm"
              label="Order date"
              type="date"
              value={orderDate}
              onChange={(e) => setOrderDate(e.target.value)}
            />
            <Textarea
              label="Notes"
              rows={2}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="Optional instructions for our sales team…"
            />
            <div className="flex items-center justify-between border-t border-default pt-3">
              <span className="text-2xs uppercase tracking-widest text-muted font-medium">Subtotal</span>
              <span className="text-sm font-mono tabular-nums font-semibold">{formatPeso(orderSubtotal)}</span>
            </div>
            <p className="text-2xs text-muted">
              Final amount includes applicable VAT and is confirmed by our sales team when the order is reviewed.
            </p>
            <Button
              type="button"
              variant="primary"
              className="w-full"
              icon={<LuSend size={14} />}
              disabled={!canSubmit}
              loading={createMut.isPending}
              onClick={() => createMut.mutate()}
            >
              Submit order for review
            </Button>
          </div>
        </Panel>
      </div>
    </div>
  );
}
