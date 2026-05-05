/** Global ⌘K command palette with grouped results, type icons, status chips, keyboard nav. */
import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Search, Loader2,
  User, ShoppingCart, Package, FileText, Receipt, Wrench,
  Box, Building2, Truck, AlertTriangle,
} from 'lucide-react';
import { client } from '@/api/client';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { formatPeso } from '@/lib/formatNumber';

type GroupType =
  | 'employee'
  | 'sales_order'
  | 'purchase_order'
  | 'work_order'
  | 'invoice'
  | 'bill'
  | 'product'
  | 'item'
  | 'customer'
  | 'vendor'
  | 'ncr';

interface PaletteItem {
  id: string;
  label: string;
  sublabel?: string | null;
  status?: string | null;
  amount?: string | null;
  url: string;
}
interface PaletteGroup {
  group: string;
  label: string;
  type: GroupType;
  items: PaletteItem[];
}
interface SearchResponse {
  data: PaletteGroup[];
  query: string;
}

const ICONS: Record<GroupType, typeof User> = {
  employee:       User,
  sales_order:    ShoppingCart,
  purchase_order: Package,
  work_order:     Wrench,
  invoice:        Receipt,
  bill:           FileText,
  product:        Box,
  item:           Box,
  customer:       Building2,
  vendor:         Truck,
  ncr:            AlertTriangle,
};

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
  const listRef = useRef<HTMLDivElement>(null);

  // Reset on close.
  useEffect(() => {
    if (!open) {
      setQ('');
      setGroups([]);
      setActiveIndex(0);
    } else {
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

  // Flat list for keyboard nav.
  const flatItems = useMemo(
    () => groups.flatMap((g) => g.items.map((it) => ({ ...it, _group: g.label, _type: g.type }))),
    [groups],
  );

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

  // Scroll active row into view.
  useEffect(() => {
    if (!open || !listRef.current) return;
    const el = listRef.current.querySelector<HTMLElement>(`[data-flat-index="${activeIndex}"]`);
    el?.scrollIntoView({ block: 'nearest' });
  }, [activeIndex, open]);

  if (!open) return null;

  const trimmed = q.trim();
  const showEmptyState = trimmed.length >= 2 && !loading && groups.length === 0;
  const totalResults = flatItems.length;

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center pt-24 px-4"
      role="dialog"
      aria-modal="true"
      aria-label="Global search"
      onClick={onClose}
    >
      <div className="absolute inset-0 bg-black/30" />
      <div
        className="relative w-full max-w-2xl rounded-md border border-default bg-canvas shadow-menu overflow-hidden"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center gap-2 px-3 py-2.5 border-b border-default">
          <Search size={14} className="text-muted shrink-0" />
          <input
            ref={inputRef}
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Search employees, orders, vendors, items, NCRs…"
            className="flex-1 bg-transparent text-sm outline-none placeholder:text-subtle"
            aria-label="Search query"
          />
          {loading && <Loader2 size={14} className="text-muted animate-spin" />}
          <kbd className="font-mono text-[10px] text-subtle border border-subtle rounded px-1 py-0.5">ESC</kbd>
        </div>

        <div ref={listRef} className="max-h-[420px] overflow-y-auto">
          {trimmed.length < 2 && (
            <div className="px-3 py-6 text-xs text-muted">
              <p>Type at least 2 characters to search across the ERP.</p>
              <p className="mt-2 text-2xs uppercase tracking-wider text-subtle">Try</p>
              <ul className="mt-1 space-y-0.5 text-xs">
                <li><span className="font-mono text-default">SO-</span> sales orders</li>
                <li><span className="font-mono text-default">PO-</span> purchase orders</li>
                <li><span className="font-mono text-default">WO-</span> work orders</li>
                <li><span className="font-mono text-default">INV-</span> invoices</li>
                <li><span className="font-mono text-default">NCR-</span> non-conformance reports</li>
                <li>Or any name — employee, vendor, customer, product</li>
              </ul>
            </div>
          )}

          {showEmptyState && (
            <div className="px-3 py-8 text-center">
              <p className="text-sm text-default">
                No results for <span className="font-mono">"{trimmed}"</span>
              </p>
              <p className="mt-1 text-xs text-muted">
                Try a different keyword, an exact ID prefix, or check your permissions for the module.
              </p>
            </div>
          )}

          {groups.map((group) => {
            const Icon = ICONS[group.type] ?? FileText;
            return (
              <div key={group.group} className="border-t border-subtle first:border-t-0">
                <div className="px-3 pt-2 pb-1 text-2xs uppercase tracking-wider text-muted font-medium flex items-center gap-2">
                  <Icon size={11} className="text-muted" />
                  <span>{group.label}</span>
                  <span className="font-mono text-subtle">·</span>
                  <span className="font-mono text-subtle tabular-nums">{group.items.length}</span>
                </div>
                <ul>
                  {group.items.map((item) => {
                    const flatIdx = flatItems.findIndex(
                      (f) => f._type === group.type && f.id === item.id && f.url === item.url,
                    );
                    const isActive = flatIdx === activeIndex;
                    return (
                      <li key={`${group.group}-${item.id}`}>
                        <button
                          data-flat-index={flatIdx}
                          onClick={() => { navigate(item.url); onClose(); }}
                          onMouseEnter={() => setActiveIndex(flatIdx)}
                          className={`w-full text-left px-3 py-2 text-sm flex items-center gap-3 ${
                            isActive ? 'bg-elevated' : 'hover:bg-subtle'
                          }`}
                        >
                          <Icon size={14} className="text-muted shrink-0" />
                          <div className="flex-1 min-w-0">
                            <div className="flex items-center gap-2">
                              <span className="font-mono text-default truncate">{item.label}</span>
                              {item.status && (
                                <Chip variant={chipVariantForStatus(item.status)}>
                                  {item.status.replace(/_/g, ' ')}
                                </Chip>
                              )}
                            </div>
                            {item.sublabel && (
                              <div className="text-xs text-muted truncate mt-0.5">{item.sublabel}</div>
                            )}
                          </div>
                          {item.amount != null && (
                            <span className="font-mono tabular-nums text-xs text-muted ml-3 shrink-0">
                              {formatPeso(Number(item.amount))}
                            </span>
                          )}
                        </button>
                      </li>
                    );
                  })}
                </ul>
              </div>
            );
          })}
        </div>

        <div className="px-3 py-1.5 border-t border-default text-2xs text-muted flex items-center justify-between font-mono">
          <div className="flex items-center gap-3">
            <span>↑↓ navigate</span>
            <span>↵ open</span>
            <span>esc close</span>
          </div>
          {totalResults > 0 && (
            <span className="tabular-nums">{totalResults} result{totalResults === 1 ? '' : 's'}</span>
          )}
        </div>
      </div>
    </div>
  );
}

export default CommandPalette;
