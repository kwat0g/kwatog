import type { Inspection } from '@/types/quality';

export function usesQuantityForm(inspection: Inspection): boolean {
  if (inspection.quantity_result) return true;
  if (!['draft', 'in_progress'].includes(inspection.status)) return false;
  if ((inspection.sample_defect_count ?? 0) > 0 || (inspection.described_defective_quantity ?? 0) > 0) return false;
  if (inspection.counted_capture?.workflow === 'finish_first_v1' && (inspection.result_version ?? 0) > 0) return false;
  return !(inspection.measurements ?? []).some(row => row.measured_value != null || row.item_result != null || row.is_pass != null);
}
