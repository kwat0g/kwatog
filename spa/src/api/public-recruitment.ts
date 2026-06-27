import axios from 'axios';
import type { PaginatedResponse } from '@/types';
import type { PublicJobPosting, TrackingInfo } from '@/types/recruitment';

const publicClient = axios.create({
  baseURL: '/api/v1/public/recruitment',
  headers: { Accept: 'application/json' },
});

export const publicRecruitmentApi = {
  listPostings: (params?: Record<string, unknown>) =>
    publicClient.get<PaginatedResponse<PublicJobPosting>>('/job-postings', { params }),
  showPosting: (id: string) =>
    publicClient.get<{ data: PublicJobPosting }>(`/job-postings/${id}`),
  apply: (postingId: string, formData: FormData) =>
    publicClient.post<{ tracking_code: string; message: string }>(`/job-postings/${postingId}/apply`, formData),
  track: (code: string) =>
    publicClient.get<{ data: TrackingInfo }>(`/applications/track/${code}`),
};
