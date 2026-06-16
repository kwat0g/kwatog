import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ExternalLink, Clock, AlertTriangle } from 'lucide-react';
import { approvalsApi } from '@/api/approvals';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { UserBadge } from '@/components/ui/UserBadge';
import { PageHeader } from '@/components/layout/PageHeader';
import { cn } from '@/lib/cn';
import type {
  ApprovalKind,
  ApprovalCardActive,
  ApprovalCardActioned,
} from '@/types/approvals';

const KIND_FILTERS: { key: ApprovalKind | 'all'; label: string }[] = [
  { key: 'all',     label: 'All' },
  { key: 'leave',   label: 'Leave' },
  { key: 'pr',      label: 'Purchase requests' },
  { key: 'po',      label: 'Purchase orders' },
  { key: 'loan',    label: 'Loans' },
  { key: 'payroll', label: 'Payroll' },
];

const KIND_LABEL: Record<ApprovalKind, string> = {
  leave:   'Leave',
  pr:      'PR',
  po:      'PO',
  loan:    'Loan',
  payroll: 'Payroll',
};

/** Per-step approval SLA (matches the backend's 24h overdue rule). */
const SLA_HOURS = 24;

/** Hours remaining against the SLA for a card, given the current clock. */
function hoursRemaining(since: string, now: number): number {
  const elapsedH = (now - new Date(since).getTime()) / 3_600_000;
  return SLA_HOURS - elapsedH;
}

function slaTone(remaining: number): 'danger' | 'warning' | 'info' {
  if (remaining <= 0) return 'danger';
  if (remaining <= 6) return 'warning';
  return 'info';
}

function formatSla(remaining: number): string {
  if (remaining <= 0) {
    const over = Math.floor(-remaining);
    return over < 1 ? 'Overdue' : `Overdue ${over}h`;
  }
  const h = Math.floor(remaining);
  const m = Math.round((remaining - h) * 60);
  return h >= 1 ? `${h}h ${m}m left` : `${m}m left`;
}

/** Ticking clock for live countdowns; updates once a minute. */
function useNow(): number {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), 60_000);
    return () => clearInterval(t);
  }, []);
  return now;
}

export default function ApprovalsBoardPage() {
  const navigate = useNavigate();
  const [kind, setKind] = useState<ApprovalKind | 'all'>('all');
  const now = useNow();

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['approvals', 'board', kind],
    queryFn: () => approvalsApi.board(kind === 'all' ? undefined : { type: kind }),
    placeholderData: (prev) => prev,
    refetchInterval: 30_000, // light polling — websocket upgrade is a future task
  });

  // Prioritise the inbox: most-overdue (oldest pending) first.
  const myAction = data
    ? [...data.my_action].sort(
        (a, b) => new Date(a.since).getTime() - new Date(b.since).getTime(),
      )
    : [];
  const overdueCount = myAction.filter((c) => hoursRemaining(c.since, now) <= 0).length;

  return (
    <div>
      <PageHeader
        title="Approvals"
        subtitle={
          data
            ? `${data.summary.my_action} requiring my action${overdueCount > 0 ? ` · ${overdueCount} overdue` : ''}`
            : undefined
        }
      />

      {/* Filter pills */}
      <div className="px-5 py-3 border-b border-default flex items-center gap-2 overflow-x-auto">
        {KIND_FILTERS.map((f) => (
          <button
            key={f.key}
            type="button"
            onClick={() => setKind(f.key)}
            className={cn(
              'px-2.5 py-1 rounded text-xs font-medium border whitespace-nowrap',
              kind === f.key
                ? 'border-default bg-elevated text-primary'
                : 'border-subtle text-muted hover:bg-elevated',
            )}
          >
            {f.label}
          </button>
        ))}
      </div>

      {isLoading && !data && (
        <div className="px-5 py-4 grid grid-cols-1 md:grid-cols-4 gap-3">
          {[0, 1, 2, 3].map((i) => (
            <div key={i} className="h-[400px] bg-elevated rounded-md animate-pulse" />
          ))}
        </div>
      )}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load approvals"
          description="Something went wrong."
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {data && (
        <div className="px-5 py-4 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
          <Column
            title="My action required"
            count={data.summary.my_action}
            tone={overdueCount > 0 ? 'danger' : 'warning'}
          >
            {myAction.length === 0 ? (
              <EmptyColumn message="Nothing waiting on you. Nice." />
            ) : (
              myAction.map((c) => (
                <ActiveCard key={`${c.type}-${c.id}`} card={c} now={now} showSla onOpen={() => navigate(c.link)} />
              ))
            )}
          </Column>

          <Column
            title="Awaiting others"
            count={data.summary.awaiting_others}
            tone="neutral"
          >
            {data.awaiting_others.length === 0 ? (
              <EmptyColumn message="No pending approvals." />
            ) : (
              data.awaiting_others.map((c) => (
                <ActiveCard key={`${c.type}-${c.id}`} card={c} now={now} onOpen={() => navigate(c.link)} />
              ))
            )}
          </Column>

          <Column
            title="Approved"
            count={data.summary.approved}
            tone="success"
          >
            {data.approved.length === 0 ? (
              <EmptyColumn message="No recent approvals." />
            ) : (
              data.approved.map((c) => (
                <ActionedCard key={`${c.type}-${c.id}`} card={c} onOpen={() => navigate(c.link)} />
              ))
            )}
          </Column>

          <Column
            title="Rejected"
            count={data.summary.rejected}
            tone="danger"
          >
            {data.rejected.length === 0 ? (
              <EmptyColumn message="No recent rejections." />
            ) : (
              data.rejected.map((c) => (
                <ActionedCard key={`${c.type}-${c.id}`} card={c} onOpen={() => navigate(c.link)} />
              ))
            )}
          </Column>
        </div>
      )}
    </div>
  );
}

function Column({
  title,
  count,
  tone,
  children,
}: {
  title: string;
  count: number;
  tone: 'success' | 'warning' | 'danger' | 'neutral';
  children: React.ReactNode;
}) {
  return (
    <div className="bg-surface border border-default rounded-md flex flex-col">
      <div className="flex items-center justify-between px-3 py-2 border-b border-default">
        <span className="text-xs font-medium text-primary">{title}</span>
        <Chip variant={tone}>{count}</Chip>
      </div>
      <div className="flex-1 p-2 space-y-2 overflow-auto max-h-[640px]">
        {children}
      </div>
    </div>
  );
}

function EmptyColumn({ message }: { message: string }) {
  return (
    <div className="text-xs text-muted text-center py-6">{message}</div>
  );
}

function ActiveCard({
  card,
  now,
  showSla = false,
  onOpen,
}: {
  card: ApprovalCardActive;
  now: number;
  /** Show the live SLA countdown + consumption bar (inbox column only). */
  showSla?: boolean;
  onOpen: () => void;
}) {
  const remaining = hoursRemaining(card.since, now);
  const tone = slaTone(remaining);
  const consumedPct = Math.min(100, Math.max(0, ((SLA_HOURS - remaining) / SLA_HOURS) * 100));
  const barColor =
    tone === 'danger' ? 'bg-danger' : tone === 'warning' ? 'bg-warning' : 'bg-accent';

  return (
    <button
      type="button"
      onClick={onOpen}
      className="w-full text-left bg-canvas border border-default rounded-md p-2.5 hover:bg-elevated transition-colors"
    >
      <div className="flex items-center justify-between mb-1">
        <span className="text-2xs uppercase tracking-wider text-muted font-medium">
          {KIND_LABEL[card.type]}
        </span>
        <Chip variant={showSla ? tone : 'neutral'}>
          <span className="inline-flex items-center gap-1 font-mono tabular-nums">
            {showSla ? (
              <>
                {remaining <= 0 ? <AlertTriangle size={10} /> : <Clock size={10} />}
                {formatSla(remaining)}
              </>
            ) : (
              `${card.age_hours}h`
            )}
          </span>
        </Chip>
      </div>
      <div className="font-mono text-xs text-primary mb-1">{card.number}</div>
      <div className="text-xs text-secondary line-clamp-2">{card.summary}</div>
      {card.amount && (
        <div className="text-xs text-muted mt-1 font-mono tabular-nums">
          ₱ {Number(card.amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
        </div>
      )}
      {card.requester && (
        <div className="text-2xs text-muted mt-1.5">
          requested by <UserBadge name={card.requester.name} role={card.requester.role} />
        </div>
      )}
      {showSla && (
        <div className="mt-2 h-1 w-full overflow-hidden rounded-full bg-subtle" aria-hidden="true">
          <div className={cn('h-full rounded-full transition-all', barColor)} style={{ width: `${consumedPct}%` }} />
        </div>
      )}
      <div className="text-2xs text-muted mt-1.5 flex items-center gap-1">
        <ExternalLink size={10} />
        Open record to act
      </div>
    </button>
  );
}

function ActionedCard({
  card,
  onOpen,
}: {
  card: ApprovalCardActioned;
  onOpen: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onOpen}
      className="w-full text-left bg-canvas border border-default rounded-md p-2.5 hover:bg-elevated transition-colors"
    >
      <div className="flex items-center justify-between mb-1">
        <span className="text-2xs uppercase tracking-wider text-muted font-medium">
          {KIND_LABEL[card.type]}
        </span>
        <Chip variant={card.action === 'approved' ? 'success' : 'danger'}>
          {card.action}
        </Chip>
      </div>
      <div className="font-mono text-xs text-primary mb-1">{card.number}</div>
      <div className="text-xs text-secondary line-clamp-2">{card.summary}</div>
      {card.remarks && (
        <div className="text-2xs text-muted mt-1 italic line-clamp-2">“{card.remarks}”</div>
      )}
      {card.actor && (
        <div className="text-2xs text-muted mt-1.5">
          by <UserBadge name={card.actor.name} role={card.actor.role} />
        </div>
      )}
    </button>
  );
}
