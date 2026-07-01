import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { formatPeso } from '@/lib/formatNumber';

export default function CustomerInvoicesPage() {
  const { data: invoices, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'invoices'],
    queryFn: () => customerPortalApi.listInvoices(),
    placeholderData: (prev) => prev,
  });

  if (isLoading) return <SkeletonBlock className="h-64 rounded-lg" />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load invoices" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;

  return (
    <Panel title="Invoices">
      {invoices && invoices.length > 0 ? (
        <table className="w-full text-xs">
          <thead>
            <tr className="border-b border-border text-muted">
              <th className="text-left py-2 px-3 font-medium">Invoice #</th>
              <th className="text-left py-2 px-3 font-medium">Date</th>
              <th className="text-right py-2 px-3 font-medium">Amount</th>
              <th className="text-right py-2 px-3 font-medium">Balance</th>
              <th className="text-left py-2 px-3 font-medium">Due</th>
              <th className="text-right py-2 px-3 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            {invoices.map((inv) => (
              <tr key={inv.id} className="border-b border-border/50 hover:bg-subtle/50 transition-colors">
                <td className="py-2.5 px-3">
                  <Link to={`/portal/customer/invoices/${inv.id}`} className="font-mono text-accent hover:underline font-medium">
                    {inv.invoice_number}
                  </Link>
                </td>
                <td className="py-2.5 px-3 text-muted">{inv.date ?? '—'}</td>
                <td className="py-2.5 px-3 text-right font-mono">{formatPeso(inv.total_amount)}</td>
                <td className="py-2.5 px-3 text-right font-mono">{formatPeso(inv.balance)}</td>
                <td className="py-2.5 px-3 text-muted">{inv.due_date ?? '—'}</td>
                <td className="py-2.5 px-3 text-right">
                  <Chip variant={chipVariantForStatus(inv.status)}>{inv.status}</Chip>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      ) : (
        <EmptyState icon="receipt" title="No invoices" description="Your invoices will appear here once issued." />
      )}
    </Panel>
  );
}
