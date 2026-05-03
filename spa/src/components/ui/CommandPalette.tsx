/** Sprint 8 — Task 75. Global ⌘K command palette. */
import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Search, Loader2 } from 'lucide-react';
import { client } from '@/api/client';

interface PaletteItem {
  id: string;
  label: string;
  sublabel?: string | null;
  url: string;
}
interface PaletteGroup {
  group: string;
  label: string;
  items: PaletteItem[];
}
interface SearchResponse {
  data: PaletteGroup[];
  query: string;
}

interface Props {
  open: boolean;
  onClose: () => void;
}

export function CommandPalette({ open, onClose }: Props) {
  const navigate = useNavigate();
  const [q, setQ] = useState('');
  const [groups, setGroups] = useState<PaletteGroup[]>([]);
  const [loading, setLoading] = useState(false);
  const [activeIndex, setActiveIndex] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);

  // Reset on close.
  useEffect(() => {
    if (!open) {
      setQ('');
      setGroups([]);
      setActiveIndex(0);
    } else {
      // Focus input on open.
      requestAnimationFrame(() => inputRef.current?.focus());
    }
  }, [open]);

  // Debounced search.
  useEffect(() => {
    if (!open || q.trim().length < 2) {
      setGroups([]);
      return;
    }
    const handle = setTimeout(async () => {
      setLoading(true);
      try {
        const res = await client.get<SearchResponse>('/search', { params: { q } });
        setGroups(res.data.data);
        setActiveIndex(0);
      } catch {
        setGroups([]);
      } finally {
        setLoading(false);
      }
    }, 200);
    return () => clearTimeout(handle);
  }, [q, open]);

  // Flat list of items for keyboard nav.
  const flatItems = groups.flatMap((g) => g.items.map((it) => ({ ...it, _group: g.label })));

  // Keyboard navigation.
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        onClose();
      } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        setActiveIndex((i) => Math.min(i + 1, Math.max(0, flatItems.length - 1)));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        setActiveIndex((i) => Math.max(0, i - 1));
      } else if (e.key === 'Enter') {
        e.preventDefault();
        const target = flatItems[activeIndex];
        if (target) {
          navigate(target.url);
          onClose();
        }
      }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, flatItems, activeIndex, navigate, onClose]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center pt-24 px-4"
      role="dialog" aria-modal="true" onClick={onClose}>
      <div className="absolute inset-0 bg-black/30" />
      <div className="relative w-full max-w-xl rounded-md border border-default bg-canvas shadow-menu overflow-hidden"
        onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center gap-2 px-3 py-2 border-b border-default">
          <Search size={14} className="text-muted" />
          <input
            ref={inputRef}
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Search employees, products, customers, vendors, orders…"
            className="flex-1 bg-transparent text-sm outline-none placeholder:text-subtle"
          />
          {loading && <Loader2 size={14} className="text-muted animate-spin" />}
          <kbd className="font-mono text-[10px] text-subtle">ESC</kbd>
        </div>

        <div className="max-h-80 overflow-y-auto">
          {q.trim().length < 2 && (
            <p className="text-xs text-muted px-3 py-3">Type at least 2 characters to search…</p>
          )}
          {q.trim().length >= 2 && !loading && groups.length === 0 && (
            <p className="text-xs text-muted px-3 py-3">No results.</p>
          )}
          {groups.map((group) => (
            <div key={group.group} className="border-t border-subtle first:border-t-0">
              <div className="px-3 pt-2 pb-1 text-2xs uppercase tracking-wider text-muted font-medium">
                {group.label}
              </div>
              <ul>
                {group.items.map((item) => {
                  const flatIdx = flatItems.findIndex((f) => f.id === item.id && f.url === item.url);
                  const isActive = flatIdx === activeIndex;
                  return (
                    <li key={`${group.group}-${item.id}`}>
                      <button
                        onClick={() => { navigate(item.url); onClose(); }}
                        onMouseEnter={() => setActiveIndex(flatIdx)}
                        className={`w-full text-left px-3 py-2 text-sm flex items-center justify-between ${isActive ? 'bg-elevated' : 'hover:bg-subtle'}`}
                      >
                        <span className="truncate">{item.label}</span>
                        {item.sublabel && (
                          <span className="font-mono text-xs text-muted ml-3 shrink-0">{item.sublabel}</span>
                        )}
                      </button>
                    </li>
                  );
                })}
              </ul>
            </div>
          ))}
        </div>

        <div className="px-3 py-1.5 border-t border-default text-2xs text-muted flex items-center gap-3 font-mono">
          <span>↑↓ navigate</span>
          <span>↵ open</span>
          <span>esc close</span>
        </div>
      </div>
    </div>
  );
}

export default CommandPalette;
