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
import { Td, Th, tableCls, theadTrCls, totalsTrCls, trCls } from '@/components/ui/table-cells';

export default function ApAgingPage() {
    const [asOf, setAsOf] = useState(new Date().toISOString().slice(0, 10));

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['accounting', 'statements', 'ap-aging', asOf],
    queryFn: () => statementsApi.apAging({ as_of: asOf }),
    placeholderData: (prev) => prev,
  });

  return (
    <div>
      <PageHeader
        title="AP Aging"
        subtitle="Accounts Payable"
        backTo="/accounting/journal-entries"
        backLabel="Journal Entries"
        actions={
          <div className="flex gap-1.5">
            <Button variant="secondary" size="sm" icon={<Download size={14} />} onClick={() => void downloadAuthenticatedFile(statementsApi.csvUrl('ap-aging', { as_of: asOf }), { errorMessage: 'Failed to export AP aging.' })}>CSV</Button>
          </div>
        }
      />

      <div className="px-5 py-3 border-b border-default flex items-end gap-3">
        <Input label="As of" type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} className="w-44" />
      </div>

      {isLoading && !data && <SkeletonTable columns={7} rows={10} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to generate AP aging" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.by_vendor.length === 0 && <EmptyState icon="inbox" title="No outstanding payables" />}
      {data && data.by_vendor.length > 0 && (
        <div className="px-5 py-4">
          <div className="border border-default rounded-md overflow-hidden">
            <table className={tableCls}>
              <thead>
                <tr className={theadTrCls}>
                  <Th>Vendor</Th>
                  <Th align="right">Current</Th>
                  <Th align="right">1-30</Th>
                  <Th align="right">31-60</Th>
                  <Th align="right">61-90</Th>
                  <Th align="right">91+</Th>
                  <Th align="right">Total</Th>
                </tr>
              </thead>
              <tbody>
                {data.by_vendor.map((r) => (
                  <tr key={r.vendor_id} className={trCls}>
                    <Td>{r.vendor_name}</Td>
                    <Td align="right" mono>{formatPeso(r.current)}</Td>
                    <Td align="right" mono>{formatPeso(r.d1_30)}</Td>
                    <Td align="right" mono>{formatPeso(r.d31_60)}</Td>
                    <Td align="right" mono>{formatPeso(r.d61_90)}</Td>
                    <Td align="right" mono className={Number(r.d91_plus) > 0 ? ' text-danger-fg font-medium' : ''}>{formatPeso(r.d91_plus)}</Td>
                    <Td align="right" mono className="font-medium">{formatPeso(r.total)}</Td>
                  </tr>
                ))}
                <tr className={totalsTrCls}>
                  <Td>TOTAL</Td>
                  <Td align="right" mono>{formatPeso(data.buckets.current)}</Td>
                  <Td align="right" mono>{formatPeso(data.buckets.d1_30)}</Td>
                  <Td align="right" mono>{formatPeso(data.buckets.d31_60)}</Td>
                  <Td align="right" mono>{formatPeso(data.buckets.d61_90)}</Td>
                  <Td align="right" mono className={Number(data.buckets.d91_plus) > 0 ? ' text-danger-fg' : ''}>{formatPeso(data.buckets.d91_plus)}</Td>
                  <Td align="right" mono>{formatPeso(data.buckets.total)}</Td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
