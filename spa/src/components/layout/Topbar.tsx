import { lazy, Suspense, useEffect, useState, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Link } from 'react-router-dom';
import { Menu, Moon, Sun, Search } from 'lucide-react';
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
 user?: { name: string; email: string } | null;
 onLogout?: () => void;
 /** Extra controls injected just before the avatar (e.g. shortcut help, X1). */
 rightExtras?: ReactNode;
}

export function Topbar({ user, onLogout, rightExtras }: TopbarProps) {
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

 return (
 <header className="sticky top-0 z-40 h-12 bg-canvas/80 backdrop-blur-xl border-b border-default flex items-center px-4 gap-5">
 <Button
 variant="ghost"
 size="sm"
 iconOnly
 icon={<Menu size={14} />}
 aria-label="Toggle sidebar"
 onClick={handleMenuClick}
 className="text-muted hover:text-primary"
 />

 <Link to="/dashboard" className="flex items-center gap-2 shrink-0">
 <BrandLogo invertOnDark alt="" className="h-7" />
 <span className="text-sm font-medium text-primary hidden sm:inline">{contact?.legal_name ?? '—'}</span>
 </Link>

 <div className="hidden md:flex h-full items-center pl-3 ml-1 border-l border-default">
 <Breadcrumbs />
 </div>

 <div className="flex-1" />

 {/* Search trigger — opens the global command palette (Sprint 8 — Task 75). */}
 <button
 type="button"
 onClick={() => setPaletteOpen(true)}
 className={cn('hidden sm:flex items-center gap-2 h-7 w-44 px-2 rounded-md border border-default text-xs text-muted hover:bg-elevated cursor-pointer', focusRing)}
 >
 <Search size={12} />
 <span className="flex-1 text-left">Search…</span>
 <kbd className="font-mono text-2xs text-subtle">⌘K</kbd>
 </button>

 <Tooltip content={resolvedTheme === 'dark' ? 'Light mode' : 'Dark mode'}>
 <Button
 variant="ghost"
 size="sm"
 iconOnly
 icon={resolvedTheme === 'dark' ? <Sun size={14} /> : <Moon size={14} />}
 aria-label="Toggle theme"
 onClick={toggle}
 className="text-muted hover:text-primary"
 />
 </Tooltip>

 <NotificationBell />

 {rightExtras}

 {user && <ProfileDropdown user={user} onLogout={onLogout} />}

 <Suspense fallback={null}>
 <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
 </Suspense>
 </header>
 );
}

export default Topbar;
