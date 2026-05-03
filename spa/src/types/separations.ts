export type SeparationReason = 'resigned' | 'terminated' | 'retired' | 'end_of_contract';
export type ClearanceStatus = 'pending' | 'in_progress' | 'completed' | 'finalized' | 'cancelled';

export interface ClearanceItem {
  department: string;
  item_key: string;
  label: string;
  status: 'pending' | 'cleared' | 'blocked';
  signed_by: string | number | null;
  signed_at: string | null;
  remarks: string | null;
}

export interface Clearance {
  id: string;
  clearance_no: string;
  employee?: {
    id: string;
    employee_no: string;
    full_name: string;
    department?: { id: string; name: string; code: string } | null;
    position?: { id: string; title: string } | null;
    pay_type: string;
    date_hired: string | null;
  } | null;
  separation_date: string | null;
  separation_reason: SeparationReason;
  clearance_items: ClearanceItem[];
  cleared_count: number;
  items_total: number;
  progress_pct: number;
  final_pay_computed: boolean;
  final_pay_amount: string | null;
  final_pay_breakdown: Record<string, string> | null;
  journal_entry: { id: string; entry_number: string } | null;
  status: ClearanceStatus;
  initiator?: { id: string; name: string } | null;
  finalizer?: { id: string; name: string } | null;
  finalized_at: string | null;
  remarks: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface InitiateSeparationData {
  separation_date: string;
  separation_reason: SeparationReason;
  remarks?: string;
}
