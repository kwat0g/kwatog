import type { PaginatedResponse, ApiSuccess } from './index';

// ─── Department ─────────────────────────────────────────────────
export interface Department {
  id: string;
  name: string;
  code: string;
  parent_id: string | null;
  parent?: Department | null;
  head_employee_id: string | null;
  head_employee?: { id: string; full_name: string } | null;
  is_active: boolean;
  positions_count?: number;
  employees_count?: number;
  created_at: string;
  updated_at: string;
}

export interface CreateDepartmentData {
  name: string;
  code: string;
  parent_id?: string | null;
  head_employee_id?: string | null;
  is_active?: boolean;
}

export type UpdateDepartmentData = Partial<CreateDepartmentData>;

// ─── Position ──────────────────────────────────────────────────
export interface Position {
  id: string;
  title: string;
  department_id: string;
  department?: Department;
  salary_grade: string | null;
  employees_count?: number;
  created_at: string;
  updated_at: string;
}

export interface CreatePositionData {
  title: string;
  department_id: string;
  salary_grade?: string | null;
}

export type UpdatePositionData = Partial<CreatePositionData>;

// Re-export for convenience
export type { PaginatedResponse, ApiSuccess };

// ─── Employee — used by Tasks 14/15 (declared here, populated by Task 14) ──
export type EmployeeStatus = 'active' | 'on_leave' | 'suspended' | 'resigned' | 'terminated' | 'retired';
export type EmploymentType = 'regular' | 'probationary' | 'contractual' | 'project_based';
export type PayType = 'monthly' | 'daily';
export type Gender = 'male' | 'female';
export type CivilStatus = 'single' | 'married' | 'widowed' | 'separated' | 'divorced';

export interface EmployeeAddress {
  street: string | null;
  barangay: string | null;
  city: string | null;
  province: string | null;
  zip_code: string | null;
}

export interface EmployeeContact {
  mobile_number: string | null;
  email: string | null;
  emergency_contact_name: string | null;
  emergency_contact_relation: string | null;
  emergency_contact_phone: string | null;
}

export interface Employee {
  id: string;
  employee_no: string;
  first_name: string;
  middle_name: string | null;
  last_name: string;
  suffix: string | null;
  full_name: string;
  birth_date: string | null;
  gender: Gender | null;
  civil_status: CivilStatus | null;
  nationality: string;
  photo_path: string | null;
  address: EmployeeAddress;
  contact: EmployeeContact;
  status: EmployeeStatus;
  employment_type: EmploymentType;
  pay_type: PayType;
  date_hired: string;
  date_regularized: string | null;
  basic_monthly_salary: string | null;
  daily_rate: string | null;
  bank_name: string | null;
  // Sensitive — masked unless authorized
  sss_no: string | null;
  philhealth_no: string | null;
  pagibig_no: string | null;
  tin: string | null;
  bank_account_no: string | null;
  department: Department | null;
  position: Position | null;
  user?: { id: string; name: string; email: string } | null;
  created_at: string;
  updated_at: string;
}
