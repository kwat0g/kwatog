export type RealtimeConnectionState = 'live' | 'reconnecting' | 'offline';
export type RealtimeChannelState = 'pending' | 'ready' | 'failed' | 'removed';
export type RealtimeDeliveryState = 'unknown' | 'healthy' | 'degraded';

let transportState: RealtimeConnectionState = 'offline';
let deliveryState: RealtimeDeliveryState = 'unknown';
let lastUpdatedAt: number | null = null;
const channelStates = new Map<string, RealtimeChannelState>();
const subscribers = new Set<() => void>();

function notify(): void {
  subscribers.forEach((subscriber) => subscriber());
}

export function getRealtimeConnectionState(): RealtimeConnectionState {
  if (transportState !== 'live') return transportState;
  if (deliveryState !== 'healthy') return 'reconnecting';
  return [...channelStates.values()].every((state) => state === 'ready') ? 'live' : 'reconnecting';
}

export function subscribeRealtimeStatus(subscriber: () => void): () => void {
  subscribers.add(subscriber);
  return () => subscribers.delete(subscriber);
}

export function getRealtimeLastUpdatedAt(): number | null {
  return lastUpdatedAt;
}

export function setRealtimeLastUpdatedAt(value: number | null): void {
  if (lastUpdatedAt === value) return;
  lastUpdatedAt = value;
  notify();
}

export function setRealtimeTransportState(
  state: RealtimeConnectionState,
  options: { resetChannels?: boolean } = {},
): void {
  if (transportState === state) return;
  transportState = state;
  if (state !== 'live' && options.resetChannels !== false) {
    for (const channelName of channelStates.keys()) channelStates.set(channelName, 'pending');
  }
  notify();
}

/** Queue health is independent of the socket: a live socket can have no worker delivering events. */
export function setRealtimeDeliveryState(state: RealtimeDeliveryState): void {
  if (deliveryState === state) return;
  deliveryState = state;
  notify();
}

export function setRealtimeChannelState(channelName: string, state: RealtimeChannelState): void {
  if (state === 'removed') channelStates.delete(channelName);
  else channelStates.set(channelName, state);
  notify();
}

/** Test and identity-reset helper; does not touch Echo subscriptions. */
export function resetRealtimeStatus(): void {
  transportState = 'offline';
  deliveryState = 'unknown';
  lastUpdatedAt = null;
  channelStates.clear();
  notify();
}
