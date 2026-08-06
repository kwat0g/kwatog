import { useQuery } from '@tanstack/react-query';
import { Button, Chip, EmptyState, SkeletonTable, Td, Th } from '@/components/ui';
import { PageHeader } from '@/components/layout/PageHeader';
import { sodApi, type SodSeverity } from '@/api/admin/sod';
import { tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { cn } from '@/lib/cn';

const SEVERITY_CHIP: Record<SodSeverity, 'danger' | 'warning' | 'info'> = {
 high: 'danger',
 medium: 'warning',
 low: 'info',
};

/**
 * REC-01 — Segregation-of-Duties matrix + "who violates SoD today" report.
 * The matrix declares incompatible permission pairs; the report scans every
 * active non-admin user and flags anyone holding both sides of a rule.
 */
export default function SodMatrixPage() {
 const matrix = useQuery({
 queryKey: ['admin', 'sod', 'matrix'],
 queryFn: () => sodApi.matrix(),
 });

 const violations = useQuery({
 queryKey: ['admin', 'sod', 'violations'],
 queryFn: () => sodApi.violations(),
 });

 return (
 <div>
 <PageHeader
 title="Segregation of Duties"
 subtitle="Incompatible permission pairs and the users who currently violate them"
 />

 <div className="px-5 py-4 space-y-8">
 {/* Violation report — the audit artifact, shown first. */}
 <section>
 <h2 className="text-sm font-medium mb-2">Who violates SoD today</h2>
 {violations.isLoading && <SkeletonTable columns={3} rows={3} />}
 {violations.isError && (
 <EmptyState
 icon="alert-circle"
 title="Failed to load report"
 action={
 <Button variant="secondary" onClick={() => violations.refetch()}>
 Retry
 </Button>
 }
 />
 )}
 {violations.data && violations.data.data.length === 0 && (
 <div className="border border-default rounded-md bg-canvas px-4 py-6 text-center text-sm text-muted">
 No active user holds a conflicting pair. Segregation of duties is clean.
 </div>
 )}
 {violations.data && violations.data.data.length > 0 && (
 <div className="border border-default rounded-md bg-canvas overflow-hidden">
 <div className="px-4 py-2 border-b border-default text-xs text-muted">
 {violations.data.meta.total_users_flagged} user(s) flagged (system_admin excluded — break-glass)
 </div>
 {violations.data.data.map((row) => (
 <div key={row.user.id} className="px-4 py-3 border-b border-default last:border-0">
 <div className="flex items-center gap-3 text-sm">
 <span className="font-medium">{row.user.name}</span>
 <span className="text-muted text-xs">{row.user.role ?? '—'}</span>
 <span className="font-mono tabular-nums text-muted text-xs">{row.user.email}</span>
 </div>
 <div className="mt-1.5 flex flex-wrap gap-1.5">
 {row.violations.map((v) => (
 <Chip key={v.code} variant={SEVERITY_CHIP[v.severity]}>
 {v.name} · {v.severity_label ?? v.severity}
 </Chip>
 ))}
 </div>
 </div>
 ))}
 </div>
 )}
 </section>

 {/* The matrix itself. */}
 <section>
 <h2 className="text-sm font-medium mb-2">Conflict matrix</h2>
 {matrix.isLoading && <SkeletonTable columns={4} rows={6} />}
 {matrix.isError && (
 <EmptyState
 icon="alert-circle"
 title="Failed to load matrix"
 action={
 <Button variant="secondary" onClick={() => matrix.refetch()}>
 Retry
 </Button>
 }
 />
 )}
 {matrix.data && (
 <div className="border border-default rounded-md bg-canvas overflow-hidden">
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>Conflict</Th>
 <Th>Permission A</Th>
 <Th>Permission B</Th>
 <Th>Severity</Th>
 </tr>
 </thead>
 <tbody>
 {matrix.data.map((rule) => (
 <tr
 key={rule.id}
 className={cn(trCls, !rule.active && 'opacity-50')}
 >
 <Td>
 <div className="font-medium">{rule.name}</div>
 {rule.rationale && (
 <div className="text-xs text-muted mt-0.5">{rule.rationale}</div>
 )}
 </Td>
 <Td mono className="text-xs">{rule.permission_a.slug}</Td>
 <Td mono className="text-xs">{rule.permission_b.slug}</Td>
 <Td>
 <Chip variant={SEVERITY_CHIP[rule.severity]}>{rule.severity_label ?? rule.severity}</Chip>
 </Td>
 </tr>
 ))}
 </tbody>
 </table>
 </div>
 )}
 </section>
 </div>
 </div>
 );
}
