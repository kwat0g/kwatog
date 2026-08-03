import { useEffect, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Pencil, EyeOff, Eye } from 'lucide-react';
import toast from 'react-hot-toast';
import { govTablesApi, type UpdateGovTableData } from '@/api/admin/gov-tables';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDecimal } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import type { ContributionAgency, GovernmentTable } from '@/types/payroll';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { Tabs } from '@/components/ui/Tabs';

const AGENCY_HELP: Record<string, string> = {
  sss: 'Flat peso amounts per bracket. EE = employee share, ER = employer share.',
  philhealth: 'Rate-based premium. Basis = clamp(salary, floor, ceiling) × rate.',
  pagibig: 'Rate-based per bracket. Max basis 10,000 → max EE/ER 200.00 each.',
  bir: 'Semi-monthly TRAIN brackets. EE column = fixed_tax, ER column = rate_on_excess.',
};

export default function AdminGovTablesPage() {
  const [active, setActive] = useState<ContributionAgency | ''>('');
  const { data: options } = useQuery({
    queryKey: ['gov-tables', 'options'],
    queryFn: () => govTablesApi.options(),
    staleTime: 5 * 60 * 1000,
  });
  const agencies = options?.agencies ?? [];
  const selectedAgency = active || agencies[0]?.value || '';

  return (
    <div>
      <PageHeader
        title="Government Tables"
        subtitle="SSS, PhilHealth, Pag-IBIG, BIR — used by the payroll engine for contribution calculations"
        backTo="/payroll/periods"
        backLabel="Payroll"
      />

      <div className="px-5 pt-3">
        <Tabs
          items={agencies.map((a) => ({ key: a.value, label: a.label }))}
          value={selectedAgency}
          onChange={(value) => setActive(value as ContributionAgency)}
          label="Contribution agency"
        />
      </div>

      {selectedAgency && <AgencyTable agency={selectedAgency as ContributionAgency} />}
    </div>
  );
}

function AgencyTable({ agency }: { agency: ContributionAgency }) {
  const qc = useQueryClient();
  const [editing, setEditing] = useState<GovernmentTable | null>(null);
  const [confirmDeactivate, setConfirmDeactivate] = useState<string | null>(null);
  const [confirmActivate, setConfirmActivate] = useState<string | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['gov-tables', agency],
    queryFn: () => govTablesApi.list(agency),
  });

  const deactivate = useMutation({
    mutationFn: (id: string) => govTablesApi.deactivate(id),
    onSuccess: () => {
      toast.success('Bracket deactivated.');
      qc.invalidateQueries({ queryKey: ['gov-tables', agency] });
      setConfirmDeactivate(null);
    },
    onError: () => toast.error('Failed to deactivate bracket.'),
  });

  const activate = useMutation({
    mutationFn: (id: string) => govTablesApi.activate(id),
    onSuccess: () => {
      toast.success('Bracket activated.');
      qc.invalidateQueries({ queryKey: ['gov-tables', agency] });
      setConfirmActivate(null);
    },
    onError: () => toast.error('Failed to activate bracket.'),
  });

  const help = AGENCY_HELP[agency];
  const rateLike = agency === 'philhealth' || agency === 'pagibig';
  const isBir = agency === 'bir';

  return (
    <div className="px-5 py-4">
      {help && <p className="text-xs text-muted mb-3">{help}</p>}

      {isLoading && <SkeletonTable columns={7} rows={6} />}
      {isError && (
        <EmptyState icon="alert-circle" title="Failed to load brackets"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
      )}
      {data && data.length === 0 && <EmptyState icon="inbox" title="No brackets seeded for this agency" />}
      {data && data.length > 0 && (
        <div className="border border-default rounded-md overflow-hidden">
          <table className={tableCls}>
            <thead>
              <tr className={theadTrCls}>
                <Th>Min</Th>
                <Th>Max</Th>
                <Th align="right">
                  {isBir ? 'Fixed tax' : 'EE share'}
                </Th>
                <Th align="right">
                  {isBir ? 'Rate on excess' : 'ER share'}
                </Th>
                <Th>Effective</Th>
                <Th>Status</Th>
                <Th align="right">Actions</Th>
              </tr>
            </thead>
            <tbody>
              {data.map((row) => (
                <tr key={row.id} className={trCls}>
                  <Td mono>{formatDecimal(row.bracket_min)}</Td>
                  <Td mono>{formatDecimal(row.bracket_max)}</Td>
                  <Td align="right" mono>
                    {rateLike ? `${(Number(row.ee_amount) * 100).toFixed(2)}%` : formatDecimal(row.ee_amount)}
                  </Td>
                  <Td align="right" mono>
                    {agency === 'pagibig' || agency === 'philhealth'
                      ? `${(Number(row.er_amount) * 100).toFixed(2)}%`
                      : isBir
                        ? `${(Number(row.er_amount) * 100).toFixed(2)}%`
                        : formatDecimal(row.er_amount)}
                  </Td>
                  <Td mono>{formatDate(row.effective_date)}</Td>
                  <Td>
                    <Chip variant={row.is_active ? 'success' : 'neutral'}>
                      {row.is_active ? 'Active' : 'Inactive'}
                    </Chip>
                  </Td>
                  <Td align="right" mono>
                    <div className="flex items-center justify-end gap-1">
                      <Button size="sm" variant="ghost" icon={<Pencil size={12} />}
                        onClick={() => setEditing(row)}>Edit</Button>
                      {row.is_active ? (
                        <Button size="sm" variant="ghost" icon={<EyeOff size={12} />}
                          onClick={() => setConfirmDeactivate(row.id)}
                          disabled={deactivate.isPending}>Deactivate</Button>
                      ) : (
                        <Button size="sm" variant="ghost" icon={<Eye size={12} />}
                          onClick={() => setConfirmActivate(row.id)}
                          disabled={activate.isPending}>Activate</Button>
                      )}
                    </div>
                  </Td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <EditBracketModal
        bracket={editing}
        onClose={() => setEditing(null)}
        agency={agency}
      />

      <ConfirmDialog
        isOpen={confirmDeactivate !== null}
        onClose={() => setConfirmDeactivate(null)}
        onConfirm={() => { if (confirmDeactivate) deactivate.mutate(confirmDeactivate); }}
        title="Deactivate this bracket?"
        variant="warning"
        confirmLabel="Deactivate"
        pending={deactivate.isPending}
      />
      <ConfirmDialog
        isOpen={confirmActivate !== null}
        onClose={() => setConfirmActivate(null)}
        onConfirm={() => { if (confirmActivate) activate.mutate(confirmActivate); }}
        title="Activate this bracket?"
        variant="primary"
        confirmLabel="Activate"
        pending={activate.isPending}
      />
    </div>
  );
}

function EditBracketModal({
  bracket, onClose, agency,
}: { bracket: GovernmentTable | null; onClose: () => void; agency: ContributionAgency }) {
  const qc = useQueryClient();
  const [data, setData] = useState<UpdateGovTableData>({});

  // Reset form when the target bracket changes.
  useEffect(() => { setData({}); }, [bracket?.id]);

  const mutation = useMutation({
    mutationFn: (payload: UpdateGovTableData) => govTablesApi.update(bracket!.id, payload),
    onSuccess: () => {
      toast.success('Bracket updated.');
      qc.invalidateQueries({ queryKey: ['gov-tables', agency] });
      onClose();
    },
    onError: () => toast.error('Failed to update bracket.'),
  });

  if (!bracket) return null;

  return (
    <Modal isOpen={!!bracket} onClose={onClose} size="md" title={`Edit ${agency.toUpperCase()} bracket`}>
      <div className="py-3 grid grid-cols-2 gap-3">
        <Input label="Bracket min"   defaultValue={bracket.bracket_min}
          onChange={(e) => setData((d) => ({ ...d, bracket_min: e.target.value }))} className="font-mono" />
        <Input label="Bracket max"   defaultValue={bracket.bracket_max}
          onChange={(e) => setData((d) => ({ ...d, bracket_max: e.target.value }))} className="font-mono" />
        <Input label={agency === 'bir' ? 'Fixed tax' : 'EE amount'} defaultValue={bracket.ee_amount}
          onChange={(e) => setData((d) => ({ ...d, ee_amount: e.target.value }))} className="font-mono" />
        <Input label={agency === 'bir' ? 'Rate on excess' : 'ER amount'} defaultValue={bracket.er_amount}
          onChange={(e) => setData((d) => ({ ...d, er_amount: e.target.value }))} className="font-mono" />
        <Input label="Effective date" type="date" defaultValue={bracket.effective_date}
          onChange={(e) => setData((d) => ({ ...d, effective_date: e.target.value }))} />
      </div>
      <p className="text-xs text-muted">
        Editing changes will affect future payroll runs immediately. Historical payrolls remain unchanged because they store the raw computed amounts.
      </p>
      <div className="flex justify-end gap-2 pt-3 border-t border-default">
        <Button variant="secondary" onClick={onClose} disabled={mutation.isPending}>Cancel</Button>
        <Button variant="primary"
          onClick={() => mutation.mutate(data)}
          disabled={mutation.isPending} loading={mutation.isPending}>
          Save changes
        </Button>
      </div>
    </Modal>
  );
}
