import { unwrappingClient as client, getCsrfCookie } from './client';

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
   */
  requestQuote: async (payload: QuoteRequestPayload): Promise<QuoteRequestResponse> => {
    const formData = new FormData();
    formData.append('full_name', payload.full_name);
    formData.append('company', payload.company);
    formData.append('email', payload.email);
    formData.append('part_description', payload.part_description);
    if (payload.annual_volume !== undefined && payload.annual_volume !== '') {
      formData.append('annual_volume', payload.annual_volume);
    }
    if (payload.drawing) formData.append('drawing', payload.drawing);

    await getCsrfCookie();
    const { data } = await client.post<QuoteRequestResponse>('/landing/quote-request', formData);
    return data;
  },

  /**
   * Subscribe to the newsletter / DFM insights list.
   */
  subscribeNewsletter: async (email: string): Promise<{ message: string }> => {
    await getCsrfCookie();
    const { data } = await client.post<{ message: string }>('/landing/newsletter', { email });
    return data;
  },

  /**
   * Download the quality policy PDF.
   */
  downloadQualityPolicy: async (): Promise<Blob> => {
    const { data } = await client.get<Blob>('/landing/quality-policy', {
      responseType: 'blob',
    });
    return data;
  },
};
