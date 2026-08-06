import { client } from './client';
import type { PaginatedResponse } from '@/types';
import type {
 JobPosting,
 JobApplication,
 ApplicationInterview,
 ApplicationNote,
 CreateJobPostingData,
} from '@/types/recruitment';

const BASE = '/hr/recruitment';

export interface RecruitmentOptions {
 employment_types: Array<{ value: string; label: string }>;
 application_stages: Array<{ value: string; label: string; is_terminal: boolean; next: string | null }>;
 posting_statuses: Array<{ value: string; label: string }>;
}

export const recruitmentApi = {
 options: () => client.get<{ data: RecruitmentOptions }>(`${BASE}/postings/options`),
 listPostings: (params?: Record<string, unknown>) =>
 client.get<PaginatedResponse<JobPosting>>(`${BASE}/postings`, { params }),
 showPosting: (id: string) =>
 client.get<{ data: JobPosting }>(`${BASE}/postings/${id}`),
 createPosting: (data: CreateJobPostingData) =>
 client.post<{ data: JobPosting }>(`${BASE}/postings`, data),
 updatePosting: (id: string, data: CreateJobPostingData) =>
 client.put<{ data: JobPosting }>(`${BASE}/postings/${id}`, data),
  deletePosting: (id: string) =>
  client.delete(`${BASE}/postings/${id}`),
  restorePosting: (id: string) =>
  client.patch(`${BASE}/postings/${id}/restore`),
 changePostingStatus: (id: string, status: string) =>
 client.patch<{ data: JobPosting }>(`${BASE}/postings/${id}/status`, { status }),

 listApplications: (params?: Record<string, unknown>) =>
 client.get<PaginatedResponse<JobApplication>>(`${BASE}/applications`, { params }),
 showApplication: (id: string) =>
 client.get<{ data: JobApplication }>(`${BASE}/applications/${id}`),
 changeStage: (id: string, data: {
 action: 'advance' | 'reject';
 rejection_reason?: string;
 interview?: { scheduled_at: string; location?: string; interviewer_name: string };
 }) =>
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
