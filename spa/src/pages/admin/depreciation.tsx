/** Sprint 8 — Task 70. Manual monthly depreciation runner. Idempotent. */
import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { depreciationApi } from '@/api/assets';
import { Button } from '@/components/ui/Button';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';

export default function DepreciationRunsPage() {
  const now = new Date();
  const [year, setYear] = useState<number>(now.getFullYear());
  const [month, setMonth] = useState<number>(now.getMonth());  // previous month by default (0-indexed = previous)

  const run = useMutation({
    mutationFn: () => depreciationApi.runMonth(year, month),
    onSuccess: (res: any) => {
      const d = res.data ?? res;
      toast.success(`Posted ${d.posted_count ?? 0} entries totalling ₱${d.total_amount ?? '0.00'}.`);
    },
    onError: () => toast.error('Failed to run depreciation.'),
  });

  return (
    <div>
      <PageHeader title="Monthly depreciation" subtitle="Idempotent — re-running for an already-processed month is a no-op." />
      <div className="px-5 py-6 max-w-2xl">
        <Panel title="Run for a period">
          <div className="grid grid-cols-3 gap-3 items-end">
            <div>
              <label className="text-xs text-muted font-medium block mb-1">Year</label>
              <input type="number" value={year} onChange={(e) => setYear(Number(e.target.value))}
                className="h-8 px-3 rounded-md border border-default bg-canvas text-sm w-full font-mono" />
            </div>
            <div>
              <label className="text-xs text-muted font-medium block mb-1">Month (1–12)</label>
              <input type="number" min={1} max={12} value={month} onChange={(e) => setMonth(Number(e.target.value))}
                className="h-8 px-3 rounded-md border border-default bg-canvas text-sm w-full font-mono" />
            </div>
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
