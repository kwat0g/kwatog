import type { AxiosInstance } from 'axios';
import { client } from '@/api/client';
import { createPortalClient } from '@/api/b2b/client';
import type {
  CreateReturnCasePayload,
  ReturnCaseActionPayload,
  ReturnCaseAttachment,
  ReturnCasePage,
  ReturnCaseRealm,
  ReturnCaseRecord,
  ReturnCaseResolutionOptions,
  ReturnCaseSourceOptions,
  ReturnCaseSourceResult,
  ReturnCaseType,
} from '@/types/returnCases';

const customerClient = createPortalClient('customer').client;
const supplierClient = createPortalClient('supplier').client;

const clientFor = (realm: ReturnCaseRealm): AxiosInstance =>
  realm === 'internal' ? client : realm === 'customer' ? customerClient : supplierClient;

const basePath = (realm: ReturnCaseRealm): string =>
  realm === 'internal' ? '/return-management/cases' : `/b2b/${realm}/problems`;

function unwrap<T>(response: { data: { data: T } }): T {
  return response.data.data;
}

export const returnCasesApi = {
  async reportNotArrived(deliveryId: string, payload: { request_key: string; message: string }): Promise<ReturnCaseRecord> {
    return unwrap(await customerClient.post<{ data: ReturnCaseRecord }>(`/b2b/customer/problems/not-arrived/${deliveryId}`, payload));
  },
  async list(
    realm: ReturnCaseRealm,
    params: { page?: number; per_page?: number; search?: string; status?: string; type?: string } = {},
  ): Promise<ReturnCasePage> {
    const { data } = await clientFor(realm).get<ReturnCasePage>(basePath(realm), { params });
    return data;
  },

  async findSources(
    realm: ReturnCaseRealm,
    type: ReturnCaseType,
    search: string,
  ): Promise<ReturnCaseSourceResult[]> {
    const response = await clientFor(realm).get<{ data: ReturnCaseSourceResult[] }>(`${basePath(realm)}/sources`, {
      params: { type, search },
    });
    return unwrap(response);
  },

  async sourceOptions(
    realm: ReturnCaseRealm,
    sourceKind: string,
    sourceId: string,
  ): Promise<ReturnCaseSourceOptions> {
    const response = await clientFor(realm).get<{ data: ReturnCaseSourceOptions }>(`${basePath(realm)}/source-options`, {
      params: { source_kind: sourceKind, source_id: sourceId },
    });
    return unwrap(response);
  },

  async show(realm: ReturnCaseRealm, id: string): Promise<ReturnCaseRecord> {
    const response = await clientFor(realm).get<{ data: ReturnCaseRecord }>(`${basePath(realm)}/${id}`);
    return unwrap(response);
  },

  async create(realm: ReturnCaseRealm, payload: CreateReturnCasePayload): Promise<ReturnCaseRecord> {
    const response = await clientFor(realm).post<{ data: ReturnCaseRecord }>(basePath(realm), payload);
    return unwrap(response);
  },

  async act(realm: ReturnCaseRealm, id: string, payload: ReturnCaseActionPayload): Promise<ReturnCaseRecord> {
    const response = await clientFor(realm).post<{ data: ReturnCaseRecord }>(`${basePath(realm)}/${id}/actions`, payload);
    return unwrap(response);
  },

  async upload(realm: ReturnCaseRealm, id: string, file: File): Promise<ReturnCaseAttachment> {
    const body = new FormData();
    body.append('file', file);
    const response = await clientFor(realm).post<{ data: ReturnCaseAttachment }>(`${basePath(realm)}/${id}/attachments`, body);
    return unwrap(response);
  },

  async download(realm: ReturnCaseRealm, caseId: string, attachmentId: string): Promise<Blob> {
    const { data } = await clientFor(realm).get<Blob>(`${basePath(realm)}/${caseId}/attachments/${attachmentId}`, {
      responseType: 'blob',
    });
    return data;
  },

  async resolutionOptions(realm: ReturnCaseRealm, id: string): Promise<ReturnCaseResolutionOptions> {
    const response = await clientFor(realm).get<{ data: ReturnCaseResolutionOptions }>(`${basePath(realm)}/${id}/resolution-options`);
    return unwrap(response);
  },
};
