import { client } from '../client';

export interface UomOption {
  id: string;
  code: string;
  name: string;
}

export const uomsApi = {
  list: () => client.get<{ data: UomOption[] }>('/inventory/uoms').then((r) => r.data.data),
};
