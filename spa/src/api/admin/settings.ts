import { client } from '../client';

export type SettingValue = string | number | boolean | unknown[] | Record<string, unknown> | null;

export interface SettingRow {
 key: string;
 value: SettingValue;
 group: string;
 label: string | null;
 description: string | null;
 updated_by_name?: string | null;
 updated_at?: string | null;
}

export type SettingsByGroup = Record<string, SettingRow[]>;

export interface SystemInfo {
 php_version: string;
 laravel_version: string;
 database: { driver: string; version: string };
 cache_driver: string;
 queue_driver: string;
 session_driver: string;
 app_env: string;
 app_debug: boolean;
 timezone: string;
 server_time: string;
}

export interface SystemHealth {
 generated_at: string;
 realtime: {
  delivery_status: 'healthy' | 'degraded';
  broadcast_queue_pending: number | null;
  outbox_pending: number | null;
  outbox_failed: number | null;
  oldest_pending_seconds: number | null;
 };
 reverb_tcp_status: 'reachable' | 'unreachable' | 'unconfigured';
 redis_cache: {
  status: 'available' | 'unavailable';
  used_memory_bytes: number | null;
  max_memory_bytes: number | null;
 };
 redis_persistent?: {
  status: 'available' | 'unavailable';
  used_memory_bytes: number | null;
  max_memory_bytes: number | null;
 };
 cache_audit: {
  status: 'clean' | 'attention' | 'unknown';
  completed_at: string | null;
  entries: number | null;
  without_ttl: number | null;
  oversized: number | null;
  unknown_size: number | null;
 };
}

export interface CacheGroup {
 label: string;
 description: string;
 families: string[];
}

export const settingsApi = {
 index: () =>
 client.get<{ data: SettingsByGroup }>('/admin/settings').then((r) => r.data.data),

 update: (key: string, value: SettingValue) =>
 client
 .put<{ data: SettingRow }>(`/admin/settings/${encodeURIComponent(key)}`, { value })
 .then((r) => r.data.data),

 systemInfo: () =>
 client.get<{ data: SystemInfo }>('/admin/system-info').then((r) => r.data.data),

 systemHealth: () =>
  client.get<{ data: SystemHealth }>('/admin/system-health').then((r) => r.data.data),

 cacheGroups: () =>
  client.get<{ data: Record<string, CacheGroup> }>('/admin/cache-groups').then((r) => r.data.data),

 clearCacheGroup: (group: string) =>
  client
   .post<{ data: {
    group: string;
    families: string[];
    invalidation: 'generation_rotated' | 'generation_rotation_requested' | 'generation_rotation_failed';
   } }>(
    `/admin/cache-groups/${encodeURIComponent(group)}/clear`,
   )
   .then((r) => r.data.data),
};
