import { client } from '../client';
import type { SupplierPerformanceResponse } from '@/types/supplierPerformance';

/**
 * Series F — Task F4. Supplier performance API client.
 */
export const supplierPerformanceApi = {
  show: (vendorId: string, months = 6) =>
    client
      .get<SupplierPerformanceResponse>(`/purchasing/vendors/${vendorId}/performance`, {
        params: { months },
      })
      .then((r) => r.data.data),

  recompute: (vendorId: string) =>
    client
      .post<{ data: { vendor_id: string; overall_score: string | null; computed_at: string | null } }>(
        `/purchasing/vendors/${vendorId}/performance/recompute`,
      )
      .then((r) => r.data.data),
};
