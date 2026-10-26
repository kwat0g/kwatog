import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { invoicesApi } from '@/api/accounting/invoices';
import { accountsApi } from '@/api/accounting/accounts';
import { downloadAuthenticatedFile } from '@/api/download';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { Select } from '@/components/ui/Select';
import { Button } from '@/components/ui/Button';
import type { Account } from '@/types/accounting';
import type { InvoicePaymentSubmission } from '@/types/invoice-payments';

function bankLeaves(accounts: Account[]): Account[] {
  const result: Account[] = [];
  const visit = (row: Account, bank: boolean) => {
    const inBank = bank || row.code === '1020';
    if (inBank && row.is_active && row.type === 'asset' && !row.children?.length && row.is_leaf !== false) result.push(row);
    for (const child of row.children ?? []) visit(child, inBank);
  };
  accounts.forEach((row) => visit(row, false));
  return result;
}

export function ReviewPaymentDialog({ invoiceId, invoiceNumber, payment, onClose }: {
  invoiceId: string; invoiceNumber: string; payment: InvoicePaymentSubmission; onClose: () => void;
}) {
  const queries = useQueryClient();
  const [account, setAccount] = useState('');
  const [checked, setChecked] = useState(false);
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');
  const [needsReview, setNeedsReview] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const pending = useRef(false);
  const accounts = useQuery({ queryKey: ['accounting', 'accounts', 'tree'], queryFn: accountsApi.tree });
  const bankAccounts = bankLeaves(accounts.data ?? []);
  const mutation = useMutation({
    mutationFn: (action: 'verify' | 'reject') => action === 'verify'
      ? invoicesApi.verifyPaymentSubmission(invoiceId, payment.id, { cash_account_id: account, funds_received_confirmed: true })
      : invoicesApi.rejectPaymentSubmission(invoiceId, payment.id, { reason: reason.trim() }),
    onSuccess: () => {
      queries.invalidateQueries({ queryKey: ['accounting', 'invoices'] });
      queries.invalidateQueries({ queryKey: ['portal', 'customer'] });
      toast.success(rejecting ? 'Payment rejected. Customer can submit corrected evidence.' : 'Funds received. Collection recorded.');
      onClose();
    },
    onError: (err: Error & { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }) => {
      setError(Object.values(err.response?.data?.errors ?? {}).flat()[0] ?? err.response?.data?.message ?? 'Payment review could not be confirmed. Refresh before retrying.');
      setNeedsReview(true);
      queries.invalidateQueries({ queryKey: ['accounting', 'invoices', invoiceId] });
    },
    onSettled: () => { pending.current = false; },
  });
  const act = (action: 'verify' | 'reject') => {
    if (pending.current || needsReview) return;
    pending.current = true;
    mutation.mutate(action);
  };
  const refresh = async () => {
    setRefreshing(true);
    try {
      const fresh = await invoicesApi.show(invoiceId);
      queries.setQueryData(['accounting', 'invoices', invoiceId], fresh);
      const row = fresh.payment_submissions?.find((item) => item.id === payment.id);
      if (!row || row.status !== 'pending') { onClose(); return; }
      if (fresh.balance !== payment.amount) { setError('Invoice balance changed. Reject this payment for reconciliation.'); setRejecting(true); }
      else setError('');
      setNeedsReview(false);
    } catch { setError('Invoice refresh failed. Please retry.'); }
    finally { setRefreshing(false); }
  };
  return <Modal isOpen title="Review payment" size="lg" onClose={() => { if (!pending.current) onClose(); }} closeOnOverlayClick={false}>
    <div className="space-y-5">
      <p className="text-sm">{invoiceNumber}</p>
      <Input label="Payment amount" value={payment.amount} readOnly prefix="₱" />
      <dl className="grid gap-4 sm:grid-cols-3 text-sm">
        <div><dt className="text-xs text-muted">Sending bank</dt><dd>{payment.sending_bank}</dd></div>
        <div><dt className="text-xs text-muted">Reference number</dt><dd className="break-all">{payment.reference_number}</dd></div>
        <div><dt className="text-xs text-muted">Payment date</dt><dd>{payment.payment_date}</dd></div>
      </dl>
      <Button variant="secondary" onClick={() => downloadAuthenticatedFile(`/invoices/${invoiceId}/payment-submissions/${payment.id}/proof`, { filename: payment.proof_file_name })}>Download payment proof</Button>
      {!rejecting ? <>
        <Select label="Receiving bank account" required value={account} onChange={(event) => setAccount(event.target.value)} disabled={accounts.isFetching || accounts.isError}>
          <option value="">Select receiving bank account</option>
          {bankAccounts.map((row) => <option key={row.id} value={row.id}>{row.code} · {row.name}</option>)}
        </Select>
        {accounts.isError && <p role="alert" className="text-sm text-danger-fg">Bank accounts could not be loaded. <Button variant="secondary" size="sm" onClick={() => accounts.refetch()}>Retry bank accounts</Button></p>}
        {!accounts.isFetching && !accounts.isError && !bankAccounts.length && <p role="alert" className="text-sm text-danger-fg">No active receiving bank posting account is available.</p>}
        <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={checked} onChange={(event) => setChecked(event.target.checked)} />I verified this payment against the receiving bank record</label>
        <p className="text-xs text-muted">Confirmation records collection and updates the invoice balance. Uploaded proof alone does not confirm cleared funds.</p>
      </> : <Textarea label="Rejection reason" required rows={3} maxLength={500} value={reason} onChange={(event) => setReason(event.target.value)} error={reason && !reason.trim() ? 'Rejection reason is required.' : undefined} />}
      {error && <p role="alert" className="text-sm text-danger-fg">{error}</p>}
      {needsReview && <Button variant="secondary" loading={refreshing} onClick={refresh}>Review refreshed invoice</Button>}
      <ModalFooter>
        <Button variant="secondary" disabled={mutation.isPending} onClick={onClose}>Cancel</Button>
        {!rejecting && <Button variant="secondary" disabled={mutation.isPending} onClick={() => setRejecting(true)}>Reject payment</Button>}
        {rejecting ? <Button variant="danger" loading={mutation.isPending} disabled={!reason.trim() || mutation.isPending || needsReview || refreshing} onClick={() => act('reject')}>Reject payment</Button>
          : <Button variant="primary" loading={mutation.isPending} disabled={!checked || !bankAccounts.some((row) => row.id === account) || accounts.isFetching || accounts.isError || mutation.isPending || needsReview || refreshing} onClick={() => act('verify')}>Confirm funds received</Button>}
      </ModalFooter>
    </div>
  </Modal>;
}
