import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { RotateCcw } from 'lucide-react';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { FinanceSection } from '@/components/dashboard/FinanceSection';
import { getWidgetComponent } from '@/components/dashboard/registry';
import { WidgetErrorBoundary } from '@/components/ui/WidgetErrorBoundary';
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

  const layout = useQuery({
    queryKey: ['dashboard', 'layout'],
    queryFn: () => dashboardLayoutApi.layout(),
  });

  const reset = useMutation({
    mutationFn: () => dashboardLayoutApi.reset(),
    onSuccess: () => {
      toast.success('Dashboard reset to your role default.');
      queryClient.invalidateQueries({ queryKey: ['dashboard', 'layout'] });
    },
    onError: () => toast.error('Failed to reset layout.'),
  });

  const subtitle = canSeeFinance
    ? 'Foundation + Hire-to-Retire + Lean Accounting are live.'
    : 'Your widgets reflect the default layout for your role. You can save a personal layout to override.';

  return (
    <div>
      <PageHeader
        title={`Welcome${user ? `, ${user.name}` : ''}`}
        subtitle={subtitle}
        actions={
          canResetLayout && layout.data && layout.data.some((w) => w.source === 'user') && (
            <Button
              variant="secondary"
              size="sm"
              icon={<RotateCcw size={14} />}
              onClick={() => reset.mutate()}
              loading={reset.isPending}
              disabled={reset.isPending}
              aria-label="Reset dashboard layout to role default"
            >
              Reset to default
            </Button>
          )
        }
      />

      <div className="px-5 py-4">
        {/* Loading — PATTERNS.md §19: skeleton, not spinner. */}
        {layout.isLoading && !layout.data && <SkeletonDetail />}

        {/* Error */}
        {layout.isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load dashboard"
            description="We couldn't load your dashboard layout."
            action={
              <Button variant="secondary" onClick={() => layout.refetch()}>
                Retry
              </Button>
            }
          />
        )}

        {/* Empty (no widgets configured for this role) */}
        {layout.data && layout.data.length === 0 && (
          <Panel title="No widgets configured">
            <p className="text-sm text-muted">
              Your role doesn't have any default dashboard widgets yet. Contact
              an administrator to set them up, or use the navigation to access
              modules directly.
            </p>
          </Panel>
        )}

        {/* Widgets */}
        {layout.data && layout.data.length > 0 && (
          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
            {layout.data.map((item) => {
              const Component = getWidgetComponent(item.key);
              return (
                <div key={item.key} className="min-h-[120px]">
                  {Component ? (
                    <WidgetErrorBoundary>
                      <Component />
                    </WidgetErrorBoundary>
                  ) : (
                    <Panel title={item.name}>
                      <p className="text-sm text-muted">
                        Widget <code className="font-mono text-xs">{item.key}</code> is
                        registered server-side but no SPA component exists yet.
                      </p>
                    </Panel>
                  )}
                </div>
              );
            })}
          </div>
        )}

        {/* Sprint 4 / Task 37 finance block — kept until widget-ised separately. */}
        {canSeeFinance && (
          <div className="mt-4">
            <FinanceSection />
          </div>
        )}
      </div>
    </div>
  );
}
