import type { ReturnCaseRealm } from '@/types/returnCases';

/** Return Case data is partitioned by API realm and the authenticated company/user scope. */
export const returnCaseQueryKeys = {
  root: ['return-cases'] as const,
  detailPrefix: ['return-cases', 'detail'] as const,
  listPrefix: ['return-cases', 'list'] as const,
  detail: (id: string, realm: ReturnCaseRealm, scope: string) =>
    ['return-cases', 'detail', id, realm, scope] as const,
  list: (realm: ReturnCaseRealm, scope: string, page: number, search: string, status: string) =>
    ['return-cases', 'list', realm, scope, page, search, status] as const,
  sources: (realm: ReturnCaseRealm, scope: string, type: string, search: string) =>
    ['return-cases', 'sources', realm, scope, type, search] as const,
  sourceOptions: (realm: ReturnCaseRealm, scope: string, kind?: string, id?: string) =>
    ['return-cases', 'source-options', realm, scope, kind, id] as const,
  resolutionOptions: (realm: ReturnCaseRealm, scope: string, id: string) =>
    ['return-cases', 'resolution-options', realm, scope, id] as const,
};
