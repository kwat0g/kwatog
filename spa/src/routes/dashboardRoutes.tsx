import { lazy, Suspense } from 'react';
import { Route, Navigate } from 'react-router-dom';
import { PermissionGuard } from '@/components/guards/PermissionGuard';
import { SkeletonDashboard, SkeletonKanban, SkeletonTable } from '@/components/ui/Skeleton';

const DashboardPage = lazy(() => import('@/pages/dashboard'));
// Task D1 — direct escape hatch to the generic widget-layout home, so users
// whose role redirects them away can still reach it explicitly.
const DashboardDefaultPage = lazy(() => import('@/pages/dashboard/default'));

// Sprint 8 dashboards (Tasks 72 + 73)
const PlantManagerDashboardPage = lazy(() => import('@/pages/dashboard/plant-manager'));
const HrDashboardPage = lazy(() => import('@/pages/dashboard/hr'));
const PpcDashboardPage = lazy(() => import('@/pages/dashboard/ppc'));
// Task D5 — dedicated Finance Officer dashboard.
const FinanceDashboardPage = lazy(() => import('@/pages/dashboard/finance'));

// D3, D6, D7, D8 — Role-specific dashboards
const PurchasingDashboardPage = lazy(() => import('@/pages/dashboard/purchasing'));
const WarehouseDashboardPage = lazy(() => import('@/pages/dashboard/warehouse'));
const QcDashboardPage = lazy(() => import('@/pages/dashboard/quality'));
const AdminDashboardPage = lazy(() => import('@/pages/dashboard/admin'));

// Task 15 — KPI Scorecard
const ScorecardPage = lazy(() => import('@/pages/dashboard/scorecard'));

// Cross-module pages (no specific module guard)
const CalendarPage = lazy(() => import('@/pages/calendar'));
const ApprovalsBoardPage = lazy(() => import('@/pages/approvals'));
const ChainTrackerPage = lazy(() => import('@/pages/chains'));
const ChainRecoveryPage = lazy(() => import('@/pages/chains/recovery'));
const NotificationsListPage = lazy(() => import('@/pages/notifications'));
const ActionCenterPage = lazy(() => import('@/pages/action-center'));
// /exceptions page file kept (scope-cut 2026-08-08) — reachable as the
// 'Exceptions' scope toggle on /action-center (?scope=exceptions).
const OperationsHealthPage = lazy(() => import('@/pages/admin/operations-health'));

const AdminUsersRolesHubPage = lazy(() => import('@/pages/admin/users-roles'));

const AdminActivityFeedPage = lazy(() => import('@/pages/admin/activity'));



export const dashboardRoutes = (
 <>
 {/* Task D1 — `/dashboard` is the role router; `/dashboard/default`
 is the explicit escape hatch to the generic widget-layout page. */}
 <Route path="/dashboard" element={<Suspense fallback={<SkeletonDashboard />}><DashboardPage /></Suspense>} />
 <Route path="/dashboard/default" element={<Suspense fallback={<SkeletonDashboard />}><DashboardDefaultPage /></Suspense>} />

 {/* Sprint 8 dashboards */}
 <Route path="/dashboard/plant-manager"
 element={<PermissionGuard permission="dashboard.plant_manager.view"><Suspense fallback={<SkeletonDashboard />}><PlantManagerDashboardPage /></Suspense></PermissionGuard>} />
 <Route path="/dashboard/hr"
 element={<PermissionGuard permission="dashboard.hr.view"><Suspense fallback={<SkeletonDashboard />}><HrDashboardPage /></Suspense></PermissionGuard>} />
 <Route path="/dashboard/ppc"
 element={<PermissionGuard permission="dashboard.ppc.view"><Suspense fallback={<SkeletonDashboard />}><PpcDashboardPage /></Suspense></PermissionGuard>} />
 {/* Task D1 — `/dashboard/finance` is the canonical Finance Officer dashboard.
 `/dashboard/accounting` is kept as a permanent redirect. */}
 <Route path="/dashboard/finance"
 element={<PermissionGuard permission="dashboard.accounting.view"><Suspense fallback={<SkeletonDashboard />}><FinanceDashboardPage /></Suspense></PermissionGuard>} />
 <Route path="/dashboard/accounting"
 element={<Navigate to="/dashboard/finance" replace />} />
 {/* D6, D7, D8 — New role-specific dashboards */}
 <Route path="/dashboard/purchasing"
 element={<PermissionGuard permission="dashboard.purchasing.view"><Suspense fallback={<SkeletonDashboard />}><PurchasingDashboardPage /></Suspense></PermissionGuard>} />
 <Route path="/dashboard/warehouse"
 element={<PermissionGuard permission="dashboard.warehouse.view"><Suspense fallback={<SkeletonDashboard />}><WarehouseDashboardPage /></Suspense></PermissionGuard>} />
 <Route path="/dashboard/quality"
 element={<PermissionGuard permission="dashboard.quality.view"><Suspense fallback={<SkeletonDashboard />}><QcDashboardPage /></Suspense></PermissionGuard>} />
 <Route path="/dashboard/admin"
 element={<PermissionGuard permission="dashboard.admin.view"><Suspense fallback={<SkeletonDashboard />}><AdminDashboardPage /></Suspense></PermissionGuard>} />
 {/* Task 15 — KPI Scorecard (any authenticated user) */}
 <Route path="/dashboard/scorecard"
 element={<Suspense fallback={<SkeletonDashboard />}><ScorecardPage /></Suspense>} />

 {/* Series F / Task F1 — Cross-module calendar */}
 <Route
 path="/calendar"
 element={<PermissionGuard permission="calendar.view"><Suspense fallback={<SkeletonTable columns={7} rows={6} />}><CalendarPage /></Suspense></PermissionGuard>}
 />

 {/* Series F / Task F2 — Approvals Kanban board */}
 <Route
 path="/approvals"
 element={<PermissionGuard permission="approvals.board.view"><Suspense fallback={<SkeletonKanban />}><ApprovalsBoardPage /></Suspense></PermissionGuard>}
 />

 {/* Chain Tracker — cross-module order-to-cash journey view */}
 <Route
 path="/chains/recovery"
 element={<PermissionGuard permission="dashboard.chain_recovery.view"><Suspense fallback={<SkeletonDashboard />}><ChainRecoveryPage /></Suspense></PermissionGuard>}
 />
 <Route
 path="/chains"
 element={<PermissionGuard permission="crm.sales_orders.view"><Suspense fallback={<SkeletonDashboard />}><ChainTrackerPage /></Suspense></PermissionGuard>}
 />

 {/* Series F / Task F7 — System activity feed */}
 <Route
 path="/admin/activity"
 element={<PermissionGuard permission="admin.activity.view"><Suspense fallback={<SkeletonTable columns={5} rows={10} />}><AdminActivityFeedPage /></Suspense></PermissionGuard>}
 />

 <Route path="/admin/users-roles"
 element={<PermissionGuard permission="admin.users.manage"><Suspense fallback={<SkeletonTable columns={5} rows={8} />}><AdminUsersRolesHubPage /></Suspense></PermissionGuard>} />

 {/* Notifications page (Sprint 8 — Task 77) */}
 <Route path="/notifications" element={<PermissionGuard permission="notifications.view"><Suspense fallback={<SkeletonTable columns={4} rows={8} />}><NotificationsListPage /></Suspense></PermissionGuard>} />
 <Route path="/action-center" element={<PermissionGuard permission="dashboard.action_center.view"><Suspense fallback={<SkeletonDashboard />}><ActionCenterPage /></Suspense></PermissionGuard>} />
 {/* /exceptions removed 2026-08-08 (scope cut — same queue as /action-center,
 only with the approval category filtered out; now a scope toggle there). */}
 <Route path="/admin/operations-health"
 element={<PermissionGuard permission="dashboard.admin.view"><Suspense fallback={<SkeletonDashboard />}><OperationsHealthPage /></Suspense></PermissionGuard>} />
 </>
);
