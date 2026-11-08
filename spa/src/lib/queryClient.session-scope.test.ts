import { afterEach, describe, expect, it } from 'vitest';
import { queryClient } from './queryClient';

describe('session-scoped query cache', () => {
  afterEach(() => queryClient.clear());

  it('does not let an old session mutation repopulate cache after it is cleared', async () => {
    let resolveRequest!: (value: { owner: string }) => void;
    let markStarted!: () => void;
    const started = new Promise<void>((resolve) => {
      markStarted = resolve;
    });
    const response = new Promise<{ owner: string }>((resolve) => {
      resolveRequest = resolve;
    });

    const mutation = queryClient.getMutationCache().build(queryClient, {
      mutationFn: () => {
        markStarted();
        return response;
      },
      onSuccess: (record) => {
        queryClient.setQueryData(['private', 'record'], record);
      },
    });

    const pending = mutation.execute(undefined);
    await started;
    queryClient.clear();
    resolveRequest({ owner: 'previous-session' });
    await expect(pending).rejects.toThrow('authenticated session changed');

    expect(queryClient.getQueryData(['private', 'record'])).toBeUndefined();
  });

  it('does not let an old session read overwrite the new session cache after it is cleared', async () => {
    const key = ['shared', 'record'] as const;
    let resolvePreviousRead!: (value: { owner: string }) => void;
    let markPreviousReadStarted!: () => void;
    const previousReadStarted = new Promise<void>((resolve) => {
      markPreviousReadStarted = resolve;
    });
    const previousResponse = new Promise<{ owner: string }>((resolve) => {
      resolvePreviousRead = resolve;
    });

    const previousRead = queryClient.fetchQuery({
      queryKey: key,
      queryFn: () => {
        markPreviousReadStarted();
        return previousResponse;
      },
      retry: false,
    });
    await previousReadStarted;

    queryClient.clear();
    await queryClient.fetchQuery({
      queryKey: key,
      queryFn: async () => ({ owner: 'current-session' }),
      retry: false,
    });

    resolvePreviousRead({ owner: 'previous-session' });
    await previousRead.catch(() => undefined);
    await Promise.resolve();

    expect(queryClient.getQueryData(key)).toEqual({ owner: 'current-session' });
  });
});
