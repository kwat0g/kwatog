import { lazy } from 'react';
import { Route, Navigate } from 'react-router-dom';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// CRM (Sprint 6 — Tasks 47, 48; SO edit added in audit §3.1)
const ProductsListPage = lazy(() => import('@/pages/crm/products'));
const CreateProductPage = lazy(() => import('@/pages/crm/products/create'));
const EditProductPage = lazy(() => import('@/pages/crm/products/edit'));
const ProductDetailPage = lazy(() => import('@/pages/crm/products/detail'));
const PriceAgreementsListPage = lazy(() => import('@/pages/crm/price-agreements'));
const CreatePriceAgreementPage = lazy(() => import('@/pages/crm/price-agreements/create'));
const EditPriceAgreementPage = lazy(() => import('@/pages/crm/price-agreements/edit'));
const SalesOrdersListPage = lazy(() => import('@/pages/crm/sales-orders'));
const CreateSalesOrderPage = lazy(() => import('@/pages/crm/sales-orders/create'));
const EditSalesOrderPage = lazy(() => import('@/pages/crm/sales-orders/edit'));
const SalesOrderDetailPage = lazy(() => import('@/pages/crm/sales-orders/detail'));

// Task 1 — CRM customer master
const CrmCustomersListPage = lazy(() => import('@/pages/crm/customers'));
const CrmCustomerCreatePage = lazy(() => import('@/pages/crm/customers/create'));
const CrmCustomerDetailPage = lazy(() => import('@/pages/crm/customers/detail'));
const CrmCustomerEditPage = lazy(() => import('@/pages/crm/customers/edit'));

// Sprint 7 Task 68 — customer complaints + 8D
const ComplaintsListPage = lazy(() => import('@/pages/crm/complaints'));
const ComplaintDetailPage = lazy(() => import('@/pages/crm/complaints/detail'));
const ComplaintCreatePage = lazy(() => import('@/pages/crm/complaints/create'));

// Commission tracking
const CommissionsListPage = lazy(() => import('@/pages/crm/commissions'));
const CommissionRatesPage = lazy(() => import('@/pages/crm/commissions/rates'));

// Sales pipeline — leads + opportunities (audit §3.1 follow-up)
const LeadsListPage = lazy(() => import('@/pages/crm/leads'));
const InquiryListPage = lazy(() => import('@/pages/crm/inquiries'));
const InquiryDetailPage = lazy(() => import('@/pages/crm/inquiries/detail'));
const CreateLeadPage = lazy(() => import('@/pages/crm/leads/create'));
const LeadDetailPage = lazy(() => import('@/pages/crm/leads/detail'));
const EditLeadPage = lazy(() => import('@/pages/crm/leads/edit'));
const OpportunitiesListPage = lazy(() => import('@/pages/crm/opportunities'));
const CreateOpportunityPage = lazy(() => import('@/pages/crm/opportunities/create'));
const OpportunityDetailPage = lazy(() => import('@/pages/crm/opportunities/detail'));
const EditOpportunityPage = lazy(() => import('@/pages/crm/opportunities/edit'));

export const crmRoutes = (
 <>
 {/* CRM module (Sprint 6 — Tasks 47, 48) */}
 <Route element={<ModuleGuard module="crm" />}>
 <Route path="/crm" element={<Navigate to="/crm/products" replace />} />

 {/* Sales pipeline — leads + opportunities (audit §3.1 follow-up) */}
 <Route path="/crm/leads"
 element={<PermissionGuard permission="crm.leads.view"><LeadsListPage /></PermissionGuard>} />
 <Route path="/crm/leads/create"
 element={<PermissionGuard permission="crm.leads.manage"><CreateLeadPage /></PermissionGuard>} />
 <Route path="/crm/leads/:id"
 element={<PermissionGuard permission="crm.leads.view"><LeadDetailPage /></PermissionGuard>} />
 <Route path="/crm/leads/:id/edit"
 element={<PermissionGuard permission="crm.leads.manage"><EditLeadPage /></PermissionGuard>} />

 {/* Public contact-form inbox — ERP-side reader for /landing/contact-inquiry */}
 <Route path="/crm/inquiries"
 element={<PermissionGuard permission="crm.inquiries.view"><InquiryListPage /></PermissionGuard>} />
 <Route path="/crm/inquiries/:id"
 element={<PermissionGuard permission="crm.inquiries.view"><InquiryDetailPage /></PermissionGuard>} />

 <Route path="/crm/opportunities"
 element={<PermissionGuard permission="crm.opportunities.view"><OpportunitiesListPage /></PermissionGuard>} />
 <Route path="/crm/opportunities/create"
 element={<PermissionGuard permission="crm.opportunities.manage"><CreateOpportunityPage /></PermissionGuard>} />
 <Route path="/crm/opportunities/:id"
 element={<PermissionGuard permission="crm.opportunities.view"><OpportunityDetailPage /></PermissionGuard>} />
 <Route path="/crm/opportunities/:id/edit"
 element={<PermissionGuard permission="crm.opportunities.manage"><EditOpportunityPage /></PermissionGuard>} />

 <Route path="/crm/products"
 element={<PermissionGuard permission="crm.products.view"><ProductsListPage /></PermissionGuard>} />
 <Route path="/crm/products/create"
 element={<PermissionGuard permission="crm.products.manage"><CreateProductPage /></PermissionGuard>} />
 <Route path="/crm/products/:id"
 element={<PermissionGuard permission="crm.products.view"><ProductDetailPage /></PermissionGuard>} />
 <Route path="/crm/products/:id/edit"
 element={<PermissionGuard permission="crm.products.manage"><EditProductPage /></PermissionGuard>} />

 <Route path="/crm/customers"
 element={<PermissionGuard permission="crm.sales_orders.view"><CrmCustomersListPage /></PermissionGuard>} />
 <Route path="/crm/customers/create"
 element={<PermissionGuard permission="accounting.customers.manage"><CrmCustomerCreatePage /></PermissionGuard>} />
 <Route path="/crm/customers/:id"
 element={<PermissionGuard permission="crm.sales_orders.view"><CrmCustomerDetailPage /></PermissionGuard>} />
 <Route path="/crm/customers/:id/edit"
 element={<PermissionGuard permission="accounting.customers.manage"><CrmCustomerEditPage /></PermissionGuard>} />

 <Route path="/crm/price-agreements"
 element={<PermissionGuard permission="crm.price_agreements.view"><PriceAgreementsListPage /></PermissionGuard>} />
 <Route path="/crm/price-agreements/create"
 element={<PermissionGuard permission="crm.price_agreements.manage"><CreatePriceAgreementPage /></PermissionGuard>} />
 <Route path="/crm/price-agreements/:id/edit"
 element={<PermissionGuard permission="crm.price_agreements.manage"><EditPriceAgreementPage /></PermissionGuard>} />

 <Route path="/crm/sales-orders"
 element={<PermissionGuard permission="crm.sales_orders.view"><SalesOrdersListPage /></PermissionGuard>} />
 <Route path="/crm/sales-orders/create"
 element={<PermissionGuard permission="crm.sales_orders.create"><CreateSalesOrderPage /></PermissionGuard>} />
 <Route path="/crm/sales-orders/:id"
 element={<PermissionGuard permission="crm.sales_orders.view"><SalesOrderDetailPage /></PermissionGuard>} />
 <Route path="/crm/sales-orders/:id/edit"
 element={<PermissionGuard permission="crm.sales_orders.update"><EditSalesOrderPage /></PermissionGuard>} />

 {/* Sprint 7 Task 68 — customer complaints + 8D */}
 <Route path="/crm/complaints"
 element={<PermissionGuard permission="crm.complaints.manage"><ComplaintsListPage /></PermissionGuard>} />
 <Route path="/crm/complaints/new"
 element={<PermissionGuard permission="crm.complaints.manage"><ComplaintCreatePage /></PermissionGuard>} />
 <Route path="/crm/complaints/:id"
 element={<PermissionGuard permission="crm.complaints.manage"><ComplaintDetailPage /></PermissionGuard>} />

 {/* Commission tracking */}
 <Route path="/crm/commissions"
 element={<PermissionGuard permission="crm.commissions.view"><CommissionsListPage /></PermissionGuard>} />
 <Route path="/crm/commissions/rates"
 element={<PermissionGuard permission="crm.commissions.manage"><CommissionRatesPage /></PermissionGuard>} />
 </Route>
 </>
);
