/**
 * ProfileDropdown — avatar-triggered menu for self-service access.
 *
 * Replaces the bare avatar + logout button in the Topbar with a dropdown
 * containing links to all self-service pages, account settings, and logout.
 */
import { useState, useRef, useEffect } from 'react';
import { Link } from 'react-router-dom';
import {
  User,
  Calendar,
  FileText,
  Receipt,
  Wallet,
  Clock,
  FolderOpen,
  LogOut,
  ChevronDown,
  LayoutDashboard,
} from 'lucide-react';
import { Avatar } from '@/components/ui/Avatar';
import { cn } from '@/lib/cn';

interface ProfileDropdownProps {
  user?: { name: string; email: string } | null;
  onLogout?: () => void;
}

const MENU_ITEMS = [
  { label: 'Dashboard',    to: '/self-service',        icon: LayoutDashboard },
  { label: 'DTR',          to: '/self-service/dtr',     icon: Calendar },
  { label: 'Leaves',       to: '/self-service/leaves',  icon: FileText },
  { label: 'Overtime',     to: '/self-service/overtime', icon: Clock },
  { label: 'Payslips',     to: '/self-service/payslips', icon: Receipt },
  { label: 'Loans',        to: '/self-service/loans',   icon: Wallet },
  { label: 'Documents',    to: '/self-service/documents', icon: FolderOpen },
  { label: 'Profile',      to: '/self-service/profile', icon: User },
] as const;

export function ProfileDropdown({ user, onLogout }: ProfileDropdownProps) {
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
        className="flex items-center gap-1.5 pl-2 ml-1 border-l border-default hover:bg-elevated rounded-md pr-1.5 py-0.5 transition-colors duration-fast"
        aria-label="Profile menu"
        aria-expanded={open}
        aria-haspopup="true"
      >
        <Avatar size="sm" name={user.name} />
        <ChevronDown
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
          className="absolute right-0 top-full mt-1 w-56 rounded-lg border border-default bg-canvas shadow-lg z-50 py-1 animate-in fade-in slide-in-from-top-2 duration-150"
        >
          {/* User info header */}
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
                className="flex items-center gap-2.5 px-3 py-1.5 text-sm text-secondary hover:bg-elevated hover:text-primary transition-colors duration-fast"
              >
                <item.icon size={14} className="shrink-0 text-muted" />
                {item.label}
              </Link>
            ))}
          </div>

          {/* Logout */}
          <div className="border-t border-default pt-1 pb-1">
            <button
              type="button"
              onClick={() => {
                setOpen(false);
                onLogout?.();
              }}
              role="menuitem"
              className="flex items-center gap-2.5 w-full px-3 py-1.5 text-sm text-danger-fg hover:bg-danger-bg transition-colors duration-fast"
            >
              <LogOut size={14} className="shrink-0" />
              Sign out
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

export default ProfileDropdown;
