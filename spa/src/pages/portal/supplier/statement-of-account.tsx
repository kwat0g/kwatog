import { useCallback, useEffect, useState } from 'react';
import { supplierPortalApi } from '@/api/b2b/supplier';
import type { VendorStatementOfAccount } from '@/types/b2b';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

const bucketLabels: Record<string, string> = {
  current: 'Current (Not Due)',
  d1_30: '1–30 Days',
  d31_60: '31–60 Days',
  d61_90: '61–90 Days',
  d91_plus: '91+ Days',
};

const bucketColors: Record<string, string> = {
  current: 'text-success',
  d1_30: 'text-warning',
  d31_60: 'text-warning',
  d61_90: 'text-danger',
  d91_plus: 'text-danger',
};

export default function SupplierStatementOfAccountPage() {
  const [soa, setSoa] = useState<VendorStatementOfAccount | null>(null);
  const [loading, setLoading] = useState(true);

  const fetch = useCallback(async () => {
    setLoading(true);
    try {
      const data = await supplierPortalApi.statementOfAccount();
      setSoa(data);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetch(); }, [fetch]);

  if (loading) {
    return (
      <div className="space-y-4">
        <SkeletonBlock className="h-8 w-48" />
        <div className="grid grid-cols-5 gap-3">
          {Array.from({ length: 5 }).map((_, i) => <SkeletonBlock key={i} className="h-24" />)}
        </div>
        <SkeletonBlock className="h-64" />
      </div>
    );
  }

  if (!soa) {
    return <EmptyState icon="file-question" title="Could not load statement" description="Failed to load the statement of account. Please try again." />;
  }

  const bucketKeys = ['current', 'd1_30', 'd31_60', 'd61_90', 'd91_plus'] as const;

  return (
    <div className="max-w-5xl space-y-6">
      {/* Header */}
      <div>
        <h2 className="text-lg font-medium">Statement of Account</h2>
        <p className="text-xs text-muted">
          {soa.vendor_name ?? 'Vendor'} — as of {soa.as_of_date}
        </p>
      </div>

      {/* Summary row */}
      <div className="flex items-baseline gap-2">
        <span className="text-2xl font-medium">{soa.total_outstanding}</span>
        <span className="text-xs text-muted">PHP total outstanding</span>
      </div>

      {/* Aging buckets */}
      <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
        {bucketKeys.map((key) => {
          const amount = soa.aging_buckets[key];
          const parsed = parseFloat(amount);
          const pct = soa.total_outstanding && parseFloat(soa.total_outstanding) > 0
            ? ((parsed / parseFloat(soa.total_outstanding)) * 100).toFixed(1)
            : '0.0';
          return (
            <Panel key={key} className="p-3 space-y-1">
              <p className="text-2xs text-muted uppercase tracking-wider">{bucketLabels[key]}</p>
              <p className={`text-base font-medium ${bucketColors[key] ?? ''}`}>{amount}</p>
              <p className="text-2xs text-muted">{pct}% of total</p>
            </Panel>
          );
        })}
      </div>

      {/* Open bills table */}
      <Panel className="overflow-hidden">
        <h3 className="text-sm font-medium px-4 pt-3 pb-2 border-b border-border">
          Open Bills ({soa.open_bills.length})
        </h3>
        {soa.open_bills.length === 0 ? (
          <div className="p-5">
            <EmptyState icon="circle-check" title="No open bills" description="All bills are paid." />
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className={tableCls}>
              <thead>
                <tr className={theadTrCls}>
                  <Th>Bill #</Th>
                  <Th>PO</Th>
                  <Th>Date</Th>
                  <Th>Due Date</Th>
                  <Th align="right">Total</Th>
                  <Th align="right">Balance</Th>
                  <Th align="center">Status</Th>
                  <Th align="center">Bucket</Th>
                </tr>
              </thead>
              <tbody>
                {soa.open_bills.map((bill) => (
                  <tr key={bill.id} className={trCls}>
                    <Td className="font-medium">{bill.bill_number}</Td>
                    <Td className="text-muted">{bill.purchase_order?.po_number ?? '—'}</Td>
                    <Td className="text-muted">{bill.date ?? '—'}</Td>
                    <Td className="text-muted">{bill.due_date ?? '—'}</Td>
                    <Td align="right" mono>{bill.total_amount}</Td>
                    <Td align="right" mono className="font-medium">{bill.balance}</Td>
                    <Td align="center">
                      <Chip variant={chipVariantForStatus(bill.status)}>{bill.status}</Chip>
                    </Td>
                    <Td align="center">
                      <span className={`text-2xs font-medium ${
                        bucketColors[bill.aging_bucket] ?? 'text-muted'
                      }`}>
                        {bucketLabels[bill.aging_bucket] ?? bill.aging_bucket}
                      </span>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>
    </div>
  );
}
