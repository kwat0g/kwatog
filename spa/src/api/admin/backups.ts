import { client } from '../client';

export interface BackupArtifact {
 name: string;
 kind: 'database' | 'files';
 size: number;
 sha256: string | null;
 created_at: string | null;
 source: 'local' | 'offsite';
}

export interface BackupOperation {
 id: string | null;
 type: 'backup' | 'restore';
 status: 'queued' | 'running' | 'completed' | 'failed' | 'available';
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
}

export interface BackupCenterData {
 backups: BackupOperation[];
 active_operation: { id: string; type: string; status: string } | null;
 configuration: {
 local_directory_configured: boolean;
 offsite_configured: boolean;
 scope: string;
 restore_requires_maintenance: boolean;
 };
}

export const backupsApi = {
 index: () => client.get<{ data: BackupCenterData }>('/admin/backups').then((r) => r.data.data),

 create: () =>
 client
 .post<{ data: { id: string; type: string; status: string }; message: string }>('/admin/backups')
 .then((r) => r.data),

 restore: (data: { database_filename: string; files_filename?: string; confirmation: string }) =>
 client
 .post<{ data: { id: string; type: string; status: string }; message: string }>('/admin/backups/restore', data)
 .then((r) => r.data),
};
