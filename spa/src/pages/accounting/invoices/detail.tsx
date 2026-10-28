import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { onFormInvalid } from '@/lib/formErrors';
import { LuPrinter, LuCoins, LuBan, LuCircleCheck } from '@/lib/icons';
import { invoicesApi } from '@/api/accounting/invoices';
import { accountingOptionsApi } from '@/api/accounting/options';
import { downloadAuthenticatedFile } from '@/api/download';
import { accountsApi } from '@/api/accounting/accounts';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { Select } from '@/components/ui/Select';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { ChainHeader } from '@/components/chain';
import { ActivityStream } from '@/components/chain/ActivityStream';
import { buildO2cChain } from '@/lib/chains';
import { invoiceStatusVariant } from '@/lib/statusVariants';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useAuthStore } from '@/stores/authStore';
import { useChainProgress } from '@/hooks/useChainProgress';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate, formatDateTime, localIsoDate } from '@/lib/formatDate';
import { numberInputProps } from '@/lib/numberInput';
import { Td, Th, tableCls, theadTrCls, totalsTrCls, trCls } from '@/components/ui/table-cells';
import type { PaymentMethod } from '@/types/accounting';
import { agingBucketLabel } from '@/lib/labels';
import { ReviewPaymentDialog } from './ReviewPaymentDialog';

const collectionSchema = z.object({
  cash_account_id: z.string().min(1, 'Required'),
  collection_date: z.string().min(1, 'Required'),
  amount: z.string().regex(/^\d{1,13}(?:\.\d{1,2})?$/, 'Use a positive amount with no more than two decimal places.').refine((value) => /[1-9]/.test(value), 'Amount must be positive.'),
  payment_method: z.string().min(1, 'Required'),
  reference_number: z.string().max(50).optional().or(z.literal('')),
  manual_reason: z.string().trim().min(10, 'Explain the exception in at least 10 characters.').max(500),
});
type CollectionFormValues = z.infer<typeof collectionSchema>;

export default function InvoiceDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can, canApprove } = usePermission();
  const user = useAuthStore((state) => state.user);
  const [showCollect, setShowCollect] = useState(false);
  const [showPaymentReview, setShowPaymentReview] = useState(false);
  const [showFinalize, setShowFinalize] = useState(false);
  const [showPrebillApproval, setShowPrebillApproval] = useState(false);
  const [showCancelConfirm, setShowCancelConfirm] = useState(false);

  const {
    data: invoice,
    isLoading,
    isError,
    refetch,
  } = useQuery({
    queryKey: ['accounting', 'invoices', id],
    queryFn: () => invoicesApi.show(id),
    enabled: !!id,
    refetchInterval: (query) => query.state.data && !['paid', 'cancelled'].includes(query.state.data.status) && document.visibilityState === 'visible' ? 30_000 : false,
  });

  // 2026-08-08 — live chain progress (finalize / collections) on the invoice page.
  useChainProgress('invoice', id, ['accounting', 'invoices', id]);
  const { data: accountingOptions, isError: paymentOptionsFailed, isFetching: paymentOptionsPending, refetch: refetchPaymentOptions } = useQuery({
    queryKey: ['accounting', 'options'],
    queryFn: () => accountingOptionsApi.list(),
  });

  const { data: cashAccounts, isError: cashAccountsFailed, isFetching: cashAccountsPending, refetch: refetchCashAccounts } = useQuery({
    queryKey: ['accounting', 'accounts', 'cash'],
    queryFn: () => accountsApi.list({ per_page: 50, type: 'asset' }),
    enabled: showCollect,
  });

  const {
    register,
    handleSubmit,
    watch,
    formState: { errors },
    reset,
  } = useForm<CollectionFormValues>({
    resolver: zodResolver(collectionSchema),
    mode: 'onChange',
    defaultValues: { collection_date: localIsoDate(), payment_method: '' },
  });

  const finalizeMut = useMutation({
    mutationFn: () => invoicesApi.finalize(id),
    onSuccess: (inv) => {
      toast.success(`Invoice ${inv.invoice_number} finalized.`);
      qc.invalidateQueries({ queryKey: ['accounting', 'invoices'] });
    },
    onError: (e: Error & { response?: { data?: { message?: string } } }) =>
      toast.error(e.response?.data?.message ?? 'Failed to finalize.'),
  });
  const approvePrebillMut = useMutation({
    mutationFn: () => invoicesApi.approvePrebill(id),
    onSuccess: () => {
      toast.success('Prebill invoice approved.');
      qc.invalidateQueries({ queryKey: ['accounting', 'invoices'] });
    },
    onError: (e: Error & { response?: { data?: { message?: string } } }) =>
      toast.error(e.response?.data?.message ?? 'Failed to approve prebill invoice.'),
  });
  const cancelMut = useMutation({
    mutationFn: () => invoicesApi.cancel(id),
    onSuccess: () => {
      toast.success('Invoice cancelled.');
      qc.invalidateQueries({ queryKey: ['accounting', 'invoices'] });
    },
    onError: (e: Error & { response?: { data?: { message?: string } } }) =>
      toast.error(e.response?.data?.message ?? 'Failed to cancel.'),
  });
  const collectMut = useMutation({
    mutationFn: (d: CollectionFormValues) =>
      invoicesApi.recordCollection(id, {
        cash_account_id: d.cash_account_id,
        collection_date: d.collection_date,
        amount: String(d.amount),
        payment_method: d.payment_method as PaymentMethod,
        reference_number: d.reference_number || undefined,
        manual_reason: d.manual_reason,
        idempotency_key: `collection:${id}:${invoice?.amount_paid ?? ''}:${invoice?.balance ?? ''}`,
      }),
    onSuccess: () => {
      toast.success('Collection recorded.');
      qc.invalidateQueries({ queryKey: ['accounting', 'invoices'] });
      setShowCollect(false);
      reset({
        collection_date: localIsoDate(),
        payment_method: '',
        cash_account_id: '',
        amount: '',
        reference_number: '',
        manual_reason: '',
      });
    },
    onError: (e: Error & { response?: { data?: { message?: string } } }) =>
      toast.error(e.response?.data?.message ?? 'Failed to record collection.'),
  });

  if (isLoading || (!invoice && !isError)) return <SkeletonDetail />;
  if (isError)
    return (
      <EmptyState
        icon="alert-circle"
        title="Failed to load invoice"
        action={
          <Button variant="secondary" onClick={() => refetch()}>
            Retry
          </Button>
        }
      />
    );
  if (!invoice) return null;

  const isDraft = invoice.status === 'draft';
  const isOpen = invoice.status === 'finalized' || invoice.status === 'partial';
  const enteredAmount = watch('amount') ?? '';
  const amountCents = (value: string) => { const [whole, fraction = ''] = value.split('.'); return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0')); };
  const amountExceedsBalance = /^\d{1,13}(?:\.\d{1,2})?$/.test(enteredAmount) && amountCents(enteredAmount) > amountCents(invoice.balance);
  const pendingPayment = invoice.payment_submissions?.find((payment) => payment.status === 'pending');
  const isPrebill = invoice.lifecycle_type === 'prebill';
  const standardDeliveryGateMessage = !invoice.sales_order
    ? 'This standard invoice has no linked sales order. Cancel this draft and create the invoice from a confirmed sales-order delivery.'
    : !invoice.delivery
      ? 'No delivery is linked. Cancel this draft and create a standard invoice from a confirmed delivery. Manual billing requires an approved prebill.'
      : invoice.delivery.status === 'return_pending'
        ? `Delivery ${invoice.delivery.delivery_number} has an unresolved truck return. Resolve the return and confirm delivery before finalizing.`
        : invoice.delivery.status === 'returned'
          ? `Delivery ${invoice.delivery.delivery_number} was returned to the depot. Cancel this draft, complete a redelivery, and invoice its confirmed delivery.`
          : invoice.delivery.status === 'cancelled'
            ? `Delivery ${invoice.delivery.delivery_number} was cancelled. Cancel this draft and use a new confirmed delivery for invoicing.`
            : invoice.delivery.status !== 'confirmed'
              ? invoice.delivery.status
                ? `Delivery ${invoice.delivery.delivery_number} is ${invoice.delivery.status.replaceAll('_', ' ')}. Complete and confirm delivery before finalizing this invoice.`
                : `Delivery ${invoice.delivery.delivery_number} status is unavailable. Reload before finalizing this invoice.`
              : null;
  const standardDeliveryBlocked = isDraft && !isPrebill && standardDeliveryGateMessage !== null;
  const prebillPending = isPrebill && isDraft && !invoice.prebill_approved_by;
  const isMaker = invoice.created_by?.id === user?.id;
  const isPrebillApprover = invoice.prebill_approved_by?.id === user?.id;
  const canApprovePrebill = prebillPending && !isMaker && canApprove('accounting.invoices.prebill_approve');
  const canEditPrebill = isDraft && isPrebill && can('accounting.invoices.update');
  const canFinalize = isDraft
    && can('accounting.invoices.create')
    && !isMaker
    && (isPrebill
      ? (!!invoice.prebill_approved_by && !isPrebillApprover)
      : !standardDeliveryBlocked);
  const activityItems = (invoice.status_history ?? []).map((event) => ({
    dot: event.to_status === 'cancelled'
      ? ('danger' as const)
      : event.event_type === 'created' || event.event_type === 'prebill_approved'
        ? ('success' as const)
        : ('info' as const),
    text: (
      <div className="space-y-0.5">
        <div>
          {event.event_type === 'created'
            ? 'Invoice draft created.'
            : event.event_type === 'prebill_approved'
              ? 'Prebill approved.'
              : event.event_type === 'status_changed'
                ? event.from_status && event.from_status !== event.to_status
                  ? `Status changed from ${event.from_status.replaceAll('_', ' ')} to ${event.to_status?.replaceAll('_', ' ') ?? 'unknown'}.`
                  : `Status set to ${event.to_status?.replaceAll('_', ' ') ?? 'unknown'}.`
                : 'Invoice updated.'}
        </div>
        <div className="text-2xs text-muted">
          By {event.actor_name ?? (event.actor_type === 'system' ? 'System' : 'Actor not recorded')}
        </div>
      </div>
    ),
    time: formatDateTime(event.occurred_at, 'Date not recorded'),
  }));
  // Header accounts (e.g. 1000 Assets) share the 10xx prefix but cannot take a posting.
  const cashParentIds = new Set((cashAccounts?.data ?? []).map((a) => a.parent_id).filter(Boolean));
  const cashAccts = (cashAccounts?.data ?? []).filter(
    (a) => a.code.startsWith('10') && a.is_active && !cashParentIds.has(a.id),
  );

  return (
    <div>
      <PageHeader
        title={
          <div className="flex items-center gap-3">
            <span className="font-mono">{invoice.invoice_number ?? 'DRAFT'}</span>
            <Chip variant={invoiceStatusVariant[pendingPayment ? 'awaiting payment verification' : invoice.display_status] ?? 'neutral'}>
              {isOpen ? pendingPayment ? 'Awaiting payment verification' : 'Awaiting customer payment' : invoice.display_status}
            </Chip>
            {invoice.is_overdue && <Chip variant="danger">overdue</Chip>}
          </div>
        }
        backTo="/accounting/invoices"
        backLabel="Invoices"
        actions={
          <div className="flex gap-1.5">
            <Button
              variant="secondary"
              size="sm"
              icon={<LuPrinter size={14} />}
              onClick={() =>
                void downloadAuthenticatedFile(invoicesApi.pdfUrl(invoice.id), {
                  openInNewTab: true,
                  errorMessage: 'Failed to generate invoice PDF.',
                })
              }
            >
              Print
            </Button>
            {canEditPrebill && (
              <Button
                variant="secondary"
                size="sm"
                onClick={() => navigate(`/accounting/invoices/${invoice.id}/edit`)}
              >
                Edit draft
              </Button>
            )}
            {canApprovePrebill && (
              <Button
                variant="primary"
                size="sm"
                icon={<LuCircleCheck size={14} />}
                onClick={() => setShowPrebillApproval(true)}
                disabled={approvePrebillMut.isPending}
              >
                Approve prebill
              </Button>
            )}
            {canFinalize && (
              <Button
                variant="primary"
                size="sm"
                icon={<LuCircleCheck size={14} />}
                onClick={() => setShowFinalize(true)}
                disabled={finalizeMut.isPending}
              >
                Finalize
              </Button>
            )}
            {pendingPayment && can('accounting.invoices.collect') && (
              <Button
                variant="primary"
                size="sm"
                icon={<LuCoins size={14} />}
                onClick={() => setShowPaymentReview(true)}
              >
                Review payment
              </Button>
            )}
            {isDraft &&
              can('accounting.invoices.update') && (
                <Button
                  variant="danger"
                  size="sm"
                  icon={<LuBan size={14} />}
                  onClick={() => setShowCancelConfirm(true)}
                >
                  Cancel
                </Button>
              )}
          </div>
        }
      />

      <div className="px-5 pt-4">
        {isOpen && <p role="status" className="text-sm text-muted mb-3">{pendingPayment ? 'Customer payment submitted. Verify the receiving bank record before recording funds.' : 'Awaiting customer payment submission.'}</p>}
        {isOpen && !pendingPayment && can('accounting.invoices.collect_manual') && <details className="mb-3 text-sm"><summary className="cursor-pointer text-muted">Exceptional collection</summary><Button variant="secondary" size="sm" onClick={() => setShowCollect(true)}>Record exceptional collection</Button></details>}
        {!!invoice.payment_submissions?.length && <Panel title="Payment submissions">
          {invoice.payment_submissions.map((payment) => <div key={payment.id} className="py-3 border-b border-default last:border-0">
            <div className="flex flex-wrap justify-between gap-2"><span>{payment.sending_bank} · {payment.reference_number} · {formatPeso(payment.amount)}</span><Chip variant={payment.status === 'verified' ? 'success' : payment.status === 'rejected' ? 'danger' : 'warning'}>{payment.status === 'pending' ? 'Awaiting verification' : payment.status}</Chip></div>
            {payment.rejection_reason && <p className="text-sm text-danger-fg">{payment.rejection_reason}</p>}
            <Button variant="secondary" size="sm" onClick={() => downloadAuthenticatedFile(`/invoices/${invoice.id}/payment-submissions/${payment.id}/proof`, { filename: payment.proof_file_name })}>Download proof</Button>
          </div>)}
        </Panel>}
        {standardDeliveryBlocked && (
          <p role="status" className="text-sm text-warning-fg mb-3">
            {standardDeliveryGateMessage}
          </p>
        )}
        {isPrebill && (
          <Panel title="Prebill approval">
            {invoice.status === 'cancelled' ? (
              <p className="text-sm text-muted">
                {invoice.prebill_approved_by ? (
                  <>
                    Cancelled after prebill approval. Previous approval by {invoice.prebill_approved_by.name} on{' '}
                    {formatDateTime(invoice.prebill_approved_at)}. This invoice cannot be posted.
                  </>
                ) : (
                  'Cancelled before prebill approval.'
                )}
              </p>
            ) : prebillPending ? (
              <p role="status" className="text-sm text-muted">
                Awaiting prebill approval. A different authorized user must approve this draft before another user can finalize it.
                {isMaker && ' You created this invoice, so you cannot approve or finalize it.'}
              </p>
            ) : invoice.prebill_approved_by ? (
              <p className="text-sm text-muted">
                Approved by {invoice.prebill_approved_by?.name ?? '—'} on {formatDateTime(invoice.prebill_approved_at)}.
                {isPrebillApprover && ' Another authorized user must finalize this invoice.'}
              </p>
            ) : (
              <p className="text-sm text-muted">Awaiting prebill approval.</p>
            )}
          </Panel>
        )}
      </div>

      <div className="px-5 pt-4">
        <Panel title="Order-to-Cash">
          {/* 2026-08-08 — compact cross-document stepper: the whole chain at a glance.
 This invoice is the Invoice step; upstream (SO/Delivery) and downstream
 (Payment) stay visible and clickable from here. */}
          <ChainHeader
            steps={buildO2cChain({
              so: invoice.sales_order
                ? { id: invoice.sales_order.id, number: invoice.sales_order.so_number }
                : null,
              delivery: invoice.delivery
                ? { id: invoice.delivery.id, number: invoice.delivery.delivery_number }
                : null,
              paymentStage: invoice.status === 'draft' ? 'awaiting_invoice' : pendingPayment ? 'pending' : invoice.payment_submissions?.[0]?.status ?? 'awaiting_submission',
              invoices: [
                {
                  id: invoice.id,
                  invoice_number: invoice.invoice_number ?? '',
                  status: invoice.status,
                },
              ],
            })}
          />
        </Panel>
      </div>

      <div className="px-5 pt-4">
        <Panel title="Activity">
          {activityItems.length > 0 ? (
            <ActivityStream items={activityItems} />
          ) : (
            <p className="text-xs text-muted">No status changes have been recorded.</p>
          )}
        </Panel>
      </div>

      <div className="px-5 py-4 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <StatCard label="Total" value={formatPeso(invoice.total_amount)} />
        <StatCard label="Collected" value={formatPeso(invoice.amount_paid)} />
        <StatCard
          label="Balance"
          value={formatPeso(invoice.balance)}
          delta={invoice.is_overdue ? { value: 'OVERDUE', direction: 'down' } : undefined}
        />
        <StatCard label="Aging" value={agingBucketLabel(invoice.aging_bucket)} />
      </div>

      <div className="px-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div className="col-span-2 space-y-4">
          <Panel title="Details">
            <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 text-sm">
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">Customer</dt>
                <dd>{invoice.customer?.name}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">Date</dt>
                <dd className="font-mono">{formatDate(invoice.date)}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">Due date</dt>
                <dd className="font-mono">{formatDate(invoice.due_date)}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">VAT</dt>
                <dd>{invoice.is_vatable ? 'Yes' : 'No'}</dd>
              </div>
              {invoice.journal_entry && (
                <div className="col-span-2">
                  <dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">
                    Journal entry
                  </dt>
                  <dd className="flex flex-wrap items-center gap-2">
                    <a
                      className="text-accent hover:underline font-mono"
                      href={`/accounting/journal-entries/${invoice.journal_entry.id}`}
                    >
                      {invoice.journal_entry.entry_number}
                    </a>
                    <Chip variant={chipVariantForStatus(invoice.journal_entry.status)}>
                      {invoice.journal_entry.status_label ?? invoice.journal_entry.status}
                    </Chip>
                  </dd>
                </div>
              )}
            </dl>
          </Panel>

          <Panel title="Line items">
            <div className="overflow-x-auto">
              <table className={`${tableCls} min-w-[680px]`}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>#</Th>
                    <Th>Description</Th>
                    <Th>Account</Th>
                    <Th align="right">Qty</Th>
                    <Th align="right">Unit price</Th>
                    <Th align="right">Total</Th>
                  </tr>
                </thead>
                <tbody>
                  {invoice.items?.map((i, idx) => (
                    <tr key={i.id} className={trCls}>
                      <Td mono className="text-muted">
                        {String(idx + 1).padStart(2, '0')}
                      </Td>
                      <Td>{i.description}</Td>
                      <Td className="text-muted text-xs">
                        {i.revenue_account ? (
                          <span>
                            <span className="font-mono">{i.revenue_account.code}</span> ·{' '}
                            {i.revenue_account.name}
                          </span>
                        ) : (
                          '—'
                        )}
                      </Td>
                      <Td align="right" mono>
                        {i.quantity}
                        {i.unit ? ` ${i.unit}` : ''}
                      </Td>
                      <Td align="right" mono>
                        {formatPeso(i.unit_price)}
                      </Td>
                      <Td align="right" mono className="font-medium">
                        {formatPeso(i.total)}
                      </Td>
                    </tr>
                  ))}
                  <tr className={trCls}>
                    <Td align="right" mono className="text-muted" colSpan={5}>
                      Subtotal
                    </Td>
                    <Td align="right" mono>
                      {formatPeso(invoice.subtotal)}
                    </Td>
                  </tr>
                  {invoice.is_vatable && (
                    <tr className={trCls}>
                      <Td align="right" mono className="text-muted" colSpan={5}>
                        VAT
                      </Td>
                      <Td align="right" mono>
                        {formatPeso(invoice.vat_amount)}
                      </Td>
                    </tr>
                  )}
                  <tr className={totalsTrCls}>
                    <Td align="right" mono colSpan={5}>
                      Total
                    </Td>
                    <Td align="right" mono>
                      {formatPeso(invoice.total_amount)}
                    </Td>
                  </tr>
                </tbody>
              </table>
            </div>
          </Panel>
        </div>

        <Panel title="Collections" className="col-span-1">
          {(invoice.collections?.length ?? 0) === 0 ? (
            <p className="text-sm text-muted">No collections yet.</p>
          ) : (
            <ul className="space-y-2 text-xs">
              {invoice.collections!.map((c) => (
                <li key={c.id} className="border-b border-subtle pb-2">
                  <div className="flex justify-between font-mono tabular-nums">
                    <span>{formatDate(c.collection_date)}</span>
                    <span className="font-medium">{formatPeso(c.amount)}</span>
                  </div>
                  <div className="text-muted">
                    {c.payment_method_label ?? c.payment_method}
                    {c.reference_number ? ` · ${c.reference_number}` : ''}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>

      <Modal
        isOpen={showCollect}
        onClose={() => setShowCollect(false)}
        title={`Exceptional collection · ${invoice.invoice_number ?? 'DRAFT'}`}
        size="sm"
      >
        <form
          onSubmit={handleSubmit(
            (d) => {
              const cents = (value: string) => { const [whole, fraction = ''] = value.split('.'); return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0')); };
              if (cents(d.amount) > cents(invoice.balance)) { toast.error('Amount exceeds outstanding balance.'); return; }
              if (!paymentOptionsFailed && !paymentOptionsPending && !cashAccountsFailed && !cashAccountsPending && !collectMut.isPending) collectMut.mutate(d);
            },
            onFormInvalid<CollectionFormValues>(),
          )}
          className="space-y-3"
        >
          {(paymentOptionsFailed || cashAccountsFailed) && <div role="alert" className="text-sm text-danger-fg">
            Payment options could not be loaded. <Button variant="secondary" size="sm" onClick={() => {
              if (paymentOptionsFailed) void refetchPaymentOptions();
              if (cashAccountsFailed) void refetchCashAccounts();
            }}>Retry payment options</Button>
          </div>}
          <Select
            label="Cash account"
            required
            {...register('cash_account_id')}
            error={errors.cash_account_id?.message}
          >
            <option value="">— Select —</option>
            {cashAccts.map((a: { id: string; code: string; name: string }) => (
              <option key={a.id} value={a.id}>
                {a.code} — {a.name}
              </option>
            ))}
          </Select>
          <Input
            label="Collection date"
            type="date"
            required
            {...register('collection_date')}
            error={errors.collection_date?.message}
          />
          <Input
            label={`Amount (max ${formatPeso(invoice.balance)})`}
            min="0.01"
            max={invoice.balance}
            className="font-mono tabular-nums text-right"
            required
            prefix="₱"
            {...numberInputProps()}
            step="0.01"
            {...register('amount')}
            error={errors.amount?.message ?? (amountExceedsBalance ? 'Amount exceeds outstanding balance.' : undefined)}
          />
          <Select
            label="Method"
            required
            {...register('payment_method')}
            error={errors.payment_method?.message}
          >
            <option value="">— Select —</option>
            {(accountingOptions?.payment_methods ?? []).map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
          <Input label="Reference no. (optional)" helper="Leave blank for cash; enter the check number or transfer reference when available." {...register('reference_number')} />
          <Textarea label="Exception reason" required maxLength={500} {...register('manual_reason')} error={errors.manual_reason?.message} />
          <ModalFooter>
            <Button type="button" variant="secondary" onClick={() => setShowCollect(false)}>
              Cancel
            </Button>
            <Button
              type="submit"
              variant="primary"
              loading={collectMut.isPending}
              disabled={amountExceedsBalance || collectMut.isPending || paymentOptionsFailed || paymentOptionsPending || cashAccountsFailed || cashAccountsPending}
            >
              Record
            </Button>
          </ModalFooter>
        </form>
      </Modal>
      {showPaymentReview && pendingPayment && <ReviewPaymentDialog invoiceId={invoice.id} invoiceNumber={invoice.invoice_number ?? 'Invoice'} payment={pendingPayment} onClose={() => setShowPaymentReview(false)} />}

      <ConfirmDialog
        isOpen={showPrebillApproval}
        onClose={() => setShowPrebillApproval(false)}
        onConfirm={() => {
          approvePrebillMut.mutate();
          setShowPrebillApproval(false);
        }}
        title="Approve prebill invoice?"
        description="This records your approval. Editing the draft clears the approval and requires a new review. A separate Finance officer must finalize it."
        confirmLabel="Approve"
        variant="warning"
        pending={approvePrebillMut.isPending}
      />
      <ConfirmDialog
        isOpen={showFinalize}
        onClose={() => setShowFinalize(false)}
        onConfirm={() => {
          finalizeMut.mutate();
          setShowFinalize(false);
        }}
        title="Finalize invoice?"
        description="Once finalized, this invoice will be sent to the customer and cannot be edited."
        confirmLabel="Finalize"
        variant="warning"
        pending={finalizeMut.isPending}
      />
      <ConfirmDialog
        isOpen={showCancelConfirm}
        onClose={() => setShowCancelConfirm(false)}
        onConfirm={() => {
          cancelMut.mutate();
          setShowCancelConfirm(false);
        }}
        title="Cancel this invoice?"
        description="This action cannot be undone."
        confirmLabel="Cancel invoice"
        variant="danger"
        pending={cancelMut.isPending}
      />
    </div>
  );
}
