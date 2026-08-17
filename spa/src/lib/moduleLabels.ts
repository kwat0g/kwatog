// Display names for the first URL segment of every module route.
//
// Lives in lib rather than in Breadcrumbs because PageHeader also needs it (for
// document.title), and a component file that exports a constant breaks React
// Fast Refresh for the whole module.

/**
 * Restructured module names — ADV2 sidebar IA. Keyed by the **first**
 * URL segment only; later segments fall through to TITLE_OVERRIDES /
 * titleize().
 */
export const MODULE_LABELS: Record<string, string> = {
 dashboard: 'Dashboard',
 'action-center': 'Action Center',
 exceptions: 'Exception Workbench',
 alerts: 'Alerts',
 calendar: 'Calendar',
 approvals: 'Approvals',
 notifications: 'Notifications',
 crm: 'Sales & CRM',
 mrp: 'Production Planning',
 production: 'Production',
 'supply-chain': 'Supply Chain',
 purchasing: 'Procurement',
 inventory: 'Warehouse',
 quality: 'Quality Control',
 accounting: 'Finance & Accounting',
 hr: 'Human Resources',
 payroll: 'Payroll & Benefits',
 maintenance: 'Maintenance',
 assets: 'Maintenance',
 admin: 'Administration',
 'self-service': 'Self-service',
};
