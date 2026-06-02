import { portalClient, getPortalCsrf } from './client';
import type {
  CustomerPortalUser,
  CustomerDashboardData,
  PortalSoSummary,
  PortalSoDetail,
  PortalInvoiceSummary,
  PortalInvoiceDetail,
  PortalDeliverySummary,
  PortalDeliveryDetail,
  PortalComplaint,
  EightDReportData,
  StatementOfAccount,
  DeliverySchedule,
  DeliveryScheduleLine,
} from '@/types/b2b';
import type { ChainStep } from '@/types/chain';

export const customerPortalApi = {
  // ── Auth ──────────────────────────────────────────
  login: async (email: string, password: string) => {
    await getPortalCsrf();
    const { data } = await portalClient.post<{ data: CustomerPortalUser }>('/b2b/customer/login', { email, password });
    return data.data;
  },

  logout: async () => {
    await portalClient.post('/b2b/customer/logout');
  },

  me: async () => {
    const { data } = await portalClient.get<{ data: CustomerPortalUser }>('/b2b/customer/me');
    return data.data;
  },

  // ── Dashboard ──────────────────────────────────────
  dashboard: async () => {
    const { data } = await portalClient.get<{ data: CustomerDashboardData }>('/b2b/customer/dashboard');
    return data.data;
  },

  // ── Sales Orders ───────────────────────────────────
  listOrders: async (params?: { status?: string; page?: number }) => {
    const { data } = await portalClient.get<{ data: PortalSoSummary[] }>('/b2b/customer/orders', { params });
    return data.data;
  },

  getOrder: async (id: string) => {
    const { data } = await portalClient.get<{ data: PortalSoDetail }>(`/b2b/customer/orders/${id}`);
    return data.data;
  },

  getOrderChain: async (id: string) => {
    const { data } = await portalClient.get<{ data: ChainStep[] }>(`/b2b/customer/orders/${id}/chain`);
    return data.data;
  },

  // ── Invoices ───────────────────────────────────────
  listInvoices: async (params?: { status?: string; page?: number }) => {
    const { data } = await portalClient.get<{ data: PortalInvoiceSummary[] }>('/b2b/customer/invoices', { params });
    return data.data;
  },

  getInvoice: async (id: string) => {
    const { data } = await portalClient.get<{ data: PortalInvoiceDetail }>(`/b2b/customer/invoices/${id}`);
    return data.data;
  },

  downloadInvoicePdf: async (id: string) => {
    const { data } = await portalClient.get<Blob>(`/b2b/customer/invoices/${id}/pdf`, {
      responseType: 'blob',
    });
    return data;
  },

  // ── Deliveries ─────────────────────────────────────
  listDeliveries: async () => {
    const { data } = await portalClient.get<{ data: PortalDeliverySummary[] }>('/b2b/customer/deliveries');
    return data.data;
  },

  getDelivery: async (id: string) => {
    const { data } = await portalClient.get<{ data: PortalDeliveryDetail }>(`/b2b/customer/deliveries/${id}`);
    return data.data;
  },

  // ── Complaints (RMA / Customer Complaints) ─────────
  listComplaints: async () => {
    const { data } = await portalClient.get<{ data: PortalComplaint[] }>('/b2b/customer/complaints');
    return data.data;
  },

  createComplaint: async (form: {
    order_id?: string;
    product_id?: string;
    severity: string;
    description: string;
    affected_quantity: number;
  }) => {
    const { data } = await portalClient.post<{ data: PortalComplaint; message: string }>('/b2b/customer/complaints', form);
    return data;
  },

  // ── 8D Report ──────────────────────────────────────
  get8dReport: async (complaintId: string) => {
    const { data } = await portalClient.get<{ data: EightDReportData }>(`/b2b/customer/complaints/${complaintId}/8d-report`);
    return data.data;
  },

  // ── Statement of Account ──────────────────────────
  getStatementOfAccount: async () => {
    const { data } = await portalClient.get<{ data: StatementOfAccount }>('/b2b/customer/statement-of-account');
    return data.data;
  },

  // ── Delivery Schedules ────────────────────────────
  listDeliverySchedules: async () => {
    const { data } = await portalClient.get<{ data: DeliverySchedule[] }>('/b2b/customer/delivery-schedules');
    return data.data;
  },

  createDeliverySchedule: async (form: {
    month: string;
    lines: DeliveryScheduleLine[];
  }) => {
    const { data } = await portalClient.post<{ data: DeliverySchedule; message: string }>('/b2b/customer/delivery-schedules', form);
    return data;
  },
};
