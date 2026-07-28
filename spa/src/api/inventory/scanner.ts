import { client } from '@/api/client';

export interface ScanResult {
  type: string;
  entity: Record<string, unknown> | null;
  suggested_actions: Array<{
    action: string;
    label: string;
    params: Record<string, unknown>;
    href: string | null;
  }>;
}

export const scannerApi = {
  resolve: (barcode: string, context: Record<string, string>) =>
    client.post<{ data: ScanResult }>('/inventory/scan/resolve', { barcode, context })
      .then((response) => response.data.data),
};
