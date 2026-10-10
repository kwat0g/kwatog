import { client } from '@/api/client';

export interface ScanResult {
  type: string;
  type_label?: string;
  entity: Record<string, unknown> | null;
  suggested_actions: Array<{
    action: string;
    label: string;
    params: Record<string, unknown>;
    href: string | null;
  }>;
}

export const scannerApi = {
  records: (context: string, search: string) =>
    client
      .get<{
        data: Array<{ value: string; label: string }>;
      }>('/inventory/scan/records', { params: { context, search } })
      .then((r) => r.data.data),
  options: () =>
    client
      .get<{
        data: { contexts: Array<{ value: string; label: string }> };
      }>('/inventory/scan/options')
      .then((r) => r.data.data),
  resolve: (barcode: string, context: Record<string, string>) =>
    client
      .post<{ data: ScanResult }>('/inventory/scan/resolve', { barcode, context })
      .then((response) => response.data.data),
};
