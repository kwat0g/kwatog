import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type {
  SuccessionPlan,
  SuccessionReadiness,
  SuccessionPriority,
  SuccessionStatus,
  CreateSuccessionPlanData,
} from '@/types/succession';

export interface SuccessionPlanListParams extends ListParams {
  readiness?: SuccessionReadiness;
  priority?: SuccessionPriority;
  status?: SuccessionStatus;
}

export const successionPlansApi = {
  list: (params?: SuccessionPlanListParams) =>
    client.get<PaginatedResponse<SuccessionPlan>>('/hr/succession-plans', { params }).then(r => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<SuccessionPlan>>(`/hr/succession-plans/${id}`).then(r => r.data.data),
  create: (data: CreateSuccessionPlanData) =>
    client.post<ApiSuccess<SuccessionPlan>>('/hr/succession-plans', data).then(r => r.data.data),
  update: (id: string, data: Partial<CreateSuccessionPlanData>) =>
    client.put<ApiSuccess<SuccessionPlan>>(`/hr/succession-plans/${id}`, data).then(r => r.data.data),
  destroy: (id: string) =>
    client.delete(`/hr/succession-plans/${id}`),
};
