/** Sprint 8 — Task 70. Manual monthly depreciation runner. Idempotent. */
import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import toast from 'react-hot-toast';
import { depreciationApi } from '@/api/assets';
import { Button } from '@/components/ui/Button';
import { Panel } from '@/components/ui/Panel';
import { Input } from '@/components/ui/Input';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';

export default function DepreciationRunsPage() {
 const now = new Date();
 const previousMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1);
 const [year, setYear] = useState<number>(previousMonth.getFullYear());
 const [month, setMonth] = useState<number>(previousMonth.getMonth() + 1);

 const run = useMutation({
 mutationFn: () => depreciationApi.runMonth(year, month),
 onSuccess: (res) => {
 const d = (res.data ?? res) as { posted_count?: number; total_amount?: string };
 toast.success(`Posted ${d.posted_count ?? '—'} entries totalling ${formatPeso(d.total_amount)}.`);
 },
 onError: (error) => toast.error(isAxiosError(error) ? error.response?.data?.message ?? 'Failed to run depreciation.' : 'Failed to run depreciation.'),
 });

 return (
 <div>
 <PageHeader title="Monthly depreciation" subtitle="Idempotent — re-running for an already-processed month is a no-op." />
 <div className="px-5 py-4 max-w-2xl">
 <Panel title="Run for a period">
 <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 items-end">
 <Input
 label="Year"
 type="number"
 value={year}
 onChange={(e) => setYear(Number(e.target.value))}
 className="font-mono tabular-nums"
 />
 <Input
 label="Month (1–12)"
 type="number"
 min={1}
 max={12}
 value={month}
 onChange={(e) => setMonth(Number(e.target.value))}
 className="font-mono tabular-nums"
 />
 <Button variant="primary" onClick={() => run.mutate()} loading={run.isPending}
 disabled={month < 1 || month > 12}>
 {run.isPending ? 'Running…' : 'Run depreciation'}
 </Button>
 </div>
 <p className="text-xs text-muted mt-3">
 Posts a single consolidated journal entry: <span className="font-mono">DR Depreciation Expense</span> /
 <span className="font-mono ml-1">CR Accumulated Depreciation</span>.
 </p>
 </Panel>
 </div>
 </div>
 );
}
