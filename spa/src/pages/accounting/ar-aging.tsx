import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Download } from 'lucide-react';
import { statementsApi } from '@/api/accounting/statements';
import { downloadAuthenticatedFile } from '@/api/download';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';

export default function ArAgingPage() {
  const [asOf, setAsOf] = useState(new Date().toISOString().slice(0, 10));

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['accounting', 'statements', 'ar-aging', asOf],
    queryFn: () => statementsApi.arAging({ as_of: asOf }),
    placeholderData: (prev) => prev,
  });

  return (
    <div>
      <PageHeader
        title="AR Aging"
        subtitle="Accounts Receivable"
        backTo="/accounting/journal-entries"
        backLabel="Journal Entries"
        actions={
          <div className="flex gap-1.5">
            <Button variant="secondary" size="sm" icon={<Download size={14} />} onClick={() => void downloadAuthenticatedFile(statementsApi.csvUrl('ar-aging', { as_of: asOf }), { errorMessage: 'Failed to export AR aging.' })}>CSV</Button>
          </div>
        }
      />

      <div className="px-5 py-3 border-b border-default flex items-end gap-3">
        <Input label="As of" type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} className="w-44" />
      </div>

      {isLoading && !data && <SkeletonTable columns={7} rows={10} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to generate AR aging" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.by_customer.length === 0 && <EmptyState icon="inbox" title="No outstanding receivables" />}
      {data && data.by_customer.length > 0 && (
        <div className="px-5 py-4">
          <div className="border border-default rounded-md overflow-hidden">
            <table className="w-full text-sm">
              <thead className="text-2xs uppercase tracking-wider text-muted">
                <tr className="border-b border-default bg-subtle">
                  <th className="h-8 px-2.5 text-left  text-2xs uppercase tracking-wider text-muted font-medium">Customer</th>
                  <th className="h-8 px-2.5 text-right text-2xs uppercase tracking-wider text-muted font-medium">Current</th>
                  <th className="h-8 px-2.5 text-right text-2xs uppercase tracking-wider text-muted font-medium">1-30</th>
                  <th className="h-8 px-2.5 text-right text-2xs uppercase tracking-wider text-muted font-medium">31-60</th>
                  <th className="h-8 px-2.5 text-right text-2xs uppercase tracking-wider text-muted font-medium">61-90</th>
                  <th className="h-8 px-2.5 text-right text-2xs uppercase tracking-wider text-muted font-medium">91+</th>
                  <th className="h-8 px-2.5 text-right text-2xs uppercase tracking-wider text-muted font-medium">Total</th>
                </tr>
              </thead>
              <tbody>
                {data.by_customer.map((r) => (
                  <tr key={r.customer_id} className="h-8 border-b border-subtle hover:bg-subtle">
                    <td className="px-2.5">{r.customer_name}</td>
                    <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(r.current)}</td>
                    <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(r.d1_30)}</td>
                    <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(r.d31_60)}</td>
                    <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(r.d61_90)}</td>
                    <td className={`px-2.5 text-right font-mono tabular-nums${Number(r.d91_plus) > 0 ? ' text-danger-fg font-medium' : ''}`}>{formatPeso(r.d91_plus)}</td>
                    <td className="px-2.5 text-right font-mono tabular-nums font-medium">{formatPeso(r.total)}</td>
                  </tr>
                ))}
                <tr className="h-9 border-t-2 border-primary font-medium">
                  <td className="px-2.5">TOTAL</td>
                  <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(data.buckets.current)}</td>
                  <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(data.buckets.d1_30)}</td>
                  <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(data.buckets.d31_60)}</td>
                  <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(data.buckets.d61_90)}</td>
                  <td className={`px-2.5 text-right font-mono tabular-nums${Number(data.buckets.d91_plus) > 0 ? ' text-danger-fg' : ''}`}>{formatPeso(data.buckets.d91_plus)}</td>
                  <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(data.buckets.total)}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
