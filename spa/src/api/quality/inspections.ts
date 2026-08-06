import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { ChainStep } from '@/types/chain';
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
  date?: string;
  from?: string;
  to?: string;
}

export const inspectionsApi = {
 options: () => client.get<{ data: { stages: Array<{ value: string; label: string }>; statuses: Array<{ value: string; label: string }>; entity_types: Array<{ value: string; label: string }>; measurement_results: Array<{ value: string; label: string }>; sampling_methods: Array<{ stage: string; value: string; label: string }> } }>('/quality/inspections/options').then((r) => r.data.data),
 list: (params?: InspectionListParams) =>
 client.get<PaginatedResponse<Inspection>>('/quality/inspections', { params }).then((r) => r.data),
 show: (id: string) =>
 client.get<ApiSuccess<Inspection>>(`/quality/inspections/${id}`).then((r) => r.data.data),
 chain: (id: string) =>
 client.get<{ data: ChainStep[] }>(`/quality/inspections/${id}/chain`).then((r) => r.data.data),
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

 generateCoC: (id: string): Promise<void> =>
 client
 .get(`/quality/inspections/${id}/coc`, { responseType: 'blob' })
 .then((r) => {
 const blob = new Blob([r.data as BlobPart], { type: 'application/pdf' });
 const url = URL.createObjectURL(blob);
 const a = document.createElement('a');
 a.href = url;
 a.download = `coc-${id}.pdf`;
 a.click();
 URL.revokeObjectURL(url);
 }),
};
