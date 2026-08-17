import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { LuRotateCcw } from '@/lib/icons';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { DashboardShell } from '@/components/dashboard/DashboardShell';
import { DashboardGrid, DashboardGridItem } from '@/components/dashboard/DashboardGrid';
import { FinanceSection } from '@/components/dashboard/FinanceSection';
import { LiveDashboardWidget } from '@/components/dashboard/registry';
import { WidgetErrorBoundary } from '@/components/ui/WidgetErrorBoundary';
import { DashboardPicker } from '@/components/dashboard/DashboardPicker';
import { dashboardLayoutApi } from '@/api/dashboard-layout';
import { useAuthStore } from '@/stores/authStore';
import { usePermission } from '@/hooks/usePermission';

/**
 * Task D1 — Default (widget-layout) dashboard.
 *
 * Previously the body of `/dashboard`. Now reachable both as the router's
 * fallback (when the user's role has no specialized dashboard, or they lack
 * the matching permission) AND directly at `/dashboard/default` so users can
 * always escape the role redirect when they want the generic view.
 *
 * Renders the user's effective layout (personal → role default → empty).
 * Each widget key resolves through the registry; unknown keys render as a
 * placeholder so a stale seed doesn't break the page.
 *
 * The original FinanceSection (Sprint 4 / Task 37) is kept for users with
 * `accounting.dashboard.view` because its body is deeper than a single
 * widget card.
 */
export default function DashboardDefaultPage() {
 const queryClient = useQueryClient();
 const user = useAuthStore((s) => s.user);
 const { can } = usePermission();
 const canSeeFinance = can('accounting.dashboard.view');
 const canResetLayout = can('dashboard.layout.reset');
 const roleLabel = user?.role?.name ?? 'Your';

 const layout = useQuery({
 queryKey: ['dashboard', 'layout', { rich: true }],
 queryFn: () => dashboardLayoutApi.layout({ rich: true }),
 });

 const widgetKeys = layout.data?.items?.map((widget) => widget.key) ?? [];
 const widgetData = useQuery({
 queryKey: ['dashboard', 'widget-data', widgetKeys],
 queryFn: () => dashboardLayoutApi.data(widgetKeys),
 enabled: widgetKeys.length > 0,
 refetchInterval: 60_000,
 });

 const reset = useMutation({
 mutationFn: () => dashboardLayoutApi.reset(layout.data?.version ?? ''),
 onSuccess: () => {
 toast.success('Dashboard reset to your role default.');
 queryClient.invalidateQueries({ queryKey: ['dashboard', 'layout'] });
 },
 onError: () => toast.error('Failed to reset layout.'),
 });

 const subtitle = canSeeFinance
 ? 'Foundation + Hire-to-Retire + Lean Accounting are live.'
 : `${roleLabel} priorities are shown from the data and permissions available to this account.`;

 return (
 <DashboardShell<Awaited<ReturnType<typeof dashboardLayoutApi.layout>>>
 title={`Welcome${user ? `, ${user.name}` : ''}`}
 subtitle={subtitle}
 query={layout}
 refreshingQueryKey={['dashboard', 'layout']}
 kpiCount={3}
 actions={layout.data ? (
 <div className="flex flex-wrap items-center justify-end gap-2">
 <DashboardPicker layout={layout.data.items} layoutVersion={layout.data.version} />
 {canResetLayout && layout.data.items.some((w) => w.source === 'user') && (
 <Button
 variant="ghost"
 icon={<LuRotateCcw size={14} />}
 onClick={() => reset.mutate()}
 loading={reset.isPending}
 aria-label="Reset dashboard layout to role default"
 >
 Reset
 </Button>
 )}
 </div>
 ) : undefined}
 >
 {(snapshot) => {
 const widgets = snapshot.items;
 return (
 <>
 {widgets.length === 0 ? (
 <Panel title="No widgets configured">
 <EmptyState
 size="compact"
 icon="grid"
 title="Nothing on your dashboard yet"
 description="Your role has no default widgets. Ask an administrator to set them up, or use the sidebar to open a module directly."
 />
 </Panel>
 ) : (
 <DashboardGrid>
 {widgets.map((item) => {
 return (
 <DashboardGridItem key={item.key} width={item.w} height={item.h}>
 <WidgetErrorBoundary>
 <LiveDashboardWidget
 widget={item}
 summary={widgetData.data?.[item.key]}
 loading={widgetData.isLoading}
 />
 </WidgetErrorBoundary>
 </DashboardGridItem>
 );
 })}
 </DashboardGrid>
 )}

 {/* Sprint 4 / Task 37 finance block — kept until widget-ised separately. */}
 {canSeeFinance && <FinanceSection />}
 </>
 );
 }}
 </DashboardShell>
 );
}
