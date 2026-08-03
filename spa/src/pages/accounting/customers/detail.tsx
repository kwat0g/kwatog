import { cn } from '@/lib/cn';
import { useNavigate, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Pencil, Plus } from 'lucide-react';
import { customersApi } from '@/api/accounting/customers';
import { invoicesApi } from '@/api/accounting/invoices';
import { priceAgreementsApi } from '@/api/crm/priceAgreements';
import { salesOrdersApi } from '@/api/crm/salesOrders';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import type { Invoice } from '@/types/accounting';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function CustomerDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { can } = usePermission();

  const { data: customer, isLoading, isError, refetch } = useQuery({
    queryKey: ['accounting', 'customers', id],
    queryFn: () => customersApi.show(id),
    enabled: !!id });
  const { data: invoicesData } = useQuery({
    queryKey: ['accounting', 'invoices', { customer_id: id }],
    queryFn: () => invoicesApi.list({ customer_id: id, per_page: 50 }),
    enabled: !!id });
  // Sprint 6 audit §3.3: surface the customer's CRM context on this page so
  // accounting officers can see active price agreements + the SOs running
  // against them without bouncing to /crm.
  const { data: priceAgreements } = useQuery({
    queryKey: ['crm', 'price-agreements', { customer_id: id }],
    queryFn: () => priceAgreementsApi.forCustomer(id),
    enabled: !!id && can('crm.price_agreements.view') });
  const { data: salesOrdersData } = useQuery({
    queryKey: ['crm', 'sales-orders', { customer_id: id }],
    queryFn: () => salesOrdersApi.list({ customer_id: id, per_page: 25 }),
    enabled: !!id && can('crm.sales_orders.view') });

  if (isLoading || (!customer && !isError)) return <SkeletonDetail />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load customer" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
  if (!customer) return null;

  const invoiceColumns: Column<Invoice>[] = [
    { key: 'invoice_number', header: 'Invoice no',
      cell: (r) => <span className="font-mono">{r.invoice_number ?? 'DRAFT'}</span> },
    { key: 'date',     header: 'Date', cell: (r) => <NumCell>{formatDate(r.date)}</NumCell> },
    { key: 'due_date', header: 'Due',  cell: (r) => <NumCell>{formatDate(r.due_date)}</NumCell> },
    { key: 'total',    header: 'Total', align: 'right', cell: (r) => <NumCell>{formatPeso(r.total_amount)}</NumCell> },
    { key: 'balance',  header: 'Balance', align: 'right', cell: (r) => <NumCell className="font-medium">{formatPeso(r.balance)}</NumCell> },
    { key: 'status',   header: 'Status', cell: (r) => <Chip variant={chipVariantForStatus(r.display_status)}>{r.display_status}</Chip> },
  ];

  const overLimit = customer.credit_warning ?? false;
  const warningPct = customer.credit_warning_ratio == null ? null : Math.round(customer.credit_warning_ratio * 100);

  return (
    <div>
      <PageHeader
        title={
          <div className="flex items-center gap-3">
            <span>{customer.name}</span>
            <Chip variant={customer.is_active ? 'success' : 'neutral'}>{customer.is_active ? 'active' : 'inactive'}</Chip>
            {overLimit && <Chip variant="danger">credit warning</Chip>}
          </div>
        }
        backTo="/accounting/customers"
        backLabel="Customers"
        breadcrumbs={[
          { label: 'Accounting' },
          { label: 'Customers', href: '/accounting/customers' },
          { label: customer.name },
        ]}
        actions={
          <div className="flex gap-1.5">
            {can('accounting.invoices.create') && (
              <Button variant="secondary" size="sm" icon={<Plus size={14} />} onClick={() => navigate(`/accounting/invoices/create?customer_id=${customer.id}`)}>New invoice</Button>
            )}
            {can('accounting.customers.manage') && (
              <Button variant="primary" size="sm" icon={<Pencil size={14} />} onClick={() => navigate(`/accounting/customers/${customer.id}/edit`)}>Edit</Button>
            )}
          </div>
        }
      />

      <div className="px-5 py-4 grid grid-cols-3 gap-4">
        <StatCard label="Credit Limit" value={customer.credit_limit ? formatPeso(customer.credit_limit) : '—'} />
        <StatCard label="Credit Used" value={formatPeso(customer.credit_used ?? '0')} />
        <StatCard label="Available"
          value={customer.credit_available !== null ? formatPeso(customer.credit_available ?? '0') : '—'}
          delta={overLimit && warningPct !== null ? { value: `≥ ${warningPct}% used`, direction: 'down' } : undefined} />
      </div>

      <div className="px-5 grid grid-cols-3 gap-4">
        <Panel title="Contact" className="col-span-1">
          <dl className="text-xs space-y-2">
            <div><dt className="text-muted">Contact person</dt><dd>{customer.contact_person ?? '—'}</dd></div>
            <div><dt className="text-muted">Email</dt><dd>{customer.email ?? '—'}</dd></div>
            <div><dt className="text-muted">Phone</dt><dd className="font-mono">{customer.phone ?? '—'}</dd></div>
            <div><dt className="text-muted">TIN</dt><dd className="font-mono">{customer.tin ?? '—'}</dd></div>
            <div><dt className="text-muted">Address</dt><dd>{customer.address ?? '—'}</dd></div>
            <div><dt className="text-muted">Payment terms</dt><dd className="font-mono">{customer.payment_terms_days} days</dd></div>
          </dl>
        </Panel>
        <Panel title="Invoices" className="col-span-2">
          {invoicesData && invoicesData.data.length > 0
            ? <DataTable
            onRowClick={(r) => navigate(`/accounting/invoices/${r.id}`)} columns={invoiceColumns} data={invoicesData.data} meta={invoicesData.meta} />
            : <EmptyState icon="inbox" title="No invoices yet" />}
        </Panel>
      </div>

      {/* Sprint 6 audit §3.3: CRM context for the customer. Visible only
          when the user has the CRM permissions; falls back to nothing
          otherwise. */}
      {can('crm.price_agreements.view') && (
        <div className="px-5 mt-4">
          <Panel title="Price agreements" meta={`${priceAgreements?.length ?? 0} ${(priceAgreements?.length ?? 0) === 1 ? 'agreement' : 'agreements'}`} noPadding>
            {!priceAgreements || priceAgreements.length === 0 ? (
              <div className="px-3 py-3 text-sm text-muted">No price agreements configured for this customer.</div>
            ) : (
              <table className={tableCls}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>Product</Th>
                    <Th align="right">Price</Th>
                    <Th align="right">Effective from</Th>
                    <Th align="right">Effective to</Th>
                    <Th>Status</Th>
                  </tr>
                </thead>
                <tbody>
                  {priceAgreements.map((pa) => (
                    <tr key={pa.id} className={cn(trCls, "cursor-pointer")} onClick={() => navigate(`/crm/products/${pa.product?.id}`)}>
                      <Td>
                        {pa.product
                          ? <span className="font-mono">{pa.product.part_number}</span>
                          : <span className="text-muted">—</span>}
                        {pa.product && <span className="ml-2 text-muted">{pa.product.name}</span>}
                      </Td>
                      <Td align="right" mono>{formatPeso(pa.price)}</Td>
                      <Td align="right" mono>{pa.effective_from}</Td>
                      <Td align="right" mono>{pa.effective_to}</Td>
                      <Td>
                        <Chip variant={pa.is_currently_active ? 'success' : 'neutral'}>
                          {pa.is_currently_active ? 'active' : 'expired'}
                        </Chip>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Panel>
        </div>
      )}

      {can('crm.sales_orders.view') && (
        <div className="px-5 mt-4 mb-4">
          <Panel title="Sales orders" meta={`${salesOrdersData?.meta?.total ?? 0} total`} noPadding>
            {!salesOrdersData || salesOrdersData.data.length === 0 ? (
              <div className="px-3 py-3 text-sm text-muted">No sales orders for this customer.</div>
            ) : (
              <table className={tableCls}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>SO no</Th>
                    <Th align="right">Date</Th>
                    <Th align="right">Total</Th>
                    <Th>Status</Th>
                  </tr>
                </thead>
                <tbody>
                  {salesOrdersData.data.map((so) => (
                    <tr key={so.id} className={cn(trCls, "cursor-pointer")} onClick={() => navigate(`/crm/sales-orders/${so.id}`)}>
                      <Td>
                        {so.so_number}
                      </Td>
                      <Td align="right" mono>{so.date}</Td>
                      <Td align="right" mono>{formatPeso(so.total_amount)}</Td>
                      <Td>
                        <Chip variant={
                          so.status === 'delivered' || so.status === 'invoiced' ? 'success'
                          : so.status === 'cancelled' ? 'danger'
                          : so.status === 'in_production' || so.status === 'confirmed' ? 'info'
                          : so.status === 'partially_delivered' ? 'warning'
                          : 'neutral'
                        }>{so.status_label}</Chip>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Panel>
        </div>
      )}
    </div>
  );
}
