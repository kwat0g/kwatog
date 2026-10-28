import { useLocation, useNavigate } from 'react-router-dom';
import { LuArrowLeft } from '@/lib/icons';
import { isValidElement, useEffect, type ReactElement, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { RefreshingIndicator } from './RefreshingIndicator';
import { Chip } from '@/components/ui/Chip';
import { MODULE_LABELS } from '@/lib/moduleLabels';
import { useBreadcrumbStore } from '@/stores/breadcrumbStore';

interface PageHeaderBaseProps {
  title: ReactNode;
  /** Supporting context; includes live result counts when the page has them. */
  subtitle?: ReactNode;
  /** Optional freshness context. Rendered with a visible "Updated" label. */
  lastUpdated?: ReactNode;
  backTo?: string;
  backLabel?: string;
  /** Optional row below the header (e.g. ChainHeader on detail pages). */
  bottom?: ReactNode;
  className?: string;
  /** Let a shared PageShell own the horizontal gutter for this header. */
  inset?: boolean;
  /**
   * Overrides the breadcrumb label for this route's final segment. Defaults to
   * the header's own title text with any status `<Chip>` left out, which is
   * right for almost every page — pass this only when the title is too long to
   * sit in the trail.
   */
  crumbLabel?: string;
  /**
   * Series X / Task X5 — when supplied, render a small "Refreshing…" pill
   * next to the title while any matching TanStack Query is refetching in
   * the background. Use the same key shape you pass to `useQuery`.
   */
  refreshingQueryKey?: readonly unknown[];
}

type PageHeaderProps = PageHeaderBaseProps & (
  | { actions?: ReactNode; primaryAction?: never; secondaryActions?: never }
  | { actions?: never; primaryAction: ReactElement; secondaryActions?: ReactNode }
);

/**
 * Flatten a `title` node to plain text.
 *
 * `omitChips` drops `<Chip>` subtrees. 26 detail pages build their title as
 * "<record number or name> <Chip>", and a breadcrumb has to name the record, not
 * report its state: the leave detail trail read "HR › Leaves › pending hr", which
 * identifies no request, changes under the user mid-approval, and printed the
 * status twice on one screen. The tab title keeps the chip, where the extra word
 * is the only progress signal a pinned tab has.
 *
 * It drops EVERY chip, not only status ones — on 2 of the 26 the chip carries
 * identity (a customer `code`, an inspection-spec `v{version}`). Both of those
 * titles also spell the record out beside the chip, so the crumb still names it;
 * a page that put its only identifier in a chip would need `crumbLabel`.
 *
 * The test is `node.type === Chip`, by identity. It is exact and type-checked —
 * a rename or move of Chip breaks the build, where a string marker would rot in
 * silence — and it cannot false-positive on an unrelated component. The residual
 * hole is narrow: a wrapper that takes its text as CHILDREN
 * (`<StatusChip>{label}</StatusChip>`) would leak the status back into all 26
 * crumbs, whereas the more natural `<StatusChip status={x} />` cannot, since its
 * text is produced inside a render this function never invokes. The trail
 * assertions in `e2e/chain-leave.spec.ts` are what catch that case.
 */
function textFromNode(node: ReactNode, omitChips = false): string {
  if (typeof node === 'string' || typeof node === 'number') return String(node);
  if (Array.isArray(node)) return node.map((child) => textFromNode(child, omitChips)).join(' ');
  if (isValidElement(node)) {
    if (omitChips && node.type === Chip) return '';
    return textFromNode((node.props as { children?: ReactNode }).children, omitChips);
  }
  return '';
}

const flatten = (text: string): string => text.replace(/\s+/g, ' ').trim();

export function PageHeader(props: PageHeaderProps) {
  const {
    title,
    subtitle,
    lastUpdated,
    backTo,
    backLabel,
    actions,
    primaryAction,
    secondaryActions,
    bottom,
    className,
    inset = true,
    crumbLabel,
    refreshingQueryKey,
  } = props;
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const documentTitle = flatten(textFromNode(title));
  const setCrumb = useBreadcrumbStore((s) => s.set);
  const clearCrumb = useBreadcrumbStore((s) => s.clear);

  // The trail's last segment is often an opaque hash id. The header already
  // knows the record's human name, so lend it rather than making every page
  // hand-write a second breadcrumb array (which is what used to happen, and
  // produced two trails on screen that disagreed with each other).
  const publishedCrumb = crumbLabel ?? flatten(textFromNode(title, true));
  useEffect(() => {
    if (!publishedCrumb) return;
    setCrumb(pathname, publishedCrumb);
    return () => clearCrumb(pathname);
  }, [pathname, publishedCrumb, setCrumb, clearCrumb]);

  useEffect(() => {
    if (!documentTitle) return;
    // "PO-202604-0015 · Procurement · ERP" beats "PO-202604-0015 · ERP" in a
    // strip of a dozen pinned tabs, where the document number alone says
    // nothing about which part of the ERP it belongs to.
    const module = MODULE_LABELS[pathname.split('/').filter(Boolean)[0] ?? ''];
    document.title = module && module !== documentTitle
      ? `${documentTitle} · ${module} · ERP`
      : `${documentTitle} · ERP`;
  }, [documentTitle, pathname]);

  const handleBackClick = (e: React.MouseEvent) => {
    e.preventDefault();
    // The label states where the link goes ("Back to Employees"), so it has to
    // go there. This preferred `navigate(-1)` whenever history had depth, which
    // meant arriving from a dashboard drill-down or a command-palette jump sent
    // the user somewhere the label never mentioned. `backTo` wins when supplied;
    // history is only the fallback for headers that declare no destination.
    if (backTo) {
      navigate(backTo);
    } else if (window.history.state && window.history.state.idx > 0) {
      navigate(-1);
    }
  };

  return (
    <div
      data-layout="page-header"
      className={cn('py-4 border-b border-default bg-canvas', inset && 'px-5', className)}
    >
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          {backTo && (
          <a href={backTo} onClick={handleBackClick} className="inline-flex items-center gap-1 text-caption text-muted hover:text-primary mb-1">
              <LuArrowLeft size={11} />
              {backLabel ?? 'Back'}
            </a>
          )}
 <h1 className="font-display text-page-title text-primary break-words">
            {title}
            {refreshingQueryKey && <RefreshingIndicator queryKey={refreshingQueryKey} />}
          </h1>
          {(subtitle || lastUpdated) && (
            <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-caption text-muted">
              {subtitle && <span>{subtitle}</span>}
              {lastUpdated && <span>Updated {lastUpdated}</span>}
            </div>
          )}
        </div>
        {primaryAction ? (
          <div className="flex flex-wrap items-center gap-1.5 sm:shrink-0">
            {secondaryActions && <div className="flex flex-wrap items-center gap-1.5">{secondaryActions}</div>}
            <div className="flex flex-wrap items-center gap-1.5">{primaryAction}</div>
          </div>
        ) : actions ? (
          <div className="flex flex-wrap items-center gap-1.5 sm:shrink-0">{actions}</div>
        ) : null}
      </div>
      {bottom && <div className="mt-3">{bottom}</div>}
    </div>
  );
}
