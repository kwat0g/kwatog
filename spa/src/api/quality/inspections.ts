import { client } from '../client';
import { downloadAuthenticatedFile } from '../download';
import type { WorkOrderFinalInspection, FinalInspectionEvidence, FinalInspectionSubmission, FinalInspectionManifest } from '@/types/quality-final';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { ChainStep } from '@/types/chain';
import type {
  ChecklistSubmission,
  CountedInspectionSubmission,
  Inspection,
  RecordInspectionItemResultsData,
  RecordInspectionMeasurementsData,
  RecordInspectionBatchResultData,
  InspectionStage,
  InspectionStatus,
  InspectionEntityType,
} from '@/types/quality';

export interface InspectionListParams extends ListParams {
  stage?: InspectionStage;
  work_queue?: 'to_inspect' | '';
  status?: InspectionStatus | '';
  product_id?: string;
  entity_type?: InspectionEntityType;
  entity_id?: string;
  date?: string;
  from?: string;
  to?: string;
}

export const inspectionsApi = {
  getWorkOrderFinalInspection: (id: string, params?: { inspection_id?: string; page?: number; defect_page?: number; per_page?: number }) =>
    client.get<ApiSuccess<WorkOrderFinalInspection>>(`/quality/work-orders/${id}/final-inspection`, { params }).then(r => r.data.data),
  saveWorkOrderFinalEvidence: (id: string, data: FinalInspectionEvidence) =>
    client.patch<ApiSuccess<WorkOrderFinalInspection>>(`/quality/work-orders/${id}/final-inspection/evidence`, data).then(r => r.data.data),
  submitWorkOrderFinalInspection: (id: string, data: FinalInspectionSubmission) =>
    client.post<ApiSuccess<WorkOrderFinalInspection>>(`/quality/work-orders/${id}/final-inspection/submit`, data).then(r => r.data.data),
  reviewWorkOrderFinalInspection: (id: string, data: { manifest: FinalInspectionManifest; remarks?: string }) =>
    client.post<ApiSuccess<WorkOrderFinalInspection>>(`/quality/work-orders/${id}/final-inspection/review`, data).then(r => r.data.data),
  replaceOutgoingEvidenceMethod: (id: string, data: { manifest: FinalInspectionManifest; reason: string }) =>
    client.post<ApiSuccess<WorkOrderFinalInspection>>(`/quality/work-orders/${id}/final-inspection/replace-method`, data).then(r => r.data.data),
  options: () =>
    client
      .get<{
        data: {
          stages: Array<{ value: string; label: string }>;
          statuses: Array<{ value: string; label: string }>;
          entity_types: Array<{ value: string; label: string }>;
          measurement_results: Array<{ value: string; label: string }>;
          sampling_methods: Array<{ stage: string; value: string; label: string }>;
        };
      }>('/quality/inspections/options')
      .then((r) => r.data.data),
  list: (params?: InspectionListParams) =>
    client
      .get<PaginatedResponse<Inspection> & { meta: { status_counts?: { to_inspect: number; awaiting_review: number; failed: number; all: number } } }>('/quality/inspections', { params })
      .then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Inspection>>(`/quality/inspections/${id}`).then((r) => r.data.data),
  chain: (id: string) =>
    client.get<{ data: ChainStep[] }>(`/quality/inspections/${id}/chain`).then((r) => r.data.data),
  recordBatchResult: (id: string, data: RecordInspectionBatchResultData | ChecklistSubmission | CountedInspectionSubmission) =>
    client
      .post<ApiSuccess<Inspection>>(`/quality/inspections/${id}/batch-result`, data)
      .then((r) => r.data.data),
  recordItemResults: (id: string, data: RecordInspectionItemResultsData) =>
    client
      .post<ApiSuccess<Inspection>>(`/quality/inspections/${id}/item-results`, data)
      .then((r) => r.data.data),
  recordMeasurements: (id: string, data: RecordInspectionMeasurementsData) =>
    client
      .patch<ApiSuccess<Inspection>>(`/quality/inspections/${id}/measurements`, data)
      .then((r) => r.data.data),
  complete: (id: string) =>
    client
      .post<ApiSuccess<Inspection>>(`/quality/inspections/${id}/complete`)
      .then((r) => r.data.data),
  review: (id: string, decision: 'passed' | 'failed', remarks?: string, expectedVersion?: number) =>
    client
      .patch<ApiSuccess<Inspection>>(
        `/quality/inspections/${id}/review`,
        { decision, remarks, expected_result_version: expectedVersion },
        { timeout: 90_000 },
      )
      .then((r) => r.data.data),
  replaceChecklist: (id: string, reason: string, expectedVersion: number) => client.post<ApiSuccess<Inspection>>(`/quality/inspections/${id}/replace-checklist`, { reason, expected_result_version: expectedVersion }).then(r => r.data.data),
  cancel: (id: string, reason?: string, expectedVersion?: number) =>
    client
      .post<ApiSuccess<Inspection>>(`/quality/inspections/${id}/cancel`, { reason, expected_result_version: expectedVersion })
      .then((r) => r.data.data),
  generateCoC: async (id: string): Promise<void> => {
    await downloadAuthenticatedFile(`/quality/inspections/${id}/coc`, {
      filename: `coc-${id}.pdf`,
    });
  },
};
