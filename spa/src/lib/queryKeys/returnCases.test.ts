import { describe, expect, it } from 'vitest';
import { returnCaseQueryKeys } from './returnCases';

describe('return case query key scopes', () => {
  it('partitions list, detail, source and resolution queries by realm and principal scope', () => {
    expect(returnCaseQueryKeys.list('customer', 'customer:account-a', 1, '', '')).not.toEqual(
      returnCaseQueryKeys.list('customer', 'customer:account-b', 1, '', ''),
    );
    expect(returnCaseQueryKeys.detail('case-a', 'internal', 'user:internal-user-a')).not.toEqual(
      returnCaseQueryKeys.detail('case-a', 'internal', 'user:internal-user-b'),
    );
    expect(returnCaseQueryKeys.sources('supplier', 'supplier:vendor-a', 'supplier', 'resin')).not.toEqual(
      returnCaseQueryKeys.sources('supplier', 'supplier:vendor-b', 'supplier', 'resin'),
    );
    expect(returnCaseQueryKeys.resolutionOptions('internal', 'user:finance-a', 'case-a')).not.toEqual(
      returnCaseQueryKeys.resolutionOptions('internal', 'user:finance-b', 'case-a'),
    );
  });
});
