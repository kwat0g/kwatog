import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { reportMutationError } from '@/lib/formErrors';
import { formatDateTime } from '@/lib/formatDate';
import { dssApi } from '@/api/dss';
import type { Recommendation } from '@/types/dss';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Input } from '@/components/ui/Input';
import { LinkButton } from '@/components/ui/LinkButton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatImpact, severityVariant } from './helpers';
import DecisionEvidence from './DecisionEvidence';
import { decisionView, recommendationStatusLabel, severityLabel } from './presentation';

interface RecommendationDetailsProps {
  recommendation: Recommendation;
  onClose?: () => void;
  backTo?: string;
}

const statusVariant = (status: Recommendation['status']) =>
  status === 'open' || status === 'in_progress'
    ? 'info'
    : status === 'snoozed'
      ? 'warning'
      : status === 'resolved'
        ? 'success'
        : 'neutral';
const verificationLabels: Record<string, string> = {
  still_open: 'Source confirms this condition remains open',
  verified_clear: 'Source confirms this condition has cleared',
  unavailable: 'Automatic source verification unavailable',
};

export default function RecommendationDetails({
  recommendation,
  onClose,
  backTo,
}: RecommendationDetailsProps) {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [outcomeNote, setOutcomeNote] = useState('');
  const [mode, setMode] = useState<'dismiss' | 'snooze' | null>(null);
  const [reason, setReason] = useState('');
  const [until, setUntil] = useState('');
  const view = decisionView(recommendation);
  const impact = formatImpact(
    recommendation.impact_estimate,
    recommendation.impact_currency ?? recommendation.impact_unit,
    recommendation.impact_is_estimate,
  );
  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  const minSnoozeDate = `${tomorrow.getFullYear()}-${String(tomorrow.getMonth() + 1).padStart(2, '0')}-${String(tomorrow.getDate()).padStart(2, '0')}`;
  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['dss'] });

  const act = useMutation({
    mutationFn: (args: {
      action: 'accept' | 'dismiss' | 'snooze' | 'execute' | 'start_follow_up';
      reason?: string;
      snoozed_until?: string;
    }) =>
      dssApi.act(recommendation.id, args.action, {
        reason: args.reason,
        snoozed_until: args.snoozed_until,
      }),
    onSuccess: (_, args) => {
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
    onError: (error) => reportMutationError(error, 'Failed to update recommendation'),
  });

  const saveOutcome = useMutation({
    mutationFn: () => dssApi.outcome(recommendation.id, { note: outcomeNote }),
    onSuccess: () => {
      toast.success('Outcome recorded');
      setOutcomeNote('');
      invalidate();
    },
    onError: (error) => reportMutationError(error, 'Failed to record outcome'),
  });

  return (
    <div className="min-w-0">
      {backTo ? (
        <PageHeader
          title={view.title}
          subtitle={view.area}
          backTo={backTo}
          backLabel="Decision Center"
        />
      ) : (
        <header className="flex items-start justify-between gap-3 px-4 py-3">
          <div className="min-w-0">
            <h2 className="break-words text-lg font-medium text-primary">{view.title}</h2>
            <p className="text-xs text-muted">{view.area}</p>
          </div>
          {onClose && (
            <Button variant="ghost" onClick={onClose} aria-label="Close recommendation details">
              Close
            </Button>
          )}
        </header>
      )}
      <div className="space-y-4 px-5 pb-6">
        <div className="flex flex-wrap items-center gap-2">
          <Chip variant={severityVariant(recommendation.severity)}>
            {severityLabel(recommendation.severity)}
          </Chip>
          <Chip variant={statusVariant(recommendation.status)}>
            {recommendationStatusLabel(recommendation.status)}
          </Chip>
          {impact && (
            <span className="text-right">
              {recommendation.impact_metric && (
                <span className="block text-xs text-muted">{recommendation.impact_metric}</span>
              )}
              <span className="font-mono tabular-nums text-sm text-primary">{impact}</span>
            </span>
          )}
        </div>
        <p className="break-words text-sm text-secondary">{view.summary}</p>
        <DecisionEvidence recommendation={recommendation} />

        {recommendation.resolution_evidence && (
          <section className="rounded-md border border-default bg-canvas px-3 py-2.5">
            <h3 className="text-xs font-medium">Verified resolution</h3>
            <p className="mt-1 text-sm text-secondary">
              {recommendation.resolution_evidence.condition} cleared.{' '}
              {recommendation.resolution_evidence.proof}
            </p>
            <p className="mt-1 text-xs text-muted">
              Checked {formatDateTime(recommendation.resolution_evidence.verified_at)} by{' '}
              {recommendation.resolution_evidence.verification_method} · run{' '}
              {recommendation.resolution_evidence.run_reference}
            </p>
          </section>
        )}

        {(recommendation.verification_status ||
          recommendation.last_evaluated_at ||
          recommendation.business_due_at) && (
          <section className="rounded-md border border-default bg-canvas px-3 py-2.5">
            <h3 className="text-xs font-medium">Source check</h3>
            {recommendation.verification_status && (
              <p className="mt-1 text-sm text-secondary">
                {verificationLabels[recommendation.verification_status] ??
                  'Source check status unavailable'}
              </p>
            )}
            {recommendation.business_due_at && (
              <p className="mt-1 text-xs text-muted">
                Business deadline: {formatDateTime(recommendation.business_due_at)}
              </p>
            )}
            {recommendation.last_evaluated_at && (
              <p className="mt-1 text-xs text-muted">
                Last checked: {formatDateTime(recommendation.last_evaluated_at)}
              </p>
            )}
          </section>
        )}

        {!!recommendation.source_references?.length && (
          <section className="rounded-md border border-default bg-canvas px-3 py-2.5">
            <h3 className="text-xs font-medium">Related records</h3>
            <ul className="mt-1 space-y-1 text-sm">
              {recommendation.source_references.map((reference) => (
                <li key={`${reference.source_kind}:${reference.public_id}`}>
                  <Link className="text-link" to={reference.destination}>
                    {reference.display_reference}
                  </Link>
                </li>
              ))}
            </ul>
          </section>
        )}

        {recommendation.action?.route && (
          <section className="rounded-md border border-subtle bg-canvas px-3 py-2.5">
            <h3 className="mb-2 text-xs font-medium">Suggested next step</h3>
            <LinkButton tone="accent" onClick={() => navigate(recommendation.action!.route!)}>
              {view.actionLabel}
            </LinkButton>
          </section>
        )}

        {['open', 'in_progress'].includes(recommendation.status) && (
          <div className="flex flex-wrap items-center gap-2">
            {recommendation.status === 'open' && (
              <Button
                size="sm"
                variant="secondary"
                disabled={act.isPending}
                onClick={() => act.mutate({ action: 'start_follow_up' })}
              >
                Start follow-up
              </Button>
            )}
            <Button
              size="sm"
              variant="primary"
              disabled={act.isPending}
              onClick={() => act.mutate({ action: 'accept' })}
            >
              {act.isPending ? 'Working…' : 'Accept'}
            </Button>
            <Button
              size="sm"
              variant="secondary"
              disabled={act.isPending}
              onClick={() => setMode(mode === 'dismiss' ? null : 'dismiss')}
            >
              Dismiss
            </Button>
            <Button
              size="sm"
              variant="secondary"
              disabled={act.isPending}
              onClick={() => setMode(mode === 'snooze' ? null : 'snooze')}
            >
              Snooze
            </Button>
          </div>
        )}
        {recommendation.status === 'accepted' && (
          <Button
            variant="primary"
            disabled={act.isPending}
            onClick={() => act.mutate({ action: 'execute' })}
          >
            {act.isPending ? 'Working…' : 'Mark as executed'}
          </Button>
        )}
        {['open', 'in_progress'].includes(recommendation.status) && mode === 'dismiss' && (
          <form
            className="flex flex-wrap items-end gap-2"
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
        {['open', 'in_progress'].includes(recommendation.status) && mode === 'snooze' && (
          <form
            className="flex flex-wrap items-end gap-2"
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
              value={until}
              onChange={(event) => setUntil(event.target.value)}
              helper="Returns to the queue at the start of this date."
              fieldSize="sm"
            />
            <Button
              size="sm"
              variant="secondary"
              type="submit"
              disabled={act.isPending || !until || until < minSnoozeDate}
            >
              Confirm snooze
            </Button>
          </form>
        )}

        {(recommendation.status === 'accepted' ||
          recommendation.status === 'executed' ||
          recommendation.status === 'dismissed') && (
          <form
            className="flex items-center gap-2"
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
              containerClassName="flex-1"
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
      </div>
    </div>
  );
}
