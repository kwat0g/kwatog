import { getEcho, type EchoClient } from '@/lib/echo';
import { setRealtimeChannelState } from './status';

interface PrivateChannelHandle {
  listen: (event: string, handler: (payload: unknown) => void) => PrivateChannelHandle;
  stopListening: (event: string) => PrivateChannelHandle;
  subscribed: (handler: () => void) => PrivateChannelHandle;
  error: (handler: (error: unknown) => void) => PrivateChannelHandle;
}

interface EventRegistration {
  handlers: Set<(payload: unknown) => void>;
  readyHandlers: Set<() => void>;
  errorHandlers: Set<(error: unknown) => void>;
  dispatch: (payload: unknown) => void;
  attached: boolean;
}

interface ChannelRegistration {
  name: string;
  events: Map<string, EventRegistration>;
  echo?: EchoClient;
  channel?: PrivateChannelHandle;
  opening?: Promise<void>;
  ready: boolean;
  failed: boolean;
}

const channels = new Map<string, ChannelRegistration>();
const subscriptionListeners = new Set<() => void>();

function notifySubscriptionListeners(): void {
  for (const listener of [...subscriptionListeners]) listener();
}

/** Number of active channel registrations, used by the transport owner. */
export function getRealtimeSubscriptionCount(): number {
  return channels.size;
}

export function subscribeRealtimeSubscriptions(listener: () => void): () => void {
  subscriptionListeners.add(listener);
  return () => subscriptionListeners.delete(listener);
}

/** Retry registrations whose Echo load or private-channel authorization failed. */
export function retryRealtimeSubscriptions(): void {
  for (const registration of channels.values()) {
    if (registration.events.size === 0 || registration.opening) continue;

    if (registration.failed && registration.channel) {
      registration.echo?.leave(registration.name);
      registration.channel = undefined;
      registration.echo = undefined;
      registration.ready = false;
      registration.failed = false;
      registration.events.forEach((eventRegistration) => {
        eventRegistration.attached = false;
      });
      // Successful initialization leaves a resolved promise in this slot.
      // Clear it before reopening a failed channel on the same Echo client.
      registration.opening = undefined;
    }

    if (!registration.channel && !registration.opening) {
      openChannel(registration);
    }
  }
}

function attachEvent(registration: ChannelRegistration, event: string, eventRegistration: EventRegistration): void {
  if (!registration.channel || eventRegistration.attached || eventRegistration.handlers.size === 0) return;

  registration.channel.listen(event, eventRegistration.dispatch);
  eventRegistration.attached = true;
}

function openChannel(registration: ChannelRegistration): void {
  if (registration.opening) return;
  setRealtimeChannelState(registration.name, 'pending');

  const opening = getEcho()
    .then((echo) => {
      if (channels.get(registration.name) !== registration || registration.events.size === 0) return;

      registration.echo = echo;
      registration.channel = echo.private(registration.name) as unknown as PrivateChannelHandle;
      registration.ready = false;
      registration.channel.subscribed(() => {
        if (channels.get(registration.name) !== registration) return;
        registration.ready = true;
        registration.failed = false;
        setRealtimeChannelState(registration.name, 'ready');
        for (const eventRegistration of registration.events.values()) {
          for (const callback of [...eventRegistration.readyHandlers]) {
            try {
              callback();
            } catch (error) {
              console.error(`Real-time catch-up failed for ${registration.name}.`, error);
            }
          }
        }
      });
      registration.channel.error((error) => {
        if (channels.get(registration.name) !== registration) return;
        registration.ready = false;
        registration.failed = true;
        setRealtimeChannelState(registration.name, 'failed');
        for (const eventRegistration of registration.events.values()) {
          for (const callback of [...eventRegistration.errorHandlers]) {
            try {
              callback(error);
            } catch (handlerError) {
              console.error(`Real-time error handler failed for ${registration.name}.`, handlerError);
            }
          }
        }
      });
      registration.events.forEach((eventRegistration, event) => attachEvent(registration, event, eventRegistration));
    })
    .catch((error: unknown) => {
      if (channels.get(registration.name) === registration) {
        registration.failed = true;
        setRealtimeChannelState(registration.name, 'failed');
        for (const eventRegistration of registration.events.values()) {
          for (const callback of [...eventRegistration.errorHandlers]) {
            try {
              callback(error);
            } catch (handlerError) {
              console.error(`Real-time error handler failed for ${registration.name}.`, handlerError);
            }
          }
        }
      }
      console.error('Real-time subscription could not be opened.', error);
    });
  registration.opening = opening;
  void opening.finally(() => {
    if (registration.opening === opening) registration.opening = undefined;
  });
}

function closeChannelIfEmpty(registration: ChannelRegistration): void {
  if (registration.events.size > 0) return;
  if (channels.get(registration.name) === registration) {
    channels.delete(registration.name);
    notifySubscriptionListeners();
  }
  registration.ready = false;
  setRealtimeChannelState(registration.name, 'removed');

  if (registration.channel) {
    registration.echo?.leave(registration.name);
  }
}

/**
 * Register a handler on a private Echo channel. Multiple React consumers of
 * the same channel/event share one Echo listener and release it by reference
 * count when the final consumer unmounts.
 */
export function subscribeRealtimeEvent<T>(
  channelName: string,
  event: string,
  handler: (payload: T) => void,
  onSubscribed?: () => void,
  onError?: (error: unknown) => void,
): () => void {
  let registration = channels.get(channelName);
  if (!registration) {
    registration = { name: channelName, events: new Map(), ready: false, failed: false };
    channels.set(channelName, registration);
    notifySubscriptionListeners();
  }

  let eventRegistration = registration.events.get(event);
  if (!eventRegistration) {
    const handlers = new Set<(payload: unknown) => void>();
    eventRegistration = {
      handlers,
      readyHandlers: new Set(),
      errorHandlers: new Set(),
      dispatch: (payload) => {
        for (const callback of [...handlers]) {
          try {
            callback(payload);
          } catch (error) {
            console.error(`Real-time handler failed for ${channelName} ${event}.`, error);
          }
        }
      },
      attached: false,
    };
    registration.events.set(event, eventRegistration);
  }

  const callbacks = eventRegistration.handlers;
  const wrapped = (payload: unknown) => handler(payload as T);
  const wrappedReady = onSubscribed ? () => onSubscribed() : undefined;
  const wrappedError = onError ? (error: unknown) => onError(error) : undefined;
  callbacks.add(wrapped);
  if (wrappedReady) eventRegistration.readyHandlers.add(wrappedReady);
  if (wrappedError) eventRegistration.errorHandlers.add(wrappedError);

  if (registration.channel) attachEvent(registration, event, eventRegistration);
  else openChannel(registration);
  if (registration.ready) wrappedReady?.();

  let active = true;
  return () => {
    if (!active) return;
    active = false;
    callbacks.delete(wrapped);
    if (wrappedReady) eventRegistration?.readyHandlers.delete(wrappedReady);
    if (wrappedError) eventRegistration?.errorHandlers.delete(wrappedError);

    if (callbacks.size === 0) {
      if (eventRegistration?.attached) registration?.channel?.stopListening(event);
      registration?.events.delete(event);
      if (registration) closeChannelIfEmpty(registration);
    }
  };
}

/** Release every owned listener, used when the authenticated identity changes. */
export function clearRealtimeSubscriptions(): void {
  const hadSubscriptions = channels.size > 0;
  for (const registration of channels.values()) {
    for (const [event, eventRegistration] of registration.events) {
      if (eventRegistration.attached) registration.channel?.stopListening(event);
    }
    if (registration.channel) registration.echo?.leave(registration.name);
    setRealtimeChannelState(registration.name, 'removed');
  }
  channels.clear();
  if (hadSubscriptions) notifySubscriptionListeners();
}
