/**
 * Series E (E2) — exports API client.
 *
 * download() opens a transient <a download> rather than fetching the blob
 * via axios, so the response stream is handed straight to the browser
 * (no JS heap pressure on multi-MB exports) AND the auth cookie travels
 * with the request automatically.
 */

import { client } from './client';
import type {
  ColumnPayload,
  CreateScheduledExportInput,
  ExportFormat,
  ScheduledExport,
} from '@/types/exports';
import type { PaginatedResponse } from '@/types';

export interface DownloadOpts {
  format?: ExportFormat;
  columns?: string[];
  filters?: Record<string, unknown>;
}

export const exportsApi = {
  columns: (module: string) =>
    client
      .get<{ data: ColumnPayload }>(`/exports/${module}/columns`)
      .then((r) => r.data.data),

  saveColumns: (module: string, columns: string[]) =>
    client
      .put<{ data: ColumnPayload }>(`/exports/${module}/columns`, { columns })
      .then((r) => r.data.data),

  preview: (module: string, opts: Omit<DownloadOpts, 'format'> = {}) =>
    client
      .get<{ data: { columns: string[]; rows: unknown[][] } }>(
        `/exports/${module}/preview`,
        { params: serializeOpts(opts) },
      )
      .then((r) => r.data.data),

  /** Trigger a browser download. Returns the URL it navigated to. */
  download: (module: string, opts: DownloadOpts = {}): string => {
    const params = new URLSearchParams();
    if (opts.format) params.set('format', opts.format);
    if (opts.columns?.length) params.set('columns', opts.columns.join(','));
    if (opts.filters) {
      for (const [k, v] of Object.entries(opts.filters)) {
        if (v === null || v === undefined || v === '') continue;
        params.set(`filters[${k}]`, String(v));
      }
    }
    const url = `/api/v1/exports/${module}/download?${params.toString()}`;
    triggerDownload(url);
    return url;
  },
};

function serializeOpts(opts: Omit<DownloadOpts, 'format'>): Record<string, string> {
  const out: Record<string, string> = {};
  if (opts.columns?.length) out.columns = opts.columns.join(',');
  if (opts.filters) {
    for (const [k, v] of Object.entries(opts.filters)) {
      if (v === null || v === undefined || v === '') continue;
      out[`filters[${k}]`] = String(v);
    }
  }
  return out;
}

function triggerDownload(url: string): void {
  const a = document.createElement('a');
  a.href = url;
  a.rel = 'noopener';
  document.body.appendChild(a);
  a.click();
  a.remove();
}

export const scheduledExportsApi = {
  list: (page = 1) =>
    client
      .get<PaginatedResponse<ScheduledExport>>('/scheduled-exports', {
        params: { page },
      })
      .then((r) => r.data),

  show: (id: string) =>
    client
      .get<{ data: ScheduledExport }>(`/scheduled-exports/${id}`)
      .then((r) => r.data.data),

  create: (data: CreateScheduledExportInput) =>
    client
      .post<{ data: ScheduledExport }>('/scheduled-exports', data)
      .then((r) => r.data.data),

  update: (id: string, data: Partial<CreateScheduledExportInput>) =>
    client
      .put<{ data: ScheduledExport }>(`/scheduled-exports/${id}`, data)
      .then((r) => r.data.data),

  destroy: (id: string) => client.delete(`/scheduled-exports/${id}`),
};
