import { unwrappingClient as client } from './client';

export interface QuoteRequestPayload {
  full_name: string;
  company: string;
  email: string;
  part_description: string;
  annual_volume?: string;
  // File upload is prepared on the client but the backend endpoint is expected
  // to accept multipart/form-data when drawing upload is enabled.
  drawing?: File;
}

export interface QuoteRequestResponse {
  message: string;
}

export const landingApi = {
  /**
   * Submit a public quote request from the landing page.
   * TODO: backend route POST /api/v1/landing/quote-request
   */
  requestQuote: async (payload: QuoteRequestPayload): Promise<QuoteRequestResponse> => {
    const formData = new FormData();
    formData.append('full_name', payload.full_name);
    formData.append('company', payload.company);
    formData.append('email', payload.email);
    formData.append('part_description', payload.part_description);
    if (payload.annual_volume) formData.append('annual_volume', payload.annual_volume);
    if (payload.drawing) formData.append('drawing', payload.drawing);

    const { data } = await client.post<QuoteRequestResponse>('/landing/quote-request', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return data;
  },

  /**
   * Subscribe to the newsletter / DFM insights list.
   * TODO: backend route POST /api/v1/landing/newsletter
   */
  subscribeNewsletter: async (email: string): Promise<{ message: string }> => {
    const { data } = await client.post<{ message: string }>('/landing/newsletter', { email });
    return data;
  },

  /**
   * Download the quality policy PDF.
   * TODO: backend route GET /api/v1/landing/quality-policy
   */
  downloadQualityPolicy: async (): Promise<Blob> => {
    const { data } = await client.get<Blob>('/landing/quality-policy', {
      responseType: 'blob',
    });
    return data;
  },
};
