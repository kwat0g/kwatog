import { useMemo } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { settingsApi, type SettingRow, type SettingValue } from '@/api/admin/settings';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { Switch } from '@/components/ui/Switch';
import { PageHeader } from '@/components/layout/PageHeader';
import { useAuthStore } from '@/stores/authStore';

const MODULE_LABELS: Record<string, string> = {
  hr: 'HR',
  attendance: 'Attendance',
  leave: 'Leave',
  payroll: 'Payroll',
  loans: 'Loans',
  accounting: 'Accounting',
  inventory: 'Inventory',
  purchasing: 'Purchasing',
  supply_chain: 'Supply Chain',
  production: 'Production',
  mrp: 'MRP / MRP II',
  crm: 'CRM',
  quality: 'Quality',
  maintenance: 'Maintenance',
};

const GROUP_LABELS: Record<string, string> = {
  company: 'Company',
  fiscal: 'Fiscal',
  payroll: 'Payroll',
  approval: 'Approvals',
  modules: 'Modules',
};

export default function SettingsPage() {
  const queryClient = useQueryClient();
  const refreshAuth = useAuthStore((s) => s.refresh);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'settings'],
    queryFn: settingsApi.index,
  });

  const update = useMutation({
    mutationFn: ({ key, value }: { key: string; value: SettingValue }) =>
      settingsApi.update(key, value),
    onSuccess: async (_data, variables) => {
      toast.success(`Saved ${variables.key}`);
      queryClient.invalidateQueries({ queryKey: ['admin', 'settings'] });
      // Module toggles affect what the sidebar shows — refresh user.
      if (variables.key.startsWith('modules.')) {
        await refreshAuth();
      }
    },
    onError: () => toast.error('Could not save setting.'),
  });

  const groups = useMemo(() => {
    if (!data) return [] as Array<[string, SettingRow[]]>;
    const ordered = ['company', 'fiscal', 'payroll', 'approval', 'modules'];
    return ordered
      .filter((g) => Array.isArray(data[g]))
      .map((g) => [g, data[g]] as [string, SettingRow[]]);
  }, [data]);

  return (
    <div>
      <PageHeader
        title="Settings"
        subtitle="Company information, payroll cadence, approvals, and module feature flags"
      />

      <div className="px-5 py-4 max-w-3xl">
        {isLoading && <SkeletonForm />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load settings"
            action={
              <Button variant="secondary" onClick={() => window.location.reload()}>
                Retry
              </Button>
            }
          />
        )}

        {data && groups.map(([group, rows]) => (
          <div key={group} className="mb-6">
            <Panel title={GROUP_LABELS[group] ?? group}>
              <div className="flex flex-col divide-y divide-[var(--border-subtle)]">
                {rows.map((row) => (
                  <SettingRowEditor
                    key={row.key}
                    row={row}
                    isModule={group === 'modules'}
                    saving={update.isPending && update.variables?.key === row.key}
                    onSave={(value) => update.mutate({ key: row.key, value })}
                  />
                ))}
              </div>
            </Panel>
          </div>
        ))}
      </div>
    </div>
  );
}

interface RowEditorProps {
  row: SettingRow;
  isModule: boolean;
  saving: boolean;
  onSave: (value: SettingValue) => void;
}

function SettingRowEditor({ row, isModule, saving, onSave }: RowEditorProps) {
  if (isModule) {
    const slug = row.key.replace('modules.', '');
    return (
      <div className="flex items-center justify-between py-2.5 first:pt-0 last:pb-0">
        <div>
          <div className="text-sm">{MODULE_LABELS[slug] ?? slug}</div>
          <div className="text-xs font-mono text-muted">{row.key}</div>
        </div>
        <Switch
          checked={Boolean(row.value)}
          disabled={saving}
          onChange={(e) => onSave(e.target.checked)}
        />
      </div>
    );
  }

  if (typeof row.value === 'boolean') {
    return (
      <div className="flex items-center justify-between py-2.5">
        <div className="text-sm">{row.key}</div>
        <Switch
          checked={row.value}
          disabled={saving}
          onChange={(e) => onSave(e.target.checked)}
        />
      </div>
    );
  }

  if (typeof row.value === 'number') {
    return (
      <ScalarRow
        row={row}
        type="number"
        saving={saving}
        onSave={(s) => onSave(s === '' ? 0 : Number(s))}
      />
    );
  }

  return (
    <ScalarRow
      row={row}
      type="text"
      saving={saving}
      onSave={(s) => onSave(s)}
    />
  );
}

function ScalarRow({
  row,
  type,
  saving,
  onSave,
}: {
  row: SettingRow;
  type: 'text' | 'number';
  saving: boolean;
  onSave: (s: string) => void;
}) {
  return (
    <div className="grid grid-cols-2 items-center gap-3 py-2.5">
      <div>
        <div className="text-sm">{row.key}</div>
        <div className="text-xs text-muted">{row.group}</div>
      </div>
      <div className="flex items-center gap-2">
        <Input
          type={type}
          defaultValue={String(row.value ?? '')}
          containerClassName="flex-1"
          onBlur={(e) => {
            if (String(row.value ?? '') !== e.currentTarget.value) {
              onSave(e.currentTarget.value);
            }
          }}
          disabled={saving}
        />
      </div>
    </div>
  );
}
