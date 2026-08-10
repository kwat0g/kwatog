import { Navigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { dashboardLayoutApi } from '@/api/dashboard-layout';
import { SkeletonGrid } from '@/components/ui/Skeleton';

/**
 * Task D1 — dashboard router.
 *
 * The landing dashboard is resolved SERVER-SIDE from the user's permissions
 * (GET /dashboard/dispatch → DashboardDispatchService). This component holds
 * no role-to-dashboard mapping.
 *
 * It used to: a `ROLE_DASHBOARDS: Record<roleSlug, …>` literal switched on
 * `user.role.slug`, so a role that was renamed, added, or re-permissioned
 * silently fell through to the generic page. Five of the thirteen seeded
 * roles were missing from that map. Permissions now decide, and when several
 * dashboards qualify the rarest permission wins — see the service.
 *
 * The server returns `/dashboard/default` when nothing else qualifies, so
 * every branch here is a redirect to a route that already exists in
 * `dashboardRoutes`; `/dashboard/default` stays reachable directly as the
 * escape hatch for users who prefer the generic widget home.
 */
export default function DashboardPage() {
 const { data, isError } = useQuery({
 queryKey: ['dashboard', 'dispatch'],
 queryFn: () => dashboardLayoutApi.dispatch(),
 staleTime: 5 * 60_000,
 });

 // A failed dispatch must not strand the user on a spinner — the generic
 // widget home works for every authenticated role.
 if (isError) return <Navigate to="/dashboard/default" replace />;

 if (!data) return <SkeletonGrid count={6} className="px-5 py-4" />;

 return <Navigate to={data.target.path} replace />;
}
