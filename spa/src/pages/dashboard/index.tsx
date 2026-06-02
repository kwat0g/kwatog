import { Navigate } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { usePermission } from '@/hooks/usePermission';
import DashboardDefaultPage from '@/pages/dashboard/default';

/**
 * Task D1 — Role-default dashboard router.
 *
 * Reads `user.role.slug` from the auth store and, if a role-specific
 * dashboard exists AND the user has the gating permission, redirects with
 * `replace` (so the back-button doesn't trap the user in a redirect loop).
 * Otherwise renders the generic widget-layout home.
 *
 * The route table includes `/dashboard/default` as a direct escape hatch so
 * users who prefer the generic widgets can bookmark it; the redirect here
 * never points there to keep `/dashboard` as the canonical landing URL.
 *
 * Note: `production_manager` is the closest match to the adviser brief's
 * "Plant Manager" persona — there is no `plant_manager` role slug in our
 * RBAC catalog (see RolePermissionSeeder). `finance_officer` maps to
 * `/dashboard/finance` (the canonical URL after Task D5; the legacy
 * `/dashboard/accounting` route now 301s into it).
 */
const ROLE_DASHBOARDS: Record<string, { path: string; permission: string }> = {
  production_manager: { path: '/dashboard/plant-manager', permission: 'dashboard.plant_manager.view' },
  hr_officer:         { path: '/dashboard/hr',            permission: 'dashboard.hr.view' },
  ppc_head:           { path: '/dashboard/ppc',           permission: 'dashboard.ppc.view' },
  finance_officer:    { path: '/dashboard/finance',       permission: 'dashboard.accounting.view' },
  // D6, D7, D8 — New role-specific dashboards
  purchasing_officer: { path: '/dashboard/purchasing',    permission: 'dashboard.purchasing.view' },
  warehouse_staff:    { path: '/dashboard/warehouse',     permission: 'dashboard.warehouse.view' },
  qc_inspector:       { path: '/dashboard/quality',       permission: 'dashboard.quality.view' },
};

export default function DashboardPage() {
  const user = useAuthStore((s) => s.user);
  const { can } = usePermission();

  const roleSlug = user?.role?.slug;
  const target = roleSlug ? ROLE_DASHBOARDS[roleSlug] : undefined;

  if (target && can(target.permission)) {
    return <Navigate to={target.path} replace />;
  }

  return <DashboardDefaultPage />;
}
