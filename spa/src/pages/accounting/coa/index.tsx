import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { accountsApi } from '@/api/accounting/accounts';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import { cn } from '@/lib/cn';
import type { Account } from '@/types/accounting';

const TYPE_CHIP: Record<string, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
  asset: 'neutral',
  liability: 'neutral',
  equity: 'neutral',
  revenue: 'neutral',
  expense: 'neutral',
};

export default function ChartOfAccountsPage() {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['accounting', 'accounts', 'tree'],
    queryFn: () => accountsApi.tree(),
  });

  const [expanded, setExpanded] = useState<Set<string>>(new Set());
  const [didInit, setDidInit] = useState(false);

  // Default: expand top-level groups once on first load. Users can then
  // collapse them freely (previously a `forceExpanded` flag made them
  // permanently open and the toggle did nothing on roots).
  useEffect(() => {
    if (!didInit && data && data.length > 0) {
      setExpanded(new Set(data.map((a) => a.id)));
      setDidInit(true);
    }
  }, [data, didInit]);

  const toggle = (id: string) => {
    setExpanded((prev) => {
      const n = new Set(prev);
      if (n.has(id)) n.delete(id); else n.add(id);
      return n;
    });
  };

  const expandAll = () => setExpanded(new Set([...collectIds(data ?? [])]));
  const collapseAll = () => setExpanded(new Set());

  return (
    <div>
      <PageHeader
        title="Chart of Accounts"
        subtitle={data ? `${countAll(data)} accounts` : undefined}
        actions={
          <div className="flex gap-1.5">
            <Button variant="secondary" size="sm" onClick={collapseAll}>Collapse all</Button>
            <Button variant="secondary" size="sm" onClick={expandAll}>Expand all</Button>
          </div>
        }
      />

      {isLoading && !data && <div className="px-5 py-4"><SkeletonTable columns={5} rows={8} /></div>}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load Chart of Accounts"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {data && data.length === 0 && (
        <EmptyState icon="inbox" title="No accounts yet" description="Run the ChartOfAccountsSeeder to install the default 45-account COA." />
      )}

      {data && data.length > 0 && (
        <div className="px-5 py-4">
          <div className="border border-default rounded-md overflow-hidden">
            <div className="grid grid-cols-12 h-8 px-2.5 bg-subtle text-2xs uppercase tracking-wider text-muted font-medium border-b border-default">
              <div className="col-span-1">Code</div>
              <div className="col-span-5">Account</div>
              <div className="col-span-2">Type</div>
              <div className="col-span-2 text-right">Debit Total</div>
              <div className="col-span-2 text-right">Balance</div>
            </div>
            <div>
              {data.map((root) => (
                <TreeRow key={root.id} node={root} depth={0} expanded={expanded} onToggle={toggle} />
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function countAll(nodes: Account[]): number {
  let n = 0;
  for (const node of nodes) {
    n += 1;
    if (node.children?.length) n += countAll(node.children);
  }
  return n;
}

function collectIds(nodes: Account[]): string[] {
  const ids: string[] = [];
  for (const n of nodes) {
    ids.push(n.id);
    if (n.children?.length) ids.push(...collectIds(n.children));
  }
  return ids;
}

function TreeRow({
  node, depth, expanded, onToggle,
}: { node: Account; depth: number; expanded: Set<string>; onToggle: (id: string) => void }) {
  const hasChildren = (node.children?.length ?? 0) > 0;
  const isOpen = expanded.has(node.id);

  return (
    <>
      <div className={cn('grid grid-cols-12 h-8 px-2.5 items-center border-b border-subtle hover:bg-subtle text-sm', !node.is_active && 'opacity-60')}>
        <div className="col-span-1 font-mono tabular-nums text-muted">{node.code}</div>
        <div className="col-span-5 flex items-center gap-1.5" style={{ paddingLeft: `${depth * 14}px` }}>
          {hasChildren ? (
            <button onClick={() => onToggle(node.id)} className="text-muted hover:text-primary">
              {isOpen ? <ChevronDown size={12} /> : <ChevronRight size={12} />}
            </button>
          ) : (
            <span className="w-3" />
          )}
          <span className={cn(hasChildren && 'font-medium')}>{node.name}</span>
          {!node.is_active && <Chip variant="neutral">inactive</Chip>}
        </div>
        <div className="col-span-2 text-xs text-muted uppercase tracking-wider">{node.type} · {node.normal_balance}</div>
        <div className="col-span-2 text-right font-mono tabular-nums">{formatPeso(node.total_debit, '—')}</div>
        <div className="col-span-2 text-right font-mono tabular-nums font-medium">{formatPeso(node.current_balance, '—')}</div>
      </div>
      {isOpen && hasChildren && node.children!.map((c) => (
        <TreeRow key={c.id} node={c} depth={depth + 1} expanded={expanded} onToggle={onToggle} />
      ))}
    </>
  );
}
