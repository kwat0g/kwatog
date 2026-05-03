/** Sprint 8 — Task 76. Bulk-print helper. */
import { client } from './client';
import toast from 'react-hot-toast';

export type BulkPrintType = 'purchase_order' | 'bill' | 'invoice';

/**
 * POST /print/bulk with { type, ids } and trigger a browser download of the
 * concatenated PDF response. Returns true on success, false on failure
 * (toasts are handled here so callers don't have to).
 */
export async function bulkPrint(type: BulkPrintType, ids: string[]): Promise<boolean> {
  if (ids.length === 0) {
    toast.error('Select at least one row to print.');
    return false;
  }
  if (ids.length > 50) {
    toast.error('Bulk print is capped at 50 documents.');
    return false;
  }
  try {
    const response = await client.post('/print/bulk', { type, ids }, { responseType: 'blob' });
    const blob = new Blob([response.data], { type: 'application/pdf' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `bulk-${type}-${new Date().toISOString().slice(0, 10)}.pdf`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
    toast.success(`Generated PDF for ${ids.length} ${ids.length === 1 ? 'document' : 'documents'}.`);
    return true;
  } catch {
    toast.error('Bulk print failed. Please try again.');
    return false;
  }
}
