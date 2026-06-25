import { lazy } from 'react';
import { Route } from 'react-router-dom';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// ADV11 — Demand & Sales Forecasting
const DemandForecastingPage  = lazy(() => import('@/pages/forecasting/demand'));
const StockOutProjectionPage = lazy(() => import('@/pages/forecasting/stock-out'));
const ForecastAccuracyPage   = lazy(() => import('@/pages/forecasting/accuracy'));

// ADV9 — Budgeting
const BudgetOverviewPage          = lazy(() => import('@/pages/budgeting'));
const BudgetCreatePage            = lazy(() => import('@/pages/budgeting/create'));
const BudgetDetailPage            = lazy(() => import('@/pages/budgeting/detail'));
const DepartmentBudgetDetailPage  = lazy(() => import('@/pages/budgeting/departments'));
const BudgetVsActualPage          = lazy(() => import('@/pages/budgeting/budget-vs-actual'));
const BudgetTransfersPage         = lazy(() => import('@/pages/budgeting/transfers'));

// ADV12 — Return Management (RMA)
const ReturnManagementListPage   = lazy(() => import('@/pages/return-management/list'));
const ReturnManagementDetailPage = lazy(() => import('@/pages/return-management/detail'));
const CreateReturnRequestPage    = lazy(() => import('@/pages/return-management/create'));

export const advancedRoutes = (
  <>
    {/* ADV11 — Demand & Sales Forecasting */}
    <Route path="/forecasting/demand"
      element={<PermissionGuard permission="forecasting.view"><DemandForecastingPage /></PermissionGuard>} />
    <Route path="/forecasting/stock-out"
      element={<PermissionGuard permission="forecasting.view"><StockOutProjectionPage /></PermissionGuard>} />
    <Route path="/forecasting/accuracy"
      element={<PermissionGuard permission="forecasting.view"><ForecastAccuracyPage /></PermissionGuard>} />

    {/* ADV9 — Budgeting */}
    <Route path="/budgeting" element={<PermissionGuard permission="budgeting.view"><BudgetOverviewPage /></PermissionGuard>} />
    <Route path="/budgeting/create" element={<PermissionGuard permission="budgeting.manage"><BudgetCreatePage /></PermissionGuard>} />
    <Route path="/budgeting/:id" element={<PermissionGuard permission="budgeting.view"><BudgetDetailPage /></PermissionGuard>} />
    <Route path="/budgeting/departments/:id" element={<PermissionGuard permission="budgeting.view"><DepartmentBudgetDetailPage /></PermissionGuard>} />
    <Route path="/budgeting/budget-vs-actual" element={<PermissionGuard permission="budgeting.view"><BudgetVsActualPage /></PermissionGuard>} />
    <Route path="/budgeting/transfers" element={<PermissionGuard permission="budgeting.view"><BudgetTransfersPage /></PermissionGuard>} />

    {/* ADV12 — Return Management (RMA) */}
    <Route path="/return-management" element={<PermissionGuard permission="return_management.view"><ReturnManagementListPage /></PermissionGuard>} />
    <Route path="/return-management/new" element={<PermissionGuard permission="return_management.manage"><CreateReturnRequestPage /></PermissionGuard>} />
    <Route path="/return-management/:id" element={<PermissionGuard permission="return_management.view"><ReturnManagementDetailPage /></PermissionGuard>} />
  </>
);
