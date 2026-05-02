import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Printer } from 'lucide-react';
import { journalEntriesApi } from '@/api/accounting/journal-entries';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';

const STATUS_VARIANT: Record<string, ChipVariant> = {
  draft: 'warning', posted: 'success', reversed: 'neutral',
};

export default function JournalEntryDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();

  const { data: je, isLoading, isError, refetch } = useQuery({
    queryKey: ['accounting', 'journal-entries', id],
    queryFn: () => journalEntriesApi.show(id),
    enabled: !!id,
  });

  const postMut = useMutation({
    mutationFn: () => journalEntriesApi.post(id),
    onSuccess: () => {
      toast.success('Entry posted.');
      qc.invalidateQueries({ queryKey: ['accounting', 'journal-entries'] });
    },
    onError: (e: any) => toast.error(e.response?.data?.message ?? 'Failed to post.'),
  });
  const reverseMut = useMutation({
    mutationFn: () => journalEntriesApi.reverse(id),
    onSuccess: (rev) => {
      toast.success(`Reversal ${rev.entry_number} posted.`);
      qc.invalidateQueries({ queryKey: ['accounting', 'journal-entries'] });
      navigate(`/accounting/journal-entries/${rev.id}`);
    },
    onError: (e: any) => toast.error(e.response?.data?.message ?? 'Failed to reverse.'),
  });
  const deleteMut = useMutation({
    mutationFn: () => journalEntriesApi.delete(id),
    onSuccess: () => {
      toast.success('Draft deleted.');
      qc.invalidateQueries({ queryKey: ['accounting', 'journal-entries'] });
      navigate('/accounting/journal-entries');
    },
    onError: (e: any) => toast.error(e.response?.data?.message ?? 'Failed to delete.'),
  });

  if (isLoading || (!je && !isError)) return <SkeletonDetail />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load entry" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
  if (!je) return null;

  const isDraft = je.status === 'draft';
  const isPosted = je.status === 'posted';

  return (
    <div>
      <PageHeader
        title={
          <div className="flex items-center gap-3">
            <span className="font-mono">{je.entry_number}</span>
            <Chip variant={STATUS_VARIANT[je.status] ?? 'neutral'}>{je.status}</Chip>
          </div>
        }
        backTo="/accounting/journal-entries"
        backLabel="Journal Entries"
        actions={
          <div className="flex gap-1.5">
            <a href={journalEntriesApi.pdfUrl(je.id)} target="_blank" rel="noreferrer">
              <Button variant="secondary" size="sm" icon={<Printer size={14} />}>Print</Button>
            </a>
            {isDraft && can('accounting.journal.post') && (
              <Button variant="primary" size="sm" onClick={() => postMut.mutate()} loading={postMut.isPending} disabled={postMut.isPending}>
                Post
              </Button>
            )}
            {isDraft && can('accounting.journal.create') && (
              <Button variant="danger" size="sm" onClick={() => { if (confirm('Delete this draft?')) deleteMut.mutate(); }} disabled={deleteMut.isPending}>
                Delete
              </Button>
            )}
            {isPosted && !je.reversed_by_entry_id && can('accounting.journal.reverse') && (
              <Button variant="secondary" size="sm" onClick={() => { if (confirm('Reverse this posted entry?')) reverseMut.mutate(); }} loading={reverseMut.isPending} disabled={reverseMut.isPending}>
                Reverse
              </Button>
            )}
          </div>
        }
      />

      <div className="px-5 py-4 grid grid-cols-3 gap-4">
        <div className="col-span-2 space-y-4">
          <Panel title="Header">
            <dl className="grid grid-cols-3 gap-3 text-sm">
              <div><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">Date</dt><dd className="font-mono">{formatDate(je.date)}</dd></div>
              <div><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">Posted at</dt><dd className="font-mono">{je.posted_at ? formatDate(je.posted_at) : '—'}</dd></div>
              <div><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">Reference</dt><dd>{je.reference_label ?? '—'}</dd></div>
              <div className="col-span-3"><dt className="text-2xs uppercase tracking-wider text-muted mb-0.5">Description</dt><dd>{je.description}</dd></div>
            </dl>
          </Panel>

          <Panel title="Lines">
            <div className="border border-default rounded-md overflow-hidden">
              <table className="w-full text-sm">
                <thead className="text-2xs uppercase tracking-wider text-muted">
                  <tr className="border-b border-default bg-subtle">
                    <th className="h-8 px-2.5 text-left">#</th>
                    <th className="h-8 px-2.5 text-left">Account</th>
                    <th className="h-8 px-2.5 text-left">Description</th>
                    <th className="h-8 px-2.5 text-right">Debit</th>
                    <th className="h-8 px-2.5 text-right">Credit</th>
                  </tr>
                </thead>
                <tbody>
                  {je.lines?.map((l) => (
                    <tr key={l.line_no} className="h-8 border-b border-subtle">
                      <td className="px-2.5 text-muted font-mono tabular-nums">{String(l.line_no).padStart(2, '0')}</td>
                      <td className="px-2.5">
                        {l.account ? <span><span className="font-mono text-muted">{l.account.code}</span> · {l.account.name}</span> : '—'}
                      </td>
                      <td className="px-2.5 text-muted">{l.description ?? '—'}</td>
                      <td className="px-2.5 text-right font-mono tabular-nums">{Number(l.debit) > 0 ? formatPeso(l.debit) : ''}</td>
                      <td className="px-2.5 text-right font-mono tabular-nums">{Number(l.credit) > 0 ? formatPeso(l.credit) : ''}</td>
                    </tr>
                  ))}
                  <tr className="h-8 border-t-2 border-primary font-medium">
                    <td colSpan={3} className="px-2.5 text-right">Totals</td>
                    <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(je.total_debit)}</td>
                    <td className="px-2.5 text-right font-mono tabular-nums">{formatPeso(je.total_credit)}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </Panel>
        </div>

        <div className="col-span-1 space-y-4">
          <Panel title="Audit">
            <dl className="text-xs space-y-2">
              <div><dt className="text-muted">Created by</dt><dd>{je.created_by?.name ?? '—'}</dd></div>
              <div><dt className="text-muted">Posted by</dt><dd>{je.posted_by?.name ?? '—'}</dd></div>
              {je.reversed_by_entry_id && (
                <div><dt className="text-muted">Reversed by</dt><dd className="font-mono">{je.reversed_by_number ?? je.reversed_by_entry_id}</dd></div>
              )}
            </dl>
          </Panel>
        </div>
      </div>
    </div>
  );
}
