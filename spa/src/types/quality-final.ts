import type { Inspection } from './quality';

export type FinalInspectionManifest = Array<{
  output_id: string;
  inspection_id: string;
  physical_quantity: number;
  result_version: number;
  specification_revision_id: string;
}>;
export interface FinalInspectionPiece {
  piece_number: number;
  problem_description: string | null;
  requirement_id?: string | null;
  readings: Array<{
    requirement_id: string;
    measured_value: string | null;
    is_pass: boolean | null;
  }>;
}
export interface WorkOrderFinalInspection {
  work_order_id: string;
  wo_number?: string;
  manifest: FinalInspectionManifest;
  produced_quantity: number;
  defective_quantity: number;
  accepted_quantity: number;
  repaired_accepted_quantity?: number;
  state: 'checking' | 'awaiting_review' | 'failed' | 'released';
  blocker?: string | null;
  context?: {
    product: { part_number: string; name: string; unit_of_measure: string } | null;
    sales_order: {
      id: string;
      so_number: string;
      requested_delivery_date: string | null;
      current_delivery_date: string | null;
    } | null;
    batch_number: string | null;
    machine: { code: string; name: string } | null;
    mold: { code: string; name: string } | null;
    production_started_at: string | null;
    production_finished_at: string | null;
    production_due_at: string | null;
    timezone: string;
    output_batches: Array<{ inspection_id: string; batch_code: string | null }>;
  };
  batches: Inspection[];
  evidence_page?: {
    inspection_id: string;
    page: number;
    per_page: number;
    total_pieces: number;
    pieces: FinalInspectionPiece[];
  };
  defect_page?: {
    page: number;
    per_page: number;
    total: number;
    pieces: Array<{
      piece_number: number;
      problem_description: string;
      requirement_id?: string | null;
    }>;
  };
  can_replace_method?: boolean;
}
export interface FinalInspectionEvidence {
  manifest: FinalInspectionManifest;
  inspection_id: string;
  expected_result_version: number;
  defective_quantity: number;
  page?: number;
  defect_page?: number;
  reset_defects?: boolean;
  remove_piece_numbers?: number[];
  pieces: Array<{
    piece_number: number;
    problem_description: string | null;
    requirement_id?: string | null;
  }>;
  readings: Array<{ piece_number: number; requirement_id: string; measured_value: string | null }>;
}
export interface FinalInspectionSubmission {
  manifest: FinalInspectionManifest;
  batches: Array<
    | {
        inspection_id: string;
        coverage_confirmed: boolean;
        inspected_quantity: number;
        checks_confirmed: true;
      }
    | {
        inspection_id: string;
        coverage_confirmed: boolean;
        inspected_quantity: number;
        checks_confirmed?: never;
      }
  >;
}
