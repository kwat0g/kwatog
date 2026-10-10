import { forwardRef, useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { LuChevronDown } from '@/lib/icons';
import { Input, type InputProps } from './Input';
import { cn } from '@/lib/cn';

interface SearchableSelectProps extends Pick<
  InputProps,
  'fieldSize' | 'helper' | 'containerClassName' | 'className' | 'id' | 'title' | 'onBlur'
> {
  label?: string;
  'aria-label'?: string;
  name?: string;
  required?: boolean;
  value: string;
  selectedLabel?: string;
  options: Array<{ value: string; label: string; disabled?: boolean }>;
  onChange: (value: string) => void;
  onSearch?: (search: string) => void;
  loading?: boolean;
  disabled?: boolean;
  error?: string;
  placeholder?: string;
}

export const SearchableSelect = forwardRef<HTMLInputElement, SearchableSelectProps>(
  (
    {
      options,
      value,
      selectedLabel,
      onChange,
      onSearch,
      loading,
      placeholder = 'Search or select…',
      ...props
    },
    ref,
  ) => {
    const id = useId().replaceAll(':', '');
    const input = useRef<HTMLInputElement | null>(null);
    const popup = useRef<HTMLDivElement>(null);
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [active, setActive] = useState(-1);
    const searchCallback = useRef(onSearch);
    searchCallback.current = onSearch;
    const hasSearch = typeof onSearch === 'function';
    useEffect(() => {
      if (!open || !hasSearch) return;
      const timer = window.setTimeout(() => searchCallback.current?.(search), 300);
      return () => window.clearTimeout(timer);
    }, [search, open, hasSearch]);
    const matches = options.filter((option) =>
      option.label.toLowerCase().includes(search.trim().toLowerCase()),
    );
    const selected = selectedLabel ?? options.find((option) => option.value === value)?.label ?? '';
    const choose = (next: string) => {
      onChange(next);
      setOpen(false);
      setSearch('');
      setActive(-1);
    };
    useEffect(() => {
      if (!open) return;
      const position = () => {
        if (!input.current || !popup.current) return;
        const bounds = input.current.getBoundingClientRect();
        const width = Math.min(Math.max(bounds.width, 320), window.innerWidth - 16);
        const below = window.innerHeight - bounds.bottom - 12;
        const above = bounds.top - 12;
        const useAbove = below < 180 && above > below;
        Object.assign(popup.current.style, {
          width: `${width}px`,
          left: `${Math.max(8, Math.min(bounds.left, window.innerWidth - width - 8))}px`,
          top: useAbove ? 'auto' : `${bounds.bottom + 4}px`,
          bottom: useAbove ? `${window.innerHeight - bounds.top + 4}px` : 'auto',
          maxHeight: `${Math.max(44, Math.min(320, useAbove ? above : below))}px`,
        });
      };
      position();
      window.addEventListener('scroll', position, true);
      window.addEventListener('resize', position);
      return () => {
        window.removeEventListener('scroll', position, true);
        window.removeEventListener('resize', position);
      };
    }, [open]);
    useEffect(() => {
      setActive(-1);
    }, [search]);
    return (
      <>
        <Input
          {...props}
          fieldSize={props.fieldSize ?? 'lg'}
          role="combobox"
          autoComplete="off"
          placeholder={placeholder}
          ref={(element) => {
            input.current = element;
            if (typeof ref === 'function') ref(element);
            else if (ref) ref.current = element;
          }}
          value={open ? search : selected}
          aria-expanded={open}
          aria-controls={`${id}-list`}
          aria-autocomplete="list"
          aria-activedescendant={open && matches[active] ? `${id}-option-${active}` : undefined}
          suffix={
            <button
              type="button"
              tabIndex={-1}
              aria-label={`Show ${props.label ?? props['aria-label'] ?? 'options'}`}
              disabled={props.disabled}
              className="flex h-full items-center"
              onMouseDown={(event) => event.preventDefault()}
              onClick={() => {
                input.current?.focus();
                setOpen(true);
                setSearch('');
              }}
            >
              <LuChevronDown size={16} aria-hidden />
            </button>
          }
          onFocus={() => {
            setOpen(true);
            setSearch('');
            setActive(-1);
          }}
          onClick={() => {
            if (!open) {
              setOpen(true);
              setSearch('');
            }
          }}
          onBlur={(event) => {
            setOpen(false);
            setSearch('');
            setActive(-1);
            props.onBlur?.(event);
          }}
          onChange={(event) => {
            setOpen(true);
            setSearch(event.target.value);
          }}
          onKeyDown={(event) => {
            if (event.key === 'Escape') {
              event.preventDefault();
              setOpen(false);
              setSearch('');
              return;
            }
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
              event.preventDefault();
              if (!open) {
                setOpen(true);
                setSearch('');
              }
              const direction = event.key === 'ArrowDown' ? 1 : -1;
              let next = active < 0 && direction < 0 ? matches.length : active;
              for (let attempt = 0; attempt < matches.length; attempt++) {
                next = (next + direction + matches.length) % matches.length;
                if (!matches[next].disabled) break;
              }
              setActive(next);
              window.setTimeout(
                () =>
                  document
                    .getElementById(`${id}-option-${next}`)
                    ?.scrollIntoView?.({ block: 'nearest' }),
                0,
              );
            }
            if (event.key === 'Enter' && open) {
              event.preventDefault();
              if (matches[active] && !matches[active].disabled) choose(matches[active].value);
            }
          }}
        />
        {open &&
          !props.disabled &&
          createPortal(
            <div
              ref={popup}
              className="fixed z-[100] overflow-auto rounded-md border border-default bg-canvas shadow-lg"
              style={{ top: -9999, left: -9999 }}
              onMouseDown={(event) => event.preventDefault()}
            >
              {loading && (
                <p role="status" className="px-3 py-2 text-xs text-muted">
                  Searching…
                </p>
              )}
              <div id={`${id}-list`} role="listbox" aria-label={props.label ?? props['aria-label']}>
                {matches.map((option, index) => (
                  <button
                    key={option.value}
                    id={`${id}-option-${index}`}
                    type="button"
                    role="option"
                    aria-selected={option.value === value}
                    disabled={option.disabled}
                    tabIndex={-1}
                    className={cn(
                      'block min-h-11 w-full px-3 py-2 text-left text-sm break-words hover:bg-elevated disabled:opacity-50 disabled:cursor-not-allowed',
                      active === index && 'bg-elevated',
                      option.value === value && 'text-accent font-medium',
                    )}
                    onClick={() => choose(option.value)}
                  >
                    {option.label}
                  </button>
                ))}
              </div>
              {!loading && matches.length === 0 && (
                <p role="status" className="px-3 py-3 text-sm text-muted">
                  No matches. Try another code or name.
                </p>
              )}
            </div>,
            document.body,
          )}
      </>
    );
  },
);
SearchableSelect.displayName = 'SearchableSelect';
