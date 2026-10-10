import { lazy, Suspense, useEffect, useState, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Link } from 'react-router-dom';
import { LuMenu, LuMoon, LuSun, LuSearch } from '@/lib/icons';
import { Tooltip } from '@/components/ui/Tooltip';
import { Button } from '@/components/ui/Button';

const CommandPalette = lazy(() =>
 import('@/components/ui/CommandPalette').then((m) => ({ default: m.CommandPalette })),
);
import { Breadcrumbs } from './Breadcrumbs';
import { NotificationBell } from './NotificationBell';
import { ProfileDropdown } from './ProfileDropdown';
import { BrandLogo } from '@/components/brand/BrandLogo';
import { useSidebarStore } from '@/stores/sidebarStore';
import { useTheme } from '@/hooks/useTheme';
import { focusRing } from '@/lib/focus';
import { useQuery } from '@tanstack/react-query';
import { landingApi } from '@/api/landing';

interface TopbarProps {
 user?: { name: string; email: string; avatarUrl?: string | null } | null;
 onLogout?: () => void;
 onOpenShortcuts?: () => void;
 /** Extra controls injected just before the avatar (e.g. shortcut help, X1). */
 rightExtras?: ReactNode;
}

export function Topbar({ user, onLogout, onOpenShortcuts, rightExtras }: TopbarProps) {
 const toggleSidebar = useSidebarStore((s) => s.toggle);
 const setMobileOpen = useSidebarStore((s) => s.setMobileOpen);
 const mobileOpen = useSidebarStore((s) => s.mobileOpen);
 const { resolvedTheme, toggle } = useTheme();
 const [paletteOpen, setPaletteOpen] = useState(false);
 const { data: contact } = useQuery({
 queryKey: ['landing', 'contact'],
 queryFn: landingApi.contact,
 staleTime: 300_000,
 });

 const handleMenuClick = () => {
 if (window.innerWidth < 768) {
 setMobileOpen(!mobileOpen);
 } else {
 toggleSidebar();
 }
 };

 // Sprint 8 — Task 75: ⌘K / Ctrl+K opens the command palette globally.
 useEffect(() => {
 const onKey = (e: KeyboardEvent) => {
 if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
 e.preventDefault();
 setPaletteOpen((v) => !v);
 }
 };
 document.addEventListener('keydown', onKey);
 return () => document.removeEventListener('keydown', onKey);
 }, []);

 // overflow-x-clip, NOT overflow-hidden: hidden forces overflow-y to auto,
 // which clips the NotificationBell / ProfileDropdown dropdowns to the 48px
 // bar (they'd render only their first row). `clip` keeps the vertical axis
 // visible while still containing the horizontal overflow the mobile shell
 // hardening needed.
 return (
  <header className="sticky top-0 z-40 flex h-12 items-center gap-2 bg-canvas border-b border-default px-4 sm:gap-5 md:grid md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] overflow-x-clip">
    <div className="flex min-w-0 flex-1 items-center gap-2 md:col-start-1 md:flex-none">
      <Button
        variant="ghost"
        size="sm"
        iconOnly
        icon={<LuMenu size={14} />}
        aria-label="Toggle sidebar"
        onClick={handleMenuClick}
        className="min-h-[44px] min-w-[44px] text-muted hover:text-primary sm:min-h-0 sm:min-w-0"
      />

      <Link to="/dashboard" className="flex min-w-0 items-center gap-2">
        <BrandLogo invertOnDark alt="Ogami ERP" className="h-7 max-w-[7rem] sm:max-w-none" />
        <span
          title={contact?.legal_name ?? '—'}
          className="hidden min-w-0 max-w-40 truncate text-sm font-medium text-primary sm:inline"
        >
          {contact?.legal_name ?? '—'}
        </span>
      </Link>

      {/* Breadcrumbs stay in the left group; their last segment truncates when space is tight. */}
      <div className="ml-1 flex h-full min-w-0 flex-1 items-center border-l border-default pl-3">
        <Breadcrumbs />
      </div>
    </div>

    <div className="flex shrink-0 items-center justify-center md:col-start-2">
      {/* Search trigger — opens the global command palette (Sprint 8 — Task 75). */}
      <button
        type="button"
        onClick={() => setPaletteOpen(true)}
        className={cn('hidden sm:flex h-7 w-44 items-center gap-2 rounded-md border border-default px-2 text-xs text-muted hover:bg-elevated cursor-pointer lg:w-48', focusRing)}
      >
        <LuSearch size={12} />
        <span className="flex-1 text-left">Search…</span>
        <kbd className="font-mono text-2xs text-subtle">⌘K</kbd>
      </button>

      {/* M009-F07 — keep a direct search control on phones without a keyboard. */}
      <Button
        variant="ghost"
        size="sm"
        iconOnly
        icon={<LuSearch size={14} />}
        aria-label="Search"
        onClick={() => setPaletteOpen(true)}
        className="min-h-[44px] min-w-[44px] text-muted hover:text-primary sm:hidden sm:min-h-0 sm:min-w-0"
      />
    </div>

    <div className="ml-auto flex shrink-0 items-center gap-1 sm:gap-2 md:col-start-3 md:gap-1 md:ml-0 md:justify-self-end lg:gap-2">
      <span className="hidden lg:inline-flex">
        <Tooltip content={resolvedTheme === 'dark' ? 'Light mode' : 'Dark mode'}>
          <Button
            variant="ghost"
            size="sm"
            iconOnly
            icon={resolvedTheme === 'dark' ? <LuSun size={14} /> : <LuMoon size={14} />}
            aria-label="Toggle theme"
            onClick={toggle}
            className="min-h-[44px] min-w-[44px] text-muted hover:text-primary sm:min-h-0 sm:min-w-0"
          />
        </Tooltip>
      </span>

      <NotificationBell />

      {rightExtras}

      {user && (
        <ProfileDropdown
          user={user}
          onLogout={onLogout}
          theme={resolvedTheme}
          onToggleTheme={toggle}
          onOpenShortcuts={onOpenShortcuts}
        />
      )}
    </div>

 <Suspense fallback={null}>
 <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
 </Suspense>
 </header>
 );
}

export default Topbar;
