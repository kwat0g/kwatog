import { client } from './client';
import type { DriverDelivery, DriverPaginated } from '@/types/driver';

export const driverApi = {
  listDeliveries: (params?: Record<string, string>) =>
    client
      .get<DriverPaginated<DriverDelivery>>('/driver/deliveries', { params })
      .then(r => r.data),

  showDelivery: (id: string) =>
    client
      .get<{ data: DriverDelivery }>(`/driver/deliveries/${id}`)
      .then(r => r.data.data),

  updateStatus: (id: string, status: DriverDelivery['status']) =>
    client
      .patch<{ data: DriverDelivery }>(`/driver/deliveries/${id}/status`, { status })
      .then(r => r.data.data),

  uploadReceipt: (id: string, file: File) => {
    const form = new FormData();
    form.append('photo', file);
    // Let axios + browser set Content-Type with the multipart boundary.
    return client
      .post<{ data: DriverDelivery }>(`/driver/deliveries/${id}/receipt`, form)
      .then(r => r.data.data);
  },
};
