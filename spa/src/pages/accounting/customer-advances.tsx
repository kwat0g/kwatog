import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { accountsApi } from '@/api/accounting/accounts';
import { customerAdvancesApi, type CustomerAdvance } from '@/api/accounting/customerAdvances';
import { downloadAuthenticatedFile } from '@/api/download';
import type { Account } from '@/types/accounting';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Textarea } from '@/components/ui/Textarea';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';

type Decision = 'verify' | 'reject' | 'waive' | 'refund';

function bankLeaves(accounts: Account[]): Account[] {
  const result: Account[] = [];
  const visit = (row: Account, inBankTree: boolean) => {
    const inBank = inBankTree || row.code === '1020';
    if (inBank && row.is_active && row.type === 'asset' && !row.children?.length && row.is_leaf !== false) result.push(row);
    for (const child of row.children ?? []) visit(child, inBank);
  };
  accounts.forEach((row) => visit(row, false));
  return result;
}

function statusVariant(status: string): 'success' | 'warning' | 'danger' | 'neutral' {
  if (['verified', 'waived', 'refunded'].includes(status)) return 'success';
  if (status === 'pending' || status === 'configured') return 'warning';
  if (status === 'rejected') return 'danger';
  return 'neutral';
}

export default function CustomerAdvancesPage() {
  const queries = useQueryClient();
  const [status, setStatus] = useState('pending');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<CustomerAdvance | null>(null);
  const [decision, setDecision] = useState<Decision>('verify');
  const [bankAccount, setBankAccount] = useState('');
  const [reason, setReason] = useState('');
  const [refundReference, setRefundReference] = useState('');
  const [verifiedFunds, setVerifiedFunds] = useState(false);
  const [error, setError] = useState('');

  const query = useQuery({
    queryKey: ['accounting', 'customer-advances', status, page],
    queryFn: () => customerAdvancesApi.list({ status: status === 'all' ? undefined : status, page, per_page: 25 }),
  });
  const accounts = useQuery({ queryKey: ['accounting', 'accounts', 'tree'], queryFn: accountsApi.tree });
  const bankAccounts = bankLeaves(accounts.data ?? []);

  const close = () => {
    if (action.isPending) return;
    setSelected(null);
    setReason('');
    setRefundReference('');
    setBankAccount('');
    setVerifiedFunds(false);
    setError('');
  };
  const open = (row: CustomerAdvance, next: Decision) => {
    setSelected(row);
    setDecision(next);
    setReason('');
    setRefundReference('');
    setBankAccount('');
    setVerifiedFunds(false);
    setError('');
  };

  const action = useMutation({
    mutationFn: () => {
      if (!selected) throw new Error('Select a customer advance first.');
      if (decision === 'verify') return customerAdvancesApi.verify(selected.id, bankAccount);
      if (decision === 'reject') return customerAdvancesApi.reject(selected.id, reason.trim());
      if (decision === 'waive') return customerAdvancesApi.waive(selected.id, reason.trim());
      return customerAdvancesApi.refund(selected.id, {
        bank_account_id: bankAccount, reference_number: refundReference.trim(), reason: reason.trim(),
      });
    },
    onSuccess: async () => {
      await queries.invalidateQueries({ queryKey: ['accounting', 'customer-advances'] });
      toast.success(decision === 'verify' ? 'Customer transfer verified and posted.'
        : decision === 'reject' ? 'Payment proof rejected.'
          : decision === 'waive' ? 'Order deposit requirement waived.' : 'Unused customer advance refunded.');
      close();
    },
    onError: (requestError: Error & { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }) => {
      setError(Object.values(requestError.response?.data?.errors ?? {}).flat()[0]
        ?? requestError.response?.data?.message
        ?? 'Finance could not complete this action. Refresh the list before retrying.');
    },
  });

  const rows = query.data?.data ?? [];
  const canSubmit = decision === 'verify'
    ? !!bankAccounts.some((account) => account.id === bankAccount) && verifiedFunds
    : decision === 'refund'
      ? !!bankAccounts.some((account) => account.id === bankAccount) && !!refundReference.trim() && !!reason.trim()
      : !!reason.trim();
  const heading = decision === 'verify' ? 'Verify customer transfer'
    : decision === 'reject' ? 'Reject payment proof'
      : decision === 'waive' ? 'Waive order deposit' : 'Refund unused advance';

  return (
    <div>
      <PageHeader title="Customer order deposits" subtitle={query.data ? `${query.data.meta.total} records` : undefined} />
      <div className="px-5 py-4">
        <Select label="Status" value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }}>
          <option value="pending">Awaiting Finance</option>
          <option value="configured">Not yet submitted</option>
          <option value="verified">Verified</option>
          <option value="rejected">Rejected</option>
          <option value="waived">Waived</option>
          <option value="refunded">Refunded</option>
          <option value="all">All statuses</option>
        </Select>
      </div>
      {query.isLoading && <SkeletonTable columns={7} rows={5} />}
      {query.isError && <EmptyState icon="alert-circle" title="Could not load customer deposits" action={<Button variant="secondary" onClick={() => query.refetch()}>Retry</Button>} />}
      {query.data && (
        <Panel title="Order deposits" meta={`${query.data.meta.total} records`} noPadding scrollBody>
          {rows.length === 0 ? (
            <div className="p-5 text-sm text-muted">No customer deposits match this status.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className={tableCls}>
                <thead><tr className={theadTrCls}>
                  <Th>Sales order</Th><Th>Customer</Th><Th>Deposit</Th><Th>Transfer</Th><Th>Status</Th><Th>Action</Th>
                </tr></thead>
                <tbody>{rows.map((row) => (
                  <tr key={row.id} className={trCls}>
                    <Td mono>{row.sales_order_number}</Td>
                    <Td>{row.customer_name}</Td>
                    <Td mono>{formatPeso(row.required_amount)}</Td>
                    <Td>
                      {row.submitted_amount ? <>
                        <p>{formatPeso(row.submitted_amount)} · {row.sending_bank ?? 'Bank not provided'}</p>
                        <p className="mt-1 text-xs text-muted">Ref {row.reference_number ?? '—'} · {row.payment_date ? formatDate(row.payment_date) : '—'}</p>
                      </> : <span className="text-muted">Not submitted</span>}
                    </Td>
                    <Td><Chip variant={statusVariant(row.status)}>{row.status.replaceAll('_', ' ')}</Chip></Td>
                    <Td>
                      {row.status === 'pending' && <Button size="sm" variant="secondary" onClick={() => open(row, 'verify')}>Review proof</Button>}
                      {['configured', 'rejected'].includes(row.status) && <Button size="sm" variant="secondary" onClick={() => open(row, 'waive')}>Waive deposit</Button>}
                      {row.status === 'verified' && Number(row.unapplied_amount ?? 0) > 0 && <Button size="sm" variant="secondary" onClick={() => open(row, 'refund')}>Refund unused</Button>}
                      {row.status === 'rejected' && row.review_reason && <span className="text-xs text-muted">{row.review_reason}</span>}
                    </Td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          )}
          {query.data.meta.last_page > 1 && <div className="flex items-center justify-between border-t border-default px-4 py-3 text-sm">
            <span className="text-muted">Page {query.data.meta.current_page} of {query.data.meta.last_page}</span>
            <div className="flex gap-2">
              <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))}>Previous</Button>
              <Button size="sm" variant="secondary" disabled={page >= query.data.meta.last_page} onClick={() => setPage((value) => value + 1)}>Next</Button>
            </div>
          </div>}
        </Panel>
      )}

      {selected && (
        <Modal isOpen title={heading} onClose={close} size="lg" closeOnOverlayClick={false}>
          <div className="space-y-5">
            <div className="rounded-md border border-default bg-subtle p-3 text-sm">
              <p className="font-medium">{selected.sales_order_number} · {selected.customer_name}</p>
              <p className="mt-1">Required {formatPeso(selected.required_amount)}{selected.submitted_amount ? ` · Submitted ${formatPeso(selected.submitted_amount)}` : ''}</p>
              {selected.sending_bank && <p className="mt-1 text-muted">Transfer from {selected.sending_bank} · ref {selected.reference_number} · {selected.payment_date ? formatDate(selected.payment_date) : 'date unavailable'}</p>}
            </div>
            {selected.proof_file_name && <Button variant="secondary" onClick={async () => {
              try { await downloadAuthenticatedFile(customerAdvancesApi.proofPath(selected.id), { filename: selected.proof_file_name! }); }
              catch { toast.error('Payment proof could not be downloaded.'); }
            }}>Download {selected.proof_file_name}</Button>}

            {decision === 'verify' && <>
              <Select label="Receiving bank account" required value={bankAccount} onChange={(event) => setBankAccount(event.target.value)} disabled={accounts.isFetching || accounts.isError}>
                <option value="">Select receiving bank account</option>
                {bankAccounts.map((row) => <option key={row.id} value={row.id}>{row.code} · {row.name}</option>)}
              </Select>
              {accounts.isError && <p role="alert" className="text-sm text-danger-fg">Bank accounts could not be loaded. <Button variant="secondary" size="sm" onClick={() => accounts.refetch()}>Retry bank accounts</Button></p>}
              <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={verifiedFunds} onChange={(event) => setVerifiedFunds(event.target.checked)} />I verified that cleared funds reached this bank account.</label>
              <p className="text-xs text-muted">Verification posts debit to bank and credit to Customer Advances liability. The order is released to production after verification.</p>
            </>}
            {decision === 'reject' && <Textarea label="Rejection reason" required rows={3} maxLength={1000} value={reason} onChange={(event) => setReason(event.target.value)} />}
            {decision === 'waive' && <Textarea label="Waiver reason" required rows={3} maxLength={1000} value={reason} onChange={(event) => setReason(event.target.value)} />}
            {decision === 'refund' && <>
              <p className="text-sm">Available to refund: <strong>{formatPeso(selected.unapplied_amount ?? '0.00')}</strong></p>
              <Select label="Refund bank account" required value={bankAccount} onChange={(event) => setBankAccount(event.target.value)} disabled={accounts.isFetching || accounts.isError}>
                <option value="">Select bank account used for the refund</option>
                {bankAccounts.map((row) => <option key={row.id} value={row.id}>{row.code} · {row.name}</option>)}
              </Select>
              <Input label="Refund reference" required maxLength={120} value={refundReference} onChange={(event) => setRefundReference(event.target.value)} />
              <Textarea label="Refund reason" required rows={3} maxLength={1000} value={reason} onChange={(event) => setReason(event.target.value)} />
            </>}
            {error && <p role="alert" className="text-sm text-danger-fg">{error}</p>}
            <ModalFooter>
              <Button variant="secondary" disabled={action.isPending} onClick={close}>Cancel</Button>
              {decision === 'verify' && <Button variant="secondary" disabled={action.isPending} onClick={() => { setDecision('reject'); setError(''); }}>Reject proof</Button>}
              {decision === 'reject' && <Button variant="secondary" disabled={action.isPending} onClick={() => { setDecision('verify'); setError(''); }}>Back to verification</Button>}
              <Button
                variant={decision === 'reject' ? 'danger' : 'primary'} loading={action.isPending}
                disabled={!canSubmit || action.isPending || accounts.isFetching || (decision !== 'reject' && accounts.isError)}
                onClick={() => action.mutate()}
              >{decision === 'verify' ? 'Confirm funds received' : decision === 'reject' ? 'Reject payment proof' : decision === 'waive' ? 'Record waiver' : 'Record refund'}</Button>
            </ModalFooter>
          </div>
        </Modal>
      )}
    </div>
  );
}
