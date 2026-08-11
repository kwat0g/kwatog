/* eslint-disable react-refresh/only-export-components -- useTouchSubmitLabel is colocated with the shell it labels, matching DashboardShell.tsx. */
import { useEffect, type ReactNode } from 'react';
import { Outlet, useNavigate, useLocation, Link } from 'react-router-dom';
import { type LucideIcon } from 'lucide-react';
import { useAuthStore } from '@/stores/authStore';
import { useThemeStore } from '@/stores/themeStore';
import { Button } from '@/components/ui/Button';
import { BottomSheet } from '@/components/ui/BottomSheet';
import { OfflineBanner, usePausedMutationCount } from '@/components/ui/OfflineBanner';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { focusRingInset } from '@/lib/focus';
import { cn } from '@/lib/cn';

/**
 * The shell every touch PWA runs on — factory floor, driver, maintenance tech.
 *
 * All three were hand-rolled copies of the same layout, which is how they
 * drifted: the driver shell lost its `flex-col`, and its `<main>` kept the
 * bottom padding budget of a tab bar it doesn't have. Anything that should look
 * the same on all three lives here; the differences are the props.
 *
 * These run on a shop-floor phone or tablet, held in a glove, so hit targets are
 * the 44px minimum rather than the 32px the desktop tables use.
 */

export interface TouchTab {
  to: string;
  label: string;
  icon: LucideIcon;
  /** Match the path exactly. Use for index routes that would otherwise stay lit. */
  exact?: boolean;
}

interface TouchShellProps {
  /** Small uppercase label above the user's name — which app this is. */
  eyebrow: string;
  /** Optional glyph beside the eyebrow. */
  eyebrowIcon?: LucideIcon;
  /** Shown when the session has no name yet, e.g. "Operator". */
  fallbackName: string;
  /** Bottom tab bar. Omit for single-screen apps (driver). */
  tabs?: readonly TouchTab[];
  /**
   * Content column width. Defaults to `max-w-2xl` — right for a phone held in
   * one hand running a single-column form. The warehouse surfaces are two- and
   * three-pane on a landscape tablet and get a wider budget.
   */
  contentWidthClassName?: string;
}

export function TouchShell({
  eyebrow,
  eyebrowIcon: EyebrowIcon,
  fallbackName,
  tabs,
  contentWidthClassName = 'max-w-2xl',
}: TouchShellProps) {
  const user = useAuthStore((s) => s.user);
  const logout = useAuthStore((s) => s.logout);
  const navigate = useNavigate();
  const location = useLocation();

  // Force the high-contrast floor palette for as long as a touch PWA is mounted,
  // then hand the user's own light/dark preference back on the way out. Read via
  // getState() rather than a selector so the actions stay out of the dependency
  // array — they are stable, and subscribing would re-run this on every theme change.
  useEffect(() => {
    const { pushOverride, popOverride } = useThemeStore.getState();
    pushOverride('floor');
    return () => popOverride();
  }, []);

  const hasTabs = Boolean(tabs?.length);

  function isActive(to: string, exact?: boolean) {
    return exact ? location.pathname === to : location.pathname.startsWith(to);
  }

  return (
    <div className="min-h-screen flex flex-col bg-surface text-primary">
      <header className="sticky top-0 z-10 border-b border-default bg-canvas">
        <div className="flex items-center justify-between px-4 py-3">
          <div>
            <div className="text-xs uppercase tracking-wider text-muted font-medium flex items-center gap-1.5">
              {EyebrowIcon && <EyebrowIcon className="w-3.5 h-3.5" aria-hidden />}
              {eyebrow}
            </div>
            <div className="font-medium leading-tight">{user?.name ?? fallbackName}</div>
          </div>
          <Button
            variant="ghost"
            size="touch"
            className="text-secondary"
            onClick={async () => {
              await logout();
              navigate('/login');
            }}
          >
            Log out
          </Button>
        </div>
        {/*
 Was mounted only in AppLayout, so wired desks that never move got an
 offline indicator and the tablets in a steel-framed plant did not.
 Rendered inside the sticky header so it travels with the chrome rather
 than pinning itself to AppLayout's 48px topbar offset.
 */}
        <OfflineBanner placement="in-header" />
      </header>

      {/* Only reserve room for the tab bar on the shells that actually have one. */}
      <main
        className={cn('flex-1 w-full mx-auto px-4 py-4', contentWidthClassName, hasTabs && 'pb-20')}
      >
        <Outlet />
      </main>

      {hasTabs && (
        <nav
          aria-label={`${eyebrow} sections`}
          className="fixed bottom-0 inset-x-0 z-10 border-t border-default bg-canvas safe-area-pb"
        >
          <div className="flex items-stretch max-w-2xl mx-auto">
            {tabs?.map((tab) => {
              const active = isActive(tab.to, tab.exact);
              const Icon = tab.icon;
              return (
                <Link
                  key={tab.to}
                  to={tab.to}
                  aria-current={active ? 'page' : undefined}
                  className={cn(
                    'flex-1 flex flex-col items-center justify-center py-2 min-h-[56px] transition-colors duration-fast',
                    focusRingInset,
                    active ? 'text-accent' : 'text-muted hover:text-secondary',
                  )}
                >
                  <Icon className="w-6 h-6" aria-hidden />
                  <span className="text-sm mt-0.5 font-medium">{tab.label}</span>
                </Link>
              );
            })}
          </div>
        </nav>
      )}
    </div>
  );
}

/**
 * Loading placeholder for a touch card list.
 *
 * Every touch page had its own copy of this, each with a different card height
 * and a different `sr-only` string, so the list visibly jumped as the real cards
 * replaced the skeleton. One shape, one announcement.
 */
export function TouchCardSkeleton({
  count = 3,
  label = 'Loading',
  /** Card height — a card with a progress bar is taller than a plain one. */
  cardClassName = 'h-28',
}: {
  count?: number;
  label?: string;
  cardClassName?: string;
}) {
  return (
    <div role="status" aria-live="polite" aria-busy="true" className="space-y-3">
      <span className="sr-only">{label}…</span>
      {Array.from({ length: count }).map((_, i) => (
        <SkeletonBlock key={i} className={cn('rounded-md', cardClassName)} />
      ))}
    </div>
  );
}

/**
 * Confirmation step for an irreversible floor commit.
 *
 * The desk surfaces gate destructive work behind ConfirmDialog — 63 files use
 * it — while the floor surfaces had nothing: one tap advanced a delivery,
 * closed a work order, or booked a scrap count, with no undo and no on-screen
 * record. This is the floor's equivalent, built on the existing BottomSheet
 * because a centred modal puts its buttons out of thumb reach on a phone held
 * one-handed.
 *
 * Deliberately NOT a general dialog. It confirms one action, states what will
 * happen in the body, and its two buttons are `size="touch"` and full-height
 * so a gloved thumb cannot land between them. `Cancel` is listed first in the
 * DOM (so it takes focus on open) but rendered second, keeping the destructive
 * button away from the edge a thumb rests on.
 */
export function TouchConfirmSheet({
  isOpen,
  onClose,
  onConfirm,
  title,
  confirmLabel,
  variant = 'primary',
  pending,
  children,
}: {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title: string;
  confirmLabel: string;
  /** `danger` for anything that records a failure, a loss, or a terminal state. */
  variant?: 'primary' | 'danger';
  pending?: boolean;
  /** What is about to happen — name the record and the magnitude. */
  children: ReactNode;
}) {
  return (
    <BottomSheet isOpen={isOpen} onClose={onClose} title={title}>
      <div className="space-y-4">
        <div className="text-base text-secondary space-y-2">{children}</div>
        <div className="grid grid-cols-2 gap-3">
          <Button
            type="button"
            variant="secondary"
            size="touch"
            onClick={onClose}
            disabled={pending}
          >
            Cancel
          </Button>
          <Button
            type="button"
            variant={variant}
            size="touch"
            loading={pending}
            onClick={onConfirm}
          >
            {confirmLabel}
          </Button>
        </div>
      </div>
    </BottomSheet>
  );
}

/**
 * Label for a submit button whose mutation may be parked in the offline queue.
 *
 * `networkMode: 'offlineFirst'` means an offline tap never resolves, so
 * "Recording…" is indistinguishable from a queued no-op that will sit there
 * until the wifi comes back. When the queue is non-empty, say so.
 */
export function useTouchSubmitLabel(isPending: boolean, idle: string, sending: string): string {
  const queued = usePausedMutationCount();
  if (!isPending) return idle;
  return queued > 0 ? 'Queued — waiting for signal' : sending;
}
