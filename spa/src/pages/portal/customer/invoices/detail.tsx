import { useQuery } from '@tanstack/react-query';
import { useParams, Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { ArrowLeft, FileDown } from 'lucide-react';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatPeso } from '@/lib/formatNumber';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';

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

export default function CustomerInvoiceDetailPage() {
  const { id } = useParams<{ id: string }>();

  const { data: invoice, isLoading } = useQuery({
    queryKey: ['portal', 'customer', 'invoice', id],
    queryFn: () => customerPortalApi.getInvoice(id!),
    enabled: !!id,
  });

  const downloadPdf = async () => {
    try {
      const blob = await customerPortalApi.downloadInvoicePdf(id!);
      downloadBlob(blob, `${invoice?.invoice_number ?? 'Invoice'}.pdf`);
    } catch {
      toast.error('Failed to download PDF.');
    }
  };

  if (isLoading) return <SkeletonBlock className="h-80 rounded-lg" />;
  if (!invoice) return <EmptyState icon="file-x" title="Invoice not found" />;

  return (
    <div className="space-y-4 max-w-4xl">
      <div className="flex items-center gap-3">
        <Link to="/portal/customer/invoices" className="text-muted hover:text-primary p-1 -ml-1">
          <ArrowLeft size={16} />
        </Link>
        <div>
          <h2 className="text-sm font-semibold">{invoice.invoice_number}</h2>
          <p className="text-2xs text-muted">{invoice.date ?? '—'}</p>
        </div>
        <Button variant="ghost" size="sm" icon={<FileDown size={14} />} onClick={downloadPdf} className="ml-auto">
          PDF
        </Button>
        <Chip variant={chipVariantForStatus(invoice.status)}>{invoice.status}</Chip>
      </div>

      <div className="grid grid-cols-3 gap-3">
        <Panel title="Total Amount" className="text-center">
          <p className="text-lg font-semibold font-mono tabular-nums">{formatPeso(invoice.total_amount)}</p>
        </Panel>
        <Panel title="Balance Due" className="text-center">
          <p className="text-lg font-semibold font-mono tabular-nums">{formatPeso(invoice.balance)}</p>
        </Panel>
        <Panel title="Due Date" className="text-center">
          <p className="text-lg font-semibold">{invoice.due_date ?? '—'}</p>
        </Panel>
      </div>

      {invoice.items && invoice.items.length > 0 && (
        <Panel title={`Items (${invoice.items.length})`}>
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b border-border text-muted">
                <th className="text-left py-2 px-3 font-medium">Description</th>
                <th className="text-right py-2 px-3 font-medium">Qty</th>
                <th className="text-right py-2 px-3 font-medium">Unit Price</th>
                <th className="text-right py-2 px-3 font-medium">Total</th>
              </tr>
            </thead>
            <tbody>
              {invoice.items.map((item, i) => (
                <tr key={i} className="border-b border-border/50">
                  <td className="py-2 px-3">{item.description}</td>
                  <td className="py-2 px-3 text-right font-mono tabular-nums">{item.quantity}</td>
                  <td className="py-2 px-3 text-right font-mono tabular-nums">{formatPeso(item.unit_price)}</td>
                  <td className="py-2 px-3 text-right font-mono tabular-nums">{formatPeso(item.total_price)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}

      {invoice.payments && invoice.payments.length > 0 && (
        <Panel title="Payments Made">
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b border-border text-muted">
                <th className="text-left py-2 px-3 font-medium">Date</th>
                <th className="text-left py-2 px-3 font-medium">Method</th>
                <th className="text-right py-2 px-3 font-medium">Amount</th>
              </tr>
            </thead>
            <tbody>
              {invoice.payments.map((p, i) => (
                <tr key={i} className="border-b border-border/50">
                  <td className="py-2 px-3 text-muted">{p.paid_at ?? '—'}</td>
                  <td className="py-2 px-3 capitalize">{p.payment_method}</td>
                  <td className="py-2 px-3 text-right font-mono tabular-nums">{formatPeso(p.amount)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}
    </div>
  );
}
