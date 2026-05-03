import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type {
  Inspection,
  CreateInspectionData,
  RecordMeasurementsData,
  AqlPlan,
  InspectionStage,
  InspectionStatus,
  InspectionEntityType,
} from '@/types/quality';

export interface InspectionListParams extends ListParams {
  stage?: InspectionStage;
  status?: InspectionStatus;
  product_id?: string;
  entity_type?: InspectionEntityType;
  entity_id?: string;
}

export const inspectionsApi = {
  list: (params?: InspectionListParams) =>
    client.get<PaginatedResponse<Inspection>>('/quality/inspections', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Inspection>>(`/quality/inspections/${id}`).then((r) => r.data.data),
  create: (data: CreateInspectionData) =>
    client.post<ApiSuccess<Inspection>>('/quality/inspections', data).then((r) => r.data.data),
  recordMeasurements: (id: string, data: RecordMeasurementsData) =>
    client
      .patch<ApiSuccess<Inspection>>(`/quality/inspections/${id}/measurements`, data)
      .then((r) => r.data.data),
  complete: (id: string) =>
    client.post<ApiSuccess<Inspection>>(`/quality/inspections/${id}/complete`).then((r) => r.data.data),
  cancel: (id: string, reason?: string) =>
    client
      .post<ApiSuccess<Inspection>>(`/quality/inspections/${id}/cancel`, { reason })
      .then((r) => r.data.data),
  aqlPreview: (batchQuantity: number) =>
    client
      .get<{ data: AqlPlan }>('/quality/inspections/aql-preview', { params: { batch_quantity: batchQuantity } })
      .then((r) => r.data.data),
};
