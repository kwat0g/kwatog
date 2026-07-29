import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Download, Printer } from 'lucide-react';
import { statementsApi } from '@/api/accounting/statements';
import { downloadAuthenticatedFile } from '@/api/download';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import { Td, tableCls, totalsTrCls, trCls } from '@/components/ui/table-cells';

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
        backTo="/accounting/journal-entries"
        backLabel="Journal Entries"
        actions={
          <div className="flex gap-1.5">
            <Button variant="secondary" size="sm" icon={<Download size={14} />} onClick={() => void downloadAuthenticatedFile(statementsApi.csvUrl('balance-sheet', { as_of: asOf }), { errorMessage: 'Failed to export balance sheet.' })}>CSV</Button>
            <Button variant="secondary" size="sm" icon={<Printer size={14} />} onClick={() => void downloadAuthenticatedFile(statementsApi.pdfUrl('balance-sheet', { as_of: asOf }), { openInNewTab: true, errorMessage: 'Failed to generate balance sheet PDF.' })}>PDF</Button>
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
          <div className="col-span-3 flex justify-end gap-5 pt-2 border-t border-default text-sm font-mono tabular-nums">
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
      <table className={tableCls}>
        <tbody>
          {rows.length === 0 && <tr className={trCls}><Td className="text-muted italic" colSpan={2}>No movement</Td></tr>}
          {rows.map((r) => (
            <tr key={r.code} className={trCls}>
              <Td><span className="font-mono text-muted">{r.code}</span> {r.name}</Td>
              <Td align="right" mono>{formatPeso(r.amount)}</Td>
            </tr>
          ))}
          <tr className={totalsTrCls}><Td>Total</Td><Td align="right" mono>{formatPeso(total)}</Td></tr>
        </tbody>
      </table>
    </div>
  );
}
