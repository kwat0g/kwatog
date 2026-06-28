import { client } from '../client';

export type SettingValue = string | number | boolean | string[] | Record<string, unknown> | null;

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

export const settingsApi = {
  index: () =>
    client.get<{ data: SettingsByGroup }>('/admin/settings').then((r) => r.data.data),

  update: (key: string, value: SettingValue) =>
    client
      .put<{ data: SettingRow }>(`/admin/settings/${encodeURIComponent(key)}`, { value })
      .then((r) => r.data.data),

  systemInfo: () =>
    client.get<{ data: SystemInfo }>('/admin/system-info').then((r) => r.data.data),
};
