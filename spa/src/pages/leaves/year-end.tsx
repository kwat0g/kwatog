import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { leaveTypesApi } from '@/api/leave';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { usePermission } from '@/hooks/usePermission';
import toast from 'react-hot-toast';

// The PHP API owns the checked-in machine-readable contract at
// api/resources/contracts/leave-year-end-validation.json. These browser-side
// values are parity-tested against it because the API image and SPA artifact
// are built separately.
const YEAR_END_MIN_YEAR = 2020;
const YEAR_END_MAX_YEAR = 2099;
const YEAR_END_YEAR_ERROR = `Enter a whole year from ${YEAR_END_MIN_YEAR} through ${YEAR_END_MAX_YEAR}.`;

function parseSupportedYear(value: string): number | null {
  if (!/^[0-9]{4}$/.test(value)) return null;

  const year = Number(value);
  return Number.isSafeInteger(year) && year >= YEAR_END_MIN_YEAR && year <= YEAR_END_MAX_YEAR
    ? year
    : null;
}

/**
 * Year-end leave processing — rendered inside the "Year-End Leave" modal on the
 * Leave page (scope cut 2026-08-08: the standalone page was a 47-LOC one-button
 * page, so it became a dialog instead of a sidebar destination). Same pattern as
 * LeaveTypesManager and ThirteenthMonthModal. Queues a background job; results
 * appear after processing completes.
 */
export function YearEndLeaveModal({
  open,
  onClose,
  onSuccess,
}: {
  open: boolean;
  onClose: () => void;
  onSuccess?: () => void;
}) {
  const { can } = usePermission();
  // The backend gates POST /process-year-end on leave.types.manage; mirror it
  // here so the button is inert rather than failing after the click.
  const canRun = can('leave.types.manage');
  const [year, setYear] = useState(new Date().getFullYear().toString());
  const selectedYear = parseSupportedYear(year);
  const mutation = useMutation({
    mutationFn: () => {
      if (selectedYear === null) throw new Error(YEAR_END_YEAR_ERROR);

      return leaveTypesApi.processYearEnd(selectedYear);
    },
    onSuccess: (data) => {
      toast.success(data?.message ?? 'Year-end processing queued.');
      onSuccess?.();
      onClose();
    },
    onError: () => toast.error('Failed to queue year-end processing.'),
  });

  return (
    <Modal isOpen={open} onClose={onClose} size="sm" title="Year-End Leave Processing">
      <div className="space-y-3 py-2">
        <p className="text-sm text-muted">
          Processes all active leave types. Unused days are converted to cash for types
          configured for year-end conversion; otherwise they are carried over or forfeited
          based on each leave type's rules.
        </p>
        <Input
          label="Year"
          type="number"
          min={YEAR_END_MIN_YEAR}
          max={YEAR_END_MAX_YEAR}
          value={year}
          error={selectedYear === null ? YEAR_END_YEAR_ERROR : undefined}
          onChange={(e) => setYear(e.target.value)}
        />
      </div>
      <ModalFooter>
        <Button variant="secondary" onClick={onClose} disabled={mutation.isPending}>
          Cancel
        </Button>
        <Button
          variant="primary"
          onClick={() => mutation.mutate()}
          disabled={!canRun || mutation.isPending || selectedYear === null}
          loading={mutation.isPending}
        >
          {mutation.isPending ? 'Queuing…' : 'Run year-end processing'}
        </Button>
      </ModalFooter>
    </Modal>
  );
}
