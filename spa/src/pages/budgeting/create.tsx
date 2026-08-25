import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { budgetingApi } from '@/api/accounting/budgeting';
import { accountsApi } from '@/api/accounting/accounts';
import { departmentsApi } from '@/api/hr/departments';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import toast from 'react-hot-toast';
import { focusFirstInvalidField, reportMutationError } from '@/lib/formErrors';
import { LuPlus, LuTrash2 } from '@/lib/icons';
import type { Budget, FiscalYear } from '@/types/budgeting';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { formatCompactCurrency } from '@/lib/formatNumber';

const MONTHS = [
  { key: 'jan', label: 'Jan' }, { key: 'feb', label: 'Feb' }, { key: 'mar', label: 'Mar' },
  { key: 'apr', label: 'Apr' }, { key: 'may', label: 'May' }, { key: 'jun', label: 'Jun' },
  { key: 'jul', label: 'Jul' }, { key: 'aug', label: 'Aug' }, { key: 'sep', label: 'Sep' },
  { key: 'oct', label: 'Oct' }, { key: 'nov', label: 'Nov' }, { key: 'dec', label: 'Dec' },
] as const;

interface LineItemForm {
  account_id: string;
  [key: string]: string;
}

const emptyLineItem = (): LineItemForm => ({
  account_id: '',
  jan: '0.00', feb: '0.00', mar: '0.00', apr: '0.00', may: '0.00', jun: '0.00',
  jul: '0.00', aug: '0.00', sep: '0.00', oct: '0.00', nov: '0.00', dec: '0.00',
});

export default function BudgetCreatePage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { id } = useParams<{ id: string }>();
  const editing = Boolean(id);
  const [fiscalYearId, setFiscalYearId] = useState('');
  const [departmentId, setDepartmentId] = useState<string | null>(null);
  const [budgetType, setBudgetType] = useState('');
  const [name, setName] = useState('');
  const [lineItems, setLineItems] = useState<LineItemForm[]>([emptyLineItem()]);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [hydrated, setHydrated] = useState(false);

  const { data: fiscalYears, isLoading: yearsLoading } = useQuery<FiscalYear[]>({ queryKey: ['budget-fiscal-years'], queryFn: () => budgetingApi.fiscalYears() });
  const { data: budget } = useQuery<Budget>({ queryKey: ['budget', id], queryFn: () => budgetingApi.show(id!), enabled: editing });
  const { data: accountPage } = useQuery({ queryKey: ['accounts', 'budget-options'], queryFn: () => accountsApi.list({ per_page: 200 }) });
  const { data: departments } = useQuery({ queryKey: ['departments'], queryFn: () => departmentsApi.tree() });
  const { data: budgetOptions } = useQuery({ queryKey: ['budgets', 'options'], queryFn: () => budgetingApi.options(), staleTime: 300_000 });

  useEffect(() => {
    if (hydrated) return;
    if (budget) {
      setFiscalYearId(budget.fiscal_year_id);
      setDepartmentId(budget.department_id ?? null);
      setBudgetType(budget.budget_type);
      setName(budget.name);
      setLineItems((budget.line_items ?? []).map((line) => ({
        account_id: line.account_id ?? '',
        ...Object.fromEntries(MONTHS.map((month) => [month.key, line[month.key] ?? '0.00'])),
      })));
      setHydrated(true);
    } else if (!editing && fiscalYears && fiscalYears.length > 0) {
      setFiscalYearId(fiscalYears.find((fy) => fy.status === 'active')?.id ?? fiscalYears[0].id);
      setHydrated(true);
    }
  }, [budget, editing, fiscalYears, hydrated]);

  const accounts = useMemo(() => {
    const desiredType = budgetType === 'capital' ? 'asset' : 'expense';
    const all = accountPage?.data ?? [];
    const parentIds = new Set(all.map((account) => account.parent_id).filter((parentId): parentId is string => parentId !== null));
    return all.filter((account) => account.is_active && !parentIds.has(account.id) && account.type === desiredType);
  }, [accountPage, budgetType]);

  const payload = () => ({
    fiscal_year_id: fiscalYearId,
    department_id: departmentId,
    budget_type: budgetType,
    name: name.trim(),
    line_items: lineItems.filter((line) => line.account_id !== '').map((line) => ({
      account_id: line.account_id,
      ...Object.fromEntries(MONTHS.map((month) => [month.key, line[month.key] || '0.00'])),
    })),
  });
  const saveMutation = useMutation({
    mutationFn: () => editing ? budgetingApi.update(id!, payload()) : budgetingApi.create(payload()),
    onSuccess: (saved) => {
      setFieldErrors({});
      queryClient.invalidateQueries({ queryKey: ['budgets'] });
      queryClient.invalidateQueries({ queryKey: ['budget-overview'] });
      toast.success(editing ? 'Budget updated.' : 'Budget created.');
      navigate(`/budgeting/${saved.id}`);
    },
    onError: (error) => reportMutationError(error, editing ? 'Failed to update budget.' : 'Failed to create budget.'),
  });

  const submit = () => {
    const next: Record<string, string> = {};
    if (!fiscalYearId) next.fiscal_year_id = 'Select a fiscal year.';
    if (!name.trim()) next.name = 'Enter a budget name.';
    if (!budgetType) next.budget_type = 'Select a budget type.';
    const selected = lineItems.filter((line) => line.account_id !== '');
    if (selected.length === 0) next.line_items = 'Add at least one line item with an account.';
    if (new Set(selected.map((line) => line.account_id)).size !== selected.length) next.line_items = 'Each account can appear only once.';
    setFieldErrors(next);
    if (Object.keys(next).length > 0) {
      focusFirstInvalidField();
      return;
    }
    saveMutation.mutate();
  };
  const updateLineItem = (index: number, field: string, value: string) => setLineItems((current) => current.map((line, i) => i === index ? { ...line, [field]: value } : line));
  const addLineItem = () => setLineItems((current) => [...current, emptyLineItem()]);
  const removeLineItem = (index: number) => setLineItems((current) => current.length > 1 ? current.filter((_, i) => i !== index) : current);
  const calcAnnual = (line: LineItemForm) => MONTHS.reduce((sum, month) => sum + (Number(line[month.key]) || 0), 0);
  const totalAllocated = lineItems.reduce((sum, line) => sum + calcAnnual(line), 0);
  const selectedYear = fiscalYears?.find((fy) => fy.id === fiscalYearId);
  const selectedDepartment = departments?.find((department: { id: string }) => department.id === departmentId);

  if (yearsLoading || (editing && !budget)) return <SkeletonDetail />;

  return (
    <div className="p-5 space-y-6">
      <PageHeader title={editing ? 'Edit Budget' : 'Create Budget'} subtitle="Set monthly allocations per eligible leaf account" backTo={editing ? `/budgeting/${id}` : '/budgeting'} backLabel="Budgeting" />
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <Panel title="Budget Details"><div className="space-y-4">
          <Select label="Fiscal Year" value={fiscalYearId} onChange={(event) => setFiscalYearId(event.target.value)} required error={fieldErrors.fiscal_year_id}><option value="">Select fiscal year...</option>{(fiscalYears ?? []).map((fy) => <option key={fy.id} value={fy.id}>FY {fy.year} ({fy.status_label ?? fy.status})</option>)}</Select>
          <Input label="Budget Name" value={name} onChange={(event) => setName(event.target.value)} placeholder="Annual operating budget" required error={fieldErrors.name} />
          <Select label="Budget Type" value={budgetType} onChange={(event) => setBudgetType(event.target.value)} required error={fieldErrors.budget_type}><option value="">— Select budget type —</option>{(budgetOptions?.budget_types ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</Select>
          <Select label="Department (optional)" value={departmentId ?? ''} onChange={(event) => setDepartmentId(event.target.value || null)}><option value="">Company-wide</option>{(departments ?? []).map((department: { id: string; name: string }) => <option key={department.id} value={department.id}>{department.name}</option>)}</Select>
        </div></Panel>
        <Panel title="Summary"><div className="space-y-3 text-sm">
          <div className="flex justify-between py-2 border-b border-default/50"><span className="text-muted">Fiscal Year</span><span className="font-medium">{selectedYear?.year ?? '—'}</span></div>
          <div className="flex justify-between py-2 border-b border-default/50"><span className="text-muted">Budget Type</span><span className="font-medium capitalize">{budgetType || '—'}</span></div>
          <div className="flex justify-between py-2 border-b border-default/50"><span className="text-muted">Department</span><span className="font-medium">{selectedDepartment?.name ?? 'Company-wide'}</span></div>
          <div className="flex justify-between py-2"><span className="text-muted">Total Lines</span><span className="font-medium">{lineItems.filter((line) => line.account_id !== '').length}</span></div>
          <div className="flex justify-between py-2 border-t border-default"><span className="font-medium">Total Allocated</span><span className="font-mono tabular-nums font-medium text-lg">{formatCompactCurrency(totalAllocated, 1_000_000, 'M')}</span></div>
        </div></Panel>
      </div>

      <Panel title="Line Items" meta={<Button size="sm" onClick={addLineItem}><LuPlus size={14} /> Add Line</Button>}>
        {fieldErrors.line_items && <p className="mb-2 text-xs text-danger-fg" role="alert">{fieldErrors.line_items}</p>}
        <div className="overflow-x-auto"><table className={tableCls}><thead><tr className={theadTrCls}><Th className="min-w-[230px] sticky left-0 bg-canvas">Account</Th>{MONTHS.map((month) => <Th align="right" className="font-mono w-[60px]" key={month.key}>{month.label}</Th>)}<Th align="right" className="w-[80px]">Annual</Th><Th className="w-[40px]" /></tr></thead><tbody>
          {lineItems.map((line, index) => <tr key={index} className={trCls}><Td className="sticky left-0 bg-canvas"><Select fieldSize="sm" aria-label="Account" value={line.account_id} onChange={(event) => updateLineItem(index, 'account_id', event.target.value)}><option value="">Select account…</option>{accounts.map((account) => <option key={account.id} value={account.id}>{account.code} — {account.name}</option>)}</Select></Td>{MONTHS.map((month) => <Td key={month.key}><Input fieldSize="sm" type="number" min="0" step="0.01" aria-label={`${month.label} amount`} className="text-right font-mono tabular-nums" value={line[month.key]} onChange={(event) => updateLineItem(index, month.key, event.target.value)} placeholder="0.00" /></Td>)}<Td align="right" mono className="font-medium">{formatCompactCurrency(calcAnnual(line), 1_000, 'K')}</Td><Td><Button type="button" variant="ghost" size="sm" iconOnly icon={<LuTrash2 size={14} />} onClick={() => removeLineItem(index)} title="Remove line" aria-label="Remove line" className="text-muted hover:text-danger-fg" /></Td></tr>)}
        </tbody></table></div>
      </Panel>

      <div className="flex justify-end gap-3"><Button variant="secondary" onClick={() => navigate(editing ? `/budgeting/${id}` : '/budgeting')}>Cancel</Button><Button variant="primary" onClick={submit} loading={saveMutation.isPending} disabled={!fiscalYearId || !name.trim()}>{editing ? 'Save Draft' : 'Create Budget'}</Button></div>
    </div>
  );
}
