import { lazy } from 'react';
import { Route } from 'react-router-dom';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';
import SelfServiceLayout from '@/layouts/SelfServiceLayout';

// Self-service (Sprint 8 — Task 74; U3 adds /leaves, /profile, /loans canonical slugs)
const SelfServiceHomePage = lazy(() => import('@/pages/self-service'));
const SelfServiceDtrPage = lazy(() => import('@/pages/self-service/dtr'));
const SelfServiceLeavePage = lazy(() => import('@/pages/self-service/leave'));
const SelfServiceLeavesPage = lazy(() => import('@/pages/self-service/leaves'));
const LeaveDetailPage = lazy(() => import('@/pages/leaves/detail'));
const SelfServiceMePage = lazy(() => import('@/pages/self-service/me'));
const SelfServiceProfilePage = lazy(() => import('@/pages/self-service/profile'));
const SelfServiceLoansPage = lazy(() => import('@/pages/self-service/loans'));
const SelfServiceOvertimePage = lazy(() => import('@/pages/self-service/overtime'));
const SelfServiceDocumentsPage = lazy(() => import('@/pages/self-service/documents'));
const SelfServiceNotifPrefsPage = lazy(() => import('@/pages/self-service/notification-preferences'));
const SelfServicePayslipsPage = lazy(() => import('@/pages/self-service/payslips'));
const SelfServicePayslipDetailPage = lazy(() => import('@/pages/self-service/payslip-detail'));
const TrainingRecordDetailPage = lazy(() => import('@/pages/hr/training/record-detail'));

const MyWorkOrdersPage = lazy(() => import('@/pages/self-service/work-orders'));
const MyWorkOrderDetailPage = lazy(() => import('@/pages/self-service/work-orders').then((module) => ({ default: module.MyWorkOrderDetailPage })));

const MyMaintenanceOrdersPage = lazy(() => import('@/pages/maintenance/work-orders'));
const MyMaintenanceOrderPage = lazy(() => import('@/pages/maintenance/work-orders/responsive-detail'));

export const selfServiceRoutes = (
 <>
 <Route element={<SelfServiceLayout />}>
 <Route path="/self-service" element={<SelfServiceHomePage />} />
 <Route path="/self-service/profile" element={<SelfServiceProfilePage />} />
 <Route path="/self-service/trainings/:id" element={<TrainingRecordDetailPage selfService />} />
 <Route path="/self-service/me" element={<SelfServiceMePage />} />
 <Route path="/self-service/documents" element={<SelfServiceDocumentsPage />} />
 <Route path="/self-service/notification-preferences" element={<PermissionGuard permission="notifications.preferences.manage"><SelfServiceNotifPrefsPage /></PermissionGuard>} />

 <Route element={<ModuleGuard module="production" />}>
 <Route path="/self-service/work-orders" element={<MyWorkOrdersPage />} />
 <Route path="/self-service/work-orders/:id" element={<MyWorkOrderDetailPage />} />
 </Route>

 <Route element={<ModuleGuard module="maintenance" />}>
 <Route path="/self-service/maintenance-work-orders" element={<PermissionGuard permission="maintenance.work.assigned"><MyMaintenanceOrdersPage /></PermissionGuard>} />
 <Route path="/self-service/maintenance-work-orders/:id" element={<PermissionGuard permission="maintenance.work.assigned"><MyMaintenanceOrderPage /></PermissionGuard>} />
 </Route>

 {/* Attendance-gated */}
 <Route element={<ModuleGuard module="attendance" />}>
 <Route path="/self-service/dtr" element={<SelfServiceDtrPage />} />
 <Route path="/self-service/overtime/:id" element={<SelfServiceOvertimePage />} />
 <Route path="/self-service/overtime" element={<SelfServiceOvertimePage />} />
 </Route>

 {/* Leave-gated */}
 <Route element={<ModuleGuard module="leave" />}>
 <Route path="/self-service/leave" element={<SelfServiceLeavePage />} />
 <Route path="/self-service/leaves" element={<SelfServiceLeavesPage />} />
 <Route
 path="/self-service/leaves/:id"
 element={<PermissionGuard permission="leave.view"><LeaveDetailPage /></PermissionGuard>}
 />
 </Route>

 {/* Loans-gated */}
 <Route element={<ModuleGuard module="loans" />}>
 <Route path="/self-service/loans/:id" element={<SelfServiceLoansPage />} />
 <Route path="/self-service/loans" element={<SelfServiceLoansPage />} />
 </Route>

 {/* Payroll-gated */}
 <Route element={<ModuleGuard module="payroll" />}>
 <Route
 path="/self-service/payslips/:id"
 element={<PermissionGuard permission="payroll.view"><SelfServicePayslipDetailPage /></PermissionGuard>}
 />
 <Route
 path="/self-service/payslips"
 element={<PermissionGuard permission="payroll.view"><SelfServicePayslipsPage /></PermissionGuard>}
 />
 </Route>
 </Route>
 </>
);
