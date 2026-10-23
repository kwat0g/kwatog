/** DSS Wave 0-4 — Recommendation detail: full explanation + lifecycle actions + outcome. */
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { reportMutationError } from '@/lib/formErrors';
import { dssApi } from '@/api/dss';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Input } from '@/components/ui/Input';
import { LinkButton } from '@/components/ui/LinkButton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatConfidence, formatImpact, rationaleEntries, severityVariant } from './helpers';

const statusVariant = (s: string): 'neutral' | 'info' | 'warning' | 'danger' =>
  s === 'open'
    ? 'info'
    : s === 'executed' || s === 'accepted'
      ? 'neutral'
      : s === 'snoozed'
        ? 'warning'
        : 'neutral';

export default function RecommendationDetailPage() {
  const { id = '' } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [outcomeNote, setOutcomeNote] = useState('');

  const {
    data: rec,
    isLoading,
    isError,
    refetch,
  } = useQuery({
    queryKey: ['dss', 'recommendations', id],
    queryFn: () => dssApi.show(id),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['dss'] });
  };

  const act = useMutation({
    mutationFn: (action: 'accept' | 'execute') => dssApi.act(id, action),
    onSuccess: (_, action) => {
      toast.success(action === 'accept' ? 'Recommendation accepted' : 'Marked as executed');
      invalidate();
    },
    onError: (error) => reportMutationError(error, 'Failed to update recommendation'),
  });

  const saveOutcome = useMutation({
    mutationFn: () => dssApi.outcome(id, { note: outcomeNote }),
    onSuccess: () => {
      toast.success('Outcome recorded');
      setOutcomeNote('');
      invalidate();
    },
    onError: (error) => reportMutationError(error, 'Failed to record outcome'),
  });

  if (isLoading && !rec) return <SkeletonTable columns={1} rows={6} />;

  if (isError || !rec) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Recommendation not found"
        description="It may have expired, or you may not have access to its detector."
        action={
          <Button variant="secondary" onClick={() => refetch()}>
            Retry
          </Button>
        }
      />
    );
  }

  const impact = formatImpact(rec.impact_estimate, rec.impact_unit, rec.impact_is_estimate);

  return (
    <div>
      <PageHeader
        title={`${rec.type.replace(/_/g, ' ')} · ${rec.domain}`}
        subtitle={`${rec.detector_id} · ${rec.detector_version}`}
      />
      <div className="px-5 pb-6 space-y-4 max-w-3xl">
        <div className="flex items-center gap-2 flex-wrap">
          <Chip variant={severityVariant(rec.severity)}>{rec.severity}</Chip>
          <Chip variant={statusVariant(rec.status)}>{rec.status}</Chip>
          {impact && <span className="font-mono tabular-nums text-sm text-primary">{impact}</span>}
          <span className="text-xs text-text-subtle">
            confidence {formatConfidence(rec.confidence)}
          </span>
          {rec.data_sufficiency !== 'sufficient' && <Chip variant="warning">thin data</Chip>}
        </div>

        <section className="rounded-md border border-subtle bg-canvas px-3 py-2.5">
          <h2 className="text-xs font-medium mb-2">Why this?</h2>
          <dl className="space-y-1">
            {rationaleEntries(rec.rationale).map(([k, v]) => (
              <div key={k} className="flex justify-between gap-4 text-xs">
                <dt className="text-text-subtle capitalize">{k}</dt>
                <dd className="font-mono tabular-nums text-right break-all">{v}</dd>
              </div>
            ))}
          </dl>
        </section>

        {rec.action?.route && (
          <section className="rounded-md border border-subtle bg-canvas px-3 py-2.5">
            <h2 className="text-xs font-medium mb-2">Suggested next step</h2>
            <LinkButton tone="accent" onClick={() => navigate(rec.action!.route!)}>
              {rec.action.label ?? 'Open prefilled workflow →'}
            </LinkButton>
          </section>
        )}

        {rec.status === 'open' && (
          <Button variant="primary" disabled={act.isPending} onClick={() => act.mutate('accept')}>
            {act.isPending ? 'Working…' : 'Accept'}
          </Button>
        )}
        {rec.status === 'accepted' && (
          <Button variant="primary" disabled={act.isPending} onClick={() => act.mutate('execute')}>
            {act.isPending ? 'Working…' : 'Mark as executed'}
          </Button>
        )}

        {(rec.status === 'accepted' || rec.status === 'executed' || rec.status === 'dismissed') && (
          <form
            className="flex items-center gap-2"
            onSubmit={(e) => {
              e.preventDefault();
              saveOutcome.mutate();
            }}
          >
            <Input
              placeholder="Measured result (e.g. stock-out avoided, ₱ saved)"
              value={outcomeNote}
              onChange={(e) => setOutcomeNote(e.target.value)}
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

        <Link to="/decision-center" className="text-xs text-link">
          ← Back to Decision Center
        </Link>
      </div>
    </div>
  );
}
