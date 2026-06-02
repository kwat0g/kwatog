import { portalClient, getPortalCsrf } from './client';
import type {
  SupplierPortalUser,
  SupplierDashboardData,
  PortalPoSummary,
  PortalPoDetail,
  PortalInvoiceSummary,
  PortalInvoiceDetail,
  PortalDeliverySummary,
  PortalShippingDocument,
  SubmittedBill,
  VendorStatementOfAccount,
  DeliverySchedule,
} from '@/types/b2b';

export const supplierPortalApi = {
  // ── Auth ──────────────────────────────────────────
  login: async (email: string, password: string) => {
    await getPortalCsrf();
    const { data } = await portalClient.post<{ data: SupplierPortalUser }>('/b2b/supplier/login', { email, password });
    return data.data;
  },

  logout: async () => {
    await portalClient.post('/b2b/supplier/logout');
  },

  me: async () => {
    const { data } = await portalClient.get<{ data: SupplierPortalUser }>('/b2b/supplier/me');
    return data.data;
  },

  // ── Dashboard ──────────────────────────────────────
  dashboard: async () => {
    const { data } = await portalClient.get<{ data: SupplierDashboardData }>('/b2b/supplier/dashboard');
    return data.data;
  },

  // ── Purchase Orders ────────────────────────────────
  listPos: async (params?: { status?: string; page?: number }) => {
    const { data } = await portalClient.get<{ data: PortalPoSummary[] }>('/b2b/supplier/purchase-orders', { params });
    return data.data;
  },

  getPo: async (id: string) => {
    const { data } = await portalClient.get<{ data: PortalPoDetail }>(`/b2b/supplier/purchase-orders/${id}`);
    return data.data;
  },

  acknowledgePo: async (id: string) => {
    const { data } = await portalClient.post<{ message: string }>(`/b2b/supplier/purchase-orders/${id}/acknowledge`);
    return data;
  },

  // ── Shipments ──────────────────────────────────────
  updateShipment: async (poId: string, form: { tracking_number?: string; estimated_arrival?: string; notes?: string }) => {
    const { data } = await portalClient.post<{ message: string }>(`/b2b/supplier/purchase-orders/${poId}/shipment-update`, form);
    return data;
  },

  // ── Invoices ───────────────────────────────────────
  listInvoices: async (params?: { status?: string; page?: number }) => {
    const { data } = await portalClient.get<{ data: PortalInvoiceSummary[] }>('/b2b/supplier/invoices', { params });
    return data.data;
  },

  getInvoice: async (id: string) => {
    const { data } = await portalClient.get<{ data: PortalInvoiceDetail }>(`/b2b/supplier/invoices/${id}`);
    return data.data;
  },

  // ── Deliveries ─────────────────────────────────────
  listDeliveries: async () => {
    const { data } = await portalClient.get<{ data: PortalDeliverySummary[] }>('/b2b/supplier/deliveries');
    return data.data;
  },

  // ── Statement of Account ────────────────────────────
  statementOfAccount: async () => {
    const { data } = await portalClient.get<{ data: VendorStatementOfAccount }>('/b2b/supplier/statement-of-account');
    return data.data;
  },

  // ── Delivery Schedules ──────────────────────────────
  listDeliverySchedules: async () => {
    const { data } = await portalClient.get<{ data: DeliverySchedule[] }>('/b2b/supplier/delivery-schedules');
    return data.data;
  },

  createDeliverySchedule: async (form: {
    purchase_order_id: string;
    month: string;
    lines: Array<{ product_name: string; quantity: number; notes?: string }>;
  }) => {
    const { data } = await portalClient.post<{ data: DeliverySchedule; message: string }>('/b2b/supplier/delivery-schedules', form);
    return data;
  },

  // ── PDF Downloads ───────────────────────────────────
  downloadPoPdf: async (id: string) => {
    const { data } = await portalClient.get<Blob>(`/b2b/supplier/purchase-orders/${id}/pdf`, {
      responseType: 'blob',
    });
    return data;
  },

  downloadInvoicePdf: async (id: string) => {
    const { data } = await portalClient.get<Blob>(`/b2b/supplier/invoices/${id}/pdf`, {
      responseType: 'blob',
    });
    return data;
  },

  // ── Shipping Documents ──────────────────────────────
  listShippingDocuments: async (poId: string) => {
    const { data } = await portalClient.get<{ data: PortalShippingDocument[] }>(`/b2b/supplier/purchase-orders/${poId}/shipping-documents`);
    return data.data;
  },

  uploadShippingDocument: async (poId: string, form: FormData) => {
    const { data } = await portalClient.post<{ data: PortalShippingDocument; message: string }>(
      `/b2b/supplier/purchase-orders/${poId}/shipping-documents`,
      form,
      { headers: { 'Content-Type': 'multipart/form-data' } }
    );
    return data;
  },

  // ── Invoice Submission (Supplier → Draft Bill) ─────
  submitInvoice: async (poId: string, form: FormData) => {
    const { data } = await portalClient.post<{ data: SubmittedBill; message: string }>(
      `/b2b/supplier/purchase-orders/${poId}/submit-invoice`,
      form,
      { headers: { 'Content-Type': 'multipart/form-data' } }
    );
    return data;
  },

};
