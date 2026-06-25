import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type {
  ReviewCycle,
  PerformanceReview,
  CycleStatus,
  CycleType,
  ReviewStatus,
  CreateCycleData,
  CreateReviewData,
  SubmitReviewData,
} from '@/types/performance-reviews';

export interface CycleListParams extends ListParams {
  status?: CycleStatus;
  cycle_type?: CycleType;
}

export interface ReviewListParams extends ListParams {
  cycle_id?: string;
  status?: ReviewStatus;
}

export const reviewCyclesApi = {
  list: (params?: CycleListParams) =>
    client.get<PaginatedResponse<ReviewCycle>>('/hr/performance-reviews/cycles', { params }).then(r => r.data),
  create: (data: CreateCycleData) =>
    client.post<ApiSuccess<ReviewCycle>>('/hr/performance-reviews/cycles', data).then(r => r.data.data),
  activate: (id: string) =>
    client.post<ApiSuccess<ReviewCycle>>(`/hr/performance-reviews/cycles/${id}/activate`).then(r => r.data.data),
  close: (id: string) =>
    client.post<ApiSuccess<ReviewCycle>>(`/hr/performance-reviews/cycles/${id}/close`).then(r => r.data.data),
};

export const performanceReviewsApi = {
  list: (params?: ReviewListParams) =>
    client.get<PaginatedResponse<PerformanceReview>>('/hr/performance-reviews', { params }).then(r => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<PerformanceReview>>(`/hr/performance-reviews/${id}`).then(r => r.data.data),
  create: (data: CreateReviewData) =>
    client.post<ApiSuccess<PerformanceReview>>('/hr/performance-reviews', data).then(r => r.data.data),
  submit: (id: string, data: SubmitReviewData) =>
    client.post<ApiSuccess<PerformanceReview>>(`/hr/performance-reviews/${id}/submit`, data).then(r => r.data.data),
  acknowledge: (id: string) =>
    client.post<ApiSuccess<PerformanceReview>>(`/hr/performance-reviews/${id}/acknowledge`).then(r => r.data.data),
};
