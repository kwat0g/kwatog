import { client } from '@/api/client';
import type { ApiSuccess } from '@/types';
import type { GrnSupplierDocuments, PurchaseOrderSupplierActivity } from '@/types/supplierActivity';

export const supplierActivityApi = {
 forPurchaseOrder: (purchaseOrderId: string) =>
  client
   .get<ApiSuccess<PurchaseOrderSupplierActivity>>(`/b2b/purchase-orders/${purchaseOrderId}/supplier-activity`)
   .then((r) => r.data.data),

 forGoodsReceipt: (grnId: string) =>
  client
   .get<ApiSuccess<GrnSupplierDocuments>>(`/b2b/goods-receipt-notes/${grnId}/supplier-documents`)
   .then((r) => r.data.data),

 documentUrl: (documentId: string) => `/api/v1/b2b/supplier-documents/${documentId}/download`,
};
