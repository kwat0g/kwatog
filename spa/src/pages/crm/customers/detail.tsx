import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { LuPencil, LuPlus } from '@/lib/icons';
import { crmCustomersApi } from '@/api/crm/customers';
import { useRealtimeRecord } from '@/hooks/useRealtimeRecord';
import { salesOrdersApi } from '@/api/crm/salesOrders';
import { complaintsApi } from '@/api/crm/complaints';
import { priceAgreementsApi } from '@/api/crm/priceAgreements';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail, SkeletonTable } from '@/components/ui/Skeleton';
import { Tabs } from '@/components/ui/Tabs';
import { PageHeader } from '@/components/layout/PageHeader';
import { ShipToAddress } from '@/components/supply-chain/ShipToAddress';
import { CustomerProductAccessManager } from '@/components/crm/CustomerProductAccessManager';
import { CustomerWarrantyOptionsManager } from '@/components/crm/CustomerWarrantyOptionsManager';
import { AgreementDocumentManager } from '@/components/contracts/AgreementDocumentManager';
import { customerAgreementPreviewValues } from '@/components/contracts/customerAgreementPreview';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import type { SalesOrder, CustomerComplaint, PriceAgreement } from '@/types/crm';

type Tab = 'details' | 'orders' | 'complaints' | 'prices';

export default function CrmCustomerDetailPage() {
 const { id = '' } = useParams<{ id: string }>();
 useRealtimeRecord('customer', id);
 const navigate = useNavigate();
 const { can } = usePermission();
 const [tab, setTab] = useState<Tab>('details');
 const availableTabs: Array<{ key: Tab; label: string }> = [{ key: 'details', label: 'Details' }];
 if (can('crm.sales_orders.view')) availableTabs.push({ key: 'orders', label: 'Sales Orders' });
 if (can('crm.complaints.manage')) availableTabs.push({ key: 'complaints', label: 'Complaints' });
 if (can('crm.price_agreements.view')) availableTabs.push({ key: 'prices', label: 'Price agreements' });
 const activeTab = availableTabs.find((item) => item.key === tab)?.key ?? 'details';
 const hasFeatureTabs = availableTabs.length > 1;

 const { data: customer, isLoading, isError, refetch } = useQuery({
 queryKey: ['crm', 'customers', 'detail', id],
 queryFn: () => crmCustomersApi.show(id),
 enabled: !!id,
 });

 const { data: productAccess } = useQuery({
  queryKey: ['crm', 'customers', id, 'product-access'],
  queryFn: () => crmCustomersApi.productAccess(id),
  enabled: !!id,
 });

 const { data: warrantyOptions } = useQuery({
  queryKey: ['crm', 'customers', id, 'warranty-options'],
  queryFn: () => crmCustomersApi.warrantyOptions(id),
  enabled: !!id,
 });

 const { data: ordersData } = useQuery({
 queryKey: ['crm', 'sales-orders', { customer_id: id }],
 queryFn: () => salesOrdersApi.list({ customer_id: id, per_page: 25 }),
 enabled: !!id && activeTab === 'orders',
 });

 const { data: complaintsData } = useQuery({
 queryKey: ['crm', 'complaints', { customer_id: id }],
 queryFn: () => complaintsApi.list({ customer_id: id, per_page: 25 }),
 enabled: !!id && activeTab === 'complaints',
 });

 const { data: priceAgreements } = useQuery({
 queryKey: ['crm', 'price-agreements', { customer_id: id }],
 queryFn: () => priceAgreementsApi.forCustomer(id),
 enabled: !!id && activeTab === 'prices',
 });

 if (isLoading) return (
 <div>
 <PageHeader title="Customer" backTo="/crm/customers" backLabel="Customers"
 />
 <SkeletonDetail />
 </div>
 );

 if (isError || !customer) return (
 <div>
 <PageHeader title="Customer" backTo="/crm/customers" backLabel="Customers"
 />
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
 { key: 'total_amount', header: 'Total', align: 'right', cell: (r) => <NumCell>{formatPeso(r.total_amount)}</NumCell> },
 { key: 'status', header: 'Status', cell: (r) => <Chip variant={chipVariantForStatus(r.status)}>{r.status_label}</Chip> },
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
 { key: 'severity', header: 'Severity', cell: (r) => <Chip variant={r.severity === 'critical' ? 'danger' : r.severity === 'high' ? 'warning' : 'neutral'}>{r.severity_label ?? r.severity}</Chip> },
 { key: 'status', header: 'Status', cell: (r) => <Chip variant={r.status === 'closed' || r.status === 'resolved' ? 'success' : r.status === 'open' ? 'warning' : 'neutral'}>{r.status_label ?? r.status}</Chip> },
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
 { key: 'price', header: 'Price', align: 'right', cell: (r) => <NumCell>{formatPeso(r.price)}</NumCell> },
 { key: 'effective_from', header: 'From', align: 'right', cell: (r) => <NumCell>{formatDate(r.effective_from)}</NumCell> },
 { key: 'effective_to', header: 'To', align: 'right', cell: (r) => <NumCell>{formatDate(r.effective_to)}</NumCell> },
 { key: 'status', header: 'Status', cell: (r) => <Chip variant={r.is_currently_active ? 'success' : 'neutral'}>{r.is_currently_active ? 'active' : 'expired'}</Chip> },
 ];

 const latitude = customer.delivery_latitude == null ? null : Number(customer.delivery_latitude);
 const longitude = customer.delivery_longitude == null ? null : Number(customer.delivery_longitude);
 const hasDeliveryPin = latitude !== null && longitude !== null
  && Number.isFinite(latitude) && Number.isFinite(longitude)
  && latitude >= -90 && latitude <= 90 && longitude >= -180 && longitude <= 180;
 const deliveryPinUrl = hasDeliveryPin
  ? `https://www.openstreetmap.org/?mlat=${latitude}&mlon=${longitude}#map=17/${latitude}/${longitude}`
  : null;
 const authorizedProductCount = productAccess?.products.filter((product) => product.is_authorized).length;
 const warrantyChoiceCount = warrantyOptions?.length;

 return (
 <div>
 <PageHeader
 title={
 <div className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
 <span className="min-w-0 break-words">{customer.name}</span>
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
 actions={
 <div className="flex gap-1.5">
 {can('crm.sales_orders.create') && (
 <Button
 variant="secondary"
 size="sm"
 icon={<LuPlus size={14} />}
 onClick={() => navigate(`/crm/sales-orders/create?customer_id=${customer.id}`)}
 >
 New SO
 </Button>
 )}
 {can('accounting.customers.manage') && (
 <Button
 variant="primary"
 size="sm"
 icon={<LuPencil size={14} />}
 onClick={() => navigate(`/crm/customers/${customer.id}/edit`)}
 >
 Edit
 </Button>
 )}
 </div>
 }
 bottom={hasFeatureTabs ? <Tabs label="Customer sections" idPrefix="customer-sections" value={activeTab} onChange={setTab} items={availableTabs} className="border-b-0" /> : null}
 />

 <div className="space-y-5 px-5 py-5">
 {activeTab === 'details' ? <>
 <div id={hasFeatureTabs ? 'customer-sections-panel-' + activeTab : undefined} role={hasFeatureTabs ? 'tabpanel' : undefined} aria-labelledby={hasFeatureTabs ? 'customer-sections-tab-' + activeTab : undefined} className="space-y-6">
 <div className="grid gap-4 xl:grid-cols-3">
 <Panel title="Customer and billing" className="min-w-0">
 <dl className="grid gap-x-4 gap-y-3 text-sm sm:grid-cols-2">
 <div><dt className="text-muted text-xs">Contact person</dt><dd>{customer.contact_person ?? <span className="text-muted">—</span>}</dd></div>
 <div><dt className="text-muted text-xs">Contact role / title</dt><dd>{customer.contact_person_role ?? <span className="text-muted">—</span>}</dd></div>
 <div><dt className="text-muted text-xs">Email</dt><dd>{customer.email ? <a href={'mailto:' + customer.email} className="break-words text-accent hover:underline">{customer.email}</a> : <span className="text-muted">—</span>}</dd></div>
 <div><dt className="text-muted text-xs">Phone</dt><dd className="font-mono">{customer.phone ?? <span className="text-muted">—</span>}</dd></div>
 <div className="sm:col-span-2"><dt className="text-muted text-xs">Billing address</dt><dd className="break-words whitespace-pre-wrap">{customer.address ?? <span className="text-muted">—</span>}</dd></div>
 </dl>
 </Panel>

 <Panel title="Delivery" className="min-w-0">
 <ShipToAddress heading={customer.name} address={customer.delivery_address} contactPerson={customer.delivery_contact_person} contactPhone={customer.delivery_contact_phone} emptyHint="No delivery address on file. Deliveries fall back to the billing address above." />
 <div className="mt-4 border-t border-default pt-3 text-sm">
  <p className="text-xs text-muted">Required delivery map pin</p>
  {hasDeliveryPin && deliveryPinUrl ? (
   <div className="mt-1 space-y-1">
    <p className="font-mono tabular-nums">{latitude!.toFixed(7)}, {longitude!.toFixed(7)}</p>
    <a href={deliveryPinUrl} target="_blank" rel="noreferrer" className="inline-flex min-h-11 items-center text-accent underline underline-offset-2 hover:text-accent-hover xl:min-h-0">Open delivery pin in map</a>
   </div>
  ) : <p role="status" className="mt-1 text-muted">No map pin saved.</p>}
 </div>
 </Panel>

 <Panel title="Commercial terms" className="min-w-0">
 <div className="space-y-3 text-sm">
 <dl className="grid gap-x-4 gap-y-3 sm:grid-cols-2">
 <div><dt className="text-muted text-xs">Default payment terms</dt><dd className="font-mono tabular-nums">{customer.payment_terms_days} days <span className="font-sans text-muted">· order can override</span></dd></div>
 <div><dt className="text-muted text-xs">Allowed payment methods</dt><dd>{customer.allowed_payment_methods === null ? 'All supported methods' : customer.allowed_payment_methods.length ? customer.allowed_payment_methods.map((method) => ({ cash: 'Cash', check: 'Check', bank_transfer: 'Bank transfer' })[method]).join(', ') : 'None configured'}</dd></div>
 <div><dt className="text-muted text-xs">Credit limit (optional)</dt><dd className="font-mono tabular-nums">{customer.credit_limit && Number(customer.credit_limit) > 0 ? formatPeso(customer.credit_limit) : 'Not set'}{customer.credit_limit && Number(customer.credit_limit) > 0 && <span className="font-sans text-muted"> · reference</span>}</dd></div>
 <div><dt className="text-muted text-xs">Shipping paid by</dt><dd>{customer.shipping_payer ? ({ customer: 'Customer', company: 'Company' }[customer.shipping_payer]) : <span className="text-muted">—</span>}</dd></div>
 <div><dt className="text-muted text-xs">Agreement period</dt><dd>{customer.agreement_starts_on || customer.agreement_ends_on ? (customer.agreement_starts_on ? formatDate(customer.agreement_starts_on) : '—') + ' to ' + (customer.agreement_ends_on ? formatDate(customer.agreement_ends_on) : '—') : <span className="text-muted">—</span>}</dd></div>
 </dl>
 <details className="border-t border-default pt-3">
  <summary className="cursor-pointer text-sm font-medium text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">Fallback warranty</summary>
  <p className="mt-2 text-xs text-muted">Used when no product-specific choice applies.</p>
  <dl className="mt-3 grid gap-x-4 gap-y-3 text-sm sm:grid-cols-2">
   <div><dt className="text-muted text-xs">Duration</dt><dd>{customer.warranty_duration_days != null ? customer.warranty_duration_days + ' days' : <span className="text-muted">Not set</span>}</dd></div>
   <div><dt className="text-muted text-xs">Report window</dt><dd>{customer.warranty_report_window_days != null ? customer.warranty_report_window_days + ' days' : <span className="text-muted">Not set</span>}</dd></div>
   <div><dt className="text-muted text-xs">Starts from</dt><dd>{customer.warranty_start_basis ?? <span className="text-muted">—</span>}</dd></div>
   <div><dt className="text-muted text-xs">Covered issues</dt><dd>{customer.warranty_covered_issues?.length ? customer.warranty_covered_issues.map((issue) => ({ manufacturing_defect: 'Manufacturing defect', shipping_damage: 'Shipping damage', customer_caused_damage: 'Customer-caused damage' })[issue]).join(', ') : <span className="text-muted">—</span>}</dd></div>
   <div><dt className="text-muted text-xs">Remedies</dt><dd>{customer.warranty_remedies?.length ? customer.warranty_remedies.map((remedy) => ({ repair: 'Repair', replace: 'Replace', credit: 'Credit', refund: 'Refund' })[remedy]).join(', ') : <span className="text-muted">—</span>}</dd></div>
  </dl>
 </details>
 {customer.agreement_status === 'expired' && <p className="mt-4 rounded border border-warning p-3 text-xs text-warning-fg">Agreement expired. Review terms with the customer; this does not deactivate the customer.</p>}
 {customer.agreement_status === 'not_yet_effective' && <p className="mt-4 rounded border border-warning p-3 text-xs text-warning-fg">Agreement start date is in the future. Review before applying these terms.</p>}
 </div>
 </Panel>
 </div>

 <details className="rounded-md border border-default bg-surface px-4 py-3">
  <summary className="flex cursor-pointer flex-wrap items-center justify-between gap-x-4 gap-y-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
   <span className="font-semibold text-primary">Product ordering setup</span>
   <span className="text-xs text-muted">{authorizedProductCount == null ? 'Manage access and warranties' : `${authorizedProductCount} allowed ${authorizedProductCount === 1 ? 'product' : 'products'} · ${warrantyChoiceCount ?? 0} ${warrantyChoiceCount === 1 ? 'warranty choice' : 'warranty choices'}`}</span>
  </summary>
  <section aria-labelledby="customer-ordering-setup-heading" className="mt-4 space-y-4 border-t border-default pt-4">
   <h2 id="customer-ordering-setup-heading" className="sr-only">Product ordering setup</h2>
   <CustomerProductAccessManager customerId={customer.id} />
   <CustomerWarrantyOptionsManager customerId={customer.id} />
  </section>
 </details>

 <details className="rounded-md border border-default bg-surface px-4 py-3">
  <summary className="flex cursor-pointer flex-wrap items-center justify-between gap-x-4 gap-y-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
   <span className="font-semibold text-primary">Agreement documents</span>
   <span className="text-xs text-muted">Attach a PDF or prepare a template draft</span>
  </summary>
  <div className="mt-4 border-t border-default pt-4">
   <AgreementDocumentManager
    realm="customer"
    entityId={customer.id}
    entityName={customer.name}
    previewValues={customerAgreementPreviewValues(customer, productAccess, warrantyOptions)}
   />
  </div>
 </details>

 </div>
 </> : activeTab === 'orders' ? (
 <div id="customer-sections-panel-orders" role="tabpanel" aria-labelledby="customer-sections-tab-orders"><Panel title="Sales orders" bodyClassName="p-0">
 {!ordersData ? <SkeletonTable columns={5} rows={5} /> : ordersData.data.length === 0 ? <EmptyState icon="inbox" title="No sales orders" description="No sales orders for this customer yet." /> : <DataTable onRowClick={(row) => navigate('/crm/sales-orders/' + row.id)} columns={soColumns} data={ordersData.data} meta={ordersData.meta} />}
 </Panel></div>
 ) : activeTab === 'complaints' ? (
 <div id="customer-sections-panel-complaints" role="tabpanel" aria-labelledby="customer-sections-tab-complaints"><Panel title="Complaints" bodyClassName="p-0">
 {!complaintsData ? <SkeletonTable columns={5} rows={5} /> : complaintsData.data.length === 0 ? <EmptyState icon="inbox" title="No complaints" description="No complaints for this customer yet." /> : <DataTable onRowClick={(row) => navigate('/crm/complaints/' + row.id)} columns={complaintColumns} data={complaintsData.data} meta={complaintsData.meta} />}
 </Panel></div>
 ) : (
 <div id="customer-sections-panel-prices" role="tabpanel" aria-labelledby="customer-sections-tab-prices"><Panel title="Price agreements" bodyClassName="p-0">
 {!priceAgreements ? <SkeletonTable columns={5} rows={5} /> : priceAgreements.length === 0 ? <EmptyState icon="inbox" title="No price agreements" description="No price agreements configured for this customer." /> : <DataTable onRowClick={(row) => navigate('/crm/price-agreements/' + row.id + '/edit')} columns={paColumns} data={priceAgreements} />}
 </Panel></div>
 )}
 </div> </div>
 );
}
