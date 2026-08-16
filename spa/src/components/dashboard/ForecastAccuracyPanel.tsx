import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { forecastingApi } from '@/api/forecasting';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';

interface Props {
 year?: number;
}

function percentage(value: number | null | undefined, signed = false): string {
 if (value === null || value === undefined || !Number.isFinite(value)) return '—';
 return `${signed && value > 0 ? '+' : ''}${value.toFixed(1)}%`;
}

export function ForecastAccuracyPanel({ year = new Date().getFullYear() }: Props) {
 const query = useQuery({
 queryKey: ['forecasting', 'accuracy', 'summary', year],
 queryFn: () => forecastingApi.accuracySummary(year),
 staleTime: 5 * 60_000,
 refetchInterval: 10 * 60_000,
 });
 const policyQuery = useQuery({
 queryKey: ['forecasting', 'options'],
 queryFn: () => forecastingApi.options(),
 staleTime: 300_000,
 });

 const detailsLink = (
 <Link to="/forecasting/accuracy" className="text-xs text-accent hover:underline">
 Details →
 </Link>
 );

 if (query.isLoading) {
 return (
 <Panel title="Forecast Accuracy" meta={String(year)} actions={detailsLink}>
 <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
 {[1, 2, 3].map((item) => <SkeletonBlock key={item} className="h-14 rounded-md" />)}
 </div>
 </Panel>
 );
 }

 if (query.isError) {
 return (
 <Panel title="Forecast Accuracy" meta={String(year)} actions={detailsLink}>
 <EmptyState
 size="compact"
 icon="alert-circle"
 title="Failed to load accuracy"
 action={<Button variant="secondary" size="sm" onClick={() => query.refetch()}>Retry</Button>}
 />
 </Panel>
 );
 }

 const accuracy = query.data;
 if (!accuracy || accuracy.periods_evaluated === 0) {
 return (
 <Panel title="Forecast Accuracy" meta={String(year)} actions={detailsLink}>
 <EmptyState
 size="compact"
 icon="bar-chart"
 title="No reconciled periods"
 description="Accuracy appears after forecast months have actual demand."
 />
 </Panel>
 );
 }

 const excellent = policyQuery.data?.accuracy_policy.excellent_mape;
 const acceptable = policyQuery.data?.accuracy_policy.acceptable_mape;
 const mapeStatus = accuracy.mape === null || excellent == null || acceptable == null
 ? 'No score'
 : accuracy.mape <= excellent
 ? 'Excellent'
 : accuracy.mape <= acceptable
 ? 'Acceptable'
 : 'Needs review';

 return (
 <Panel title="Forecast Accuracy" meta={String(year)} actions={detailsLink}>
 <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
 <div className="rounded-md bg-subtle px-3 py-2">
 <dt className="text-2xs uppercase tracking-wide text-muted">MAPE</dt>
 <dd className="mt-1 font-mono text-lg font-medium tabular-nums text-primary">
 {percentage(accuracy.mape)}
 </dd>
 <dd className="text-2xs text-muted">{mapeStatus}</dd>
 </div>
 <div className="rounded-md bg-subtle px-3 py-2">
 <dt className="text-2xs uppercase tracking-wide text-muted">Bias</dt>
 <dd className="mt-1 font-mono text-lg font-medium tabular-nums text-primary">
 {percentage(accuracy.bias, true)}
 </dd>
 <dd className="text-2xs text-muted">
 {accuracy.bias === null ? 'No score' : accuracy.bias > 0 ? 'Under forecast' : accuracy.bias < 0 ? 'Over forecast' : 'Balanced'}
 </dd>
 </div>
 <div className="rounded-md bg-subtle px-3 py-2">
 <dt className="text-2xs uppercase tracking-wide text-muted">Periods</dt>
 <dd className="mt-1 font-mono text-lg font-medium tabular-nums text-primary">
 {accuracy.periods_evaluated}
 </dd>
 <dd className="text-2xs text-muted">Reconciled</dd>
 </div>
 </dl>
 </Panel>
 );
}

export default ForecastAccuracyPanel;
