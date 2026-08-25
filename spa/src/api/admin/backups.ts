import { client } from '../client';

export interface BackupArtifact {
 name: string;
 kind: 'database' | 'files';
 size: number;
 sha256: string | null;
 created_at: string | null;
 source: 'local' | 'offsite';
 availability: 'local' | 'remote' | 'missing' | 'mismatch';
 // The catalog can only prove size cheaply; SHA-256 is enforced by restore
 // preflight, so a list response never claims `checksum_verified`.
 integrity: 'manifest_match' | 'not_verified' | 'mismatch';
}

export interface BackupOperation {
 id: string | null;
 type: 'backup' | 'restore';
 status: 'queued' | 'running' | 'completed' | 'failed' | 'available' | 'rollback_required' | 'rolled_back';
 artifacts: {
 database: BackupArtifact | null;
 files: BackupArtifact | null;
 };
 error_message: string | null;
 requested_by: number | null;
 requested_by_name: string | null;
 created_at: string | null;
 started_at: string | null;
 completed_at: string | null;
 manifest_committed?: boolean;
 restorable?: boolean;
 availability?: {
 database: BackupArtifact['availability'];
 files: BackupArtifact['availability'] | null;
 };
}

export interface BackupCenterData {
 backups: BackupOperation[];
 next_cursor: string | null;
 active_operation: { id: string; type: string; status: string; created_at?: string; started_at?: string | null } | null;
 configuration: {
 local_directory_configured: boolean;
 offsite_configured: boolean;
 scope: string;
 restore_requires_maintenance: boolean;
 };
}

export const backupsApi = {
 index: (cursor?: string | null) =>
 client
 .get<{ data: BackupCenterData }>('/admin/backups', { params: cursor ? { cursor } : undefined })
 .then((r) => r.data.data),

 create: () =>
 client
 .post<{ data: { id: string; type: string; status: string }; message: string }>('/admin/backups')
 .then((r) => r.data),

 restore: (data: { backup_operation_id?: string; database_filename: string; files_filename?: string; confirmation: string }) =>
 client
 .post<{ data: { id: string; type: string; status: string }; message: string }>('/admin/backups/restore', data)
 .then((r) => r.data),
};
