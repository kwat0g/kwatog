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
import { Td, Th, tableCls, theadTrCls, totalsTrCls, trCls } from '@/components/ui/table-cells';

export default function TrialBalancePage() {
  const today = new Date();
  const monthStart = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().slice(0, 10);
  const monthEnd   = new Date(today.getFullYear(), today.getMonth() + 1, 0).toISOString().slice(0, 10);

  const [from, setFrom] = useState(monthStart);
  const [to,   setTo]   = useState(monthEnd);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['accounting', 'statements', 'trial-balance', from, to],
    queryFn: () => statementsApi.trialBalance({ from, to }),
  });

  return (
    <div>
      <PageHeader
        title="Trial Balance"
        backTo="/accounting/journal-entries"
        backLabel="Journal Entries"
        actions={
          <div className="flex gap-1.5">
            <Button variant="secondary" size="sm" icon={<Download size={14} />} onClick={() => void downloadAuthenticatedFile(statementsApi.csvUrl('trial-balance', { from, to }), { errorMessage: 'Failed to export trial balance.' })}>CSV</Button>
            <Button variant="secondary" size="sm" icon={<Printer size={14} />} onClick={() => void downloadAuthenticatedFile(statementsApi.pdfUrl('trial-balance', { from, to }), { openInNewTab: true, errorMessage: 'Failed to generate trial balance PDF.' })}>PDF</Button>
          </div>
        }
      />

      <div className="px-5 py-3 border-b border-default flex items-end gap-3">
        <Input label="From" type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-44" />
        <Input label="To"   type="date" value={to}   onChange={(e) => setTo(e.target.value)}   className="w-44" />
      </div>

      {isLoading && !data && <SkeletonTable columns={5} rows={10} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to generate trial balance" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.accounts.length === 0 && <EmptyState icon="inbox" title="No movement in this period" />}
      {data && data.accounts.length > 0 && (
        <div className="px-5 py-4">
          <div className="border border-default rounded-md overflow-hidden">
            <table className={tableCls}>
              <thead>
                <tr className={theadTrCls}>
                  <Th>Code</Th>
                  <Th>Account</Th>
                  <Th>Type</Th>
                  <Th align="right">Debit</Th>
                  <Th align="right">Credit</Th>
                </tr>
              </thead>
              <tbody>
                {data.accounts.map((a) => (
                  <tr key={a.code} className={trCls}>
                    <Td mono className="text-muted">{a.code}</Td>
                    <Td>{a.name}</Td>
                    <Td className="text-xs text-muted uppercase tracking-wider">{a.type}</Td>
                    <Td align="right" mono>{Number(a.debit_total)  > 0 ? formatPeso(a.debit_total)  : ''}</Td>
                    <Td align="right" mono>{Number(a.credit_total) > 0 ? formatPeso(a.credit_total) : ''}</Td>
                  </tr>
                ))}
                <tr className={totalsTrCls}>
                  <Td align="right" mono colSpan={3}>Totals</Td>
                  <Td align="right" mono>{formatPeso(data.totals.debit)}</Td>
                  <Td align="right" mono>{formatPeso(data.totals.credit)}</Td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
