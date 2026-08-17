/**
 * DepreciationRunner — monthly depreciation runner as a modal.
 *
 * Folded off the /admin/depreciation page 2026-08-08 (scope cut): it was a
 * single-action settings chore wearing a sidebar slot under Administration,
 * despite being an asset operation (permission assets.depreciation.view).
 * Now it lives on the Fixed Assets page behind a header button. Idempotent —
 * re-running for an already-processed month is a no-op.
 */
import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { depreciationApi } from '@/api/assets';
import { Button } from '@/components/ui/Button';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Input } from '@/components/ui/Input';
import { PendingHint } from '@/components/ui/PendingHint';
import { formatPeso } from '@/lib/formatNumber';export function DepreciationRunner({ isOpen, onClose }: { isOpen: boolean; onClose: () => void }) {
 const now = new Date();
 const [year, setYear] = useState<number>(now.getFullYear());
 // Defaults to the PREVIOUS month: `getMonth()` is 0-indexed, and the input is
 // 1-based, so August (getMonth() = 7) renders as "7" = July. Kept from the
 // original /admin/depreciation page. Re-running a processed month is a no-op.
 const [month, setMonth] = useState<number>(now.getMonth());

  const run = useMutation({
    mutationFn: () => depreciationApi.runMonth(year, month),
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    onSuccess: (res: any) => {
      const d = res.data ?? res;
      toast.success(`Posted ${d.posted_count ?? '—'} entries totalling ${formatPeso(d.total_amount)}.`);
      onClose();
    },
    onError: () => toast.error('Failed to run depreciation.'),
  });

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Run monthly depreciation" size="sm">
      <div className="space-y-4 px-5 py-4">
        <p className="text-xs text-muted">
          Posts a single consolidated journal entry: <span className="font-mono">DR Depreciation Expense</span> /{' '}
          <span className="font-mono">CR Accumulated Depreciation</span>. Idempotent — re-running an
          already-processed month is a no-op.
        </p>
        <div className="grid grid-cols-2 gap-3">
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
        </div>
      </div>
      <div className="px-5 pb-2">
        <PendingHint active={run.isPending} label="the depreciation post" />
      </div>
      <ModalFooter>
        <Button variant="secondary" onClick={onClose} disabled={run.isPending}>
          Cancel
        </Button>
        <Button variant="primary" onClick={() => run.mutate()} loading={run.isPending} disabled={month < 1 || month > 12}>
          {run.isPending ? 'Running…' : 'Run depreciation'}
        </Button>
      </ModalFooter>
    </Modal>
  );
}
