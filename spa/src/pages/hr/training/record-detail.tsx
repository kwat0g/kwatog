import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import { employeeTrainingsApi } from '@/api/hr/employee-trainings';
import { selfServiceApi } from '@/api/self-service';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { formatDate } from '@/lib/formatDate';

interface TrainingRecordDetailProps {
  selfService?: boolean;
}

export default function TrainingRecordDetailPage({ selfService = false }: TrainingRecordDetailProps) {
  const { id } = useParams<{ id: string }>();
  const { data: record, isLoading, isError, refetch } = useQuery({
    queryKey: ['employee-training', selfService ? 'self' : 'hr', id],
    queryFn: () => selfService ? selfServiceApi.training(id!) : employeeTrainingsApi.show(id!),
    enabled: Boolean(id),
  });

  const date = (value: string | null) => value ? formatDate(value) : 'Not recorded';
  const backTo = selfService ? '/self-service' : record?.employee?.id
    ? `/hr/employees/${record.employee.id}`
    : '/hr/employees';

  return (
    <div>
      <PageHeader
        title="Training record"
        subtitle={record?.training?.name}
        backTo={backTo}
        backLabel={selfService ? 'Self-service' : record?.employee?.full_name ?? 'Employees'}
      />
      <div className="max-w-3xl px-5 py-4">
        {isLoading && (
          <div className="rounded-md border border-default p-5 text-sm text-muted" role="status">
            Loading training record…
          </div>
        )}
        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Training record unavailable"
            description="This record could not be opened. It may have been removed or you may no longer have access."
            action={<Button variant="secondary" onClick={() => void refetch()}>Try again</Button>}
          />
        )}
        {record && (
          <Panel
            title={record.training?.name ?? 'Training'}
            meta={<Chip variant={chipVariantForStatus(record.status)}>{record.status_label}</Chip>}
          >
            <dl className="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
              {!selfService && record.employee && (
                <div>
                  <dt className="text-xs text-muted">Employee</dt>
                  <dd className="mt-1 text-sm text-primary">{record.employee.full_name}</dd>
                </div>
              )}
              <div>
                <dt className="text-xs text-muted">Scheduled</dt>
                <dd className="mt-1 text-sm text-primary">{date(record.scheduled_for)}</dd>
              </div>
              <div>
                <dt className="text-xs text-muted">Completed</dt>
                <dd className="mt-1 text-sm text-primary">{date(record.completed_at)}</dd>
              </div>
              <div>
                <dt className="text-xs text-muted">Expires</dt>
                <dd className="mt-1 text-sm text-primary">{date(record.expires_at)}</dd>
              </div>
            </dl>
          </Panel>
        )}
      </div>
    </div>
  );
}
