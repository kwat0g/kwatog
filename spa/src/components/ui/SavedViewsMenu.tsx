import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { LuChevronDown, LuStar, LuTrash2 } from '@/lib/icons';
import { cn } from '@/lib/cn';
import { focusRing } from '@/lib/focus';
import { expandAppPath } from '@/lib/appPaths';
import { useTablePrefsStore, type SavedFilterView } from '@/stores/tablePrefsStore';

interface SavedViewsMenuProps {
  values: Record<string, unknown>;
  searchValue: string;
  searchable: boolean;
  onApply?: (filters: Record<string, string>, clearKeys: string[]) => void;
}

const EMPTY_VIEWS: SavedFilterView[] = [];
const MAX_SAVED_VIEWS = 12;
const TRANSIENT_KEYS = new Set(['page', 'per_page', 'limit']);

function serializableFilters(values: Record<string, unknown>, searchValue: string, searchable: boolean) {
  const filters: Record<string, string> = {};
  for (const [key, value] of Object.entries(values)) {
    if (TRANSIENT_KEYS.has(key) || value === undefined || value === null || value === '') continue;
    if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
      filters[key] = String(value);
    }
  }
  if (searchable && searchValue) filters.search = searchValue;
  else if (searchable) delete filters.search;
  return filters;
}

function createViewId(): string {
  return globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export function SavedViewsMenu({ values, searchValue, searchable, onApply }: SavedViewsMenuProps) {
  const location = useLocation();
  const navigate = useNavigate();
  const filterShape = Object.keys(values)
    .filter((key) => !TRANSIENT_KEYS.has(key))
    .sort()
    .join(',') || (searchable ? 'search' : 'filters');
  const preferenceKey = `list:${expandAppPath(location.pathname)}:${filterShape}`;
  const views = useTablePrefsStore((state) => state.byTable[preferenceKey]?.savedViews ?? EMPTY_VIEWS);
  const setSavedViews = useTablePrefsStore((state) => state.setSavedViews);
  const [open, setOpen] = useState(false);
  const [editingName, setEditingName] = useState(false);
  const [name, setName] = useState('');
  const rootRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const menuId = useId();
  const currentFilters = serializableFilters(values, searchValue, searchable);
  const clearKeys = [...new Set([
    ...Object.keys(values).filter((key) => !TRANSIENT_KEYS.has(key)),
    ...(searchable ? ['search'] : []),
  ])];

  useEffect(() => {
    if (!open) return;
    const closeOnOutside = (event: PointerEvent) => {
      if (!rootRef.current?.contains(event.target as Node)) setOpen(false);
    };
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return;
      event.stopPropagation();
      setOpen(false);
      triggerRef.current?.focus();
    };
    document.addEventListener('pointerdown', closeOnOutside);
    document.addEventListener('keydown', closeOnEscape);
    return () => {
      document.removeEventListener('pointerdown', closeOnOutside);
      document.removeEventListener('keydown', closeOnEscape);
    };
  }, [open]);

  const applyView = (view: SavedFilterView) => {
    if (onApply) {
      onApply(view.filters, clearKeys);
    } else {
      const params = new URLSearchParams(location.search);
      clearKeys.forEach((key) => params.delete(key));
      params.delete('page');
      for (const [key, value] of Object.entries(view.filters)) params.set(key, value);
      const query = params.toString();
      navigate({
        pathname: location.pathname,
        search: query ? `?${query}` : '',
        hash: location.hash,
      }, { replace: true });
    }
    setOpen(false);
  };

  const saveCurrentView = (event: FormEvent) => {
    event.preventDefault();
    const cleanName = name.trim();
    if (!cleanName) return;
    const duplicate = views.find((view) => view.name.toLocaleLowerCase() === cleanName.toLocaleLowerCase());
    const next: SavedFilterView[] = duplicate
      ? views.map((view) => view.id === duplicate.id ? { ...view, name: cleanName, filters: currentFilters } : view)
      : [...views, { id: createViewId(), name: cleanName, filters: currentFilters }];
    if (next.length > MAX_SAVED_VIEWS) return;
    setSavedViews(preferenceKey, next);
    setName('');
    setEditingName(false);
  };

  const removeView = (id: string) => {
    setSavedViews(preferenceKey, views.filter((view) => view.id !== id));
  };

  return (
    <div ref={rootRef} className="relative shrink-0">
      <button
        ref={triggerRef}
        type="button"
        aria-label="Saved views"
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-controls={menuId}
        onClick={() => setOpen((wasOpen) => !wasOpen)}
        className={cn(
          'inline-flex h-8 items-center gap-1.5 rounded-md border border-default bg-canvas px-2.5 text-sm text-primary transition-colors hover:bg-elevated',
          focusRing,
        )}
      >
        <LuStar size={14} aria-hidden="true" />
        <span>Views</span>
        {views.length > 0 && <span className="text-xs tabular-nums text-muted">{views.length}</span>}
        <LuChevronDown size={13} aria-hidden="true" className={cn('transition-transform duration-fast', open && 'rotate-180')} />
      </button>

      {open && (
        <div
          id={menuId}
          role="dialog"
          aria-label="Saved list views"
          className="absolute left-0 top-full z-40 mt-2 w-72 max-w-[calc(100vw-2rem)] rounded-md border border-default bg-canvas p-2 shadow-lg"
        >
          <div className="px-2 pb-2 text-xs font-semibold text-secondary">Saved views</div>
          {views.length === 0 ? (
            <p className="px-2 pb-2 text-xs text-muted">Save a filter and search combination to reuse it later.</p>
          ) : (
            <div className="mb-2 flex max-h-56 flex-col overflow-y-auto">
              {views.map((view) => (
                <div key={view.id} className="flex items-center gap-1 rounded hover:bg-elevated">
                  <button
                    type="button"
                    onClick={() => applyView(view)}
                    className={cn('min-h-hit min-w-0 flex-1 truncate px-2 text-left text-sm text-primary', focusRing)}
                  >
                    {view.name}
                  </button>
                  <button
                    type="button"
                    aria-label={`Delete saved view ${view.name}`}
                    title={`Delete ${view.name}`}
                    onClick={() => removeView(view.id)}
                    className={cn('mr-1 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded text-muted hover:bg-subtle hover:text-danger-fg', focusRing)}
                  >
                    <LuTrash2 size={14} aria-hidden="true" />
                  </button>
                </div>
              ))}
            </div>
          )}

          {editingName ? (
            <form onSubmit={saveCurrentView} className="flex flex-col gap-2 border-t border-default pt-2">
              <label htmlFor={`${menuId}-name`} className="text-xs font-medium text-secondary">View name</label>
              <input
                id={`${menuId}-name`}
                autoFocus
                maxLength={48}
                value={name}
                onChange={(event) => setName(event.target.value)}
                placeholder="e.g. My pending approvals"
                className={cn('h-9 rounded-md border border-default bg-canvas px-2 text-sm text-primary outline-none focus:border-accent focus:ring-2 focus:ring-accent', focusRing)}
              />
              <div className="flex justify-end gap-2">
                <button
                  type="button"
                  onClick={() => { setEditingName(false); setName(''); }}
                  className={cn('h-8 rounded-md px-2 text-sm text-secondary hover:bg-elevated', focusRing)}
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={!name.trim() || (views.length >= MAX_SAVED_VIEWS && !views.some((view) => view.name.toLocaleLowerCase() === name.trim().toLocaleLowerCase()))}
                  className={cn('h-8 rounded-md bg-accent px-3 text-sm font-medium text-accent-fg disabled:cursor-not-allowed disabled:opacity-50', focusRing)}
                >
                  Save view
                </button>
              </div>
            </form>
          ) : (
            <button
              type="button"
              onClick={() => setEditingName(true)}
              disabled={views.length >= MAX_SAVED_VIEWS}
              className={cn('min-h-hit w-full rounded-md border-t border-default px-2 pt-2 text-left text-sm font-medium text-accent hover:text-accent-hover disabled:cursor-not-allowed disabled:text-muted', focusRing)}
            >
              Save current filters as a view
            </button>
          )}
        </div>
      )}
    </div>
  );
}
