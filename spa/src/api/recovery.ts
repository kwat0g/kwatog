import axios from 'axios';
import type { BackupOperation } from '@/api/admin/backups';

const recoveryClient = axios.create({
 baseURL: '/api/v1/recovery',
 timeout: 30_000,
 headers: { Accept: 'application/json' },
});

function withToken(token: string) {
 return { headers: { Authorization: `Bearer ${token}` } };
}

export interface RecoveryOperation {
 id: string;
 status: string;
 phase?: string | null;
 error_message?: string | null;
 rollback_available?: boolean;
}

export const recoveryApi = {
 login: async (secret: string) => (await recoveryClient.post<{ access_token: string; expires_in: number }>('/session', { secret })).data,
 index: async (token: string) => (await recoveryClient.get<{ data: { backups: BackupOperation[] } }>('/backups', withToken(token))).data.data,
 verify: async (token: string, database_filename: string) => (await recoveryClient.post<{ message: string }>('/backups/verify', { database_filename }, withToken(token))).data,
 restore: async (token: string, data: { manifest_id: string; database_filename: string; files_filename?: string; confirmation: string }) => (await recoveryClient.post<{ message: string; data: RecoveryOperation }>('/restore', data, withToken(token))).data,
 operation: async (token: string, id: string) => (await recoveryClient.get<{ data: RecoveryOperation }>(`/operations/${encodeURIComponent(id)}`, withToken(token))).data.data,
 rollback: async (token: string, id: string) => (await recoveryClient.post<{ message: string }>(`/operations/${encodeURIComponent(id)}/rollback`, {}, withToken(token))).data,
};
