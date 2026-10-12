import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { DashboardShell } from '@/components/dashboard/DashboardShell';
import { KpiStrip } from '@/components/dashboard/KpiStrip';
import { DashboardGrid, DashboardGridItem } from '@/components/dashboard/DashboardGrid';
import { LiveDashboardWidget } from '@/components/dashboard/registry';
import { dashboardLayoutApi } from '@/api/dashboard-layout';
import { DASHBOARD_REFRESH_MS } from '@/api/dashboards';

/** The layout envelope the render prop receives. Named so the JSX open tag does
 * not need a four-deep generic argument list, which the TSX parser reads as
 * shift operators. */
type LayoutSnapshot = Awaited<ReturnType<typeof dashboardLayoutApi.layout>>;

interface RoleDashboardShellProps {
  title: string;
  subtitle: string;
  /** KPI codes for the health strip. Empty when the role holds no KPI gate. */
  kpiCodes: string[];
  emptyTitle: string;
  emptyDescription: string;
}

/**
 * The body every purpose-built role dashboard shares (DSS 01-matrix §D3).
 *
 * Extracted from `pages/dashboard/company.tsx` when the five remaining role
 * pages were added, for the reason CLAUDE.md gives for every other shared rule
 * here: six pages had begun to be six copies of one idea, and the copy that
 * drifts is the one that still strips a widget the viewer may not read.
 *
 * What a viewer sees is permission-derived end to end, and this component never
 * names a role:
 *
 *   health strip → the KPI scorecard, whose gate is a slug SET per module
 *                  (KpiSnapshotService::moduleGates). A code the viewer may not
 *                  read renders its empty message, never a crash.
 *   body         → the effective layout (personal → role default), stripped by
 *                  DashboardLayoutService against each widget's own gate.
 *
 * That is why a block can simply be absent, and why it is correct for it to be:
 * adding a block to a role's default is a `dashboard_layouts` decision, and
 * granting one is a `role_permissions` decision. Neither is a code change, and
 * neither page needs to know.
 *
 * Deliberately no picker or reset here — the widget home owns personal
 * customisation, and one layout per user beats two that can disagree.
 */
export function RoleDashboardShell({
  title,
  subtitle,
  kpiCodes,
  emptyTitle,
  emptyDescription,
}: RoleDashboardShellProps) {
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

  // Hoisted rather than inlined in the attribute: a self-closing element inside
  // a JSX ternary inside an attribute value mis-parses (esbuild reads the `/>`
  // as closing the outer element).
  const headerNode = kpiCodes.length > 0 ? <KpiStrip codes={kpiCodes} compact /> : undefined;

  return (
    <DashboardShell<LayoutSnapshot>
      title={title}
      subtitle={subtitle}
      query={layout}
      refreshingQueryKey={['dashboard', 'layout']}
      kpiCount={kpiCodes.length}
      updatedAt={updatedAt}
      header={headerNode}
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
                  title={emptyTitle}
                  description={emptyDescription}
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
