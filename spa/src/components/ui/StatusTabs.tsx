import { useRef, type KeyboardEvent } from 'react';
import { cn } from '@/lib/cn';

export interface StatusTabItem {
  value: string;
  label: string;
  count: number;
}

export function StatusTabs({
  items,
  value,
  onChange,
  label,
  panelId,
}: {
  items: StatusTabItem[];
  value: string;
  onChange: (value: string) => void;
  label: string;
  panelId?: string;
}) {
  const refs = useRef<Array<HTMLButtonElement | null>>([]);
  const activeIndex = Math.max(0, items.findIndex((item) => item.value === value));

  const move = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
    let next: number;
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (index + 1) % items.length;
    else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') next = (index - 1 + items.length) % items.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = items.length - 1;
    else return;
    event.preventDefault();
    onChange(items[next].value);
    refs.current[next]?.focus();
  };

  return (
    <div className="w-full overflow-x-auto border-b border-default">
      <div
        role="tablist"
        aria-label={label}
        className="relative grid w-full min-w-[560px]"
        style={{ gridTemplateColumns: `repeat(${items.length}, minmax(0, 1fr))` }}
      >
        {items.map((item, index) => {
          const selected = item.value === value;
          return (
            <button
              key={item.value}
              ref={(element) => { refs.current[index] = element; }}
              type="button"
              role="tab"
              aria-label={`${item.label} ${item.count}`}
              aria-selected={selected}
              aria-controls={panelId}
              tabIndex={selected ? 0 : -1}
              onClick={() => onChange(item.value)}
              onKeyDown={(event) => move(event, index)}
              className={cn(
                'inline-flex min-w-0 items-center justify-center gap-2 px-2 py-3 text-label whitespace-nowrap transition-colors duration-fast motion-reduce:transition-none',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-inset',
                selected ? 'text-primary font-medium' : 'text-secondary hover:text-primary',
              )}
            >
              <span className="truncate">{item.label}</span>
              <span className={cn('tabular-nums', item.count === 0 ? 'text-subtle' : 'text-muted')}>
                {item.count}
              </span>
            </button>
          );
        })}
        <span
          aria-hidden="true"
          className="absolute bottom-0 left-0 h-0.5 bg-accent transition-transform duration-normal motion-reduce:transition-none"
          style={{ width: `${100 / items.length}%`, transform: `translateX(${activeIndex * 100}%)` }}
        />
      </div>
    </div>
  );
}
