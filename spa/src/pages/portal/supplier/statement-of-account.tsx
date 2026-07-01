import { useCallback, useEffect, useState } from 'react';
import { supplierPortalApi } from '@/api/b2b/supplier';
import type { VendorStatementOfAccount } from '@/types/b2b';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';

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
        <h2 className="text-lg font-semibold">Statement of Account</h2>
        <p className="text-xs text-muted">
          {soa.vendor_name ?? 'Vendor'} — as of {soa.as_of_date}
        </p>
      </div>

      {/* Summary row */}
      <div className="flex items-baseline gap-2">
        <span className="text-2xl font-bold">{soa.total_outstanding}</span>
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
              <p className={`text-base font-bold ${bucketColors[key] ?? ''}`}>{amount}</p>
              <p className="text-2xs text-muted">{pct}% of total</p>
            </Panel>
          );
        })}
      </div>

      {/* Open bills table */}
      <Panel className="overflow-hidden">
        <h3 className="text-sm font-semibold px-4 pt-3 pb-2 border-b border-border">
          Open Bills ({soa.open_bills.length})
        </h3>
        {soa.open_bills.length === 0 ? (
          <div className="p-6">
            <EmptyState icon="circle-check" title="No open bills" description="All bills are paid." />
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b border-border text-muted">
                  <th className="text-left px-4 py-2 font-medium">Bill #</th>
                  <th className="text-left px-4 py-2 font-medium">PO</th>
                  <th className="text-left px-4 py-2 font-medium">Date</th>
                  <th className="text-left px-4 py-2 font-medium">Due Date</th>
                  <th className="text-right px-4 py-2 font-medium">Total</th>
                  <th className="text-right px-4 py-2 font-medium">Balance</th>
                  <th className="text-center px-4 py-2 font-medium">Status</th>
                  <th className="text-center px-4 py-2 font-medium">Bucket</th>
                </tr>
              </thead>
              <tbody>
                {soa.open_bills.map((bill) => (
                  <tr key={bill.id} className="border-b border-border/50 hover:bg-subtle/50 transition-colors">
                    <td className="px-4 py-2.5 font-medium">{bill.bill_number}</td>
                    <td className="px-4 py-2.5 text-muted">{bill.purchase_order?.po_number ?? '—'}</td>
                    <td className="px-4 py-2.5 text-muted">{bill.date ?? '—'}</td>
                    <td className="px-4 py-2.5 text-muted">{bill.due_date ?? '—'}</td>
                    <td className="px-4 py-2.5 text-right">{bill.total_amount}</td>
                    <td className="px-4 py-2.5 text-right font-semibold">{bill.balance}</td>
                    <td className="px-4 py-2.5 text-center">
                      <Chip variant={chipVariantForStatus(bill.status)}>{bill.status}</Chip>
                    </td>
                    <td className="px-4 py-2.5 text-center">
                      <span className={`text-2xs font-medium ${
                        bucketColors[bill.aging_bucket] ?? 'text-muted'
                      }`}>
                        {bucketLabels[bill.aging_bucket] ?? bill.aging_bucket}
                      </span>
                    </td>
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
