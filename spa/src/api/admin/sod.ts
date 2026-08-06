import { client } from '../client';
import type { ApiSuccess } from '@/types';

export type SodSeverity = 'low' | 'medium' | 'high';

export interface SodPermissionRef {
 slug: string;
 name: string;
}

export interface SodConflictRule {
 id: string;
 code: string;
 name: string;
 severity: SodSeverity;
 severity_label?: string;
 rationale: string | null;
 active: boolean;
 permission_a: SodPermissionRef;
 permission_b: SodPermissionRef;
}

export interface SodViolation {
 user: { id: string; name: string; email: string; role: string | null };
 violations: { code: string; name: string; severity: SodSeverity; severity_label?: string }[];
}

export const sodApi = {
 matrix: () =>
 client.get<ApiSuccess<SodConflictRule[]>>('/admin/sod/matrix').then((r) => r.data.data),

 violations: () =>
 client
 .get<{ data: SodViolation[]; meta: { total_users_flagged: number } }>('/admin/sod/violations')
 .then((r) => r.data),
};
