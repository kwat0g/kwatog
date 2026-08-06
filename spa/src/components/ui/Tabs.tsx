import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';

/**
 * In-page section tabs driven by local state — the sibling of
 * [`TabNavigation`](./TabNavigation.tsx), which routes instead. Nine pages had
 * hand-rolled this strip in four different sizes; this is the one look.
 *
 * Arrow keys move between tabs and only the selected tab is a tab stop, which is
 * what the ARIA tabs pattern calls for. Render the panel yourself and give it
 * `role="tabpanel"`.
 */

export interface TabItem<T extends string> {
 key: T;
 label: ReactNode;
 /** Right-aligned count, rendered in mono like every other number. */
 count?: number;
 disabled?: boolean;
}

interface TabsProps<T extends string> {
 items: Array<TabItem<T>>;
 value: T;
 onChange: (value: T) => void;
 /** Names the strip for assistive tech, e.g. "Employee sections". */
 label: string;
 /** Right-aligned controls that belong to the strip, e.g. a currency picker. */
 trailing?: ReactNode;
 className?: string;
}

export function Tabs<T extends string>({ items, value, onChange, label, trailing, className }: TabsProps<T>) {
 const move = (from: number, delta: number) => {
 const enabled = items.map((t, i) => (t.disabled ? -1 : i)).filter((i) => i >= 0);
 if (enabled.length === 0) return;
 const pos = enabled.indexOf(from);
 const next = enabled[(pos + delta + enabled.length) % enabled.length];
 onChange(items[next].key);
 };

 return (
 <div className={cn('flex items-center gap-3 border-b border-default', className)}>
 <div role="tablist" aria-label={label} className="flex min-w-0 gap-0 overflow-x-auto">
 {items.map((tab, i) => {
 const isActive = tab.key === value;
 return (
 <button
 key={tab.key}
 type="button"
 role="tab"
 aria-selected={isActive}
 disabled={tab.disabled}
 tabIndex={isActive ? 0 : -1}
 onClick={() => onChange(tab.key)}
 onKeyDown={(e) => {
 if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
 e.preventDefault();
 move(i, 1);
 } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
 e.preventDefault();
 move(i, -1);
 }
 }}
 className={cn(
 'relative inline-flex h-10 shrink-0 items-center gap-1.5 px-4 text-sm whitespace-nowrap',
 'transition-colors duration-fast cursor-pointer',
 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-inset',
 'disabled:opacity-50 disabled:cursor-not-allowed',
 isActive ? 'text-primary font-medium' : 'text-secondary hover:text-primary hover:bg-subtle',
 )}
 >
 {tab.label}
 {tab.count !== undefined && (
 <span className="font-mono tabular-nums text-xs text-muted">{tab.count}</span>
 )}
 {isActive && (
 <span
 className="absolute bottom-0 left-2 right-2 h-0.5 rounded-full bg-accent"
 aria-hidden
 />
 )}
 </button>
 );
 })}
 </div>
 {trailing && <div className="ml-auto flex shrink-0 items-center pb-1">{trailing}</div>}
 </div>
 );
}
