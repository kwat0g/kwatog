import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Skill, CreateSkillData, UpdateSkillData } from '@/types/hr';

export const skillsApi = {
 list: (params?: ListParams) =>
 client.get<PaginatedResponse<Skill>>('/hr/skills', { params }).then((r) => r.data),
 show: (id: string) =>
 client.get<ApiSuccess<Skill>>(`/hr/skills/${id}`).then((r) => r.data.data),
 create: (data: CreateSkillData) =>
 client.post<ApiSuccess<Skill>>('/hr/skills', data).then((r) => r.data.data),
 update: (id: string, data: UpdateSkillData) =>
 client.patch<ApiSuccess<Skill>>(`/hr/skills/${id}`, data).then((r) => r.data.data),
 deactivate: (id: string) =>
 client.patch<ApiSuccess<Skill>>(`/hr/skills/${id}/deactivate`).then((r) => r.data.data),
};