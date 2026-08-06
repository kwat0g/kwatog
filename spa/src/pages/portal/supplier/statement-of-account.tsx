import { useCallback, useEffect, useState } from 'react';
import { supplierPortalApi } from '@/api/b2b/supplier';
import type { VendorStatementOfAccount } from '@/types/b2b';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

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

 const bucketKeys = soa ? soa.aging_bucket_options.map((option) => option.value) : [];
 const bucketLabels = new Map<string, string>((soa?.aging_bucket_options ?? []).map((option) => [option.value, option.label]));

 return (
 <div>
 <PageHeader
 title="Statement of Account"
 subtitle={soa ? `${soa.vendor_name ?? 'Vendor'} · As of ${soa.as_of_date}` : undefined}
 backTo="/portal/supplier"
 backLabel="Portal"
 />

 {/* One padded body holds every state, so loading and loaded agree on width. */}
 <div className="px-5 py-4 space-y-4 max-w-5xl">
 {loading && <SkeletonBlock className="h-96 rounded-md" />}

 {!loading && !soa && (
 <EmptyState
 icon="alert-circle"
 title="Could not load statement"
 description="Failed to load the statement of account. Please try again."
 action={<Button variant="secondary" onClick={() => fetch()}>Retry</Button>}
 />
 )}

 {!loading && soa && (
 <>
 {/* Summary row */}
 <div className="flex items-baseline gap-2">
 <span className="text-2xl font-medium font-mono tabular-nums">{soa.total_outstanding}</span>
 <span className="text-xs text-muted">Total outstanding</span>
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
 <Panel key={key} bodyClassName="p-3 space-y-1">
 <p className="text-2xs text-muted uppercase tracking-wider">{bucketLabels.get(key) ?? key}</p>
 <p className={`text-base font-medium font-mono tabular-nums ${bucketColors[key] ?? ''}`}>{amount}</p>
 <p className="text-2xs text-muted">{pct}% of total</p>
 </Panel>
 );
 })}
 </div>

 {/* Open bills table */}
 <Panel title={`Open Bills (${soa.open_bills.length})`} noPadding>
 {soa.open_bills.length === 0 ? (
 <EmptyState icon="circle-check" title="No open bills" description="All bills are paid." />
 ) : (
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
 <Chip variant={chipVariantForStatus(bill.status)}>{bill.status_label ?? bill.status}</Chip>
 </Td>
 <Td align="center">
 <span className={`text-2xs font-medium ${
 bucketColors[bill.aging_bucket] ?? 'text-muted'
 }`}>
 {bucketLabels.get(bill.aging_bucket) ?? bill.aging_bucket}
 </span>
 </Td>
 </tr>
 ))}
 </tbody>
 </table>
 )}
 </Panel>
 </>
 )}
 </div>
 </div>
 );
}
