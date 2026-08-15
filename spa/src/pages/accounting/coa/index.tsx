import { useEffect, useState, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { LuChevronDown, LuChevronRight } from '@/lib/icons';
import { accountsApi } from '@/api/accounting/accounts';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import { cn } from '@/lib/cn';
import { usePermission } from '@/hooks/usePermission';
import type { Account } from '@/types/accounting';
import { focusRing } from '@/lib/focus';

export default function ChartOfAccountsPage() {
 const { can } = usePermission();
 const [filters, setFilters] = useUrlFilters({ search: '' });

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['accounting', 'accounts', 'tree'],
 queryFn: () => accountsApi.tree(),
 placeholderData: (prev) => prev,
 });

 const filteredData = useMemo(() => {
 if (!data) return null;
 if (!filters.search) return data;
 const q = filters.search.toLowerCase();
 const filterTree = (nodes: Account[]): Account[] => {
 const result: Account[] = [];
 for (const node of nodes) {
 const matches = node.name.toLowerCase().includes(q) || node.code.toLowerCase().includes(q);
 const filteredChildren = node.children ? filterTree(node.children) : undefined;
 if (matches || (filteredChildren && filteredChildren.length > 0)) {
 result.push({ ...node, children: filteredChildren });
 }
 }
 return result;
 };
 return filterTree(data);
 }, [data, filters.search]);

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

 const expandAll = () => setExpanded(new Set([...collectIds(filteredData ?? [])]));
 const collapseAll = () => setExpanded(new Set());

 return (
 <div>
 <PageHeader
 title="Chart of Accounts"
 subtitle={filteredData ? `${countAll(filteredData)} accounts` : undefined}
 actions={
 <div className="flex gap-1.5">
 <Button variant="secondary" size="sm" onClick={collapseAll}>Collapse all</Button>
 <Button variant="secondary" size="sm" onClick={expandAll}>Expand all</Button>
 {can('accounting.coa.manage') && (
 <Link to="/accounting/coa/create">
 <Button variant="primary" size="sm">Add account</Button>
 </Link>
 )}
 </div>
 }
 />

 <div className="px-5 pt-4">
 <FilterBar
 onSearch={(search) => setFilters((f) => ({ ...f, search }))}
 searchPlaceholder="Search account code or name..."
 />
 </div>

 {isLoading && !filteredData && <div className="px-5 py-4"><SkeletonTable columns={5} rows={8} /></div>}

 {isError && (
 <EmptyState
 icon="alert-circle"
 title="Failed to load Chart of Accounts"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 )}

 {filteredData && filteredData.length === 0 && (
 <EmptyState icon="inbox" title="No accounts found" description={filters.search ? `No matches for "${filters.search}".` : "Run the ChartOfAccountsSeeder to install the default 45-account COA."} />
 )}

 {filteredData && filteredData.length > 0 && (
 <div className="px-5 py-4">
 <div className="border border-default rounded-md overflow-hidden">
 {/* Header matches DataTable (bg-canvas + border-b) for consistency. */}
 <div className="grid grid-cols-12 h-row px-2.5 items-center bg-canvas text-2xs uppercase tracking-wider text-muted font-medium border-b border-default">
 <div className="col-span-1">Code</div>
 <div className="col-span-5">Account</div>
 <div className="col-span-2">Type</div>
 <div className="col-span-2 text-right">Debit Total</div>
 <div className="col-span-2 text-right">Balance</div>
 </div>
 <div>
 {filteredData.map((root) => (
 <TreeRow key={root.id} node={root} depth={0} expanded={expanded} onToggle={toggle} canManage={can('accounting.coa.manage')} />
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
 node, depth, expanded, onToggle, canManage,
}: { node: Account; depth: number; expanded: Set<string>; onToggle: (id: string) => void; canManage: boolean }) {
 const hasChildren = (node.children?.length ?? 0) > 0;
 const isOpen = expanded.has(node.id);

 return (
 <>
 <div className={cn('group grid grid-cols-12 h-8 px-2.5 items-center border-b border-subtle hover:bg-subtle text-sm', !node.is_active && 'opacity-60')}>
 <div className="col-span-1 font-mono tabular-nums text-muted">{node.code}</div>
 <div className="col-span-5 flex items-center gap-1.5" style={{ paddingLeft: `${depth * 14}px` }}>
 {hasChildren ? (
 <button
 type="button"
 onClick={() => onToggle(node.id)}
 aria-label={`${isOpen ? 'Collapse' : 'Expand'} ${node.name}`}
 aria-expanded={isOpen}
 className={cn('text-muted hover:text-primary cursor-pointer rounded', focusRing)}
 >
 {isOpen ? <LuChevronDown size={12} /> : <LuChevronRight size={12} />}
 </button>
 ) : (
 <span className="w-3" />
 )}
 <Link
 to={`/accounting/journal-entries?account_id=${node.id}`}
 className={cn('hover:text-accent hover:underline truncate', hasChildren && 'font-medium')}
 title={`View ledger for ${node.name}`}
 >
 {node.name}
 </Link>
 {canManage && (
 <Link
 to={`/accounting/coa/${node.id}/edit`}
 onClick={(e) => e.stopPropagation()}
 className="ml-1 text-xs text-muted hover:text-accent opacity-0 group-hover:opacity-100 transition-opacity"
 >
 Edit
 </Link>
 )}
 {!node.is_active && <Chip variant="neutral">inactive</Chip>}
 </div>
 <div className="col-span-2 text-sm text-muted">{node.type_label ?? node.type} · {node.normal_balance_label ?? node.normal_balance}</div>
 <div className="col-span-2 text-right font-mono tabular-nums">{formatPeso(node.total_debit, '—')}</div>
 <div className="col-span-2 text-right font-mono tabular-nums font-medium">{formatPeso(node.current_balance, '—')}</div>
 </div>
 {isOpen && hasChildren && node.children!.map((c) => (
 <TreeRow key={c.id} node={c} depth={depth + 1} expanded={expanded} onToggle={onToggle} canManage={canManage} />
 ))}
 </>
 );
}
