import { afterEach, describe, expect, it, vi } from 'vitest';

const echoConstructorMock = vi.hoisted(() => vi.fn(function EchoMock() { return { disconnect: vi.fn() }; }));

vi.mock('laravel-echo', () => ({ default: echoConstructorMock }));

import { getEcho, resetEcho } from './echo';

describe('Echo client loading', () => {
  afterEach(() => {
    resetEcho();
    delete (window as unknown as { Pusher?: unknown }).Pusher;
    vi.clearAllMocks();
  });

  it('installs the Pusher constructor when the module default is a namespace object', async () => {
    const pusherModule = await import('pusher-js');
    const defaultConstructor = typeof pusherModule.default === 'function'
      ? pusherModule.default
      : (pusherModule.default as unknown as { Pusher?: typeof pusherModule.default }).Pusher
        ?? (pusherModule as unknown as { Pusher?: typeof pusherModule.default }).Pusher;

    await getEcho();

    expect(typeof defaultConstructor).toBe('function');
    expect((window as unknown as { Pusher?: unknown }).Pusher).toBe(defaultConstructor);
    expect(echoConstructorMock).toHaveBeenCalledWith(expect.objectContaining({ broadcaster: 'reverb' }));
  });
});
