import client from './client';
import type { Paginated } from '@/types/common';
import type {
  JobPosting,
  JobApplication,
  ApplicationInterview,
  ApplicationNote,
  CreateJobPostingData,
} from '@/types/recruitment';

const BASE = '/recruitment';

export const recruitmentApi = {
  listPostings: (params?: Record<string, unknown>) =>
    client.get<Paginated<JobPosting>>(`${BASE}/postings`, { params }),
  showPosting: (id: string) =>
    client.get<{ data: JobPosting }>(`${BASE}/postings/${id}`),
  createPosting: (data: CreateJobPostingData) =>
    client.post<{ data: JobPosting }>(`${BASE}/postings`, data),
  updatePosting: (id: string, data: CreateJobPostingData) =>
    client.put<{ data: JobPosting }>(`${BASE}/postings/${id}`, data),
  deletePosting: (id: string) =>
    client.delete(`${BASE}/postings/${id}`),
  changePostingStatus: (id: string, status: string) =>
    client.patch<{ data: JobPosting }>(`${BASE}/postings/${id}/status`, { status }),

  listApplications: (params?: Record<string, unknown>) =>
    client.get<Paginated<JobApplication>>(`${BASE}/applications`, { params }),
  showApplication: (id: string) =>
    client.get<{ data: JobApplication }>(`${BASE}/applications/${id}`),
  changeStage: (id: string, data: { action: 'advance' | 'reject'; rejection_reason?: string }) =>
    client.patch<{ data: JobApplication }>(`${BASE}/applications/${id}/stage`, data),
  scheduleInterview: (id: string, data: { scheduled_at: string; location?: string; interviewer_name: string }) =>
    client.post<{ data: ApplicationInterview }>(`${BASE}/applications/${id}/interviews`, data),
  updateInterview: (id: string, data: { notes?: string; outcome?: string }) =>
    client.patch<{ data: ApplicationInterview }>(`${BASE}/interviews/${id}`, data),
  addNote: (id: string, body: string) =>
    client.post<{ data: ApplicationNote }>(`${BASE}/applications/${id}/notes`, { body }),
  downloadResume: (id: string) =>
    client.get(`${BASE}/applications/${id}/resume`, { responseType: 'blob' }),
  getConversionData: (id: string) =>
    client.get<{ data: Record<string, string | null> }>(`${BASE}/applications/${id}/convert`),
};
