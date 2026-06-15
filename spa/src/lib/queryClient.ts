import { QueryClient } from '@tanstack/react-query';

/**
 * Shared TanStack Query client.
 *
 * Lives in its own module (importing nothing from the app) so it can be
 * imported from non-React code — notably the auth store's `logout` action
 * and the Axios 401 handler, both of which call `queryClient.clear()` to
 * prevent cross-user cache leaks on shared terminals.
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      retry: 1,
      refetchOnWindowFocus: false,
      networkMode: 'offlineFirst',
    },
    mutations: {
      networkMode: 'offlineFirst',
    },
  },
});
