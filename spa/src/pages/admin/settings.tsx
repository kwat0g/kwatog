import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import {
  Building2,
  Calendar,
  Banknote,
  CheckCircle2,
  Shield,
  Puzzle,
  Search,
} from 'lucide-react';
import { settingsApi, type SettingRow, type SettingValue } from '@/api/admin/settings';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { Switch } from '@/components/ui/Switch';
import { PageHeader } from '@/components/layout/PageHeader';
import { useAuthStore } from '@/stores/authStore';

const MODULE_LABELS: Record<string, string> = {
  hr: 'Human Resources',
  attendance: 'Attendance',
  leave: 'Leave Management',
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
  assets: 'Assets',
  search: 'Global Search',
  notifications: 'Notifications',
  recruitment: 'Recruitment',
  return_management: 'Return Management',
  b2b_portals: 'B2B Portals',
  forecasting: 'Forecasting',
  budgeting: 'Budgeting',
};

const MODULE_DEPENDENCIES: Record<string, string[]> = {
  budgeting: ['accounting'],
  forecasting: ['inventory', 'crm'],
  recruitment: ['hr'],
  payroll: ['hr', 'attendance'],
  loans: ['hr', 'payroll'],
  leave: ['hr'],
  attendance: ['hr'],
  quality: ['production', 'inventory'],
  production: ['mrp', 'inventory'],
  supply_chain: ['inventory', 'purchasing'],
  maintenance: ['production'],
  b2b_portals: ['crm', 'accounting'],
  return_management: ['crm', 'inventory'],
};

function getEnabledDependents(moduleSlug: string, allModuleRows: SettingRow[]): string[] {
  return Object.entries(MODULE_DEPENDENCIES)
    .filter(([, deps]) => deps.includes(moduleSlug))
    .filter(([mod]) => {
      const row = allModuleRows.find((r) => r.key === `modules.${mod}`);
      return row && Boolean(row.value);
    })
    .map(([mod]) => MODULE_LABELS[mod] ?? mod);
}

interface GroupMeta {
  label: string;
  description: string;
  icon: React.ReactNode;
}

const GROUP_META: Record<string, GroupMeta> = {
  company: {
    label: 'Company',
    description: 'Organization identity used on documents and reports',
    icon: <Building2 size={16} />,
  },
  fiscal: {
    label: 'Fiscal',
    description: 'Fiscal year configuration',
    icon: <Calendar size={16} />,
  },
  payroll: {
    label: 'Payroll',
    description: 'Pay schedule and payslip delivery',
    icon: <Banknote size={16} />,
  },
  approval: {
    label: 'Approvals',
    description: 'Approval thresholds and auto-resolve behavior',
    icon: <CheckCircle2 size={16} />,
  },
  accounting: {
    label: 'Accounting',
    description: 'Default accounts and automated collection',
    icon: <Banknote size={16} />,
  },
  attendance: {
    label: 'Attendance',
    description: 'Overtime detection from biometric data',
    icon: <Calendar size={16} />,
  },
  hr: {
    label: 'HR',
    description: 'Hiring and employee provisioning',
    icon: <Building2 size={16} />,
  },
  purchasing: {
    label: 'Purchasing',
    description: 'Three-way matching tolerances',
    icon: <Banknote size={16} />,
  },
  inventory: {
    label: 'Inventory',
    description: 'Stock policies and safety stock calculation',
    icon: <Puzzle size={16} />,
  },
  security: {
    label: 'Security',
    description: 'Login policies, session timeouts, and password rules',
    icon: <Shield size={16} />,
  },
  modules: {
    label: 'Modules',
    description: 'Enable or disable entire modules for all users',
    icon: <Puzzle size={16} />,
  },
};

const GROUP_ORDER = [
  'company',
  'fiscal',
  'payroll',
  'approval',
  'accounting',
  'attendance',
  'hr',
  'purchasing',
  'inventory',
  'security',
  'modules',
];

export default function SettingsPage() {
  const queryClient = useQueryClient();
  const refreshAuth = useAuthStore((s) => s.refresh);
  const [search, setSearch] = useState('');

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
      if (variables.key.startsWith('modules.')) {
        await refreshAuth();
      }
    },
    onError: () => toast.error('Could not save setting.'),
  });

  const groups = useMemo(() => {
    if (!data) return [] as Array<[string, SettingRow[]]>;
    const q = search.toLowerCase().trim();
    return GROUP_ORDER
      .filter((g) => Array.isArray(data[g]) && data[g].length > 0)
      .map((g) => {
        const rows = q
          ? data[g].filter((r: SettingRow) =>
              r.key.toLowerCase().includes(q) ||
              (r.label ?? '').toLowerCase().includes(q) ||
              (r.description ?? '').toLowerCase().includes(q),
            )
          : data[g];
        return [g, rows] as [string, SettingRow[]];
      })
      .filter(([, rows]) => rows.length > 0);
  }, [data, search]);

  return (
    <div>
      <PageHeader
        title="Settings"
        subtitle="Company information, payroll, approvals, security policies, and module feature flags"
      />

      <div className="px-5 py-4 max-w-3xl space-y-6">
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

        {data && (
          <>
            <div className="relative max-w-sm">
              <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted" />
              <Input
                placeholder="Search settings..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="pl-9"
              />
            </div>

            {groups.length === 0 && search && (
              <EmptyState
                icon="search"
                title="No settings found"
                description={`No settings match "${search}".`}
              />
            )}

            {groups.map(([group, rows]) => {
              const meta = GROUP_META[group];
              return (
                <SettingsGroup
                  key={group}
                  group={group}
                  meta={meta}
                  rows={rows}
                  saving={update.isPending ? update.variables?.key : undefined}
                  onSave={(key, value) => update.mutate({ key, value })}
                />
              );
            })}
          </>
        )}
      </div>
    </div>
  );
}

interface SettingsGroupProps {
  group: string;
  meta?: GroupMeta;
  rows: SettingRow[];
  saving?: string;
  onSave: (key: string, value: SettingValue) => void;
}

function SettingsGroup({ group, meta, rows, saving, onSave }: SettingsGroupProps) {
  const isModule = group === 'modules';

  return (
    <Panel
      title={
        <span className="flex items-center gap-2">
          {meta && <span className="text-muted">{meta.icon}</span>}
          <span>{meta?.label ?? group}</span>
        </span>
      }
    >
      {meta?.description && (
        <p className="text-xs text-muted -mt-1 mb-3">{meta.description}</p>
      )}
      <div className="flex flex-col divide-y divide-[var(--border-subtle)]">
        {rows.map((row) => (
          <SettingRowEditor
            key={row.key}
            row={row}
            isModule={isModule}
            allModuleRows={isModule ? rows : undefined}
            isSaving={saving === row.key}
            onSave={(value) => onSave(row.key, value)}
          />
        ))}
      </div>
    </Panel>
  );
}

interface RowEditorProps {
  row: SettingRow;
  isModule: boolean;
  allModuleRows?: SettingRow[];
  isSaving: boolean;
  onSave: (value: SettingValue) => void;
}

function SettingRowEditor({ row, isModule, allModuleRows, isSaving, onSave }: RowEditorProps) {
  const [confirmToggle, setConfirmToggle] = useState<{
    key: string;
    currentValue: boolean;
  } | null>(null);

  const label = row.label ?? row.key;
  const description = row.description;

  if (isModule) {
    const slug = row.key.replace('modules.', '');
    const displayLabel = row.label ?? MODULE_LABELS[slug] ?? slug;
    const isEnabled = Boolean(row.value);
    const dependents = isEnabled && allModuleRows
      ? getEnabledDependents(slug, allModuleRows)
      : [];

    return (
      <>
        <div className="flex items-center justify-between py-3 first:pt-0 last:pb-0">
          <div className="flex-1 min-w-0 pr-4">
            <div className="text-sm font-medium">{displayLabel}</div>
            <div className="text-2xs font-mono text-muted mt-0.5">{row.key}</div>
            {description && (
              <div className="text-xs text-muted mt-1">{description}</div>
            )}
            <ChangeAttribution row={row} />
          </div>
          <Switch
            checked={isEnabled}
            disabled={isSaving}
            onChange={() =>
              setConfirmToggle({ key: row.key, currentValue: isEnabled })
            }
          />
        </div>
        <ConfirmDialog
          isOpen={confirmToggle?.key === row.key}
          title={
            confirmToggle?.currentValue
              ? `Disable ${displayLabel}?`
              : `Enable ${displayLabel}?`
          }
          description={
            confirmToggle?.currentValue
              ? (dependents.length > 0
                  ? `Disabling ${displayLabel} may affect these enabled modules that depend on it: ${dependents.join(', ')}. All ${displayLabel} pages will become inaccessible for all users. Existing data is preserved.`
                  : `All ${displayLabel} pages will become inaccessible for all users. Existing data is preserved and will be visible again when re-enabled.`)
              : `${displayLabel} pages will become accessible to users with the appropriate permissions.`
          }
          confirmLabel={confirmToggle?.currentValue ? 'Disable' : 'Enable'}
          variant={confirmToggle?.currentValue ? 'danger' : 'primary'}
          onConfirm={() => {
            onSave(!confirmToggle!.currentValue);
            setConfirmToggle(null);
          }}
          onClose={() => setConfirmToggle(null)}
          pending={isSaving}
        />
      </>
    );
  }

  if (typeof row.value === 'boolean') {
    return (
      <div className="flex items-center justify-between py-3">
        <div className="flex-1 min-w-0 pr-4">
          <div className="text-sm font-medium">{label}</div>
          <div className="text-2xs font-mono text-muted mt-0.5">{row.key}</div>
          {description && (
            <div className="text-xs text-muted mt-1">{description}</div>
          )}
          <ChangeAttribution row={row} />
        </div>
        <Switch
          checked={row.value}
          disabled={isSaving}
          onChange={(e) => onSave(e.target.checked)}
        />
      </div>
    );
  }

  if (typeof row.value === 'number') {
    return (
      <ScalarRow
        row={row}
        label={label}
        description={description}
        type="number"
        saving={isSaving}
        onSave={(s) => onSave(s === '' ? 0 : Number(s))}
      />
    );
  }

  return (
    <ScalarRow
      row={row}
      label={label}
      description={description}
      type="text"
      saving={isSaving}
      onSave={(s) => onSave(s)}
    />
  );
}

function ChangeAttribution({ row }: { row: SettingRow }) {
  if (!row.updated_by_name || !row.updated_at) return null;
  return (
    <div className="text-2xs text-muted mt-1">
      Last changed by {row.updated_by_name} &middot;{' '}
      {new Date(row.updated_at).toLocaleDateString()}
    </div>
  );
}

function ScalarRow({
  row,
  label,
  description,
  type,
  saving,
  onSave,
}: {
  row: SettingRow;
  label: string;
  description: string | null;
  type: 'text' | 'number';
  saving: boolean;
  onSave: (s: string) => void;
}) {
  return (
    <div className="grid grid-cols-[1fr_auto] items-start gap-4 py-3">
      <div className="min-w-0">
        <div className="text-sm font-medium">{label}</div>
        <div className="text-2xs font-mono text-muted mt-0.5">{row.key}</div>
        {description && (
          <div className="text-xs text-muted mt-1">{description}</div>
        )}
        <ChangeAttribution row={row} />
      </div>
      <div className="w-48">
        <Input
          type={type}
          defaultValue={String(row.value ?? '')}
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
