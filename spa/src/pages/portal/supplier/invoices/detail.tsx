import { PortalTable } from '@/components/portal/PortalTable';
import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { LuFileDown } from '@/lib/icons';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatPeso } from '@/lib/formatNumber';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

function downloadBlob(blob: Blob, filename: string) {
 const url = window.URL.createObjectURL(blob);
 const a = document.createElement('a');
 a.href = url;
 a.download = filename;
 document.body.appendChild(a);
 a.click();
 window.URL.revokeObjectURL(url);
 a.remove();
}

export default function SupplierInvoiceDetailPage() {
 const { id } = useParams<{ id: string }>();

 const { data: invoice, isLoading, isError, refetch } = useQuery({
 queryKey: ['portal', 'supplier', 'invoice', id],
 queryFn: () => supplierPortalApi.getInvoice(id!),
 enabled: !!id,
 });

 const downloadPdf = async () => {
 try {
 const blob = await supplierPortalApi.downloadInvoicePdf(id!);
 downloadBlob(blob, `${invoice?.bill_number ?? 'Invoice'}.pdf`);
 } catch {
 toast.error('Failed to download PDF.');
 }
 };

 return (
 <div>
 <PageHeader
 title={invoice?.bill_number ?? 'Invoice'}
 subtitle={invoice?.date ?? undefined}
 backTo="/portal/supplier/invoices"
 backLabel="Invoices"
 actions={invoice ? (
 <div className="flex items-center gap-2">
 <Button variant="ghost" size="sm" icon={<LuFileDown size={14} />} onClick={downloadPdf}>
 PDF
 </Button>
 <Chip variant={chipVariantForStatus(invoice.status)}>{invoice.status_label ?? invoice.status}</Chip>
 </div>
 ) : undefined}
 />

 {/* One padded body holds every state, so loading and loaded agree on width. */}
 <div className="px-5 py-4 space-y-4 max-w-4xl">
 {isLoading && <SkeletonBlock className="h-80 rounded-md" />}

 {isError && (
 <EmptyState
 icon="alert-circle"
 title="Failed to load invoice"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 )}

 {!isLoading && !isError && !invoice && (
 <EmptyState icon="file-x" title="Invoice not found" />
 )}

 {!isLoading && !isError && invoice && (
 <>
 <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
 <Panel title="Total Amount" bodyClassName="p-4 text-center">
 <p className="text-lg font-medium font-mono tabular-nums">{formatPeso(invoice.total_amount)}</p>
 </Panel>
 <Panel title="Balance" bodyClassName="p-4 text-center">
 <p className="text-lg font-medium font-mono tabular-nums">{formatPeso(invoice.balance)}</p>
 </Panel>
 <Panel title="Due Date" bodyClassName="p-4 text-center">
 <p className="text-lg font-medium">{invoice.due_date ?? '—'}</p>
 </Panel>
 </div>

 {invoice.items && invoice.items.length > 0 && (
 <Panel title={`Items (${invoice.items.length})`} noPadding>
 <PortalTable>
<table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>Description</Th>
 <Th align="right">Qty</Th>
 <Th align="right">Unit Price</Th>
 <Th align="right">Total</Th>
 </tr>
 </thead>
 <tbody>
 {invoice.items.map((item, i) => (
 <tr key={i} className={trCls}>
 <Td>{item.description}</Td>
 <Td align="right" mono>{item.quantity}</Td>
 <Td align="right" mono>{formatPeso(item.unit_price)}</Td>
 <Td align="right" mono>{formatPeso(item.total)}</Td>
 </tr>
 ))}
 </tbody>
 </table>
</PortalTable>
 </Panel>
 )}

 {invoice.payments && invoice.payments.length > 0 && (
 <Panel title="Payments" noPadding>
 <PortalTable>
<table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>Date</Th>
 <Th>Method</Th>
 <Th align="right">Amount</Th>
 </tr>
 </thead>
 <tbody>
 {invoice.payments.map((p, i) => (
 <tr key={i} className={trCls}>
 <Td className="text-muted">{p.payment_date ?? '—'}</Td>
 <Td className="capitalize">{p.payment_method_label ?? p.payment_method}</Td>
 <Td align="right" mono>{formatPeso(p.amount)}</Td>
 </tr>
 ))}
 </tbody>
 </table>
</PortalTable>
 </Panel>
 )}
 </>
 )}
 </div>
 </div>
 );
}
