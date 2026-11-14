/** Read an HTTP response status without coupling shared policy code to Axios. */
export function getHttpErrorStatus(error: unknown): number | undefined {
  if (typeof error !== 'object' || error === null || !('response' in error)) return undefined;

  const response = (error as { response?: unknown }).response;
  if (typeof response !== 'object' || response === null || !('status' in response)) return undefined;

  const status = (response as { status?: unknown }).status;
  return typeof status === 'number' ? status : undefined;
}

/** Client errors are determined by the request and should not be auto-retried. */
export function isClientHttpError(error: unknown): boolean {
  const status = getHttpErrorStatus(error);
  return status !== undefined && status >= 400 && status < 500;
}

/** Keep transient retries for network/server failures, never for client responses. */
export function shouldRetryQuery(failureCount: number, error: unknown): boolean {
  return !isClientHttpError(error) && failureCount < 1;
}

type QueryWithErrorState = { state: { error: unknown } };

/** Preserve explicit always-refetch behavior unless the last request was rejected. */
export function refetchOnMountUnlessClientError(query: QueryWithErrorState): boolean | 'always' {
  return isClientHttpError(query.state.error) ? false : 'always';
}

/** Revalidate on focus/reconnect unless the last response rejected the request. */
export function refetchUnlessClientHttpError(query: QueryWithErrorState): boolean {
  return !isClientHttpError(query.state.error);
}

/** Stop a polling query after a client response; 5xx/network failures may recover. */
export function refetchIntervalUnlessClientHttpError(interval: number | false) {
  return (query: QueryWithErrorState): number | false =>
    isClientHttpError(query.state.error) ? false : interval;
}
