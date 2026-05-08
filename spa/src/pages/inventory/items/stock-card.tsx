import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Download, Printer } from 'lucide-react';
import { stockCardApi } from '@/api/inventory/stockCard';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';

function todayStr(): string {
  const d = new Date();
  return d.toISOString().slice(0, 10);
}
function monthAgoStr(): string {
  const d = new Date();
  d.setMonth(d.getMonth() - 1);
  return d.toISOString().slice(0, 10);
}
function fmtNum(s: string, decimals = 3): string {
  const n = Number(s);
  if (Number.isNaN(n)) return s;
  return n.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}
function fmtMoney(s: string): string {
  return Number(s).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export default function StockCardPage() {
  const { id = '' } = useParams<{ id: string }>();
  const [from, setFrom] = useState(monthAgoStr);
  const [to, setTo]     = useState(todayStr);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'stock-card', id, from, to],
    queryFn: () => stockCardApi.show(id, { from, to }),
    placeholderData: (prev) => prev,
  });

  return (
    <div>
      <PageHeader
        title="Stock card"
        backTo="/inventory/items"
        backLabel="Items"
        subtitle={
          data
            ? `${data.item.code} · ${data.item.name}`
            : undefined
        }
        actions={
          <div className="flex gap-2">
            <Button variant="secondary" size="sm" icon={<Download size={14} />} disabled>
              Export CSV
            </Button>
            <Button variant="secondary" size="sm" icon={<Printer size={14} />} onClick={() => window.print()}>
              Print
            </Button>
          </div>
        }
      />

      {/* Range filter */}
      <div className="px-5 py-3 border-b border-default flex flex-wrap items-end gap-3">
        <Input
          label="From"
          type="date"
          value={from}
          onChange={(e: { target: { value: string } }) => setFrom(e.target.value)}
        />
        <Input
          label="To"
          type="date"
          value={to}
          onChange={(e: { target: { value: string } }) => setTo(e.target.value)}
        />
        <Button variant="secondary" size="sm" onClick={() => refetch()}>
          Apply
        </Button>
      </div>

      {/* States */}
      {isLoading && !data && (
        <div className="px-5 py-4">
          <SkeletonTable columns={7} rows={10} />
        </div>
      )}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load stock card"
          description="Something went wrong loading movements."
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {data && data.rows.length === 0 && (
        <div className="px-5 py-4">
          {/* Show summary cards even when no rows in range */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
            <StatCard
              label="Opening balance"
              value={`${fmtNum(data.opening.balance)} ${data.item.unit_of_measure}`}
              helper={`₱ ${fmtMoney(data.opening.value)}`}
            />
            <StatCard
              label="Closing balance"
              value={`${fmtNum(data.closing.balance)} ${data.item.unit_of_measure}`}
              helper={`₱ ${fmtMoney(data.closing.value)}`}
            />
            <StatCard
              label="Weighted avg cost"
              value={`₱ ${fmtMoney(data.closing.weighted_avg)}`}
            />
          </div>
          <EmptyState
            icon="package"
            title="No movements in this range"
            description="Try widening the date range or selecting a different item."
          />
        </div>
      )}

      {data && data.rows.length > 0 && (
        <div className="px-5 py-4">
          {/* Summary */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
            <StatCard
              label="Opening balance"
              value={`${fmtNum(data.opening.balance)} ${data.item.unit_of_measure}`}
              helper={`₱ ${fmtMoney(data.opening.value)} · avg ₱ ${fmtMoney(data.opening.weighted_avg)}`}
            />
            <StatCard
              label="Closing balance"
              value={`${fmtNum(data.closing.balance)} ${data.item.unit_of_measure}`}
              helper={`₱ ${fmtMoney(data.closing.value)} · avg ₱ ${fmtMoney(data.closing.weighted_avg)}`}
            />
            <StatCard
              label="Movements"
              value={String(data.rows.length)}
              helper={`${data.from} → ${data.to}`}
            />
          </div>

          {/* Movement ledger */}
          <div className="overflow-x-auto border border-default rounded-md">
            <table className="w-full border-collapse text-xs">
              <thead className="bg-subtle">
                <tr>
                  <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Date</th>
                  <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Reference</th>
                  <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Movement</th>
                  <th className="h-8 px-2.5 text-right text-2xs uppercase tracking-wider text-muted font-medium">In</th>
                  <th className="h-8 px-2.5 text-right text-2xs uppercase tracking-wider text-muted font-medium">Out</th>
                  <th className="h-8 px-2.5 text-right text-2xs uppercase tracking-wider text-muted font-medium">Unit cost (₱)</th>
                  <th className="h-8 px-2.5 text-right text-2xs uppercase tracking-wider text-muted font-medium">Balance</th>
                </tr>
              </thead>
              <tbody>
                {data.rows.map((row) => (
                  <tr key={row.id} className="h-8 border-t border-subtle hover:bg-subtle">
                    <td className="px-2.5 font-mono tabular-nums text-muted">
                      {row.date ? row.date.slice(0, 16).replace('T', ' ') : '—'}
                    </td>
                    <td className="px-2.5 font-mono">
                      {row.reference_url ? (
                        <Link to={row.reference_url} className="text-accent hover:underline">
                          {row.reference_id ?? row.reference_type}
                        </Link>
                      ) : (
                        <span className="text-muted">{row.reference_id ?? '—'}</span>
                      )}
                    </td>
                    <td className="px-2.5">{row.movement_type}</td>
                    <td className="px-2.5 text-right font-mono tabular-nums">
                      {Number(row.in) > 0 ? fmtNum(row.in) : '—'}
                    </td>
                    <td className="px-2.5 text-right font-mono tabular-nums">
                      {Number(row.out) > 0 ? fmtNum(row.out) : '—'}
                    </td>
                    <td className="px-2.5 text-right font-mono tabular-nums">{fmtMoney(row.unit_cost)}</td>
                    <td className="px-2.5 text-right font-mono tabular-nums font-medium">{fmtNum(row.balance)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
