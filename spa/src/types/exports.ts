/**
 * Series E (E2) — Configurable + scheduled-export types.
 */

export type ExportFormat = 'csv' | 'xlsx';
export type ExportFrequency = 'daily' | 'weekly' | 'monthly';

export interface ColumnDefinition {
  key: string;
  label: string;
  default: boolean;
  format: 'text' | 'money' | 'date' | string;
}

export interface ColumnPayload {
  module: string;
  columns: ColumnDefinition[];
  selected: string[];
}

export interface ScheduledExport {
  id: string;
  name: string;
  module: string;
  columns: string[];
  filters: Record<string, unknown>;
  format: ExportFormat;
  frequency: ExportFrequency;
  day_of_week: number | null;
  day_of_month: number | null;
  time_of_day: string | null;
  recipients: string[];
  last_run_at: string | null;
  next_run_at: string | null;
  is_active: boolean;
  owner: { id: string; name: string } | null;
  created_at: string | null;
}

export interface CreateScheduledExportInput {
  name: string;
  module: string;
  columns: string[];
  filters?: Record<string, unknown>;
  format?: ExportFormat;
  frequency: ExportFrequency;
  day_of_week?: number | null;
  day_of_month?: number | null;
  time_of_day?: string;
  recipients: string[];
  is_active?: boolean;
}
