import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { leaveTypesApi } from '@/api/leave';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { usePermission } from '@/hooks/usePermission';
import toast from 'react-hot-toast';

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
  const mutation = useMutation({
    mutationFn: () => leaveTypesApi.processYearEnd(parseInt(year)),
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
          Processes all leave types marked as year-end convertible. Unused days are
          converted to cash, carried over, or forfeited based on each leave type's rules.
        </p>
        <Input
          label="Year"
          type="number"
          min="2020"
          max="2100"
          value={year}
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
          disabled={!canRun || mutation.isPending}
          loading={mutation.isPending}
        >
          {mutation.isPending ? 'Queuing…' : 'Run year-end processing'}
        </Button>
      </ModalFooter>
    </Modal>
  );
}
