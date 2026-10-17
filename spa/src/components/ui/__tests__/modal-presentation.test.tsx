import { afterEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, render, screen } from '@testing-library/react';
import { Modal, ModalFooter } from '../Modal';
import { BottomSheet } from '../BottomSheet';
import { PHONE_QUERY, useMediaQuery } from '@/hooks/useMediaQuery';
import { Button } from '../Button';

/**
 * W1.3 — the phone action surface.
 *
 * The rule ("a dialog is a bottom sheet below 640px") lives inside `Modal`, so
 * these tests are the whole contract: the 214 call sites inherit it and do not
 * need their own. jsdom has no `matchMedia`, which is itself the reason the hook
 * guards the call — every other unit test in this suite renders dialogs with no
 * viewport at all, and a hook that assumed media-query support would fail all of
 * them.
 */

interface MediaStub {
  set(next: boolean): void;
  readonly mql: { matches: boolean };
}

/** A controllable `window.matchMedia` for the phone query. */
function stubMatchMedia(matches: boolean): MediaStub {
  const listeners = new Set<() => void>();
  const mql = {
    matches,
    media: PHONE_QUERY,
    addEventListener: (_: string, cb: () => void) => listeners.add(cb),
    removeEventListener: (_: string, cb: () => void) => listeners.delete(cb),
  };
  Object.defineProperty(window, 'matchMedia', {
    configurable: true,
    writable: true,
    value: vi.fn(() => mql),
  });
  return {
    mql,
    set(next: boolean) {
      mql.matches = next;
      act(() => listeners.forEach((cb) => cb()));
    },
  };
}

function renderModal(props: Partial<React.ComponentProps<typeof Modal>> = {}) {
  return render(
    <Modal isOpen onClose={() => undefined} title="Create work order" {...props}>
      <input aria-label="Quantity" defaultValue="1200" />
      <ModalFooter>
        <Button variant="ghost">Cancel</Button>
        <Button>Create</Button>
      </ModalFooter>
    </Modal>,
  );
}

afterEach(() => {
  cleanup();
  // jsdom does not implement matchMedia; removing the stub restores the
  // environment every other test in the suite runs in.
  delete (window as unknown as { matchMedia?: unknown }).matchMedia;
});

describe('Modal presentation', () => {
  it('is a centred dialog when the environment has no media-query support', () => {
    // The whole unit suite runs here. "No matchMedia" must mean desktop, never
    // a crash — that was the risk of putting a media query inside a primitive
    // 214 call sites render.
    renderModal();
    expect(screen.getByRole('dialog')).toBeVisible();
    expect(screen.getByRole('dialog').querySelector('[data-presentation]')).toHaveAttribute(
      'data-presentation',
      'dialog',
    );
  });

  it('becomes a bottom sheet below sm, with no page opting in', () => {
    const media = stubMatchMedia(true);
    renderModal();

    const panel = screen.getByRole('dialog').querySelector('[data-presentation]');
    expect(panel).toHaveAttribute('data-presentation', 'sheet');

    // Edge-to-edge and bottom-anchored, not a card floating in the middle.
    expect(panel?.className).toContain('rounded-t-lg');
    expect(panel?.className).toContain('border-x-0');
    expect(panel?.parentElement?.className).toContain('items-end');
    // Above the home indicator.
    expect(panel?.className).toContain('env(safe-area-inset-bottom)');

    // And it follows a live orientation change, not just the first paint.
    media.set(false);
    expect(panel).toHaveAttribute('data-presentation', 'dialog');
  });

  it('keeps a named close control on an untitled sheet', () => {
    // On a phone the backdrop is a 0–N px sliver behind a full-width panel, so
    // the sheet must carry its own way out even when the caller passed no title.
    stubMatchMedia(true);
    render(
      <Modal isOpen onClose={() => undefined}>
        <p>Body</p>
      </Modal>,
    );

    expect(screen.getByRole('button', { name: 'Close modal' })).toBeVisible();
    expect(screen.getByRole('dialog')).toHaveAccessibleName('Dialog');
  });

  it('names an untitled dialog for assistive tech', () => {
    render(
      <Modal isOpen onClose={() => undefined}>
        <p>Body</p>
      </Modal>,
    );
    expect(screen.getByRole('dialog')).toHaveAccessibleName('Dialog');
  });

  it('lets a caller force either presentation against the viewport', () => {
    stubMatchMedia(true);
    const { unmount } = renderModal({ presentation: 'dialog' });
    expect(
      screen.getByRole('dialog').querySelector('[data-presentation]'),
    ).toHaveAttribute('data-presentation', 'dialog');
    unmount();

    stubMatchMedia(false);
    renderModal({ presentation: 'sheet' });
    expect(
      screen.getByRole('dialog').querySelector('[data-presentation]'),
    ).toHaveAttribute('data-presentation', 'sheet');
  });

  it('gives the sheet footer the 44px touch floor and the dialog footer does not', () => {
    stubMatchMedia(true);
    renderModal();
    const sheetFooter = screen.getByRole('button', { name: 'Create' }).closest('div[class*="sticky"]');
    expect(sheetFooter?.className).toContain('[&>button]:min-h-11');
    cleanup();

    stubMatchMedia(false);
    renderModal();
    const dialogFooter = screen.getByRole('button', { name: 'Create' }).closest('div[class*="sticky"]');
    expect(dialogFooter?.className).not.toContain('[&>button]:min-h-11');
    // The dialog footer keeps the offsets its body padding defines.
    expect(dialogFooter?.className).toContain('-mx-5');
  });

  it('moves focus into the dialog on open — the behaviour BottomSheet used to lack', () => {
    stubMatchMedia(true);
    render(
      <BottomSheet isOpen onClose={() => undefined} title="Apply for leave">
        <input aria-label="Leave type" />
      </BottomSheet>,
    );

    // requestAnimationFrame-driven, so wait for it rather than assert synchronously.
    return new Promise<void>((resolve) => {
      requestAnimationFrame(() => {
        expect(document.activeElement).toBe(
          screen.getByRole('button', { name: 'Close modal' }),
        );
        resolve();
      });
    });
  });

  it('BottomSheet is the sheet presentation of the same dialog', () => {
    stubMatchMedia(false);
    render(
      <BottomSheet isOpen onClose={() => undefined} title="Apply for leave">
        <input aria-label="Leave type" />
      </BottomSheet>,
    );
    expect(
      screen.getByRole('dialog').querySelector('[data-presentation]'),
    ).toHaveAttribute('data-presentation', 'sheet');
  });
});

describe('useMediaQuery', () => {
  it('reports false, not a throw, where matchMedia does not exist', () => {
    function Probe() {
      const phone = useMediaQuery(PHONE_QUERY);
      return <span>{phone ? 'phone' : 'desktop'}</span>;
    }
    render(<Probe />);
    expect(screen.getByText('desktop')).toBeVisible();
  });

  it('subscribes to changes and unsubscribes on unmount', () => {
    const remove = vi.fn();
    stubMatchMedia(true);
    const mql = (window.matchMedia as unknown as () => { removeEventListener: typeof remove })();
    mql.removeEventListener = remove;

    function Probe() {
      const phone = useMediaQuery(PHONE_QUERY);
      return <span>{phone ? 'phone' : 'desktop'}</span>;
    }
    const { unmount } = render(<Probe />);
    expect(screen.getByText('phone')).toBeVisible();
    unmount();
    expect(remove).toHaveBeenCalled();
  });
});
