import { act, renderHook } from '@testing-library/react';
import { expect, it } from 'vitest';
import { useQueryRecordTarget } from './useQueryRecordTarget';

interface Row {
  id: string;
  status: string;
}

it('keeps a target bound to its id and reports when its query is stale or unavailable', () => {
  const pending: Row = { id: 'record-1', status: 'pending' };
  const { result, rerender } = renderHook(
    ({ rows, isFetching, isError }: { rows: Row[]; isFetching: boolean; isError: boolean }) =>
      useQueryRecordTarget(rows, { isFetching, isError }),
    { initialProps: { rows: [pending], isFetching: false, isError: false } },
  );

  act(() => result.current.open(pending));
  expect(result.current.target).toBe(pending);
  expect(result.current.isFresh).toBe(true);

  const updated: Row = { ...pending, status: 'approved' };
  rerender({ rows: [updated], isFetching: false, isError: false });
  expect(result.current.target).toBe(updated);
  expect(result.current.isFresh).toBe(true);

  rerender({ rows: [], isFetching: true, isError: false });
  expect(result.current.target).toBeNull();
  expect(result.current.isChecking).toBe(true);
  expect(result.current.isFresh).toBe(false);

  rerender({ rows: [], isFetching: false, isError: false });
  expect(result.current.isUnavailable).toBe(true);
  expect(result.current.isFresh).toBe(false);

  rerender({ rows: [updated], isFetching: false, isError: true });
  expect(result.current.target).toBe(updated);
  expect(result.current.isUnverified).toBe(true);
  expect(result.current.isFresh).toBe(false);
});
