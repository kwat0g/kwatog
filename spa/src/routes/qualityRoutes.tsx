import { lazy } from 'react';
import { Route, Navigate } from 'react-router-dom';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// Quality (Sprint 7 — Task 59)
const InspectionSpecsListPage  = lazy(() => import('@/pages/quality/inspection-specs'));
const InspectionSpecEditorPage = lazy(() => import('@/pages/quality/inspection-specs/editor'));
const InspectionsListPage      = lazy(() => import('@/pages/quality/inspections'));
const InspectionDetailPage     = lazy(() => import('@/pages/quality/inspections/detail'));
const InspectionCreatePage     = lazy(() => import('@/pages/quality/inspections/create'));
const QualityDashboardPage     = lazy(() => import('@/pages/quality/dashboard'));
const NcrsListPage             = lazy(() => import('@/pages/quality/ncrs'));
const NcrDetailPage            = lazy(() => import('@/pages/quality/ncrs/detail'));
const NcrCreatePage            = lazy(() => import('@/pages/quality/ncrs/create'));
// ADV7 — NCR Templates
const NcrTemplatesListPage = lazy(() => import('@/pages/quality/ncr-templates'));
const NcrTemplateFormPage  = lazy(() => import('@/pages/quality/ncr-templates/create'));
// ADV3 — IATF 16949 traceability search
const TraceabilityPage = lazy(() => import('@/pages/quality/traceability'));
// SPC — Statistical Process Control
const SpcChartsListPage     = lazy(() => import('@/pages/quality/spc'));
const SpcChartDetailPage    = lazy(() => import('@/pages/quality/spc/chart-detail'));
const SpcCapabilityStudyPage = lazy(() => import('@/pages/quality/spc/capability-study'));
// COPQ — Cost of Poor Quality analytics
const CopqAnalyticsPage = lazy(() => import('@/pages/quality/copq'));
// Task 16 — Document Control
const DocumentsListPage  = lazy(() => import('@/pages/quality/documents'));
const DocumentDetailPage = lazy(() => import('@/pages/quality/documents/detail'));
const DocumentCreatePage = lazy(() => import('@/pages/quality/documents/create'));

export const qualityRoutes = (
  <>
    {/* Quality module (Sprint 7 — Tasks 59 + 60) */}
    <Route element={<ModuleGuard module="quality" />}>
      <Route path="/quality" element={<Navigate to="/quality/dashboard" replace />} />
      <Route path="/quality/dashboard"
        element={<PermissionGuard permission="quality.view"><QualityDashboardPage /></PermissionGuard>} />
      <Route path="/quality/inspection-specs"
        element={<PermissionGuard permission="quality.specs.view"><InspectionSpecsListPage /></PermissionGuard>} />
      <Route path="/quality/inspection-specs/:productId"
        element={<PermissionGuard permission="quality.specs.view"><InspectionSpecEditorPage /></PermissionGuard>} />
      <Route path="/quality/inspections"
        element={<PermissionGuard permission="quality.inspections.view"><InspectionsListPage /></PermissionGuard>} />
      <Route path="/quality/inspections/new"
        element={<PermissionGuard permission="quality.inspections.manage"><InspectionCreatePage /></PermissionGuard>} />
      <Route path="/quality/inspections/:id"
        element={<PermissionGuard permission="quality.inspections.view"><InspectionDetailPage /></PermissionGuard>} />
      <Route path="/quality/ncrs"
        element={<PermissionGuard permission="quality.ncr.view"><NcrsListPage /></PermissionGuard>} />
      <Route path="/quality/ncrs/new"
        element={<PermissionGuard permission="quality.ncr.manage"><NcrCreatePage /></PermissionGuard>} />
      <Route path="/quality/ncrs/:id"
        element={<PermissionGuard permission="quality.ncr.view"><NcrDetailPage /></PermissionGuard>} />
      {/* ADV7 — NCR Templates */}
      <Route path="/quality/ncr-templates"
        element={<PermissionGuard permission="quality.ncr.manage"><NcrTemplatesListPage /></PermissionGuard>} />
      <Route path="/quality/ncr-templates/new"
        element={<PermissionGuard permission="quality.ncr.manage"><NcrTemplateFormPage /></PermissionGuard>} />
      <Route path="/quality/ncr-templates/:id/edit"
        element={<PermissionGuard permission="quality.ncr.manage"><NcrTemplateFormPage /></PermissionGuard>} />
      {/* ADV3 — IATF 16949 traceability search */}
      <Route path="/quality/traceability"
        element={<PermissionGuard permission="quality.inspections.view"><TraceabilityPage /></PermissionGuard>} />
      {/* SPC — Statistical Process Control */}
      <Route path="/quality/spc"
        element={<PermissionGuard permission="quality.spc.view"><SpcChartsListPage /></PermissionGuard>} />
      <Route path="/quality/spc/capability"
        element={<PermissionGuard permission="quality.spc.view"><SpcCapabilityStudyPage /></PermissionGuard>} />
      <Route path="/quality/spc/:id"
        element={<PermissionGuard permission="quality.spc.view"><SpcChartDetailPage /></PermissionGuard>} />
      {/* COPQ — Cost of Poor Quality analytics */}
      <Route path="/quality/copq"
        element={<PermissionGuard permission="quality.copq.view"><CopqAnalyticsPage /></PermissionGuard>} />
      {/* Task 16 — Document Control */}
      <Route path="/quality/documents"
        element={<PermissionGuard permission="quality.documents.view"><DocumentsListPage /></PermissionGuard>} />
      <Route path="/quality/documents/new"
        element={<PermissionGuard permission="quality.documents.manage"><DocumentCreatePage /></PermissionGuard>} />
      <Route path="/quality/documents/:id"
        element={<PermissionGuard permission="quality.documents.view"><DocumentDetailPage /></PermissionGuard>} />
    </Route>
  </>
);
