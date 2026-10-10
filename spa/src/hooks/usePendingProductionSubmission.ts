import { useState } from 'react';

type Submission<T> = { data: T; key: string };
const prefix = 'ogami:pending-production:';

function read<T>(scope: string): Submission<T> | null {
  try {
    const value = JSON.parse(sessionStorage.getItem(prefix + scope) ?? 'null');
    return value && typeof value.key === 'string' && value.data && typeof value.data === 'object'
      ? value
      : null;
  } catch {
    return null;
  }
}

// Keep the original request in this tab before sending it; reload must never create a second batch.
export function usePendingProductionSubmission<T>(scope: string) {
  const [state, setState] = useState(() => ({ scope, pending: read<T>(scope) }));
  const [storageError, setStorageError] = useState('');
  if (state.scope !== scope) setState({ scope, pending: read<T>(scope) });
  const remember = (submission: Submission<T>): boolean => {
    try {
      sessionStorage.setItem(prefix + scope, JSON.stringify(submission));
      setState({ scope, pending: submission });
      setStorageError('');
      return true;
    } catch {
      setStorageError(
        'Recording cannot be protected after reload. Allow browser storage, then retry. Nothing was sent.',
      );
      return false;
    }
  };
  const clear = () => {
    try {
      sessionStorage.removeItem(prefix + scope);
    } catch {
      /* A stale request remains safe to check or retry. */
    }
    setState({ scope, pending: null });
  };
  return { pending: state.scope === scope ? state.pending : null, remember, clear, storageError };
}
