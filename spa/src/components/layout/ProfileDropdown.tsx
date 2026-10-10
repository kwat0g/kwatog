/**
 * ProfileDropdown — avatar-triggered menu for self-service access.
 *
 * Replaces the bare avatar + logout button in the Topbar with a dropdown
 * containing links to all self-service pages, account settings, and logout.
 */
import { useState, useRef, useEffect } from 'react';
import { Link } from 'react-router-dom';
import {
 LuUser,
 LuCalendar,
 LuFileText,
 LuReceipt,
 LuWallet,
 LuClock,
 LuFolderOpen,
 LuLogOut,
 LuChevronDown,
 LuLayoutDashboard,
 LuCircleHelp,
 LuMoon,
 LuSun,
 LuShieldCheck,
} from '@/lib/icons';
import { Avatar } from '@/components/ui/Avatar';
import { cn } from '@/lib/cn';
import { focusRing, focusRingInset } from '@/lib/focus';

interface ProfileDropdownProps {
 user?: { name: string; email: string; avatarUrl?: string | null } | null;
 onLogout?: () => void;
 theme?: 'light' | 'dark';
 onToggleTheme?: () => void;
 onOpenShortcuts?: () => void;
}

const MENU_ITEMS = [
 { label: 'Dashboard', to: '/self-service', icon: LuLayoutDashboard },
 { label: 'DTR', to: '/self-service/dtr', icon: LuCalendar },
 { label: 'Leaves', to: '/self-service/leaves', icon: LuFileText },
 { label: 'Overtime', to: '/self-service/overtime', icon: LuClock },
 { label: 'Payslips', to: '/self-service/payslips', icon: LuReceipt },
 { label: 'Loans', to: '/self-service/loans', icon: LuWallet },
 { label: 'Documents', to: '/self-service/documents', icon: LuFolderOpen },
 { label: 'Profile', to: '/self-service/profile', icon: LuUser },
 { label: 'Approval cover', to: '/approvals/delegations', icon: LuShieldCheck },
] as const;

export function ProfileDropdown({ user, onLogout, theme, onToggleTheme, onOpenShortcuts }: ProfileDropdownProps) {
 const [open, setOpen] = useState(false);
 const menuRef = useRef<HTMLDivElement>(null);
 const btnRef = useRef<HTMLButtonElement>(null);

 // Close on click outside or Escape.
 useEffect(() => {
 if (!open) return;
 const handler = (e: MouseEvent | FocusEvent) => {
 if (
 menuRef.current &&
 !menuRef.current.contains(e.target as Node) &&
 btnRef.current &&
 !btnRef.current.contains(e.target as Node)
 ) {
 setOpen(false);
 }
 };
 const onKey = (e: KeyboardEvent) => {
 if (e.key === 'Escape') setOpen(false);
 };
 document.addEventListener('mousedown', handler);
 document.addEventListener('focusin', handler);
 document.addEventListener('keydown', onKey);
 return () => {
 document.removeEventListener('mousedown', handler);
 document.removeEventListener('focusin', handler);
 document.removeEventListener('keydown', onKey);
 };
 }, [open]);

 if (!user) return null;

 return (
 <div className="relative">
 <button
 ref={btnRef}
 type="button"
 onClick={() => setOpen((v) => !v)}
 className={cn('flex min-h-[44px] items-center gap-1.5 pl-2 ml-1 border-l border-default hover:bg-elevated rounded-md pr-1.5 py-0.5 transition-colors duration-fast cursor-pointer sm:min-h-0', focusRing)}
 aria-label={`Profile menu for ${user.name}`}
 aria-expanded={open}
 aria-haspopup="menu"
 >
 <Avatar size="sm" name={user.name} src={user.avatarUrl} className="shrink-0" />
 <span title={user.name} className="hidden min-w-0 max-w-24 truncate text-sm text-secondary md:inline lg:max-w-48">
 {user.name}
 </span>
 <LuChevronDown
 size={12}
 className={cn(
 'text-muted transition-transform duration-fast',
 open && 'rotate-180',
 )}
 />
 </button>

 {open && (
 <div
 ref={menuRef}
 role="menu"
 className="absolute right-0 top-full mt-1 w-56 rounded-md border border-default bg-canvas z-50 py-1 animate-in fade-in slide-in-from-top-2 duration-150"
 >
 {/* LuUser info header */}
 <div className="px-3 py-2 border-b border-default">
 <p className="text-sm font-medium truncate">{user.name}</p>
 <p className="text-xs text-muted truncate">{user.email}</p>
 </div>

 {/* Self-service links */}
 <div className="py-1">
 {MENU_ITEMS.map((item) => (
 <Link
 key={item.to}
 to={item.to}
 onClick={() => setOpen(false)}
 role="menuitem"
 className={cn('group mx-1 flex min-h-9 items-center gap-2.5 rounded-md px-2.5 py-1.5 text-sm text-secondary transition-colors duration-fast hover:bg-accent/10 hover:text-primary', focusRingInset)}
 >
 <item.icon size={14} className="shrink-0 text-muted transition-colors duration-fast group-hover:text-accent group-focus-visible:text-accent" />
 {item.label}
 </Link>
 ))}
 </div>

 {(onToggleTheme || onOpenShortcuts) && (
 <div className="border-t border-default py-1">
 {onToggleTheme && (
 <button
 type="button"
 onClick={() => {
 setOpen(false);
 onToggleTheme();
 }}
 role="menuitem"
 className={cn('group mx-1 flex min-h-9 w-[calc(100%-0.5rem)] items-center gap-2.5 rounded-md px-2.5 py-1.5 text-sm text-secondary transition-colors duration-fast hover:bg-accent/10 hover:text-primary', focusRingInset)}
 >
 {theme === 'dark' ? <LuSun size={14} className="shrink-0 text-muted group-hover:text-accent group-focus-visible:text-accent" /> : <LuMoon size={14} className="shrink-0 text-muted group-hover:text-accent group-focus-visible:text-accent" />}
 {theme === 'dark' ? 'Use light mode' : 'Use dark mode'}
 </button>
 )}
 {onOpenShortcuts && (
 <button
 type="button"
 onClick={() => {
 setOpen(false);
 onOpenShortcuts();
 }}
 role="menuitem"
 className={cn('group mx-1 flex min-h-9 w-[calc(100%-0.5rem)] items-center gap-2.5 rounded-md px-2.5 py-1.5 text-sm text-secondary transition-colors duration-fast hover:bg-accent/10 hover:text-primary', focusRingInset)}
 >
 <LuCircleHelp size={14} className="shrink-0 text-muted group-hover:text-accent group-focus-visible:text-accent" />
 Keyboard shortcuts
 </button>
 )}
 </div>
 )}

 {/* Logout */}
 <div className="border-t border-default pt-1 pb-1">
 <button
 type="button"
 onClick={() => {
 setOpen(false);
 onLogout?.();
 }}
 role="menuitem"
 className={cn('flex items-center gap-2.5 w-full px-3 py-1.5 text-sm text-danger-fg hover:bg-danger-bg transition-colors duration-fast cursor-pointer', focusRingInset)}
 >
 <LuLogOut size={14} className="shrink-0" />
 Sign out
 </button>
 </div>
 </div>
 )}
 </div>
 );
}

export default ProfileDropdown;
