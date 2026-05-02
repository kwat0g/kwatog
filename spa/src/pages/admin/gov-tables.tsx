import { useEffect, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Pencil, EyeOff, Eye } from 'lucide-react';
import toast from 'react-hot-toast';
import { govTablesApi, type UpdateGovTableData } from '@/api/admin/gov-tables';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDecimal } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import type { ContributionAgency, GovernmentTable } from '@/types/payroll';

const AGENCIES: { key: ContributionAgency; label: string; help: string }[] = [
  { key: 'sss',        label: 'SSS',        help: 'Flat peso amounts per bracket. EE = employee share, ER = employer share.' },
  { key: 'philhealth', label: 'PhilHealth', help: 'Rate-based premium. Basis = clamp(salary, floor, ceiling) × rate.' },
  { key: 'pagibig',    label: 'Pag-IBIG',   help: 'Rate-based per bracket. Max basis 10,000 → max EE/ER 200.00 each.' },
  { key: 'bir',        label: 'BIR Tax',    help: 'Semi-monthly TRAIN brackets. EE column = fixed_tax, ER column = rate_on_excess.' },
];

export default function AdminGovTablesPage() {
  const [active, setActive] = useState<ContributionAgency>('sss');

  return (
    <div>
      <PageHeader
        title="Government Tables"
        subtitle="SSS, PhilHealth, Pag-IBIG, BIR — used by the payroll engine for contribution calculations"
      />

      <div className="px-5 pt-3">
        <div className="flex items-center gap-1 border-b border-default">
          {AGENCIES.map((a) => (
            <button
              key={a.key}
              onClick={() => setActive(a.key)}
              className={
                'px-3 py-2 text-xs border-b-2 -mb-[1px] ' +
                (active === a.key
                  ? 'text-primary border-accent'
                  : 'text-muted border-transparent hover:text-primary')
              }
            >{a.label}</button>
          ))}
        </div>
      </div>

      <AgencyTable agency={active} />
    </div>
  );
}

function AgencyTable({ agency }: { agency: ContributionAgency }) {
  const qc = useQueryClient();
  const [editing, setEditing] = useState<GovernmentTable | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['gov-tables', agency],
    queryFn: () => govTablesApi.list(agency),
  });

  const deactivate = useMutation({
    mutationFn: (id: string) => govTablesApi.deactivate(id),
    onSuccess: () => {
      toast.success('Bracket deactivated.');
      qc.invalidateQueries({ queryKey: ['gov-tables', agency] });
    },
    onError: () => toast.error('Failed to deactivate bracket.'),
  });

  const activate = useMutation({
    mutationFn: (id: string) => govTablesApi.activate(id),
    onSuccess: () => {
      toast.success('Bracket activated.');
      qc.invalidateQueries({ queryKey: ['gov-tables', agency] });
    },
    onError: () => toast.error('Failed to activate bracket.'),
  });

  const help = AGENCIES.find((a) => a.key === agency)?.help;
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
          <table className="w-full border-collapse text-sm">
            <thead>
              <tr className="border-b border-default bg-canvas">
                <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Min</th>
                <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Max</th>
                <th className="h-8 px-2.5 text-right text-2xs uppercase tracking-wider text-muted font-medium">
                  {isBir ? 'Fixed tax' : 'EE share'}
                </th>
                <th className="h-8 px-2.5 text-right text-2xs uppercase tracking-wider text-muted font-medium">
                  {isBir ? 'Rate on excess' : 'ER share'}
                </th>
                <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Effective</th>
                <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Status</th>
                <th className="h-8 px-2.5 text-right text-2xs uppercase tracking-wider text-muted font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {data.map((row) => (
                <tr key={row.id} className="h-8 border-b border-subtle hover:bg-subtle">
                  <td className="px-2.5 font-mono tabular-nums">{formatDecimal(row.bracket_min)}</td>
                  <td className="px-2.5 font-mono tabular-nums">{formatDecimal(row.bracket_max)}</td>
                  <td className="px-2.5 text-right font-mono tabular-nums">
                    {rateLike ? `${(Number(row.ee_amount) * 100).toFixed(2)}%` : formatDecimal(row.ee_amount)}
                  </td>
                  <td className="px-2.5 text-right font-mono tabular-nums">
                    {agency === 'pagibig' || agency === 'philhealth'
                      ? `${(Number(row.er_amount) * 100).toFixed(2)}%`
                      : isBir
                        ? `${(Number(row.er_amount) * 100).toFixed(2)}%`
                        : formatDecimal(row.er_amount)}
                  </td>
                  <td className="px-2.5 font-mono">{formatDate(row.effective_date)}</td>
                  <td className="px-2.5">
                    <Chip variant={row.is_active ? 'success' : 'neutral'}>
                      {row.is_active ? 'Active' : 'Inactive'}
                    </Chip>
                  </td>
                  <td className="px-2.5 text-right">
                    <div className="flex items-center justify-end gap-1">
                      <Button size="sm" variant="ghost" icon={<Pencil size={12} />}
                        onClick={() => setEditing(row)}>Edit</Button>
                      {row.is_active ? (
                        <Button size="sm" variant="ghost" icon={<EyeOff size={12} />}
                          onClick={() => deactivate.mutate(row.id)}
                          disabled={deactivate.isPending}>Deactivate</Button>
                      ) : (
                        <Button size="sm" variant="ghost" icon={<Eye size={12} />}
                          onClick={() => activate.mutate(row.id)}
                          disabled={activate.isPending}>Activate</Button>
                      )}
                    </div>
                  </td>
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
