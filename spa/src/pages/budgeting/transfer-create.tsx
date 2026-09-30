import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { budgetingApi } from '@/api/accounting/budgeting';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { formatPeso } from '@/lib/formatNumber';
import toast from 'react-hot-toast';
import { focusFirstInvalidField, reportMutationError } from '@/lib/formErrors';
import type { Budget } from '@/types/budgeting';

const MONTHS = [
  { key: 'jan', label: 'January' }, { key: 'feb', label: 'February' }, { key: 'mar', label: 'March' },
  { key: 'apr', label: 'April' }, { key: 'may', label: 'May' }, { key: 'jun', label: 'June' },
  { key: 'jul', label: 'July' }, { key: 'aug', label: 'August' }, { key: 'sep', label: 'September' },
  { key: 'oct', label: 'October' }, { key: 'nov', label: 'November' }, { key: 'dec', label: 'December' },
] as const;

export default function BudgetTransferCreatePage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [fiscalYearId, setFiscalYearId] = useState('');
  const [fromBudgetId, setFromBudgetId] = useState('');
  const [toBudgetId, setToBudgetId] = useState('');
  const [fromLineId, setFromLineId] = useState('');
  const [toLineId, setToLineId] = useState('');
  const [month, setMonth] = useState('jan');
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const { data: fiscalYears, isLoading: yearsLoading } = useQuery({
    queryKey: ['budget-fiscal-years'],
    queryFn: () => budgetingApi.fiscalYears(),
  });

  const budgetsQuery = useQuery({
    queryKey: ['budgets', 'transfer-source', fiscalYearId],
    queryFn: () => budgetingApi.list({ fiscal_year_id: fiscalYearId, per_page: 100 }),
    enabled: !!fiscalYearId,
  });
  const budgets: Budget[] = useMemo(() => budgetsQuery.data?.data ?? [], [budgetsQuery.data]);
  const liveBudgets = useMemo(
    () => budgets.filter((budget) => budget.status === 'active' || budget.status === 'approved'),
    [budgets],
  );

  const fromBudgetQuery = useQuery({
    queryKey: ['budget', fromBudgetId],
    queryFn: () => budgetingApi.show(fromBudgetId),
    enabled: !!fromBudgetId,
  });
  const toBudgetQuery = useQuery({
    queryKey: ['budget', toBudgetId],
    queryFn: () => budgetingApi.show(toBudgetId),
    enabled: !!toBudgetId,
  });

  const saveMutation = useMutation({
    mutationFn: () => budgetingApi.requestTransfer({
      from_line_item_id: fromLineId,
      to_line_item_id: toLineId,
      month,
      amount,
      reason: reason.trim(),
    }),
    onSuccess: (saved) => {
      queryClient.invalidateQueries({ queryKey: ['budget-transfers'] });
      toast.success(`Transfer ${saved.transfer_number} requested; VP approval applies it.`);
      navigate('/budgeting/transfers');
    },
    onError: (error) => reportMutationError(error, 'Could not request the transfer.'),
  });

  const submit = () => {
    const next: Record<string, string> = {};
    if (!fiscalYearId) next.fiscal_year_id = 'Select a fiscal year.';
    if (!fromLineId) next.from_line = 'Select a source line.';
    if (!toLineId) next.to_line = 'Select a destination line.';
    if (fromLineId && toLineId && fromLineId === toLineId) next.to_line = 'Source and destination must differ.';
    if (!amount || Number(amount) <= 0) next.amount = 'Enter a positive amount.';
    if (reason.trim().length < 5) next.reason = 'Give a reason of at least 5 characters.';
    setFieldErrors(next);
    if (Object.keys(next).length > 0) {
      focusFirstInvalidField();
      return;
    }
    saveMutation.mutate();
  };

  if (yearsLoading) return <SkeletonDetail />;

  return (
    <div className="p-5 space-y-6">
      <PageHeader
        title="Request Budget Transfer"
        subtitle="Move allocation between lines in one fiscal year — VP approval applies it"
        backTo="/budgeting/transfers"
        backLabel="Transfers"
      />
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <Panel title="Source Line">
          <div className="space-y-4">
            <Select label="Fiscal Year" value={fiscalYearId} onChange={(event) => { setFiscalYearId(event.target.value); setFromBudgetId(''); setToBudgetId(''); setFromLineId(''); setToLineId(''); }} required error={fieldErrors.fiscal_year_id}>
              <option value="">Select fiscal year...</option>
              {(fiscalYears ?? []).map((fy) => <option key={fy.id} value={fy.id}>FY {fy.year} ({fy.status_label ?? fy.status})</option>)}
            </Select>
            <Select label="Source Budget (live only)" value={fromBudgetId} onChange={(event) => { setFromBudgetId(event.target.value); setFromLineId(''); }} required disabled={!fiscalYearId}>
              <option value="">Select budget...</option>
              {liveBudgets.map((budget) => <option key={budget.id} value={budget.id}>{budget.name}{budget.department ? ` — ${budget.department.name}` : ''}</option>)}
            </Select>
            <Select label="Source Line" value={fromLineId} onChange={(event) => setFromLineId(event.target.value)} required error={fieldErrors.from_line} disabled={!fromBudgetId}>
              <option value="">Select line...</option>
              {(fromBudgetQuery.data?.line_items ?? []).map((line) => (
                <option key={line.id} value={line.id}>{line.account?.code} — {line.account?.name} ({formatPeso(line.annual_total)} annual)</option>
              ))}
            </Select>
          </div>
        </Panel>
        <Panel title="Destination Line">
          <div className="space-y-4">
            <Select label="Destination Budget (live only)" value={toBudgetId} onChange={(event) => { setToBudgetId(event.target.value); setToLineId(''); }} required disabled={!fiscalYearId}>
              <option value="">Select budget...</option>
              {liveBudgets.map((budget) => <option key={budget.id} value={budget.id}>{budget.name}{budget.department ? ` — ${budget.department.name}` : ''}</option>)}
            </Select>
            <Select label="Destination Line" value={toLineId} onChange={(event) => setToLineId(event.target.value)} required error={fieldErrors.to_line} disabled={!toBudgetId}>
              <option value="">Select line...</option>
              {(toBudgetQuery.data?.line_items ?? []).map((line) => (
                <option key={line.id} value={line.id}>{line.account?.code} — {line.account?.name} ({formatPeso(line.annual_total)} annual)</option>
              ))}
            </Select>
            <Select label="Month Bucket" value={month} onChange={(event) => setMonth(event.target.value)} required>
              {MONTHS.map((m) => <option key={m.key} value={m.key}>{m.label}</option>)}
            </Select>
            <Input label="Amount (₱)" type="number" min="0" step="0.01" value={amount} onChange={(event) => setAmount(event.target.value)} placeholder="0.00" required error={fieldErrors.amount} />
            <Textarea label="Reason" value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Why does this allocation need to move?" required error={fieldErrors.reason} rows={3} />
          </div>
        </Panel>
      </div>
      <div className="flex justify-end gap-3">
        <Button variant="secondary" onClick={() => navigate('/budgeting/transfers')}>Cancel</Button>
        <Button variant="primary" onClick={submit} loading={saveMutation.isPending} disabled={!fromLineId || !toLineId || !amount}>
          Request Transfer
        </Button>
      </div>
    </div>
  );
}
