import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { DashboardShell } from '@/components/dashboard/DashboardShell';
import { KpiStrip } from '@/components/dashboard/KpiStrip';
import { COMPANY_KPIS } from '@/lib/dashboardKpis';
import { DashboardGrid, DashboardGridItem } from '@/components/dashboard/DashboardGrid';
import { LiveDashboardWidget } from '@/components/dashboard/registry';
import { dashboardLayoutApi } from '@/api/dashboard-layout';
import { DASHBOARD_REFRESH_MS } from '@/api/dashboards';

/**
 * Company overview — the cross-functional executive surface (DSS 01-matrix §B).
 *
 * Deliberately a THIN page, and the shell is the deliverable: it is dispatched
 * by holding `dashboard.company.view` (never by role name), its health strip is
 * the six company KPIs, and its body is the widget registry. The eight blocks
 * §B specifies are not new analytics — every one of them already exists as a
 * gated widget or KPI reading, so re-deriving them here would have created a
 * second, differently-gated copy of numbers the modules already publish.
 *
 * What the viewer sees is permission-derived end to end:
 *
 *   health strip → the KPI scorecard, whose gate is a slug SET that admits the
 *                  company-wide read (KpiSnapshotService::moduleGates)
 *   body         → the effective layout (personal → role default), stripped by
 *                  DashboardLayoutService against each widget's own gate
 *
 * That is why a block can simply be absent. Money, chain health, alerts, demand
 * vs supply and the approvals worklist render for an executive; §B's quality
 * panel does not, because it draws on `quality.*` grants the executive does not
 * hold — and omitting a panel is the documented rule. Nothing here names a role,
 * so granting that slug adds the block with no code change.
 *
 * Demand-vs-supply is the block that proved the rule rather than dodging it. It
 * was absent because its two forecasts were gated on module PAGE slugs
 * (`forecasting.view`, `accounting.dashboard.view`) that the executive does not
 * hold and has no reason to; re-gating them on the datasets' read SETS — which
 * admit `dashboard.company.view` — put the block here without granting a single
 * new authority. A widget's gate is the read of its data, and this page is where
 * that stops being a slogan.
 *
 * Unlike `/dashboard/default` there is no picker or reset: this is a survey of
 * the company, and its block set is the role default rather than a personal
 * choice. Personal customisation still exists — it just belongs to the widget
 * home, which keeps one layout per user instead of two that can disagree.
 */
export default function CompanyDashboardPage() {
  const layout = useQuery({
    queryKey: ['dashboard', 'layout', { rich: true }],
    queryFn: () => dashboardLayoutApi.layout({ rich: true }),
  });

  const widgetKeys = layout.data?.items?.map((widget) => widget.key) ?? [];

  const widgetData = useQuery({
    queryKey: ['dashboard', 'widget-data', widgetKeys],
    queryFn: () => dashboardLayoutApi.data(widgetKeys),
    enabled: widgetKeys.length > 0,
    refetchInterval: DASHBOARD_REFRESH_MS,
  });

  // Freshness for the shell's stale badge: the newest widget-data timestamp.
  const updatedAt = useMemo(() => {
    const times = Object.values(widgetData.data ?? {})
      .map((summary) => Date.parse(summary.updated_at))
      .filter((time) => Number.isFinite(time));

    return times.length > 0 ? new Date(Math.max(...times)).toISOString() : null;
  }, [widgetData.data]);

  return (
    <DashboardShell<Awaited<ReturnType<typeof dashboardLayoutApi.layout>>>
      title="Company Overview"
      subtitle="Cross-functional read: money, chain health and approvals across all three chains."
      query={layout}
      refreshingQueryKey={['dashboard', 'layout']}
      kpiCount={COMPANY_KPIS.length}
      updatedAt={updatedAt}
      header={<KpiStrip codes={COMPANY_KPIS} compact />}
    >
      {(snapshot) => {
        const widgets = snapshot.items;

        return (
          <>
            {widgetData.isError && (
              <div role="alert" className="text-sm text-danger-fg">
                Dashboard values could not be refreshed. Displayed values may be outdated.
              </div>
            )}

            {widgets.length === 0 ? (
              <Panel title="No blocks configured">
                <EmptyState
                  size="compact"
                  icon="grid"
                  title="Nothing on this overview yet"
                  description="Your role has no default blocks for this page. Money, chain health, alerts and approvals arrive as you hold those module reads."
                />
              </Panel>
            ) : (
              <DashboardGrid>
                {widgets.map((item) => (
                  <DashboardGridItem key={item.key} width={item.w} height={item.h}>
                    <LiveDashboardWidget
                      widget={item}
                      summary={widgetData.data?.[item.key]}
                      loading={widgetData.isLoading}
                      onRetry={() => {
                        void widgetData.refetch();
                      }}
                    />
                  </DashboardGridItem>
                ))}
              </DashboardGrid>
            )}
          </>
        );
      }}
    </DashboardShell>
  );
}
