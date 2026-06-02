import { client } from '../client';
import type { ApiSuccess } from '@/types';
import type { ShipmentLot } from '@/types/supplyChain';

export interface CreateShipmentLotPayload {
  work_order_ids: string[];
  quantity?: number;
  lot_date?: string;
}

export const shipmentLotsApi = {
  /** Returns the most recent shipment lot for the given delivery, or null. */
  showForDelivery: (deliveryHashId: string) =>
    client
      .get<{ data: ShipmentLot | null }>(
        `/quality/traceability/deliveries/${deliveryHashId}/shipment-lot`,
      )
      .then((r) => r.data.data),

  createForDelivery: (deliveryHashId: string, payload: CreateShipmentLotPayload) =>
    client
      .post<ApiSuccess<ShipmentLot>>(
        `/quality/traceability/deliveries/${deliveryHashId}/shipment-lot`,
        payload,
      )
      .then((r) => r.data.data),

  show: (id: string) =>
    client
      .get<ApiSuccess<ShipmentLot>>(`/quality/traceability/shipment-lots/${id}`)
      .then((r) => r.data.data),
};
