import { useCallback, useState } from 'react';

interface QueryRecordTargetOptions {
  isFetching: boolean;
  isError: boolean;
}

/**
 * Keep an open action dialog attached to a record ID while deriving its row
 * from the latest query result. A target is actionable only after the active
 * query has settled successfully and still contains that record.
 */
export function useQueryRecordTarget<T extends { id: string }>(
  rows: readonly T[] | undefined,
  { isFetching, isError }: QueryRecordTargetOptions,
) {
  const [targetId, setTargetId] = useState<string | null>(null);
  const target = targetId === null ? null : rows?.find((row) => row.id === targetId) ?? null;

  const open = useCallback((row: T) => setTargetId(row.id), []);
  const close = useCallback(() => setTargetId(null), []);

  return {
    targetId,
    target,
    open,
    close,
    isChecking: targetId !== null && isFetching,
    isUnavailable: targetId !== null && !isFetching && !isError && target === null,
    isUnverified: targetId !== null && isError,
    isFresh: targetId !== null && target !== null && !isFetching && !isError,
  };
}
