import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, act } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { useKeyboardShortcuts } from './useKeyboardShortcuts';
import { PageActionsProvider, usePageActions } from '@/contexts/PageActionsContext';
import type { ReactNode } from 'react';

function Wrapper({ children, initialEntries = ['/'] }: { children: ReactNode; initialEntries?: string[] }) {
  return (
    <MemoryRouter initialEntries={initialEntries}>
      <PageActionsProvider>{children}</PageActionsProvider>
    </MemoryRouter>
  );
}

function dispatchKey(key: string, opts: KeyboardEventInit = {}, target: EventTarget = document) {
  const e = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...opts });
  target.dispatchEvent(e);
}

describe('useKeyboardShortcuts', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = '';
  });

  it('navigates on `g h` two-key sequence', () => {
    let pathname = '/';
    function Spy() {
      pathname = useLocation().pathname;
      useKeyboardShortcuts();
      return null;
    }
    render(
      <Wrapper initialEntries={['/start']}>
        <Spy />
      </Wrapper>,
    );

    act(() => {
      dispatchKey('g');
      dispatchKey('h');
    });
    expect(pathname).toBe('/hr/employees');
  });

  it('does NOT navigate when a non-target letter follows `g`', () => {
    let pathname = '/start';
    function Spy() {
      pathname = useLocation().pathname;
      useKeyboardShortcuts();
      return null;
    }
    render(
      <Wrapper initialEntries={['/start']}>
        <Spy />
      </Wrapper>,
    );

    act(() => {
      dispatchKey('g');
      dispatchKey('z');
    });
    expect(pathname).toBe('/start');
  });

  it('expires the leader after 1s of inactivity', () => {
    let pathname = '/start';
    function Spy() {
      pathname = useLocation().pathname;
      useKeyboardShortcuts();
      return null;
    }
    render(
      <Wrapper initialEntries={['/start']}>
        <Spy />
      </Wrapper>,
    );

    act(() => {
      dispatchKey('g');
    });
    act(() => {
      vi.advanceTimersByTime(1100);
    });
    act(() => {
      dispatchKey('h');
    });
    expect(pathname).toBe('/start');
  });

  it('does not fire navigation while typing in an input', () => {
    let pathname = '/start';
    function Spy() {
      pathname = useLocation().pathname;
      useKeyboardShortcuts();
      return null;
    }
    render(
      <Wrapper initialEntries={['/start']}>
        <Spy />
      </Wrapper>,
    );

    const input = document.createElement('input');
    document.body.appendChild(input);
    input.focus();
    act(() => {
      dispatchKey('g', {}, input);
      dispatchKey('h', {}, input);
    });
    expect(pathname).toBe('/start');
  });

  it('toggles helpOpen on `?`', () => {
    let helpOpen = false;
    function Spy() {
      const api = useKeyboardShortcuts();
      helpOpen = api.helpOpen;
      return null;
    }
    render(
      <Wrapper>
        <Spy />
      </Wrapper>,
    );
    expect(helpOpen).toBe(false);

    act(() => {
      dispatchKey('?');
    });
    expect(helpOpen).toBe(true);

    act(() => {
      dispatchKey('?');
    });
    expect(helpOpen).toBe(false);
  });

  it('fires onSave for ⌘S even inside an input', () => {
    const onSave = vi.fn();
    function Inner() {
      useKeyboardShortcuts();
      usePageActions({ onSave });
      return null;
    }
    render(
      <Wrapper>
        <Inner />
      </Wrapper>,
    );

    const input = document.createElement('input');
    document.body.appendChild(input);
    input.focus();
    act(() => {
      dispatchKey('s', { metaKey: true }, input);
    });
    expect(onSave).toHaveBeenCalledTimes(1);
  });

  it('fires onCreate for ⌘ ⇧ N outside inputs', () => {
    const onCreate = vi.fn();
    function Inner() {
      useKeyboardShortcuts();
      usePageActions({ onCreate });
      return null;
    }
    render(
      <Wrapper>
        <Inner />
      </Wrapper>,
    );

    act(() => {
      dispatchKey('n', { metaKey: true, shiftKey: true });
    });
    expect(onCreate).toHaveBeenCalledTimes(1);
  });
});
