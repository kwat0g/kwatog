import { createContext, useContext } from 'react';
import type { RealtimeConnectionState } from './status';

export interface RealtimeContextValue {
  state: RealtimeConnectionState;
  lastUpdatedAt: number | null;
}

export const RealtimeContext = createContext<RealtimeContextValue>({
  state: 'offline',
  lastUpdatedAt: null,
});

export function useRealtimeStatus(): RealtimeContextValue {
  return useContext(RealtimeContext);
}
