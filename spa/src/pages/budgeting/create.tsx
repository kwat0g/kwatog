import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
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
import { Plus, Trash2 } from 'lucide-react';
import type { FiscalYear } from '@/types/budgeting';

const MONTHS = [
  { key: 'jan', label: 'Jan' }, { key: 'feb', label: 'Feb' }, { key: 'mar', label: 'Mar' },
  { key: 'apr', label: 'Apr' }, { key: 'may', label: 'May' }, { key: 'jun', label: 'Jun' },
  { key: 'jul', label: 'Jul' }, { key: 'aug', label: 'Aug' }, { key: 'sep', label: 'Sep' },
  { key: 'oct', label: 'Oct' }, { key: 'nov', label: 'Nov' }, { key: 'dec', label: 'Dec' },
] as const;

interface LineItemForm {
  account_id: number;
  [key: string]: number;
}

const emptyLineItem = (): LineItemForm => ({
  account_id: 0,
  jan: 0, feb: 0, mar: 0, apr: 0, may: 0, jun: 0,
  jul: 0, aug: 0, sep: 0, oct: 0, nov: 0, dec: 0,
});

export default function BudgetCreatePage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const { data: fiscalYears, isLoading: yearsLoading } = useQuery<FiscalYear[]>({
    queryKey: ['fiscal-years'],
    queryFn: () => budgetingApi.fiscalYears(),
  });

  const { data: accounts } = useQuery({
    queryKey: ['accounts'],
    queryFn: () => accountsApi.list(),
  });

  const { data: departments } = useQuery({
    queryKey: ['departments'],
    queryFn: () => departmentsApi.tree(),
  });

  const [fiscalYearId, setFiscalYearId] = useState<number>(0);
  const [departmentId, setDepartmentId] = useState<number | null>(null);
  const [budgetType, setBudgetType] = useState('operating');
  const [name, setName] = useState('');
  const [lineItems, setLineItems] = useState<LineItemForm[]>([emptyLineItem()]);

  const createMutation = useMutation({
    mutationFn: async () => {
      if (!fiscalYearId) throw new Error('Please select a fiscal year.');
      if (!name.trim()) throw new Error('Please enter a budget name.');
      const validItems = lineItems.filter((li) => li.account_id > 0);
      if (validItems.length === 0) throw new Error('Please add at least one line item with an account.');
      return budgetingApi.create({
        fiscal_year_id: fiscalYearId,
        department_id: departmentId || null,
        budget_type: budgetType,
        name: name.trim(),
        line_items: validItems,
      });
    },
    onSuccess: (budget) => {
      queryClient.invalidateQueries({ queryKey: ['budgets'] });
      queryClient.invalidateQueries({ queryKey: ['budget-overview'] });
      toast.success('Budget created.');
      navigate(`/budgeting/${budget.id}`);
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Failed to create budget.');
    },
  });

  const updateLineItem = (index: number, field: string, value: number) => {
    setLineItems((prev) =>
      prev.map((li, i) => (i === index ? { ...li, [field]: value } : li)),
    );
  };

  const addLineItem = () => setLineItems((prev) => [...prev, emptyLineItem()]);
  const removeLineItem = (index: number) => {
    setLineItems((prev) => (prev.length > 1 ? prev.filter((_, i) => i !== index) : prev));
  };

  const calcAnnual = (li: LineItemForm) =>
    MONTHS.reduce((sum, m) => sum + (li[m.key] || 0), 0);

  const totalAllocated = lineItems.reduce((sum, li) => sum + calcAnnual(li), 0);

  if (yearsLoading) return <SkeletonDetail />;

  return (
    <div className="p-6 space-y-6">
      <PageHeader
        title="Create Budget"
        subtitle="Set up a new budget with monthly allocations per account"
        backTo="/budgeting"
        backLabel="Budgeting"
        breadcrumbs={[{ label: 'Budgeting', href: '/budgeting' }, { label: 'Create Budget' }]}
      />

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Budget Details */}
        <Panel title="Budget Details">
          <div className="space-y-4">
            <Select
              label="Fiscal Year"
              value={String(fiscalYearId)}
              onChange={(e) => setFiscalYearId(Number(e.target.value))}
              required
            >
              <option value={0}>Select fiscal year...</option>
              {(fiscalYears ?? []).map((fy) => (
                <option key={fy.id} value={fy.id}>
                  FY {fy.year} ({fy.status})
                </option>
              ))}
            </Select>

            <Input
              label="Budget Name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g. 2025 Operating Budget"
              required
            />

            <Select label="Budget Type" value={budgetType} onChange={(e) => setBudgetType(e.target.value)}>
              <option value="operating">Operating</option>
              <option value="capital">Capital</option>
              <option value="project">Project</option>
              <option value="department">Department</option>
            </Select>

            <Select label="Department (optional)" value={String(departmentId ?? '')} onChange={(e) => setDepartmentId(e.target.value ? Number(e.target.value) : null)}>
              <option value="">Company-wide</option>
              {(departments ?? []).map((dept: { id: string; name: string; code: string }) => (
                <option key={dept.id} value={dept.id}>{dept.name}</option>
              ))}
            </Select>
          </div>
        </Panel>

        {/* Summary */}
        <Panel title="Summary">
          <div className="space-y-3 text-sm">
            <div className="flex justify-between py-2 border-b border-default/50">
              <span className="text-muted">Fiscal Year</span>
              <span className="font-medium">
                {fiscalYears?.find((fy) => Number(fy.id) === fiscalYearId)?.year ?? '—'}
              </span>
            </div>
            <div className="flex justify-between py-2 border-b border-default/50">
              <span className="text-muted">Budget Type</span>
              <span className="font-medium capitalize">{budgetType}</span>
            </div>
            <div className="flex justify-between py-2 border-b border-default/50">
              <span className="text-muted">Department</span>
              <span className="font-medium">
                {departmentId
                  ? departments?.find((d: { id: string }) => d.id === String(departmentId))?.name ?? '—'
                  : 'Company-wide'}
              </span>
            </div>
            <div className="flex justify-between py-2">
              <span className="text-muted">Total Lines</span>
              <span className="font-medium">{lineItems.filter((li) => li.account_id > 0).length}</span>
            </div>
            <div className="flex justify-between py-2 border-t border-default">
              <span className="font-semibold">Total Allocated</span>
              <span className="font-mono tabular-nums font-bold text-lg">₱{(totalAllocated / 1_000_000).toFixed(2)}M</span>
            </div>
          </div>
        </Panel>
      </div>

      {/* Line Items */}
      <Panel
        title="Line Items"
        meta={
          <Button size="sm" onClick={addLineItem}><Plus size={14} /> Add Line</Button>
        }
      >
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-default text-left text-xs uppercase tracking-wider text-muted">
                <th className="py-2 pr-3 min-w-[180px] sticky left-0 bg-canvas">Account</th>
                {MONTHS.map((m) => (
                  <th key={m.key} className="py-2 pr-2 text-right font-mono w-[60px]">{m.label}</th>
                ))}
                <th className="py-2 pr-3 text-right w-[80px]">Annual</th>
                <th className="py-2 w-[40px]"></th>
              </tr>
            </thead>
            <tbody>
              {lineItems.map((li, i) => (
                <tr key={i} className="border-b border-default/50 hover:bg-elevated/50 transition-colors">
                  <td className="py-1.5 pr-3 sticky left-0 bg-canvas">
                    <select
                      value={li.account_id}
                      onChange={(e) => updateLineItem(i, 'account_id', Number(e.target.value))}
                      className="w-full text-sm border border-default rounded px-2 py-1 bg-canvas focus:outline-none focus:ring-1 focus:ring-accent"
                    >
                      <option value={0}>Select account...</option>
                      {(accounts?.data ?? []).map((acct: { id: string; code: string; name: string }) => (
                        <option key={acct.id} value={acct.id}>{acct.code} — {acct.name}</option>
                      ))}
                    </select>
                  </td>
                  {MONTHS.map((m) => (
                    <td key={m.key} className="py-1.5 pr-2">
                      <input
                        type="number"
                        value={li[m.key]}
                        onChange={(e) => updateLineItem(i, m.key, Number(e.target.value) || 0)}
                        className="w-full text-right text-xs font-mono border border-default rounded px-1 py-1 bg-canvas focus:outline-none focus:ring-1 focus:ring-accent"
                        placeholder="0"
                      />
                    </td>
                  ))}
                  <td className="py-1.5 pr-3 text-right font-mono tabular-nums font-medium">
                    ₱{(calcAnnual(li) / 1_000).toFixed(0)}K
                  </td>
                  <td className="py-1.5">
                    <button
                      onClick={() => removeLineItem(i)}
                      className="p-1 text-muted hover:text-danger transition-colors"
                      title="Remove line"
                    >
                      <Trash2 size={14} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {lineItems.length === 0 && (
          <p className="text-sm text-muted py-4 text-center">No line items. Click "Add Line" to begin.</p>
        )}
      </Panel>

      {/* Submit */}
      <div className="flex justify-end gap-3">
        <Button variant="secondary" onClick={() => navigate('/budgeting')}>
          Cancel
        </Button>
        <Button
          variant="primary"
          onClick={() => createMutation.mutate()}
          loading={createMutation.isPending}
          disabled={!fiscalYearId || !name.trim()}
        >
          Create Budget
        </Button>
      </div>
    </div>
  );
}
