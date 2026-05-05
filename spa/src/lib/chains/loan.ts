/**
 * Sprint P1 — centralized chain-step builder for Employee Loans / Cash Advances.
 *
 * Hire-to-Retire chain (Loans subdomain):
 *   Submitted → Approved → Disbursed → Repaying → Settled
 *
 * `Repaying` is "active" once any payment exists but the balance > 0;
 * `Settled` is "done" when status = paid or balance ≤ 0.
 */
import type { ChainStep } from '@/types/chain';
import type { EmployeeLoan } from '@/types/loans';

export function buildLoanChain(loan: EmployeeLoan): ChainStep[] {
  const isActiveOrPaid = loan.status === 'active' || loan.status === 'paid';
  const totalPaid = parseFloat(loan.total_paid ?? '0');
  const balance = parseFloat(loan.balance ?? '0');

  if (loan.status === 'cancelled' || loan.status === 'rejected') {
    return [
      { key: 'submitted', label: 'Submitted', state: 'done', date: loan.created_at?.slice(0, 10) },
      {
        key: 'closed',
        label: loan.status === 'rejected' ? 'Rejected' : 'Cancelled',
        state: 'done',
        date: loan.updated_at?.slice(0, 10),
      },
    ];
  }

  return [
    { key: 'submitted', label: 'Submitted', state: 'done', date: loan.created_at?.slice(0, 10) },
    {
      key: 'approved',
      label: 'Approved',
      state: isActiveOrPaid ? 'done' : loan.status === 'pending' ? 'active' : 'pending',
      date: loan.approved_at?.slice(0, 10),
    },
    {
      key: 'disbursed',
      label: 'Disbursed',
      state: isActiveOrPaid ? 'done' : 'pending',
      date: loan.start_date ?? undefined,
    },
    {
      key: 'repaying',
      label: 'Repaying',
      state:
        loan.status === 'paid'
          ? 'done'
          : loan.status === 'active' && totalPaid > 0
            ? 'active'
            : 'pending',
    },
    {
      key: 'settled',
      label: 'Settled',
      state: loan.status === 'paid' || balance <= 0 ? 'done' : 'pending',
      date: loan.end_date ?? undefined,
    },
  ];
}
