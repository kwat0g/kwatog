import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { SubmitPaymentDialog } from './SubmitPaymentDialog';
import { useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { LuFileDown } from '@/lib/icons';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { StatCard } from '@/components/ui/StatCard';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import { Chip } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { KpiGrid } from '@/components/dashboard/DashboardShell';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { invoiceStatusVariant } from '@/lib/statusVariants';

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
  const [showPayment, setShowPayment] = useState(false);

  const { data: invoice, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'invoice', id],
    queryFn: () => customerPortalApi.getInvoice(id!),
    enabled: !!id,
    refetchInterval: (query) => query.state.data && !['paid', 'cancelled'].includes(query.state.data.status) && document.visibilityState === 'visible' ? 30_000 : false,
  });

  const downloadPdf = async () => {
    try {
      const blob = await customerPortalApi.downloadInvoicePdf(id!);
      downloadBlob(blob, `${invoice?.invoice_number ?? 'Invoice'}.pdf`);
    } catch {
      toast.error('Failed to download PDF.');
    }
  };
  const isOpen = invoice?.status === 'finalized' || invoice?.status === 'partial';
  const pendingPayment = invoice?.payment_submissions?.some((payment) => payment.status === 'pending');

  return (
    <div>
      <PageHeader
        title={
          invoice ? (
            <>
              {invoice.invoice_number}{' '}
              <Chip variant={invoiceStatusVariant[pendingPayment ? 'awaiting payment verification' : (invoice.display_status ?? invoice.status)] ?? 'neutral'}>
                {isOpen ? pendingPayment ? 'Awaiting payment verification' : 'Awaiting payment' : invoice.status_label ?? invoice.status}
              </Chip>
            </>
          ) : (
            'Invoice'
          )
        }
        subtitle={invoice?.date ? formatDate(invoice.date) : undefined}
        backTo="/portal/customer/invoices"
        backLabel="Invoices"
        actions={invoice ? (
          <div className="flex flex-wrap gap-2">
          {invoice.can_submit_payment && <Button variant="primary" size="sm" onClick={() => setShowPayment(true)}>Submit payment</Button>}
          <Button variant="secondary" size="sm" icon={<LuFileDown size={14} />} onClick={downloadPdf}>
            Download PDF
          </Button>
          </div>
        ) : undefined}
      />

      <div className="px-5 py-4 space-y-4">
        {isLoading && <SkeletonDetail />}

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
            <KpiGrid count={3}>
              <StatCard label="Total Amount" value={formatPeso(invoice.total_amount)} />
              <StatCard label="Balance Due" value={formatPeso(invoice.balance)} />
              <StatCard label="Due Date" value={invoice.due_date ? formatDate(invoice.due_date) : '—'} />
            </KpiGrid>

            {invoice.sales_order?.payment_method && <p className="text-sm text-muted">
              Order {invoice.sales_order.so_number} payment method: <span className="font-medium text-primary">{{ cash: 'Cash', check: 'Check', bank_transfer: 'Bank transfer' }[invoice.sales_order.payment_method]}</span>.
            </p>}

            {isOpen && !pendingPayment && invoice.can_submit_payment === false && invoice.payment_submission_blocked_reason?.startsWith('Bank transfer was not selected') && (
              <p role="status" className="rounded-md border border-warning bg-warning-bg px-4 py-3 text-sm text-warning-fg">
                {invoice.payment_submission_blocked_reason}
              </p>
            )}

            {!!invoice.payment_submissions?.length && <Panel title="Payment submissions">
              <div className="space-y-4">
                {invoice.payment_submissions.map((payment) => <div key={payment.id} className="space-y-2 border-b border-default pb-4 last:border-0 last:pb-0">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span>{payment.sending_bank} · {payment.reference_number} · {formatPeso(payment.amount)}</span>
                    <Chip variant={payment.status === 'verified' ? 'success' : payment.status === 'rejected' ? 'danger' : 'warning'}>{payment.status === 'pending' ? 'Awaiting verification' : payment.status === 'verified' ? 'Payment verified' : 'Rejected'}</Chip>
                  </div>
                  <p className="text-xs text-muted">Payment date: {formatDate(payment.payment_date)}</p>
                  {payment.status === 'pending' && <p role="status" className="text-sm text-muted">Payment submitted. Finance will verify receipt of funds. Your invoice balance changes after verification.</p>}
                  {payment.rejection_reason && <p role="alert" className="text-sm text-danger-fg">{payment.rejection_reason}</p>}
                  <Button variant="secondary" size="sm" onClick={async () => { try { downloadBlob(await customerPortalApi.downloadInvoicePaymentProof(invoice.id, payment.id), payment.proof_file_name); } catch { toast.error('Payment proof download failed. Please retry.'); } }}>Download proof</Button>
                </div>)}
              </div>
            </Panel>}

            {invoice.items && invoice.items.length > 0 && (
              <Panel title="Items" meta={String(invoice.items.length)} noPadding>
                <div className="overflow-x-auto">
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
                      {invoice.items.map((item) => (
                        <tr key={item.id} className={trCls}>
                          <Td>{item.description}</Td>
                          <Td align="right" mono>{item.quantity}</Td>
                          <Td align="right" mono>{formatPeso(item.unit_price)}</Td>
                          <Td align="right" mono>{formatPeso(item.total)}</Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </Panel>
            )}

            {invoice.collections && invoice.collections.length > 0 && (
              <Panel title="Payments Made" meta={String(invoice.collections.length)} noPadding>
                <div className="overflow-x-auto">
                  <table className={tableCls}>
                    <thead>
                      <tr className={theadTrCls}>
                        <Th>Date</Th>
                        <Th>Method</Th>
                        <Th align="right">Amount</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {invoice.collections.map((p) => (
                        <tr key={p.id} className={trCls}>
                          <Td className="text-muted">{p.collection_date ? formatDate(p.collection_date) : '—'}</Td>
                          <Td className="capitalize">{p.payment_method_label ?? p.payment_method}</Td>
                          <Td align="right" mono>{formatPeso(p.amount)}</Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </Panel>
            )}
          </>
        )}
      </div>
      {showPayment && invoice && <SubmitPaymentDialog invoice={invoice} onClose={() => setShowPayment(false)} />}
    </div>
  );
}
