import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Training, CreateTrainingData, UpdateTrainingData } from '@/types/hr';

export const trainingsApi = {
 list: (params?: ListParams) =>
 client.get<PaginatedResponse<Training>>('/hr/trainings', { params }).then((r) => r.data),
 show: (id: string) =>
 client.get<ApiSuccess<Training>>(`/hr/trainings/${id}`).then((r) => r.data.data),
 create: (data: CreateTrainingData) =>
 client.post<ApiSuccess<Training>>('/hr/trainings', data).then((r) => r.data.data),
 update: (id: string, data: UpdateTrainingData) =>
 client.patch<ApiSuccess<Training>>(`/hr/trainings/${id}`, data).then((r) => r.data.data),
  delete: (id: string) =>
  client.delete(`/hr/trainings/${id}`),
  restore: (id: string) =>
  client.patch(`/hr/trainings/${id}/restore`),
};