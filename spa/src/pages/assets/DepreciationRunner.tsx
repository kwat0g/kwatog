/**
 * DepreciationRunner — monthly depreciation runner as a modal.
 *
 * Folded off the /admin/depreciation page 2026-08-08 (scope cut): it was a
 * single-action settings chore wearing a sidebar slot under Administration,
 * despite being an asset operation (permission assets.depreciation.run).
 * Now it lives on the Fixed Assets page behind a header button. Idempotent —
 * re-running for an already-processed month is a no-op.
 *
 * The backfill arm (AS-02/AS-07) exists because a backdated acquisition can
 * leave gaps in months that are already posted; the plain run then refuses
 * with "run the explicit backfill workflow first", and before this checkbox
 * the SPA had no way to send it.
 */
import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import toast from 'react-hot-toast';
import { depreciationApi } from '@/api/assets';
import { Button } from '@/components/ui/Button';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Input } from '@/components/ui/Input';
import { Checkbox } from '@/components/ui/Checkbox';
import { PendingHint } from '@/components/ui/PendingHint';
import { formatPeso } from '@/lib/formatNumber';

export function DepreciationRunner({ isOpen, onClose }: { isOpen: boolean; onClose: () => void }) {
 const now = new Date();
 const previousMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1);
 const [year, setYear] = useState<number>(previousMonth.getFullYear());
 // Date#getMonth() is zero-based; the API input is the human 1–12 month.
 const [month, setMonth] = useState<number>(previousMonth.getMonth() + 1);
 const [backfill, setBackfill] = useState(false);

 const run = useMutation({
  mutationFn: () => depreciationApi.runMonth(year, month, backfill),
  onSuccess: (res) => {
   const d = (res.data ?? res) as { posted_count?: number; total_amount?: string; processed_periods?: number };
   const entries = `${d.posted_count ?? '—'} ${d.posted_count === 1 ? 'entry' : 'entries'}`;
   toast.success(
    backfill
     ? `Backfilled ${d.processed_periods ?? '—'} periods through the target; last month posted ${entries} totalling ${formatPeso(d.total_amount)}.`
     : `Posted ${entries} totalling ${formatPeso(d.total_amount)}.`,
   );
   onClose();
  },
  onError: (error) => {
   const message = isAxiosError(error) ? error.response?.data?.message ?? 'Failed to run depreciation.' : 'Failed to run depreciation.';
   // The completeness guard names the backfill workflow as the remedy; arm it
   // so the operator's next click actually runs it.
   if (/backfill/i.test(message)) setBackfill(true);
   toast.error(message);
  },
 });

 const runLabel = run.isPending ? (backfill ? 'Backfilling…' : 'Running…') : backfill ? 'Run backfill' : 'Run depreciation';

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
    <Checkbox
     name="backfill"
     checked={backfill}
     onChange={(e) => setBackfill(e.target.checked)}
     label={
      <span>
       Backfill through the target month — repairs gaps in already-posted months (e.g. a backdated asset)
       with supplemental journals.
      </span>
     }
    />
   </div>
   <div className="px-5 pb-2">
    <PendingHint active={run.isPending} label={backfill ? 'the depreciation backfill' : 'the depreciation post'} />
   </div>
   <ModalFooter>
    <Button variant="secondary" onClick={onClose} disabled={run.isPending}>
     Cancel
    </Button>
    <Button variant="primary" onClick={() => run.mutate()} loading={run.isPending} disabled={month < 1 || month > 12}>
     {runLabel}
    </Button>
   </ModalFooter>
  </Modal>
 );
}
