import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Download, Printer } from 'lucide-react';
import { statementsApi } from '@/api/accounting/statements';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';

export default function BalanceSheetPage() {
  const [asOf, setAsOf] = useState(new Date().toISOString().slice(0, 10));

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['accounting', 'statements', 'balance-sheet', asOf],
    queryFn: () => statementsApi.balanceSheet({ as_of: asOf }),
  });

  return (
    <div>
      <PageHeader
        title="Balance Sheet"
        actions={
          <div className="flex gap-1.5">
            <a href={statementsApi.csvUrl('balance-sheet', { as_of: asOf })}>
              <Button variant="secondary" size="sm" icon={<Download size={14} />}>CSV</Button>
            </a>
            <a href={statementsApi.pdfUrl('balance-sheet', { as_of: asOf })} target="_blank" rel="noreferrer">
              <Button variant="secondary" size="sm" icon={<Printer size={14} />}>PDF</Button>
            </a>
          </div>
        }
      />

      <div className="px-5 py-3 border-b border-default flex items-end gap-3">
        <Input label="As of" type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} className="w-44" />
        {data && <Chip variant={data.balanced ? 'success' : 'danger'}>{data.balanced ? 'Balanced' : 'IMBALANCE'}</Chip>}
      </div>

      {isLoading && !data && <SkeletonTable columns={2} rows={10} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to generate balance sheet" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && (
        <div className="px-5 py-4 grid grid-cols-3 gap-4">
          <Section title="Assets" rows={data.assets.accounts} total={data.assets.total} />
          <Section title="Liabilities" rows={data.liabilities.accounts} total={data.liabilities.total} />
          <Section title="Equity" rows={data.equity.accounts} total={data.equity.total} />
          <div className="col-span-3 flex justify-end gap-8 pt-2 border-t border-default text-sm font-mono tabular-nums">
            <div>Total Assets: <span className="font-medium">{formatPeso(data.total_assets)}</span></div>
            <div>Total Liabilities + Equity: <span className="font-medium">{formatPeso(data.total_liabilities_equity)}</span></div>
          </div>
        </div>
      )}
    </div>
  );
}

function Section({ title, rows, total }: { title: string; rows: { code: string; name: string; amount: string }[]; total: string }) {
  return (
    <div className="border border-default rounded-md overflow-hidden">
      <div className="px-2.5 py-1.5 bg-subtle text-2xs uppercase tracking-wider text-muted font-medium border-b border-default">{title}</div>
      <table className="w-full text-sm">
        <tbody>
          {rows.length === 0 && <tr className="h-7"><td className="px-2.5 text-muted italic" colSpan={2}>No movement</td></tr>}
          {rows.map((r) => (
            <tr key={r.code} className="h-7 border-b border-subtle">
              <td className="px-2.5"><span className="font-mono text-muted">{r.code}</span> {r.name}</td>
              <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(r.amount)}</td>
            </tr>
          ))}
          <tr className="h-8 border-t-2 border-primary font-medium"><td className="px-2.5">Total</td><td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(total)}</td></tr>
        </tbody>
      </table>
    </div>
  );
}
