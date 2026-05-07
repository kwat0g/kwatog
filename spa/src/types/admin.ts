import type { PaginatedResponse } from './index';

// ─── Admin User Management (Task U2) ──────────────────────────────
export type AdminUserStatus = 'active' | 'inactive' | 'locked';

export interface AdminUserRoleSummary {
  id: string;
  name: string;
  slug: string;
}

export interface AdminUserEmployeeSummary {
  id: string;
  employee_no: string;
  full_name: string;
  department: { id: string; name: string } | null;
  position?: { id: string; title: string } | null;
}

export interface AdminUserListItem {
  id: string;
  name: string;
  email: string;
  status: AdminUserStatus;
  is_active: boolean;
  is_locked: boolean;
  must_change_password: boolean;
  role: AdminUserRoleSummary | null;
  employee: AdminUserEmployeeSummary | null;
  last_activity: string | null;
  created_at: string | null;
}

export interface LoginEvent {
  id: string;
  status: string;
  reason: string | null;
  email_attempted: string | null;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string | null;
}

export interface AdminUserDetail extends Omit<AdminUserListItem, 'status'> {
  is_active: boolean;
  is_locked: boolean;
  locked_until: string | null;
  must_change_password: boolean;
  theme_mode: string | null;
  password_changed_at: string | null;
  recent_logins: LoginEvent[];
}

export interface AdminUserListFilters {
  search?: string;
  role_id?: string;
  department_id?: string;
  status?: AdminUserStatus | '';
  sort?: string;
  direction?: 'asc' | 'desc';
  page?: number;
  per_page?: number;
}

export type AdminUserListResponse = PaginatedResponse<AdminUserListItem>;

export interface CreateAdminUserData {
  name: string;
  email: string;
  role_id: string;
  send_welcome?: boolean;
}

export interface CreateAdminUserResponse {
  message: string;
  data: {
    id: string;
    email: string;
    name: string;
    temp_password: string | null;
  };
}

// ─── Per-User Permission Overrides (Series R/R2) ──────────────────
export type PermissionOverrideType = 'grant' | 'revoke';

export interface UserPermissionOverride {
  id: string;
  type: PermissionOverrideType;
  permission: {
    id: string;
    slug: string;
    name: string;
    module: string;
    description: string | null;
  };
  granted_by: { id: string; name: string; email: string } | null;
  reason: string;
  expires_at: string | null;
  is_expired: boolean;
  created_at: string | null;
  updated_at: string | null;
}

export interface CreateUserPermissionOverrideData {
  permission_slug: string;
  type: PermissionOverrideType;
  reason: string;
  /** ISO datetime string. Null/omitted = never expires. */
  expires_at?: string | null;
}
