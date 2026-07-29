import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { currencyApi } from '@/api/accounting/currency';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDecimal } from '@/lib/formatNumber';
import type { TranslatedLine } from '@/types/accounting';
import { Td, Th, tableCls, theadTrCls, totalsTrCls, trCls } from '@/components/ui/table-cells';
import { Tabs } from '@/components/ui/Tabs';
import { cn } from '@/lib/cn';

type Tab = 'balance-sheet' | 'income-statement' | 'trial-balance';

const TABS: { key: Tab; label: string }[] = [
  { key: 'balance-sheet', label: 'Balance Sheet' },
  { key: 'income-statement', label: 'Income Statement' },
  { key: 'trial-balance', label: 'Trial Balance' },
];

// Reporting currencies we hold rates for. JPY is the parent default.
const CURRENCIES = ['JPY', 'USD'];

export default function ParentPackPage() {
  const [tab, setTab] = useState<Tab>('balance-sheet');
  const [currency, setCurrency] = useState('JPY');
  const today = new Date().toISOString().slice(0, 10);
  const [asOf, setAsOf] = useState(today);
  const [from, setFrom] = useState(today.slice(0, 8) + '01');
  const [to, setTo] = useState(today);

  return (
    <div>
      <PageHeader
        title="Parent Reporting Pack"
        subtitle={`PHP books translated to ${currency} (current-rate method) for the Japanese parent`}
        backTo="/accounting/journal-entries"
        backLabel="Journal Entries"
      />

      <div className="px-5 pt-3">
        <Tabs
          items={TABS}
          value={tab}
          onChange={setTab}
          label="Financial statement"
          trailing={
            <Select
              fieldSize="sm"
              aria-label="Reporting currency"
              value={currency}
              onChange={(e) => setCurrency(e.target.value)}
              containerClassName="w-24"
            >
              {CURRENCIES.map((c) => <option key={c} value={c}>{c}</option>)}
            </Select>
          }
        />
      </div>

      <div className="px-5 py-3 border-b border-default flex items-end gap-3">
        {tab === 'balance-sheet' ? (
          <Input label="As of" type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} className="w-44" />
        ) : (
          <>
            <Input label="From" type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-44" />
            <Input label="To" type="date" value={to} onChange={(e) => setTo(e.target.value)} className="w-44" />
          </>
        )}
      </div>

      {tab === 'balance-sheet' && <TranslatedBalanceSheet asOf={asOf} currency={currency} />}
      {tab === 'income-statement' && <TranslatedIncomeStatement from={from} to={to} currency={currency} />}
      {tab === 'trial-balance' && <TranslatedTrialBalance from={from} to={to} currency={currency} />}
    </div>
  );
}

/** Shared: a missing-rate 422 surfaces as a helpful empty state, not a crash. */
function useTranslated<T>(key: unknown[], fn: () => Promise<T>) {
  return useQuery({ queryKey: key, queryFn: fn, retry: false });
}

function RateBadges({ closing, average }: { closing?: string; average?: string }) {
  return (
    <div className="flex gap-2">
      {closing !== undefined && <Chip variant="info">Closing rate: {Number(closing).toFixed(6)}</Chip>}
      {average !== undefined && <Chip variant="neutral">Average rate: {Number(average).toFixed(6)}</Chip>}
    </div>
  );
}

function ErrorState({ currency, refetch }: { currency: string; refetch: () => void }) {
  return (
    <EmptyState
      icon="alert-circle"
      title={`No ${currency} rate for this date`}
      description={`Add an FX rate on or before the report date in FX Rates, then retry.`}
      action={<Button variant="secondary" onClick={refetch}>Retry</Button>}
    />
  );
}

function TranslatedBalanceSheet({ asOf, currency }: { asOf: string; currency: string }) {
  const { data, isLoading, isError, refetch } = useTranslated(
    ['accounting', 'parent-pack', 'bs', asOf, currency],
    () => currencyApi.balanceSheet({ as_of: asOf, currency }),
  );

  if (isLoading) return <div className="px-5 py-4"><SkeletonTable columns={3} rows={10} /></div>;
  if (isError || !data) return <div className="px-5 py-4"><ErrorState currency={currency} refetch={refetch} /></div>;

  return (
    <div className="px-5 py-4 space-y-4">
      <div className="flex items-center justify-between">
        <RateBadges closing={data.closing_rate} average={data.average_rate} />
        <Chip variant={data.balanced ? 'success' : 'danger'}>{data.balanced ? 'Balanced (incl. CTA)' : 'IMBALANCE'}</Chip>
      </div>
      <div className="grid grid-cols-3 gap-4">
        <TransSection title="Assets" rows={data.assets.accounts} total={data.assets.total} currency={currency} />
        <TransSection title="Liabilities" rows={data.liabilities.accounts} total={data.liabilities.total} currency={currency} />
        <TransSection title="Equity (incl. CTA)" rows={data.equity.accounts} total={data.equity.total} currency={currency} highlightCode="3900" />
      </div>
      <div className="flex justify-end gap-5 pt-2 border-t border-default text-sm font-mono tabular-nums">
        <div>Total Assets: <span className="font-medium">{currency} {formatDecimal(data.total_assets)}</span></div>
        <div>Total Liab. + Equity: <span className="font-medium">{currency} {formatDecimal(data.total_liabilities_equity)}</span></div>
      </div>
    </div>
  );
}

function TranslatedIncomeStatement({ from, to, currency }: { from: string; to: string; currency: string }) {
  const { data, isLoading, isError, refetch } = useTranslated(
    ['accounting', 'parent-pack', 'is', from, to, currency],
    () => currencyApi.incomeStatement({ from, to, currency }),
  );

  if (isLoading) return <div className="px-5 py-4"><SkeletonTable columns={3} rows={10} /></div>;
  if (isError || !data) return <div className="px-5 py-4"><ErrorState currency={currency} refetch={refetch} /></div>;

  const s = data.statement;
  return (
    <div className="px-5 py-4 space-y-4">
      <RateBadges average={data.average_rate} />
      <TransSection title="Revenue" rows={s.revenue.accounts} total={s.revenue.total} currency={currency} />
      <TransSection title="Cost of Goods Sold" rows={s.cogs.accounts} total={s.cogs.total} currency={currency} />
      <div className="flex justify-end text-sm font-mono tabular-nums border-t border-subtle pt-1.5">
        Gross Profit: <span className="font-medium ml-2">{currency} {formatDecimal(s.gross_profit)}</span>
      </div>
      <TransSection title="Operating Expenses" rows={s.operating_expenses.accounts} total={s.operating_expenses.total} currency={currency} />
      <div className="flex justify-end text-sm font-mono tabular-nums border-t-2 border-t-strong pt-2">
        Net Income: <span className="font-medium ml-2">{currency} {formatDecimal(s.net_income)}</span>
      </div>
    </div>
  );
}

function TranslatedTrialBalance({ from, to, currency }: { from: string; to: string; currency: string }) {
  const { data, isLoading, isError, refetch } = useTranslated(
    ['accounting', 'parent-pack', 'tb', from, to, currency],
    () => currencyApi.trialBalance({ from, to, currency }),
  );

  if (isLoading) return <div className="px-5 py-4"><SkeletonTable columns={3} rows={12} /></div>;
  if (isError || !data) return <div className="px-5 py-4"><ErrorState currency={currency} refetch={refetch} /></div>;

  return (
    <div className="px-5 py-4 space-y-3">
      <RateBadges closing={data.closing_rate} />
      <div className="border border-default rounded-md overflow-hidden">
        <table className={tableCls}>
          <thead>
            <tr className={theadTrCls}>
              <Th>Account</Th>
              <Th align="right">Debit ({currency})</Th>
              <Th align="right">Credit ({currency})</Th>
            </tr>
          </thead>
          <tbody>
            {data.accounts.map((a) => (
              <tr key={a.code} className={trCls}>
                <Td><span className="font-mono text-muted">{a.code}</span> {a.name}</Td>
                <Td align="right" mono>{formatDecimal(a.debit_total)}</Td>
                <Td align="right" mono>{formatDecimal(a.credit_total)}</Td>
              </tr>
            ))}
            <tr className={totalsTrCls}>
              <Td>Totals</Td>
              <Td align="right" mono>{formatDecimal(data.totals.debit)}</Td>
              <Td align="right" mono>{formatDecimal(data.totals.credit)}</Td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  );
}

function TransSection({ title, rows, total, currency, highlightCode }: {
  title: string;
  rows: TranslatedLine[];
  total: string;
  currency: string;
  highlightCode?: string;
}) {
  return (
    <div className="border border-default rounded-md overflow-hidden">
      <div className="px-2.5 py-1.5 bg-subtle text-2xs uppercase tracking-wider text-muted font-medium border-b border-default">{title}</div>
      <table className={tableCls}>
        <tbody>
          {rows.length === 0 && <tr className={trCls}><Td className="text-muted italic" colSpan={2}>No movement</Td></tr>}
          {rows.map((r) => (
            <tr key={r.code ?? r.name} className={cn(trCls, r.code === highlightCode && 'bg-warning-bg')}>
              <Td>
                {r.code && <span className="font-mono text-muted">{r.code}</span>} {r.name}
                <span className="block text-2xs text-muted">PHP {formatDecimal(r.amount_php)} @ {Number(r.rate_applied).toFixed(6)}</span>
              </Td>
              <Td align="right" mono className="align-top">{currency} {formatDecimal(r.amount)}</Td>
            </tr>
          ))}
          <tr className={totalsTrCls}>
            <Td>Total</Td>
            <Td align="right" mono>{currency} {formatDecimal(total)}</Td>
          </tr>
        </tbody>
      </table>
    </div>
  );
}
