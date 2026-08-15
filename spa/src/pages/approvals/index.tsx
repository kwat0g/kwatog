import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { LuExternalLink, LuClock, LuTriangleAlert } from '@/lib/icons';
import { approvalsApi } from '@/api/approvals';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { UserBadge } from '@/components/ui/UserBadge';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { PageHeader } from '@/components/layout/PageHeader';
import { cn } from '@/lib/cn';
import { formatPeso } from '@/lib/formatNumber';
import { focusRing } from '@/lib/focus';
import type { ApprovalKind, ApprovalCardActive, ApprovalCardActioned } from '@/types/approvals';

/** Hours remaining against the SLA for a card, given the current clock. */
function hoursRemaining(since: string, now: number, slaHours: number): number {
  const elapsedH = (now - new Date(since).getTime()) / 3_600_000;
  return slaHours - elapsedH;
}

function slaTone(remaining: number, slaHours: number): 'danger' | 'warning' | 'info' {
  if (remaining <= 0) return 'danger';
  // Warn during the final quarter of the configured SLA, rather than using
  // a fixed number of hours that would be wrong for shorter/longer policies.
  if (slaHours > 0 && remaining <= slaHours * 0.25) return 'warning';
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
  const { data: options } = useQuery({
    queryKey: ['approvals', 'options'],
    queryFn: () => approvalsApi.options(),
    staleTime: 5 * 60 * 1000,
  });
  const kindLabels = new Map((options?.kinds ?? []).map((option) => [option.value, option.label]));
  const slaHours = options?.overdue_hours;
  const kindFilters = [
    { key: 'all' as const, label: 'All' },
    ...(options?.kinds ?? []).map((option) => ({ key: option.value, label: option.label })),
  ];

  // Prioritise the inbox: most-overdue (oldest pending) first.
  const myAction = data
    ? [...data.my_action].sort((a, b) => new Date(a.since).getTime() - new Date(b.since).getTime())
    : [];
  const overdueCount =
    slaHours == null
      ? 0
      : myAction.filter((c) => hoursRemaining(c.since, now, slaHours) <= 0).length;

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
      <div className="px-5 py-3 border-b border-default flex items-center overflow-x-auto">
        <SegmentedControl
          size="sm"
          label="Approval type"
          value={kind}
          onChange={setKind}
          options={kindFilters.map((f) => ({ value: f.key, label: f.label }))}
        />
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
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
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
                <ActiveCard
                  key={`${c.type}-${c.id}`}
                  card={c}
                  now={now}
                  slaHours={slaHours}
                  showSla
                  kindLabels={kindLabels}
                  onOpen={() => navigate(c.link)}
                />
              ))
            )}
          </Column>

          <Column title="Awaiting others" count={data.summary.awaiting_others} tone="neutral">
            {data.awaiting_others.length === 0 ? (
              <EmptyColumn message="No pending approvals." />
            ) : (
              data.awaiting_others.map((c) => (
                <ActiveCard
                  key={`${c.type}-${c.id}`}
                  card={c}
                  now={now}
                  slaHours={slaHours}
                  kindLabels={kindLabels}
                  onOpen={() => navigate(c.link)}
                />
              ))
            )}
          </Column>

          <Column title="Approved" count={data.summary.approved} tone="success">
            {data.approved.length === 0 ? (
              <EmptyColumn message="No recent approvals." />
            ) : (
              data.approved.map((c) => (
                <ActionedCard
                  key={`${c.type}-${c.id}`}
                  card={c}
                  kindLabels={kindLabels}
                  onOpen={() => navigate(c.link)}
                />
              ))
            )}
          </Column>

          <Column title="Rejected" count={data.summary.rejected} tone="danger">
            {data.rejected.length === 0 ? (
              <EmptyColumn message="No recent rejections." />
            ) : (
              data.rejected.map((c) => (
                <ActionedCard
                  key={`${c.type}-${c.id}`}
                  card={c}
                  kindLabels={kindLabels}
                  onOpen={() => navigate(c.link)}
                />
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
      <div className="flex-1 p-2 space-y-2 overflow-auto max-h-[640px]">{children}</div>
    </div>
  );
}

function EmptyColumn({ message }: { message: string }) {
  return <div className="text-xs text-muted text-center py-4">{message}</div>;
}

function ActiveCard({
  card,
  now,
  kindLabels,
  slaHours,
  showSla = false,
  onOpen,
}: {
  card: ApprovalCardActive;
  now: number;
  kindLabels: ReadonlyMap<string, string>;
  slaHours?: number;
  /** Show the live SLA countdown + consumption bar (inbox column only). */
  showSla?: boolean;
  onOpen: () => void;
}) {
  const remaining = hoursRemaining(card.since, now, slaHours ?? 0);
  const tone = slaTone(remaining, slaHours ?? 0);
  const consumedPct = slaHours
    ? Math.min(100, Math.max(0, ((slaHours - remaining) / slaHours) * 100))
    : 0;
  const hasSla = showSla && slaHours != null;
  const barColor =
    tone === 'danger' ? 'bg-danger-bg' : tone === 'warning' ? 'bg-warning-bg' : 'bg-accent';

  return (
    <button
      type="button"
      onClick={onOpen}
      className={cn(
        'w-full text-left bg-canvas border border-default rounded-md p-2.5 hover:bg-elevated transition-colors cursor-pointer',
        focusRing,
      )}
    >
      <div className="flex items-center justify-between mb-1">
        <span className="text-2xs uppercase tracking-wider text-muted font-medium">
          {kindLabels.get(card.type) ?? card.type}
        </span>
        <Chip variant={hasSla ? tone : 'neutral'}>
          <span className="inline-flex items-center gap-1 font-mono tabular-nums">
            {hasSla ? (
              <>
                {remaining <= 0 ? <LuTriangleAlert size={10} /> : <LuClock size={10} />}
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
          {formatPeso(card.amount)}
        </div>
      )}
      {card.requester && (
        <div className="text-2xs text-muted mt-1.5">
          requested by <UserBadge name={card.requester.name} role={card.requester.role} />
        </div>
      )}
      {hasSla && (
        <div className="mt-2 h-1 w-full overflow-hidden rounded-full bg-subtle" aria-hidden="true">
          <div
            className={cn('h-full rounded-full transition-[width]', barColor)}
            style={{ width: `${consumedPct}%` }}
          />
        </div>
      )}
      <div className="text-2xs text-muted mt-1.5 flex items-center gap-1">
        <LuExternalLink size={10} />
        Open record to act
      </div>
    </button>
  );
}

function ActionedCard({
  card,
  kindLabels,
  onOpen,
}: {
  card: ApprovalCardActioned;
  kindLabels: ReadonlyMap<string, string>;
  onOpen: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onOpen}
      className={cn(
        'w-full text-left bg-canvas border border-default rounded-md p-2.5 hover:bg-elevated transition-colors cursor-pointer',
        focusRing,
      )}
    >
      <div className="flex items-center justify-between mb-1">
        <span className="text-2xs uppercase tracking-wider text-muted font-medium">
          {kindLabels.get(card.type) ?? card.type}
        </span>
        <Chip variant={card.action === 'approved' ? 'success' : 'danger'}>{card.action}</Chip>
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
