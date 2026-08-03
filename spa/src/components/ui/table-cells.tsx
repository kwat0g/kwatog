/* eslint-disable react-refresh/only-export-components -- table style primitives are intentionally exported beside their cell components. */
/**
 * Table cell primitives — the one source of truth for table typography.
 *
 * DESIGN-SYSTEM.md → "Data table": 32px rows, 0 10px cell padding, header
 * 10px uppercase tracking-wider muted 500, numbers always mono + tabular.
 *
 * Import these instead of retyping the class strings. Hand-typed headers had
 * drifted into six variants (missing height, `px-4 py-2`, no uppercase,
 * stray double spaces), which is exactly the kind of thing nobody notices in
 * one table and everybody notices across forty.
 */
import { type ReactNode, type ThHTMLAttributes, type TdHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

type Align = 'left' | 'right' | 'center';

const alignCls: Record<Align, string> = {
  left: 'text-left',
  right: 'text-right',
  center: 'text-center',
};

/** Header cell classes. Use when you can't use `<Th>` (e.g. inside a map). */
export const thCls = (align: Align = 'left'): string =>
  cn('h-9 px-3.5 text-2xs uppercase tracking-wider text-muted font-semibold bg-[var(--bg-thead)] select-none', alignCls[align]);

/** Body cell classes. `mono` for any numeric / ID / date content. */
export const tdCls = (align: Align = 'left', mono = false): string =>
  cn('px-3.5 py-2.5 align-middle', alignCls[align], mono && 'font-mono tabular-nums');

/** Row classes — 40px tall, hairline separator, high-contrast theme-aware zebra striping, hover highlight, selected outline. */
export const trCls = 'h-10 border-b border-subtle/80 odd:bg-[var(--bg-zebra-odd)] even:bg-[var(--bg-zebra-even)] hover:bg-[var(--bg-row-hover)] transition-all duration-fast aria-selected:outline aria-selected:outline-2 aria-selected:outline-landing-accent aria-selected:-outline-offset-2 aria-selected:z-10 aria-selected:shadow-xs';

/** Header row classes. */
export const theadTrCls = 'border-b border-default bg-[var(--bg-thead)]';

/**
 * Totals / grand-total row — a heavier rule above it and 500 weight.
 *
 * Colours the top edge specifically (`border-t-strong`, not `border-primary`)
 * because `cn` is plain clsx: `trCls` already carries `border-subtle`, and an
 * all-sides colour appended after it loses on stylesheet order, silently
 * leaving the rule hairline-grey. A side-specific utility cannot collide.
 */
export const totalsTrCls = 'h-10 border-t-2 border-t-strong font-medium bg-[var(--bg-thead)]';

/** Table element classes. */
export const tableCls = 'w-full border-collapse text-sm';

interface ThProps extends Omit<ThHTMLAttributes<HTMLTableCellElement>, 'align'> {
  align?: Align;
  children?: ReactNode;
}

export function Th({ align = 'left', className, children, ...rest }: ThProps) {
  return (
    <th scope="col" className={cn(thCls(align), className)} {...rest}>
      {children}
    </th>
  );
}

interface TdProps extends Omit<TdHTMLAttributes<HTMLTableCellElement>, 'align'> {
  align?: Align;
  /** Numbers, IDs, dates — anything that should align vertically in a column. */
  mono?: boolean;
  children?: ReactNode;
}

export function Td({ align = 'left', mono = false, className, children, ...rest }: TdProps) {
  return (
    <td className={cn(tdCls(align, mono), className)} {...rest}>
      {children}
    </td>
  );
}
