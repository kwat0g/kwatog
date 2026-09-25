import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { MaterialIssueSlip } from '@/types/inventory';

export interface MaterialIssueOptions {
 work_orders: Array<{
  id: string;
  wo_number: string;
  status: 'confirmed' | 'in_progress' | 'paused';
  product_part_number: string | null;
 }>;
 sources: Array<{
  location_id: string;
  label: string;
  available: string;
  reserved_for_work_order: string;
  is_blocked: boolean;
 }>;
 reservations: Array<{
  id: string;
  location_id: string;
  quantity: string;
  reserved_at: string | null;
 }>;
}

export const materialIssuesApi = {
 options: (params?: { work_order_id?: string; item_id?: string }) =>
  client.get<{ data: MaterialIssueOptions }>('/inventory/material-issues/options', { params }).then((r) => r.data.data),
 list: (params?: ListParams & { status?: string; from?: string; to?: string }) =>
 client.get<PaginatedResponse<MaterialIssueSlip>>('/inventory/material-issues', { params }).then((r) => r.data),
 show: (id: string) =>
 client.get<ApiSuccess<MaterialIssueSlip>>(`/inventory/material-issues/${id}`).then((r) => r.data.data),
  create: (data: {
 work_order_id?: string | number | null;
 issued_date: string;
 reference_text?: string;
 remarks?: string;
 items: Array<{
 item_id: string;
 location_id: string;
 quantity_issued: string;
 issued_uom_code?: string;
 lot_number?: string;
 material_reservation_id?: string;
 remarks?: string;
 }>;
  }, idempotencyKey: string) =>
  client.post<ApiSuccess<MaterialIssueSlip>>('/inventory/material-issues', data, { headers: { 'Idempotency-Key': idempotencyKey } }).then((r) => r.data.data),
};
