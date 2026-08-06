import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { leaveTypesApi } from '@/api/leave';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import toast from 'react-hot-toast';

export default function YearEndLeavePage() {
  const { can } = usePermission();
  // The backend gates POST /process-year-end on leave.types.manage; mirror it
  // here so the button is inert rather than failing after the click.
  const canRun = can('leave.types.manage');
  const [year, setYear] = useState(new Date().getFullYear().toString());
  const mutation = useMutation({
    mutationFn: () => leaveTypesApi.processYearEnd(parseInt(year)),
    onSuccess: (data: any) => {
      toast.success(data?.message ?? 'Year-end processing queued.');
    },
    onError: () => toast.error('Failed to queue year-end processing.'),
  });
  return (
    <div>
      <PageHeader title="Year-End Leave Processing" backTo="/hr/leaves" backLabel="Leaves" />
      <div className="max-w-lg mx-auto px-5 py-4">
        <Panel title="Convert / forfeit leave balances" meta="Queues a background job. Results appear after processing completes.">
          <div className="space-y-3">
            <p className="text-sm text-muted">
              Processes all leave types marked as year-end convertible. Unused days are
              converted to cash, carried over, or forfeited based on each leave type's rules.
            </p>
            <Input label="Year" type="number" min="2020" max="2100" value={year} onChange={(e) => setYear(e.target.value)} />
            <Button
              variant="primary"
              onClick={() => mutation.mutate()}
              disabled={!canRun || mutation.isPending}
              loading={mutation.isPending}
            >
              {mutation.isPending ? 'Queuing…' : 'Run year-end processing'}
            </Button>
          </div>
        </Panel>
      </div>
    </div>
  );
}