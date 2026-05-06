/**
 * WS-D.1 — Chain registry SPA client.
 *
 * Mirrors api/app/Common/Services/ChainRegistry. The SPA uses this in
 * react-query to hydrate the canonical step labels for `<ChainHeader>`
 * instead of duplicating the catalog in spa/src/lib/chains/*.ts.
 */
import { client } from './client';
import type { ApiSuccess } from '@/types';

export interface ChainStepDefinition {
  key: string;
  label: string;
}

export interface ChainDefinition {
  key: string;
  label: string;
  steps: ChainStepDefinition[];
}

export const chainsApi = {
  list: () =>
    client.get<{ data: ChainDefinition[] }>('/chains').then((r) => r.data.data),

  definition: (key: string) =>
    client
      .get<ApiSuccess<ChainDefinition>>(`/chains/${key}/definition`)
      .then((r) => r.data.data),
};
