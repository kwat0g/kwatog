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
  total_rows: number;
  imported_rows: number;
  created_by: string | null;
  created_at: string | null;
  rolled_back_at: string | null;
}

/**
 * REC-03 — master-data CSV import. FormData uploads deliberately do NOT set a
 * Content-Type header so the browser adds the multipart boundary.
 */
export const importsApi = {
  entities: () =>
    client.get<{ data: string[] }>('/imports').then((r) => r.data.data),

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

/** Fixed per-entity column hints (backend confirms actual headers on dry-run). */
export const IMPORT_SCHEMAS: Record<string, { required: string[]; optional: string[] }> = {
  coa: {
    required: ['code', 'name', 'type', 'normal_balance'],
    optional: ['description', 'parent_code'],
  },
  items: {
    required: ['code', 'name', 'item_type', 'unit_of_measure', 'category'],
    optional: ['standard_cost', 'description', 'reorder_point', 'lead_time_days'],
  },
  customers: {
    required: ['name'],
    optional: ['code', 'contact_person', 'email', 'phone', 'address', 'tin', 'credit_limit', 'payment_terms_days'],
  },
  vendors: {
    required: ['name'],
    optional: ['contact_person', 'email', 'phone', 'address', 'tin', 'payment_terms_days'],
  },
};
