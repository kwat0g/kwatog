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
        actions={
          <div className="flex gap-1.5">
            <a href={statementsApi.csvUrl('trial-balance', { from, to })}>
              <Button variant="secondary" size="sm" icon={<Download size={14} />}>CSV</Button>
            </a>
            <a href={statementsApi.pdfUrl('trial-balance', { from, to })} target="_blank" rel="noreferrer">
              <Button variant="secondary" size="sm" icon={<Printer size={14} />}>PDF</Button>
            </a>
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
            <table className="w-full text-sm">
              <thead className="text-2xs uppercase tracking-wider text-muted">
                <tr className="border-b border-default bg-subtle">
                  <th className="h-8 px-2.5 text-left">Code</th>
                  <th className="h-8 px-2.5 text-left">Account</th>
                  <th className="h-8 px-2.5 text-left">Type</th>
                  <th className="h-8 px-2.5 text-right">Debit</th>
                  <th className="h-8 px-2.5 text-right">Credit</th>
                </tr>
              </thead>
              <tbody>
                {data.accounts.map((a) => (
                  <tr key={a.code} className="h-8 border-b border-subtle hover:bg-subtle">
                    <td className="px-2.5 font-mono tabular-nums text-muted">{a.code}</td>
                    <td className="px-2.5">{a.name}</td>
                    <td className="px-2.5 text-xs text-muted uppercase tracking-wider">{a.type}</td>
                    <td className="px-2.5 text-right font-mono tabular-nums">{Number(a.debit_total)  > 0 ? formatPeso(a.debit_total)  : ''}</td>
                    <td className="px-2.5 text-right font-mono tabular-nums">{Number(a.credit_total) > 0 ? formatPeso(a.credit_total) : ''}</td>
                  </tr>
                ))}
                <tr className="h-9 border-t-2 border-primary font-medium">
                  <td colSpan={3} className="px-2.5 text-right">Totals</td>
                  <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(data.totals.debit)}</td>
                  <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(data.totals.credit)}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
