/**
 * Series F — Task F5. Directory types.
 */

export interface DirectoryEmployee {
  id: string;
  employee_no: string;
  full_name: string;
  first_name: string;
  last_name: string;
  photo_path: string | null;
  mobile_number: string | null; // masked
  email: string | null;
  status: string | null;
  position: { id: string; title: string } | null;
  department: { id: string; name: string; code: string } | null;
}

export interface DirectoryListResponse {
  data: DirectoryEmployee[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface DirectoryListParams {
  search?: string;
  department_id?: string;
  page?: number;
  per_page?: number;
}

export interface OrgChartGroup {
  department: { id: string; name: string; code: string } | null;
  employees: DirectoryEmployee[];
}

export interface OrgChartResponse {
  data: OrgChartGroup[];
}
