import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Download, Printer } from 'lucide-react';
import { statementsApi } from '@/api/accounting/statements';
import { downloadAuthenticatedFile } from '@/api/download';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import { Td, tableCls, trCls } from '@/components/ui/table-cells';
import { cn } from '@/lib/cn';

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
        backTo="/accounting/journal-entries"
        backLabel="Journal Entries"
        actions={
          <div className="flex gap-1.5">
            <Button variant="secondary" size="sm" icon={<Download size={14} />} onClick={() => void downloadAuthenticatedFile(statementsApi.csvUrl('income-statement', { from, to }), { errorMessage: 'Failed to export income statement.' })}>CSV</Button>
            <Button variant="secondary" size="sm" icon={<Printer size={14} />} onClick={() => void downloadAuthenticatedFile(statementsApi.pdfUrl('income-statement', { from, to }), { openInNewTab: true, errorMessage: 'Failed to generate income statement PDF.' })}>PDF</Button>
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
            <table className={tableCls}>
              <tbody>
                <Section label="REVENUE" rows={data.revenue.accounts} totalLabel="Total Revenue" total={data.revenue.total} />
                {data.cogs.accounts.length > 0 && <Section label="COST OF GOODS SOLD" rows={data.cogs.accounts} totalLabel="Total COGS" total={data.cogs.total} />}
                <tr className={cn(trCls, 'border-t-2 border-primary font-medium')}><Td>GROSS PROFIT</Td><Td align="right" mono>{formatPeso(data.gross_profit)}</Td></tr>
                <Section label="OPERATING EXPENSES" rows={data.operating_expenses.accounts} totalLabel="Total OpEx" total={data.operating_expenses.total} />
                <tr className={cn(trCls, 'border-t-2 border-primary font-medium')}>
                  <Td className="text-base">NET INCOME</Td>
                  <Td align="right" mono className={' text-base ' + (Number(data.net_income) >= 0 ? 'text-success-fg' : 'text-danger-fg')}>{formatPeso(data.net_income)}</Td>
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
      <tr><Td className="bg-subtle text-2xs uppercase tracking-wider text-muted font-medium" colSpan={2}>{label}</Td></tr>
      {rows.map((r) => (
        <tr key={r.code} className={trCls}>
          <Td><span className="font-mono text-muted">{r.code}</span> · {r.name}</Td>
          <Td align="right" mono>{formatPeso(r.amount)}</Td>
        </tr>
      ))}
      <tr className={cn(trCls, 'font-medium')}><Td>{totalLabel}</Td><Td align="right" mono>{formatPeso(total)}</Td></tr>
    </>
  );
}
