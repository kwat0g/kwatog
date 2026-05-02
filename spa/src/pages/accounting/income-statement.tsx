import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Download, Printer } from 'lucide-react';
import { statementsApi } from '@/api/accounting/statements';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';

export default function IncomeStatementPage() {
  const today = new Date();
  const yearStart = new Date(today.getFullYear(), 0, 1).toISOString().slice(0, 10);
  const yearEnd   = new Date(today.getFullYear(), 11, 31).toISOString().slice(0, 10);
  const [from, setFrom] = useState(yearStart);
  const [to,   setTo]   = useState(yearEnd);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['accounting', 'statements', 'income-statement', from, to],
    queryFn: () => statementsApi.incomeStatement({ from, to }),
  });

  return (
    <div>
      <PageHeader
        title="Income Statement"
        actions={
          <div className="flex gap-1.5">
            <a href={statementsApi.csvUrl('income-statement', { from, to })}>
              <Button variant="secondary" size="sm" icon={<Download size={14} />}>CSV</Button>
            </a>
            <a href={statementsApi.pdfUrl('income-statement', { from, to })} target="_blank" rel="noreferrer">
              <Button variant="secondary" size="sm" icon={<Printer size={14} />}>PDF</Button>
            </a>
          </div>
        }
      />

      <div className="px-5 py-3 border-b border-default flex items-end gap-3">
        <Input label="From" type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-44" />
        <Input label="To"   type="date" value={to}   onChange={(e) => setTo(e.target.value)}   className="w-44" />
      </div>

      {isLoading && !data && <SkeletonTable columns={2} rows={10} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to generate income statement" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && (
        <div className="px-5 py-4">
          <div className="border border-default rounded-md overflow-hidden max-w-3xl">
            <table className="w-full text-sm">
              <tbody>
                <Section label="REVENUE" rows={data.revenue.accounts} totalLabel="Total Revenue" total={data.revenue.total} />
                {data.cogs.accounts.length > 0 && <Section label="COST OF GOODS SOLD" rows={data.cogs.accounts} totalLabel="Total COGS" total={data.cogs.total} />}
                <tr className="h-9 border-t-2 border-primary font-medium"><td className="px-2.5">GROSS PROFIT</td><td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(data.gross_profit)}</td></tr>
                <Section label="OPERATING EXPENSES" rows={data.operating_expenses.accounts} totalLabel="Total OpEx" total={data.operating_expenses.total} />
                <tr className="h-10 border-t-2 border-primary border-b-2 border-primary font-medium">
                  <td className="px-2.5 text-base">NET INCOME</td>
                  <td className={'px-2.5 text-right font-mono tabular-nums text-base ' + (Number(data.net_income) >= 0 ? 'text-success-fg' : 'text-danger-fg')}>{formatPeso(data.net_income)}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}

function Section({ label, rows, totalLabel, total }: { label: string; rows: { code: string; name: string; amount: string }[]; totalLabel: string; total: string }) {
  return (
    <>
      <tr><td colSpan={2} className="px-2.5 py-1.5 bg-subtle text-2xs uppercase tracking-wider text-muted font-medium">{label}</td></tr>
      {rows.map((r) => (
        <tr key={r.code} className="h-7 border-b border-subtle">
          <td className="px-2.5 pl-6"><span className="font-mono text-muted">{r.code}</span> · {r.name}</td>
          <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(r.amount)}</td>
        </tr>
      ))}
      <tr className="h-7 border-b border-default font-medium"><td className="px-2.5">{totalLabel}</td><td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(total)}</td></tr>
    </>
  );
}
