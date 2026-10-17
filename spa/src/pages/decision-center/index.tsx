/** DSS Wave 0-4 — Decision Center list page. Display only: every number comes from the API. */
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { LuTriangleAlert, LuCircleAlert, LuInfo, LuChevronDown } from '@/lib/icons';
import { reportMutationError } from '@/lib/formErrors';
import { dssApi } from '@/api/dss';
import type { Recommendation, RecommendationListParams, RecommendationSeverity } from '@/types/dss';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Input } from '@/components/ui/Input';
import { ToggleChip } from '@/components/ui/SegmentedControl';
import { DataTablePagination } from '@/components/ui/DataTablePagination';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatConfidence, formatImpact, rationaleEntries, severityVariant } from './helpers';

const severityIcon = (s: RecommendationSeverity) =>
  s === 'critical' ? LuTriangleAlert : s === 'warning' ? LuCircleAlert : LuInfo;

function WhyPanel({ rec }: { rec: Recommendation }) {
  const [open, setOpen] = useState(false);
  if (!open) {
    return (
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="text-xs text-link flex items-center gap-1"
      >
        Why this? <LuChevronDown size={12} />
      </button>
    );
  }
  return (
    <dl className="mt-2 rounded-md border border-subtle bg-elevated/40 px-3 py-2 space-y-1">
      <div className="flex justify-between gap-4 text-xs">
        <dt className="text-text-subtle">Detector</dt>
        <dd className="font-mono tabular-nums">
          {rec.detector_id} · {rec.detector_version}
        </dd>
      </div>
      <div className="flex justify-between gap-4 text-xs">
        <dt className="text-text-subtle">Data sufficiency</dt>
        <dd>{rec.data_sufficiency}</dd>
      </div>
      {rationaleEntries(rec.rationale).map(([k, v]) => (
        <div key={k} className="flex justify-between gap-4 text-xs">
          <dt className="text-text-subtle capitalize">{k}</dt>
          <dd className="font-mono tabular-nums text-right break-all">{v}</dd>
        </div>
      ))}
    </dl>
  );
}

function ActionRow({ rec }: { rec: Recommendation }) {
  const queryClient = useQueryClient();
  const [mode, setMode] = useState<'dismiss' | 'snooze' | null>(null);
  const [reason, setReason] = useState('');
  const [until, setUntil] = useState('');
  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['dss'] });

  const act = useMutation({
    mutationFn: (args: {
      action: 'accept' | 'dismiss' | 'snooze';
      reason?: string;
      snoozed_until?: string;
    }) =>
      dssApi.act(rec.id, args.action, { reason: args.reason, snoozed_until: args.snoozed_until }),
    onSuccess: (_, args) => {
      toast.success(
        args.action === 'accept'
          ? 'Recommendation accepted'
          : args.action === 'dismiss'
            ? 'Recommendation dismissed'
            : 'Recommendation snoozed',
      );
      setMode(null);
      invalidate();
    },
    onError: (error) => reportMutationError(error, 'Failed to update recommendation'),
  });

  return (
    <div className="mt-2 flex items-center gap-2 flex-wrap">
      <Button
        size="xs"
        variant="primary"
        disabled={act.isPending}
        onClick={() => act.mutate({ action: 'accept' })}
      >
        {act.isPending ? 'Working…' : 'Accept'}
      </Button>
      <Button
        size="xs"
        variant="secondary"
        onClick={() => setMode(mode === 'dismiss' ? null : 'dismiss')}
      >
        Dismiss
      </Button>
      <Button
        size="xs"
        variant="secondary"
        onClick={() => setMode(mode === 'snooze' ? null : 'snooze')}
      >
        Snooze
      </Button>
      {mode === 'dismiss' && (
        <form
          className="flex items-center gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            act.mutate({ action: 'dismiss', reason });
          }}
        >
          <Input
            placeholder="Why does this not apply? (required)"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            fieldSize="sm"
          />
          <Button
            size="xs"
            variant="danger"
            type="submit"
            disabled={act.isPending || !reason.trim()}
          >
            Confirm
          </Button>
        </form>
      )}
      {mode === 'snooze' && (
        <form
          className="flex items-center gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            act.mutate({ action: 'snooze', snoozed_until: new Date(until).toISOString() });
          }}
        >
          <Input
            type="date"
            value={until}
            onChange={(e) => setUntil(e.target.value)}
            fieldSize="sm"
          />
          <Button size="xs" variant="secondary" type="submit" disabled={act.isPending || !until}>
            Confirm
          </Button>
        </form>
      )}
    </div>
  );
}

export default function DecisionCenterPage() {
  const [filters, setFilters] = useState<RecommendationListParams>({ page: 1, per_page: 20 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['dss', 'recommendations', filters],
    queryFn: () => dssApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const toggleSeverity = (sev: RecommendationSeverity) =>
    setFilters((f) => ({ ...f, severity: f.severity === sev ? undefined : sev, page: 1 }));

  const groups = (data?.data ?? []).reduce<Record<RecommendationSeverity, Recommendation[]>>(
    (acc, r) => {
      (acc[r.severity] ||= []).push(r);
      return acc;
    },
    { critical: [], warning: [], info: [] },
  );

  const total = data?.meta.total ?? 0;

  return (
    <div>
      <PageHeader
        title="Decision Center"
        subtitle={data ? `${total} open recommendation${total === 1 ? '' : 's'}` : undefined}
      />

      <div className="px-5 pb-3 flex items-center gap-2 flex-wrap">
        {(['critical', 'warning', 'info'] as RecommendationSeverity[]).map((s) => (
          <ToggleChip key={s} active={filters.severity === s} onClick={() => toggleSeverity(s)}>
            <Chip variant={severityVariant(s)}>{s}</Chip>
          </ToggleChip>
        ))}
        <span className="text-2xs text-text-subtle ml-2">
          Advisory only — the DSS recommends, people decide.
        </span>
      </div>

      {isLoading && !data && <SkeletonTable columns={1} rows={6} />}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load recommendations"
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      )}

      {data && data.data.length === 0 && (
        <EmptyState
          icon="check-circle"
          title="No open recommendations"
          description="Nothing needs a decision right now. New recommendations appear here as detectors report them."
        />
      )}

      {data && data.data.length > 0 && (
        <div className="px-5 pb-6 space-y-4">
          {(['critical', 'warning', 'info'] as RecommendationSeverity[]).map((sev) => {
            const items = groups[sev];
            if (!items.length) return null;
            const SevIcon = severityIcon(sev);
            return (
              <section key={sev}>
                <h2 className="text-2xs uppercase tracking-wider text-muted font-medium mb-2 flex items-center gap-2">
                  <SevIcon size={11} />
                  <span>{sev}</span>
                  <span className="font-mono tabular-nums text-text-subtle">·</span>
                  <span className="font-mono tabular-nums text-text-subtle">{items.length}</span>
                </h2>
                <div className="rounded-md border border-subtle divide-y divide-subtle bg-canvas">
                  {items.map((rec) => {
                    const impact = formatImpact(
                      rec.impact_estimate,
                      rec.impact_unit,
                      rec.impact_is_estimate,
                    );
                    return (
                      <div key={rec.id} className="px-3 py-2.5">
                        <div className="flex items-center gap-2 flex-wrap">
                          <Link
                            to={`/decision-center/${rec.id}`}
                            className="text-sm font-medium text-link"
                          >
                            {rec.type.replace(/_/g, ' ')} · {rec.domain}
                          </Link>
                          <Chip variant="neutral">{rec.detector_id}</Chip>
                          {impact && (
                            <span className="font-mono tabular-nums text-xs text-primary">
                              {impact}
                            </span>
                          )}
                          <span className="text-2xs text-text-subtle">
                            confidence {formatConfidence(rec.confidence)}
                          </span>
                          {rec.data_sufficiency !== 'sufficient' && (
                            <Chip variant="warning">thin data</Chip>
                          )}
                        </div>
                        <WhyPanel rec={rec} />
                        <ActionRow rec={rec} />
                      </div>
                    );
                  })}
                </div>
              </section>
            );
          })}
          {data.meta.last_page > 1 && (
            <DataTablePagination
              meta={data.meta}
              perPage={filters.per_page}
              onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
              onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
            />
          )}
        </div>
      )}
    </div>
  );
}
