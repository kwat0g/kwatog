/** Global ⌘K command palette: recent items, page navigation, record search. */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { cn } from '@/lib/cn';
import { useNavigate } from 'react-router-dom';
import {
  Search,
  Loader2,
  Clock,
  X,
  User,
  ShoppingCart,
  Package,
  FileText,
  Receipt,
  Wrench,
  Box,
  Building2,
  Truck,
  AlertTriangle,
  ArrowRight,
  type LucideIcon,
} from 'lucide-react';
import { client } from '@/api/client';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { formatPeso } from '@/lib/formatNumber';
import { focusRingInset } from '@/lib/focus';
import { useDebounce } from '@/hooks/useDebounce';
import { LinkButton } from './LinkButton';
import { SECTIONS, isNavItemVisible } from '@/components/layout/Sidebar';
import { useAuthStore } from '@/stores/authStore';
import { useRecentItemsStore } from '@/stores/recentItemsStore';

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

/** Stable empty reference — a fresh `[]` per render would break the `sections` memo. */
const NO_GROUPS: PaletteGroup[] = [];

const ICONS: Record<GroupType, LucideIcon> = {
  employee: User,
  sales_order: ShoppingCart,
  purchase_order: Package,
  work_order: Wrench,
  invoice: Receipt,
  bill: FileText,
  product: Box,
  item: Box,
  customer: Building2,
  vendor: Truck,
  ncr: AlertTriangle,
};

function iconForType(type?: string | null): LucideIcon {
  return (type && type in ICONS ? ICONS[type as GroupType] : FileText) as LucideIcon;
}

/** Normalized row — every section renders through this shape. */
interface Row {
  url: string;
  label: string;
  sublabel?: string | null;
  status?: string | null;
  amount?: string | null;
  icon: LucideIcon;
  /** Palette group type ('sales_order', 'page', …) — preserved into recents. */
  type?: string | null;
  /** Record IDs render in mono; page names in sans. */
  mono?: boolean;
}

interface Section {
  key: string;
  label: string;
  icon?: LucideIcon;
  rows: Row[];
  /** Optional affordance on the right of the section header. */
  headerAction?: { label: string; onClick: () => void };
}

interface Props {
  open: boolean;
  onClose: () => void;
}

export function CommandPalette({ open, onClose }: Props) {
  const navigate = useNavigate();
  const [q, setQ] = useState('');
  const [activeIndex, setActiveIndex] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLDivElement>(null);

  const permissions = useAuthStore((s) => s.permissions);
  const features = useAuthStore((s) => s.features);
  const roleSlug = useAuthStore((s) => s.user?.role?.slug);
  const recents = useRecentItemsStore((s) => s.items);
  const addRecent = useRecentItemsStore((s) => s.add);
  const clearRecents = useRecentItemsStore((s) => s.clear);

  // Reset on close.
  useEffect(() => {
    if (!open) {
      setQ('');
      setActiveIndex(0);
    } else {
      requestAnimationFrame(() => inputRef.current?.focus());
    }
  }, [open]);

  const trimmed = q.trim();
  const searching = trimmed.length >= 2;

  /*
   * Record search, debounced.
   *
   * This was a `setTimeout` + `await client.get` inside an effect, whose
   * cleanup cleared the *timer* but not the *in-flight request*. Typing
   * "abc" then "abcd" 250ms later fired both — and whichever resolved last
   * won, so a slow response for a stale query could overwrite the newer
   * results. Keying the query on the debounced term makes the cache the
   * arbiter: only the term we are still asking about can render.
   */
  const debouncedTerm = useDebounce(trimmed, 200);
  const enabled = open && debouncedTerm.length >= 2;

  const { data: groups = NO_GROUPS, isFetching } = useQuery({
    queryKey: ['command-palette', 'search', debouncedTerm],
    queryFn: ({ signal }) =>
      client
        .get<SearchResponse>('/search', { params: { q: debouncedTerm }, signal })
        .then((r) => r.data.data),
    enabled,
    // Keep the previous term's rows on screen while the next ones load,
    // rather than blanking the list on every keystroke.
    placeholderData: (prev) => prev,
  });

  // Count the debounce window as loading. Otherwise the 200ms before the
  // request starts reads as "settled with no results", and the empty state
  // blinks between keystrokes.
  const loading = isFetching || (searching && debouncedTerm !== trimmed);

  // A new result set invalidates the highlighted row.
  useEffect(() => {
    setActiveIndex(0);
  }, [groups]);

  // "Go to" pages — the same gated sitemap as the sidebar.
  const visibleNav = useMemo(
    () =>
      SECTIONS.map((section) => ({
        ...section,
        items: section.items.filter((item) =>
          isNavItemVisible(item, { permissions, features, roleSlug }),
        ),
      })).filter((s) => s.items.length > 0),
    [permissions, features, roleSlug],
  );

  const sections = useMemo<Section[]>(() => {
    const out: Section[] = [];

    if (!searching) {
      if (recents.length > 0) {
        out.push({
          key: 'recent',
          label: 'Recent',
          icon: Clock,
          headerAction: { label: 'Clear', onClick: clearRecents },
          rows: recents.map((r) => ({
            url: r.url,
            label: r.label,
            sublabel: r.sublabel,
            status: r.status,
            type: r.type,
            icon: r.type === 'page' ? ArrowRight : iconForType(r.type),
            mono: r.type !== 'page',
          })),
        });
      }
      // Full app map, grouped like the sidebar.
      for (const section of visibleNav) {
        out.push({
          key: `nav-${section.label}`,
          label: section.label,
          rows: section.items.map((item) => ({
            url: item.to,
            label: item.label,
            type: 'page',
            icon: item.icon,
          })),
        });
      }
      return out;
    }

    // Query mode — matching pages first, then record results.
    const needle = trimmed.toLowerCase();
    const pageRows = visibleNav
      .flatMap((s) => s.items.map((item) => ({ item, section: s.label })))
      .filter(
        ({ item, section }) =>
          item.label.toLowerCase().includes(needle) || section.toLowerCase().includes(needle),
      )
      .map<Row>(({ item, section }) => ({
        url: item.to,
        label: item.label,
        sublabel: section,
        type: 'page',
        icon: item.icon,
      }));
    if (pageRows.length > 0) {
      out.push({ key: 'pages', label: 'Pages', rows: pageRows });
    }

    for (const group of groups) {
      out.push({
        key: `api-${group.group}`,
        label: group.label,
        icon: iconForType(group.type),
        rows: group.items.map((item) => ({
          url: item.url,
          label: item.label,
          sublabel: item.sublabel,
          status: item.status,
          amount: item.amount,
          type: group.type,
          icon: iconForType(group.type),
          mono: true,
        })),
      });
    }
    return out;
  }, [searching, trimmed, visibleNav, recents, groups, clearRecents]);

  // Flat list for keyboard nav, plus each section's start offset for indexing.
  const { flatRows, sectionOffsets } = useMemo(() => {
    const rows: Row[] = [];
    const offsets: number[] = [];
    for (const s of sections) {
      offsets.push(rows.length);
      rows.push(...s.rows);
    }
    return { flatRows: rows, sectionOffsets: offsets };
  }, [sections]);

  // Clamp the active row when the list shrinks.
  useEffect(() => {
    setActiveIndex((i) => Math.min(i, Math.max(0, flatRows.length - 1)));
  }, [flatRows.length]);

  const pick = useCallback(
    (row: Row) => {
      addRecent({
        url: row.url,
        label: row.label,
        sublabel: row.sublabel,
        status: row.status,
        type: row.type,
      });
      navigate(row.url);
      onClose();
    },
    [addRecent, navigate, onClose],
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
        setActiveIndex((i) => Math.min(i + 1, Math.max(0, flatRows.length - 1)));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        setActiveIndex((i) => Math.max(0, i - 1));
      } else if (e.key === 'Enter') {
        e.preventDefault();
        const target = flatRows[activeIndex];
        if (target) pick(target);
      }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, flatRows, activeIndex, pick, onClose]);

  // Scroll active row into view.
  useEffect(() => {
    if (!open || !listRef.current) return;
    const el = listRef.current.querySelector<HTMLElement>(`[data-flat-index="${activeIndex}"]`);
    el?.scrollIntoView({ block: 'nearest' });
  }, [activeIndex, open]);

  if (!open) return null;

  const showEmptyState = searching && !loading && sections.length === 0;
  const totalResults = flatRows.length;

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
        className="relative w-full max-w-2xl rounded-md border border-default bg-canvas shadow-menu overflow-hidden animate-slide-up"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center gap-2 px-3 py-2.5 border-b border-default">
          <Search size={14} className="text-muted shrink-0" />
          <input
            ref={inputRef}
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Search pages, employees, orders, vendors, items, NCRs…"
            className="flex-1 bg-transparent text-sm outline-none placeholder:text-subtle"
            aria-label="Search query"
          />
          {loading && <Loader2 size={14} className="text-muted animate-spin" />}
          <kbd className="font-mono text-2xs text-subtle border border-subtle rounded px-1 py-0.5">
            ESC
          </kbd>
        </div>

        <div ref={listRef} className="max-h-[420px] overflow-y-auto">
          {!searching && recents.length === 0 && (
            <div className="px-3 pt-3 pb-1 text-xs text-muted">
              Pick a page below, or type at least 2 characters to search records (
              <span className="font-mono text-primary">SO-</span>,{' '}
              <span className="font-mono text-primary">PO-</span>,{' '}
              <span className="font-mono text-primary">WO-</span>,{' '}
              <span className="font-mono text-primary">INV-</span>,{' '}
              <span className="font-mono text-primary">NCR-</span>, any name).
            </div>
          )}

          {showEmptyState && (
            <div className="px-3 py-5 text-center">
              <p className="text-sm text-primary">
                No results for <span className="font-mono">"{trimmed}"</span>
              </p>
              <p className="mt-1 text-xs text-muted">
                Try a different keyword, an exact ID prefix, or check your permissions for the
                module.
              </p>
            </div>
          )}

          {sections.map((section, sectionIdx) => (
            <div key={section.key} className="border-t border-subtle first:border-t-0">
              <div className="px-3 pt-2 pb-1 text-2xs uppercase tracking-wider text-muted font-medium flex items-center gap-2">
                {section.icon && <section.icon size={11} className="text-muted" />}
                <span className="flex-1">{section.label}</span>
                <span className="font-mono text-subtle tabular-nums">{section.rows.length}</span>
                {section.headerAction && (
                  <LinkButton
                    tone="muted"
                    onClick={section.headerAction.onClick}
                    icon={<X size={10} />}
                    className="normal-case tracking-normal font-normal"
                  >
                    {section.headerAction.label}
                  </LinkButton>
                )}
              </div>
              <ul>
                {section.rows.map((row, rowIdx) => {
                  const flatIdx = sectionOffsets[sectionIdx] + rowIdx;
                  const isActive = flatIdx === activeIndex;
                  return (
                    <li key={`${section.key}-${row.url}`}>
                      <button
                        data-flat-index={flatIdx}
                        onClick={() => pick(row)}
                        onMouseEnter={() => setActiveIndex(flatIdx)}
                        className={cn(
                          'w-full text-left px-3 py-2 text-sm flex items-center gap-3 cursor-pointer',
                          focusRingInset,
                          isActive ? 'bg-elevated' : 'hover:bg-subtle',
                        )}
                      >
                        <row.icon size={14} className="text-muted shrink-0" />
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-2">
                            <span className={`truncate ${row.mono ? 'font-mono' : ''}`}>
                              {row.label}
                            </span>
                            {row.status && (
                              <Chip variant={chipVariantForStatus(row.status)}>
                                {row.status.replace(/_/g, ' ')}
                              </Chip>
                            )}
                          </div>
                          {row.sublabel && (
                            <div className="text-xs text-muted truncate mt-0.5">{row.sublabel}</div>
                          )}
                        </div>
                        {row.amount != null && (
                          <span className="font-mono tabular-nums text-xs text-muted ml-3 shrink-0">
                            {formatPeso(Number(row.amount))}
                          </span>
                        )}
                      </button>
                    </li>
                  );
                })}
              </ul>
            </div>
          ))}
        </div>

        <div className="px-3 py-1.5 border-t border-default text-2xs text-muted flex items-center justify-between font-mono">
          <div className="flex items-center gap-3">
            <span>↑↓ navigate</span>
            <span>↵ open</span>
            <span>esc close</span>
          </div>
          {totalResults > 0 && (
            <span className="tabular-nums">
              {totalResults} result{totalResults === 1 ? '' : 's'}
            </span>
          )}
        </div>
      </div>
    </div>
  );
}

export default CommandPalette;
