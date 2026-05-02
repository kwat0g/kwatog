import type { Department } from './hr';

export interface LeaveType {
  id: string;
  name: string;
  code: string;
  default_balance: string;
  is_paid: boolean;
  requires_document: boolean;
  is_convertible_on_separation: boolean;
  is_convertible_year_end: boolean;
  conversion_rate: string;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface EmployeeLeaveBalance {
  id: string;
  employee_id: string;
  leave_type: { id: string; code: string; name: string };
  year: number;
  total_credits: string;
  used: string;
  remaining: string;
}

export type LeaveStatus = 'pending_dept' | 'pending_hr' | 'approved' | 'rejected' | 'cancelled';

export interface LeaveRequest {
  id: string;
  leave_request_no: string;
  employee: { id: string; employee_no: string; full_name: string; department: string | null } | null;
  leave_type: { id: string; code: string; name: string } | null;
  start_date: string;
  end_date: string;
  days: string;
  reason: string | null;
  document_path: string | null;
  status: LeaveStatus;
  dept_approver: { id: string; name: string } | null;
  dept_approved_at: string | null;
  hr_approver: { id: string; name: string } | null;
  hr_approved_at: string | null;
  rejection_reason: string | null;
  created_at: string;
  updated_at: string;
}

export interface CreateLeaveRequestData {
  employee_id: string;
  leave_type_id: string;
  start_date: string;
  end_date: string;
  reason?: string;
  document_path?: string;
}

export type { Department };
