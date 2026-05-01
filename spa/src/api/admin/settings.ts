import { client } from '../client';

export type SettingValue = string | number | boolean | string[] | Record<string, unknown> | null;

export interface SettingRow {
  key: string;
  value: SettingValue;
  group: string;
  updated_at?: string | null;
}

export type SettingsByGroup = Record<string, SettingRow[]>;

export const settingsApi = {
  index: () =>
    client.get<{ data: SettingsByGroup }>('/admin/settings').then((r) => r.data.data),

  update: (key: string, value: SettingValue) =>
    client
      .put<{ data: SettingRow }>(`/admin/settings/${encodeURIComponent(key)}`, { value })
      .then((r) => r.data.data),
};
