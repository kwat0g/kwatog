import { Link } from 'react-router-dom';
import { Menu, Moon, Sun, Search, LogOut } from 'lucide-react';
import { Avatar } from '@/components/ui/Avatar';
import { Tooltip } from '@/components/ui/Tooltip';
import { Breadcrumbs } from './Breadcrumbs';
import { NotificationBell } from './NotificationBell';
import { useSidebarStore } from '@/stores/sidebarStore';
import { useTheme } from '@/hooks/useTheme';

interface TopbarProps {
  user?: { name: string; email: string } | null;
  onLogout?: () => void;
}

export function Topbar({ user, onLogout }: TopbarProps) {
  const toggleSidebar = useSidebarStore((s) => s.toggle);
  const { resolvedTheme, toggle } = useTheme();

  return (
    <header className="sticky top-0 z-40 h-12 bg-canvas border-b border-default flex items-center px-3 gap-3">
      <button
        type="button"
        onClick={toggleSidebar}
        aria-label="Toggle sidebar"
        className="h-7 w-7 inline-flex items-center justify-center rounded-md text-muted hover:bg-elevated hover:text-primary"
      >
        <Menu size={14} />
      </button>

      <Link to="/dashboard" className="flex items-center gap-2 shrink-0">
        <span className="h-[22px] w-[22px] rounded-md bg-primary text-canvas inline-flex items-center justify-center font-medium text-sm">
          O
        </span>
        <span className="text-sm font-medium text-primary hidden sm:inline">Ogami ERP</span>
      </Link>

      <div className="hidden md:flex h-full items-center pl-3 ml-1 border-l border-default">
        <Breadcrumbs />
      </div>

      <div className="flex-1" />

      {/* Search trigger — full command palette in Task 75. */}
      <button
        type="button"
        className="hidden sm:flex items-center gap-2 h-7 w-44 px-2 rounded-md border border-default text-xs text-muted hover:bg-elevated"
      >
        <Search size={12} />
        <span className="flex-1 text-left">Search…</span>
        <kbd className="font-mono text-[10px] text-text-subtle">⌘K</kbd>
      </button>

      <Tooltip content={resolvedTheme === 'dark' ? 'Light mode' : 'Dark mode'}>
        <button
          type="button"
          onClick={toggle}
          aria-label="Toggle theme"
          className="h-7 w-7 inline-flex items-center justify-center rounded-md text-muted hover:bg-elevated hover:text-primary"
        >
          {resolvedTheme === 'dark' ? <Sun size={14} /> : <Moon size={14} />}
        </button>
      </Tooltip>

      <NotificationBell />

      {user && (
        <div className="flex items-center gap-2 pl-2 ml-1 border-l border-default">
          <Avatar size="sm" name={user.name} />
          <Tooltip content="Sign out">
            <button
              type="button"
              onClick={onLogout}
              aria-label="Sign out"
              className="h-7 w-7 inline-flex items-center justify-center rounded-md text-muted hover:bg-elevated hover:text-primary"
            >
              <LogOut size={14} />
            </button>
          </Tooltip>
        </div>
      )}
    </header>
  );
}
