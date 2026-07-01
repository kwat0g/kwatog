import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { formatPeso } from '@/lib/formatNumber';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';

const BUCKET_LABELS: Record<string, string> = {
  current: 'Current',
  d1_30: '1–30 Days',
  d31_60: '31–60 Days',
  d61_90: '61–90 Days',
  d91_plus: '91+ Days',
};

const BUCKET_COLORS: Record<string, string> = {
  current: 'text-success',
  d1_30: 'text-warning',
  d31_60: 'text-warning',
  d61_90: 'text-danger',
  d91_plus: 'text-danger',
};

export default function StatementOfAccountPage() {
  const { data: soa, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'statement-of-account'],
    queryFn: () => customerPortalApi.getStatementOfAccount(),
  });

  if (isLoading) return <SkeletonBlock className="h-96 rounded-lg" />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load statement" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
  if (!soa) return <EmptyState icon="receipt" title="Statement not available" />;

  return (
    <div className="space-y-4 max-w-5xl">
      <div className="flex items-center gap-3">
        <Link to="/portal/customer" className="text-muted hover:text-primary p-1 -ml-1">
          <ArrowLeft size={16} />
        </Link>
        <div>
          <h2 className="text-sm font-semibold">Statement of Account</h2>
          <p className="text-2xs text-muted">
            {soa.customer_name ?? 'Customer'} &middot; As of {soa.as_of_date}
          </p>
        </div>
      </div>

      {/* Total Outstanding */}
      <div className="grid grid-cols-1 md:grid-cols-5 gap-3">
        {Object.entries(soa.aging_buckets).map(([key, value]) => (
          <StatCard
            key={key}
            label={BUCKET_LABELS[key] ?? key}
            value={formatPeso(value)}
            className={BUCKET_COLORS[key]}
          />
        ))}
      </div>

      <div className="text-center p-3 bg-surface border border-default rounded-md">
        <span className="text-2xs uppercase tracking-wide text-muted">Total Outstanding</span>
        <p className="text-xl font-semibold font-mono text-primary mt-1">
          {formatPeso(soa.total_outstanding)}
        </p>
      </div>

      {/* Open Invoices */}
      <Panel title={`Open Invoices (${soa.open_invoices.length})`}>
        {soa.open_invoices.length > 0 ? (
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b border-border text-muted">
                <th className="text-left py-2 px-3 font-medium">Invoice #</th>
                <th className="text-left py-2 px-3 font-medium">Date</th>
                <th className="text-left py-2 px-3 font-medium">Due Date</th>
                <th className="text-right py-2 px-3 font-medium">Amount</th>
                <th className="text-right py-2 px-3 font-medium">Balance</th>
                <th className="text-right py-2 px-3 font-medium">Status</th>
              </tr>
            </thead>
            <tbody>
              {soa.open_invoices.map((inv) => (
                <tr key={inv.id} className="border-b border-border/50 hover:bg-subtle/50 transition-colors">
                  <td className="py-2 px-3">
                    <Link to={`/portal/customer/invoices/${inv.id}`} className="font-mono text-accent hover:underline">
                      {inv.invoice_number}
                    </Link>
                  </td>
                  <td className="py-2 px-3 text-muted">{inv.date ?? '—'}</td>
                  <td className="py-2 px-3 text-muted">{inv.due_date ?? '—'}</td>
                  <td className="py-2 px-3 text-right font-mono tabular-nums">{formatPeso(inv.total_amount)}</td>
                  <td className="py-2 px-3 text-right font-mono tabular-nums">{formatPeso(inv.balance)}</td>
                  <td className="py-2 px-3 text-right">
                    <Chip variant={chipVariantForStatus(inv.status)}>{inv.status}</Chip>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <EmptyState icon="receipt" title="All invoices are paid" description="No outstanding invoices at this time." />
        )}
      </Panel>
    </div>
  );
}
