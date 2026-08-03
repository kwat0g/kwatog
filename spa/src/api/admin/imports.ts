import { client } from '../client';

export interface ImportError {
  row: number;
  message: string;
}

export interface ImportDryRunResult {
  total: number;
  valid: number;
  errors: ImportError[];
  columns: string[];
}

export interface ImportCommitResult {
  batch_id: string;
  total: number;
  imported: number;
}

export interface ImportBatch {
  id: string;
  entity_type: string;
  filename: string | null;
  status: 'committed' | 'rolled_back';
  status_label?: string;
  total_rows: number;
  imported_rows: number;
  created_by: string | null;
  created_at: string | null;
  rolled_back_at: string | null;
}

export interface ImportSchema {
  required: string[];
  optional: string[];
}

export interface ImportOptions {
  entities: string[];
  schemas: Record<string, ImportSchema>;
}

/**
 * REC-03 — master-data CSV import. FormData uploads deliberately do NOT set a
 * Content-Type header so the browser adds the multipart boundary.
 */
export const importsApi = {
  entities: () =>
    client.get<{ data: ImportOptions }>('/imports').then((r) => r.data.data),

  dryRun: (entity: string, file: File) => {
    const fd = new FormData();
    fd.append('file', file);
    return client
      .post<{ data: ImportDryRunResult }>(`/imports/${entity}/dry-run`, fd)
      .then((r) => r.data.data);
  },

  commit: (entity: string, file: File) => {
    const fd = new FormData();
    fd.append('file', file);
    return client
      .post<{ data: ImportCommitResult; message: string }>(`/imports/${entity}/commit`, fd)
      .then((r) => r.data);
  },

  batches: () =>
    client.get<{ data: ImportBatch[] }>('/imports/batches').then((r) => r.data.data),

  rollback: (batchId: string) =>
    client.post<{ message: string }>(`/imports/batches/${batchId}/rollback`).then((r) => r.data),
};
