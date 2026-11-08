import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useInfiniteQuery } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { reportMutationError } from '@/lib/formErrors';
import { formatDateTime } from '@/lib/formatDate';
import { recommendationStatusVariant } from '@/lib/statusVariants';
import { dssApi } from '@/api/dss';
import type { Recommendation } from '@/types/dss';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Input } from '@/components/ui/Input';
import { LinkButton } from '@/components/ui/LinkButton';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { useRealtimeSignal } from '@/hooks/useRealtimeSignal';
import { formatImpact, severityVariant } from './helpers';
import DecisionEvidence from './DecisionEvidence';
import MasterHygieneAffectedRecords from './MasterHygieneAffectedRecords';
import { decisionView, recommendationStatusLabel, severityLabel } from './presentation';

interface RecommendationDetailsProps {
  recommendation: Recommendation;
  backTo?: string;
}

const verificationLabels: Record<string, string> = {
  still_open: 'Source confirms this condition remains open',
  verified_clear: 'Source confirms this condition has cleared',
  unavailable: 'Automatic source verification unavailable',
};

export default function RecommendationDetails({
  recommendation,
  backTo,
}: RecommendationDetailsProps) {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  useRealtimeSignal('dss_changed', 'dss.refresh', '.dss.changed');
  const [outcomeNote, setOutcomeNote] = useState('');
  const [mode, setMode] = useState<'dismiss' | 'snooze' | null>(null);
  const [reason, setReason] = useState('');
  const [until, setUntil] = useState('');
  const events = useInfiniteQuery({
    queryKey: ['dss', 'events', recommendation.id],
    queryFn: ({ pageParam }) => dssApi.events(recommendation.id, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (page) => page.next_cursor ?? undefined,
    retry: false,
  });
  const view = decisionView(recommendation);
  const impact = formatImpact(
    recommendation.impact_estimate,
    recommendation.impact_currency ?? recommendation.impact_unit,
    recommendation.impact_is_estimate,
  );
  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  const dateInputValue = (date: Date) => {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${date.getFullYear()}-${month}-${day}`;
  };
  const minSnoozeDate = dateInputValue(tomorrow);
  const expiry = recommendation.expires_at ? new Date(recommendation.expires_at) : null;
  const maxSnoozeDate =
    expiry && !Number.isNaN(expiry.getTime())
      ? dateInputValue(new Date(expiry.getTime() - 1))
      : undefined;
  const canSnooze = !maxSnoozeDate || maxSnoozeDate >= minSnoozeDate;
  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['dss'] });
  const severityChip = (
    <Chip variant={severityVariant(recommendation.severity)}>
      {severityLabel(recommendation.severity)}
    </Chip>
  );
  const statusChip = (
    <Chip variant={recommendationStatusVariant[recommendation.status]}>
      {recommendationStatusLabel(recommendation.status)}
    </Chip>
  );

  const act = useMutation({
    mutationFn: (args: {
      action: 'accept' | 'dismiss' | 'snooze' | 'execute' | 'start_follow_up';
      reason?: string;
      snoozed_until?: string;
    }) =>
      dssApi.act(recommendation.id, args.action, {
        reason: args.reason,
        snoozed_until: args.snoozed_until,
        expected_version: recommendation.version,
      }),
    onSuccess: (updated, args) => {
      queryClient.setQueryData(['dss', 'recommendations', updated.id], updated);
      toast.success(
        args.action === 'accept'
          ? 'Recommendation accepted'
          : args.action === 'dismiss'
            ? 'Recommendation dismissed'
            : args.action === 'snooze'
              ? 'Recommendation snoozed'
              : args.action === 'start_follow_up'
                ? 'Follow-up started'
                : 'Marked as executed',
      );
      setMode(null);
      invalidate();
    },
    onError: async (error) => {
      if (error instanceof AxiosError && error.response?.status === 409
        && error.response.data?.code === 'dss_recommendation_state_conflict') {
        const refresh = invalidate();
        reportMutationError(error, 'Failed to update recommendation');
        await refresh;
        return;
      }
      reportMutationError(error, 'Failed to update recommendation');
    },
  });

  const saveOutcome = useMutation({
    mutationFn: () => dssApi.outcome(recommendation.id, { note: outcomeNote }),
    onSuccess: (updated) => {
      queryClient.setQueryData(['dss', 'recommendations', updated.id], updated);
      toast.success('Outcome recorded');
      setOutcomeNote('');
      invalidate();
    },
    onError: (error) => reportMutationError(error, 'Failed to record outcome'),
  });

  const availableActions = recommendation.available_actions;

  return (
    <div className="min-w-0">
      {backTo ? (
        <PageHeader
          title={
            <div className="flex min-w-0 flex-wrap items-center gap-2">
              <span className="min-w-0 break-words">{view.title}</span>
              {severityChip}
            </div>
          }
          subtitle={view.area}
          backTo={backTo}
          backLabel="Decision Center"
          actions={statusChip}
        />
      ) : (
        <header className="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h2 className="break-words text-lg font-medium text-primary">{view.title}</h2>
              {severityChip}
            </div>
            <p className="text-xs text-muted">{view.area}</p>
          </div>
          {statusChip}
        </header>
      )}
      <div className="mx-auto grid w-full max-w-[1600px] grid-cols-1 items-start gap-4 px-5 py-4 xl:grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        <div className="min-w-0 space-y-4">
          <Panel title="Recommendation" meta="Advisory">
            <p className="max-w-3xl break-words text-sm text-secondary">{view.summary}</p>

            {(availableActions.accept || availableActions.dismiss || availableActions.snooze || availableActions.execute || availableActions.start_follow_up) && (
              <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-default pt-3">
                {availableActions.start_follow_up && (
                  <Button
                    size="sm"
                    variant="secondary"
                    disabled={act.isPending}
                    onClick={() => act.mutate({ action: 'start_follow_up' })}
                  >
                    {recommendation.status === 'snoozed' ? 'Resume follow-up' : 'Start follow-up'}
                  </Button>
                )}
                {availableActions.accept && (
                  <>
                    <Button
                      size="sm"
                      variant="primary"
                      disabled={act.isPending}
                      onClick={() => act.mutate({ action: 'accept' })}
                    >
                      {act.isPending ? 'Working…' : 'Accept'}
                    </Button>
                  </>
                )}
                {availableActions.dismiss && (
                  <Button
                    size="sm"
                    variant="secondary"
                    disabled={act.isPending}
                    onClick={() => setMode(mode === 'dismiss' ? null : 'dismiss')}
                  >
                    Dismiss
                  </Button>
                )}
                {availableActions.snooze && (
                  <Button
                    size="sm"
                    variant="secondary"
                    disabled={act.isPending}
                    onClick={() => setMode(mode === 'snooze' ? null : 'snooze')}
                  >
                    Snooze
                  </Button>
                )}
                {availableActions.execute && (
                  <Button
                    variant="primary"
                    size="sm"
                    disabled={act.isPending}
                    onClick={() => act.mutate({ action: 'execute' })}
                  >
                    {act.isPending ? 'Working…' : 'Mark as executed'}
                  </Button>
                )}
              </div>
            )}

            {availableActions.dismiss && mode === 'dismiss' && (
              <form
                className="mt-4 grid grid-cols-1 items-end gap-2 border-t border-default pt-3 sm:grid-cols-[minmax(0,1fr)_auto]"
                onSubmit={(event) => {
                  event.preventDefault();
                  act.mutate({ action: 'dismiss', reason });
                }}
              >
                <Input
                  label="Reason"
                  placeholder="Why does this not apply? (required)"
                  value={reason}
                  onChange={(event) => setReason(event.target.value)}
                  required
                  fieldSize="sm"
                />
                <Button
                  size="sm"
                  variant="danger"
                  type="submit"
                  disabled={act.isPending || !reason.trim()}
                >
                  Confirm dismissal
                </Button>
              </form>
            )}
            {availableActions.snooze && mode === 'snooze' && (
              <form
                className="mt-4 grid grid-cols-1 items-end gap-2 border-t border-default pt-3 sm:grid-cols-[minmax(0,1fr)_auto]"
                onSubmit={(event) => {
                  event.preventDefault();
                  act.mutate({
                    action: 'snooze',
                    snoozed_until: new Date(`${until}T00:00:00`).toISOString(),
                  });
                }}
              >
                <Input
                  label="Snooze until"
                  type="date"
                  min={minSnoozeDate}
                  max={maxSnoozeDate}
                  value={until}
                  disabled={!canSnooze}
                  onChange={(event) => setUntil(event.target.value)}
                  helper={
                    canSnooze
                      ? 'Returns to the queue before this recommendation expires.'
                      : 'This recommendation expires before the earliest available return date.'
                  }
                  fieldSize="sm"
                />
                <Button
                  size="sm"
                  variant="secondary"
                  type="submit"
                  disabled={
                    act.isPending ||
                    !canSnooze ||
                    !until ||
                    until < minSnoozeDate ||
                    (!!maxSnoozeDate && until > maxSnoozeDate)
                  }
                >
                  Confirm snooze
                </Button>
              </form>
            )}

            {availableActions.record_outcome && (
              <form
                className="mt-4 flex flex-col gap-2 border-t border-default pt-3 sm:flex-row sm:items-end"
                onSubmit={(event) => {
                  event.preventDefault();
                  saveOutcome.mutate();
                }}
              >
                <Input
                  aria-label="Measured result"
                  placeholder="Measured result (e.g. stock-out avoided, ₱ saved)"
                  value={outcomeNote}
                  onChange={(event) => setOutcomeNote(event.target.value)}
                  fieldSize="sm"
                  containerClassName="min-w-0 flex-1"
                />
                <Button
                  size="sm"
                  variant="secondary"
                  type="submit"
                  disabled={saveOutcome.isPending || !outcomeNote.trim()}
                >
                  Record outcome
                </Button>
              </form>
            )}
          </Panel>

          <DecisionEvidence recommendation={recommendation} />
          {recommendation.detector_id === 'dss.master_hygiene' && (
            <MasterHygieneAffectedRecords recommendationId={recommendation.id} />
          )}
          <Panel title="Activity">
            {events.isLoading ? (
              <p role="status" className="text-sm text-muted">Loading activity…</p>
            ) : events.isLoadingError ? (
              <div className="flex items-center gap-2 text-sm text-secondary">
                <span>Could not load activity.</span>
                <Button size="sm" variant="secondary" onClick={() => void events.refetch()}>Retry</Button>
              </div>
            ) : (events.data?.pages.flatMap((page) => page.data).length ?? 0) === 0 ? (
              <p className="text-sm text-muted">No activity recorded yet.</p>
            ) : (
              <>
                <ol className="divide-y divide-subtle">
                  {events.data?.pages.flatMap((page) => page.data).map((event) => (
                    <li key={event.id} className="py-3 first:pt-0 last:pb-0">
                      <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <span className="text-sm font-medium text-primary">{event.label}</span>
                        <time className="text-xs text-muted" dateTime={event.at ?? undefined}>
                          {formatDateTime(event.at)}
                        </time>
                      </div>
                      {(event.actor || event.reason || event.outcome_summary) && (
                        <p className="mt-1 break-words text-xs text-secondary">
                          {[event.actor, event.reason, event.outcome_summary].filter(Boolean).join(' · ')}
                        </p>
                      )}
                    </li>
                  ))}
                </ol>
                {events.hasNextPage && (
                  <Button
                    className="mt-3"
                    size="sm"
                    variant="secondary"
                    disabled={events.isFetchingNextPage}
                    onClick={() => void events.fetchNextPage()}
                  >
                    {events.isFetchingNextPage ? 'Loading…' : 'Load older events'}
                  </Button>
                )}
                {events.isError && events.data && (
                  <Button className="mt-3" size="sm" variant="secondary" onClick={() => void events.fetchNextPage()}>
                    Retry loading older events
                  </Button>
                )}
              </>
            )}
          </Panel>
        </div>

        <aside className="min-w-0 space-y-4">
          <Panel title="Business context">
            <dl className="divide-y divide-subtle text-sm">
              <div className="py-2 first:pt-0">
                <div className="flex items-start justify-between gap-4">
                  <dt className="text-xs text-muted">Business impact</dt>
                  <dd className="text-right font-mono tabular-nums text-primary">
                    {impact ?? 'Not available'}
                  </dd>
                </div>
                {recommendation.impact_metric && (
                  <p className="mt-0.5 text-right text-xs text-muted">
                    {recommendation.impact_metric}
                  </p>
                )}
              </div>
              {recommendation.business_due_at && (
                <div className="flex items-start justify-between gap-4 py-2">
                  <dt className="text-xs text-muted">Business deadline</dt>
                  <dd className="text-right text-sm text-primary">
                    {formatDateTime(recommendation.business_due_at)}
                  </dd>
                </div>
              )}
              {recommendation.status === 'snoozed' && recommendation.snoozed_until && (
                <div className="flex items-start justify-between gap-4 py-2">
                  <dt className="text-xs text-muted">Snoozed until</dt>
                  <dd className="text-right text-sm text-primary">{formatDateTime(recommendation.snoozed_until)}</dd>
                </div>
              )}
            </dl>
          </Panel>

          {(recommendation.verification_status || recommendation.last_evaluated_at) && (
            <Panel title="Source check">
              {recommendation.verification_status && (
                <p className="text-sm text-secondary">
                  {verificationLabels[recommendation.verification_status] ??
                    'Source check status unavailable'}
                </p>
              )}
              {recommendation.last_evaluated_at && (
                <p className="mt-2 text-xs text-muted">
                  Last checked: {formatDateTime(recommendation.last_evaluated_at)}
                </p>
              )}
            </Panel>
          )}

          {recommendation.resolution_evidence && (
            <Panel title="Verified resolution">
              <p className="text-sm text-secondary">
                {recommendation.resolution_evidence.condition} cleared.{' '}
                {recommendation.resolution_evidence.proof}
              </p>
              <p className="mt-2 text-xs text-muted">
                Checked {formatDateTime(recommendation.resolution_evidence.verified_at)} by{' '}
                {recommendation.resolution_evidence.verification_method} · run{' '}
                {recommendation.resolution_evidence.run_reference}
              </p>
            </Panel>
          )}

          {recommendation.action?.route && (
            <Panel title="Suggested next step">
              <LinkButton tone="accent" onClick={() => navigate(recommendation.action!.route!)}>
                {view.actionLabel}
              </LinkButton>
            </Panel>
          )}

          {!!recommendation.source_references?.length && (
            <Panel
              title="Related records"
              meta={`${recommendation.source_references.length} record${recommendation.source_references.length === 1 ? '' : 's'}`}
            >
              <ul className="divide-y divide-subtle text-sm">
                {recommendation.source_references.map((reference) => (
                  <li
                    key={`${reference.source_kind}:${reference.public_id}`}
                    className="py-2 first:pt-0"
                  >
                    <Link className="text-link" to={reference.destination}>
                      {reference.display_reference}
                    </Link>
                  </li>
                ))}
              </ul>
            </Panel>
          )}
        </aside>
      </div>
    </div>
  );
}
