import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Pencil, Plus } from 'lucide-react';
import { crmCustomersApi } from '@/api/crm/customers';
import { salesOrdersApi } from '@/api/crm/salesOrders';
import { complaintsApi } from '@/api/crm/complaints';
import { priceAgreementsApi } from '@/api/crm/priceAgreements';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import type { SalesOrder, CustomerComplaint, PriceAgreement } from '@/types/crm';

type Tab = 'orders' | 'complaints' | 'prices';

export default function CrmCustomerDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { can } = usePermission();
  const [tab, setTab] = useState<Tab>('orders');

  const { data: customer, isLoading, isError, refetch } = useQuery({
    queryKey: ['crm', 'customers', 'detail', id],
    queryFn: () => crmCustomersApi.show(id),
    enabled: !!id,
  });

  const { data: ordersData } = useQuery({
    queryKey: ['crm', 'sales-orders', { customer_id: id }],
    queryFn: () => salesOrdersApi.list({ customer_id: id, per_page: 25 }),
    enabled: !!id && can('crm.sales_orders.view') && tab === 'orders',
  });

  const { data: complaintsData } = useQuery({
    queryKey: ['crm', 'complaints', { customer_id: id }],
    queryFn: () => complaintsApi.list({ customer_id: id, per_page: 25 }),
    enabled: !!id && can('crm.complaints.manage') && tab === 'complaints',
  });

  const { data: priceAgreements } = useQuery({
    queryKey: ['crm', 'price-agreements', { customer_id: id }],
    queryFn: () => priceAgreementsApi.forCustomer(id),
    enabled: !!id && can('crm.price_agreements.view') && tab === 'prices',
  });

  if (isLoading) return (
    <div>
      <PageHeader title="Customer" backTo="/crm/customers" backLabel="Customers"
        breadcrumbs={[{ label: 'CRM' }, { label: 'Customers', href: '/crm/customers' }, { label: 'Customer' }]} />
      <SkeletonDetail />
    </div>
  );

  if (isError || !customer) return (
    <div>
      <PageHeader title="Customer" backTo="/crm/customers" backLabel="Customers"
        breadcrumbs={[{ label: 'CRM' }, { label: 'Customers', href: '/crm/customers' }, { label: 'Customer' }]} />
      <EmptyState
        icon="alert-circle"
        title="Failed to load customer"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
      />
    </div>
  );

  const soColumns: Column<SalesOrder>[] = [
    {
      key: 'so_number', header: 'SO no',
      cell: (r) => (
        <Link to={`/crm/sales-orders/${r.id}`} className="font-mono text-accent hover:underline">
          {r.so_number}
        </Link>
      ),
    },
    { key: 'date', header: 'Date', align: 'right', cell: (r) => <NumCell>{formatDate(r.date)}</NumCell> },
    { key: 'item_count', header: 'Items', align: 'right', cell: (r) => <NumCell>{r.item_count}</NumCell> },
    {
      key: 'total_amount', header: 'Total', align: 'right',
      cell: (r) => <NumCell>{formatPeso(r.total_amount)}</NumCell>,
    },
    {
      key: 'status', header: 'Status',
      cell: (r) => <Chip variant={chipVariantForStatus(r.status)}>{r.status_label}</Chip>,
    },
  ];

  const complaintColumns: Column<CustomerComplaint>[] = [
    {
      key: 'complaint_number', header: 'No',
      cell: (r) => (
        <Link to={`/crm/complaints/${r.id}`} className="font-mono text-accent hover:underline">
          {r.complaint_number}
        </Link>
      ),
    },
    { key: 'severity', header: 'Severity', cell: (r) => <Chip variant={r.severity === 'critical' ? 'danger' : r.severity === 'high' ? 'warning' : 'neutral'}>{r.severity}</Chip> },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={r.status === 'closed' || r.status === 'resolved' ? 'success' : r.status === 'open' ? 'warning' : 'neutral'}>{r.status}</Chip> },
    { key: 'description', header: 'Description', cell: (r) => <span className="truncate max-w-xs block">{r.description}</span> },
    { key: 'received_date', header: 'Received', align: 'right', cell: (r) => <NumCell>{r.received_date ? formatDate(r.received_date) : '—'}</NumCell> },
  ];

  const paColumns: Column<PriceAgreement>[] = [
    {
      key: 'product', header: 'Product',
      cell: (r) => r.product
        ? <Link to={`/crm/products/${r.product.id}`} className="font-mono text-accent hover:underline">{r.product.part_number}</Link>
        : <span className="text-muted">—</span>,
    },
    { key: 'price', header: 'Price', align: 'right', cell: (r) => <NumCell>₱ {Number(r.price).toFixed(2)}</NumCell> },
    { key: 'effective_from', header: 'From', align: 'right', cell: (r) => <NumCell>{formatDate(r.effective_from)}</NumCell> },
    { key: 'effective_to', header: 'To', align: 'right', cell: (r) => <NumCell>{formatDate(r.effective_to)}</NumCell> },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={r.is_currently_active ? 'success' : 'neutral'}>{r.is_currently_active ? 'active' : 'expired'}</Chip> },
  ];

  return (
    <div>
      <PageHeader
        title={
          <div className="flex items-center gap-3">
            <span>{customer.name}</span>
            {customer.code && (
              <Chip variant="neutral" className="font-mono">{customer.code}</Chip>
            )}
            <Chip variant={customer.is_active ? 'success' : 'neutral'}>
              {customer.is_active ? 'active' : 'inactive'}
            </Chip>
          </div>
        }
        backTo="/crm/customers"
        backLabel="Customers"
        breadcrumbs={[
          { label: 'CRM' },
          { label: 'Customers', href: '/crm/customers' },
          { label: customer.name },
        ]}
        actions={
          <div className="flex gap-1.5">
            {can('crm.sales_orders.create') && (
              <Button
                variant="secondary"
                size="sm"
                icon={<Plus size={14} />}
                onClick={() => navigate(`/crm/sales-orders/create?customer_id=${customer.id}`)}
              >
                New SO
              </Button>
            )}
            {can('accounting.customers.manage') && (
              <Button
                variant="primary"
                size="sm"
                icon={<Pencil size={14} />}
                onClick={() => navigate(`/crm/customers/${customer.id}/edit`)}
              >
                Edit
              </Button>
            )}
          </div>
        }
      />

      <div className="px-5 py-4 grid gap-4 lg:grid-cols-3">
        <div className="lg:col-span-1 space-y-4">
          <Panel title="Contact">
            <dl className="text-sm space-y-2">
              <div>
                <dt className="text-muted text-xs">Contact person</dt>
                <dd>{customer.contact_person ?? <span className="text-muted">—</span>}</dd>
              </div>
              <div>
                <dt className="text-muted text-xs">Email</dt>
                <dd>
                  {customer.email
                    ? <a href={`mailto:${customer.email}`} className="text-accent hover:underline">{customer.email}</a>
                    : <span className="text-muted">—</span>}
                </dd>
              </div>
              <div>
                <dt className="text-muted text-xs">Phone</dt>
                <dd className="font-mono">{customer.phone ?? <span className="text-muted">—</span>}</dd>
              </div>
              <div>
                <dt className="text-muted text-xs">Address</dt>
                <dd className="whitespace-pre-wrap">{customer.address ?? <span className="text-muted">—</span>}</dd>
              </div>
              <div>
                <dt className="text-muted text-xs">Payment terms</dt>
                <dd className="font-mono tabular-nums">{customer.payment_terms_days} days</dd>
              </div>
              <div>
                <dt className="text-muted text-xs">Credit limit</dt>
                <dd className="font-mono tabular-nums">
                  {customer.credit_limit ? formatPeso(customer.credit_limit) : <span className="text-muted">—</span>}
                </dd>
              </div>
            </dl>
          </Panel>
        </div>

        <div className="lg:col-span-2 space-y-4">
          {/* Tab strip */}
          <div className="border-b border-default flex gap-0">
            {[
              { key: 'orders' as Tab, label: 'Sales Orders' },
              { key: 'complaints' as Tab, label: 'Complaints' },
              { key: 'prices' as Tab, label: 'Price Agreements' },
            ].map(({ key, label }) => (
              <button
                key={key}
                type="button"
                onClick={() => setTab(key)}
                className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
                  tab === key
                    ? 'border-accent text-primary'
                    : 'border-transparent text-secondary hover:text-primary'
                }`}
              >
                {label}
              </button>
            ))}
          </div>

          {/* Sales Orders tab */}
          {tab === 'orders' && (
            <div>
              {!can('crm.sales_orders.view') ? (
                <EmptyState icon="lock" title="No permission to view sales orders" />
              ) : !ordersData ? (
                <EmptyState icon="inbox" title="Loading…" />
              ) : ordersData.data.length === 0 ? (
                <EmptyState icon="inbox" title="No sales orders" description="No sales orders for this customer yet." />
              ) : (
                <DataTable
                  columns={soColumns}
                  data={ordersData.data}
                  meta={ordersData.meta}
                />
              )}
            </div>
          )}

          {/* Complaints tab */}
          {tab === 'complaints' && (
            <div>
              {!can('crm.complaints.manage') ? (
                <EmptyState icon="lock" title="No permission to view complaints" />
              ) : !complaintsData ? (
                <EmptyState icon="inbox" title="Loading…" />
              ) : complaintsData.data.length === 0 ? (
                <EmptyState icon="inbox" title="No complaints" description="No complaints from this customer yet." />
              ) : (
                <DataTable
                  columns={complaintColumns}
                  data={complaintsData.data}
                  meta={complaintsData.meta}
                  onRowClick={(row) => navigate(`/crm/complaints/${row.id}`)}
                />
              )}
            </div>
          )}

          {/* Price Agreements tab */}
          {tab === 'prices' && (
            <div>
              {!can('crm.price_agreements.view') ? (
                <EmptyState icon="lock" title="No permission to view price agreements" />
              ) : !priceAgreements ? (
                <EmptyState icon="inbox" title="Loading…" />
              ) : priceAgreements.length === 0 ? (
                <EmptyState icon="inbox" title="No price agreements" description="No price agreements configured for this customer." />
              ) : (
                <DataTable
                  columns={paColumns}
                  data={priceAgreements}
                />
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
