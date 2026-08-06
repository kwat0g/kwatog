import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { ArchiveRestore, Printer } from 'lucide-react';
import { journalEntriesApi } from '@/api/accounting/journal-entries';
import { downloadAuthenticatedFile } from '@/api/download';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { Td, Th, tableCls, theadTrCls, totalsTrCls, trCls } from '@/components/ui/table-cells';

const STATUS_VARIANT: Record<string, ChipVariant> = {
 draft: 'warning', posted: 'success', reversed: 'neutral',
};

export default function JournalEntryDetailPage() {
 const { id = '' } = useParams<{ id: string }>();
 const navigate = useNavigate();
 const qc = useQueryClient();
 const { can } = usePermission();
 const [showPost, setShowPost] = useState(false);
 const [showDelete, setShowDelete] = useState(false);
 const [showReverse, setShowReverse] = useState(false);

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
 onError: (e: Error & { response?: { data?: { message?: string } } }) => toast.error(e.response?.data?.message ?? 'Failed to post.'),
 });
 const reverseMut = useMutation({
 mutationFn: () => journalEntriesApi.reverse(id),
 onSuccess: (rev) => {
 toast.success(`Reversal ${rev.entry_number} posted.`);
 qc.invalidateQueries({ queryKey: ['accounting', 'journal-entries'] });
 navigate(`/accounting/journal-entries/${rev.id}`);
 },
 onError: (e: Error & { response?: { data?: { message?: string } } }) => toast.error(e.response?.data?.message ?? 'Failed to reverse.'),
 });
 const deleteMut = useMutation({
  mutationFn: () => journalEntriesApi.delete(id),
  onSuccess: () => {
  toast.success('Draft archived.');
  qc.invalidateQueries({ queryKey: ['accounting', 'journal-entries'] });
  navigate('/accounting/journal-entries');
  },
  onError: (e: Error & { response?: { data?: { message?: string } } }) => toast.error(e.response?.data?.message ?? 'Failed to delete.'),
  });
 const restoreMut = useMutation({
  mutationFn: () => journalEntriesApi.restore(id),
  onSuccess: () => {
  toast.success('Entry restored.');
  qc.invalidateQueries({ queryKey: ['accounting', 'journal-entries'] });
  refetch();
  },
  onError: (e: Error & { response?: { data?: { message?: string } } }) => toast.error(e.response?.data?.message ?? 'Failed to restore entry.'),
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
 <Chip variant={STATUS_VARIANT[je.status] ?? 'neutral'}>{je.status_label ?? je.status}</Chip>
 </div>
 }
 backTo="/accounting/journal-entries"
 backLabel="Journal Entries"
 breadcrumbs={[
 { label: 'Accounting' },
 { label: 'Journal Entries', href: '/accounting/journal-entries' },
 { label: je.entry_number },
 ]}
 actions={
 <div className="flex gap-1.5">
 <Button variant="secondary" size="sm" icon={<Printer size={14} />} onClick={() => void downloadAuthenticatedFile(journalEntriesApi.pdfUrl(je.id), { openInNewTab: true, errorMessage: 'Failed to generate journal entry PDF.' })}>Print</Button>
 {isDraft && can('accounting.journal.post') && (
 <Button variant="primary" size="sm" onClick={() => setShowPost(true)} disabled={postMut.isPending}>
 Post
 </Button>
 )}
{isDraft && can('accounting.journal.create') && (
  <Button variant="danger" size="sm" onClick={() => setShowDelete(true)} disabled={deleteMut.isPending}>
  Delete
  </Button>
  )}
 {can('accounting.journal.create') && (
  <Button variant="secondary" size="sm" icon={<ArchiveRestore size={14} />} onClick={() => restoreMut.mutate()} loading={restoreMut.isPending} disabled={restoreMut.isPending}>
  Restore
  </Button>
  )}
 {isPosted && !je.reversed_by_entry_id && can('accounting.journal.reverse') && (
 <Button variant="secondary" size="sm" onClick={() => setShowReverse(true)} loading={reverseMut.isPending} disabled={reverseMut.isPending}>
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
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>#</Th>
 <Th>Account</Th>
 <Th>Description</Th>
 <Th align="right">Debit</Th>
 <Th align="right">Credit</Th>
 </tr>
 </thead>
 <tbody>
 {je.lines?.map((l) => (
 <tr key={l.line_no} className={trCls}>
 <Td mono className="text-muted">{String(l.line_no).padStart(2, '0')}</Td>
 <Td>
 {l.account ? <span><span className="font-mono text-muted">{l.account.code}</span> · {l.account.name}</span> : '—'}
 </Td>
 <Td className="text-muted">{l.description ?? '—'}</Td>
 <Td align="right" mono>{Number(l.debit) > 0 ? formatPeso(l.debit) : ''}</Td>
 <Td align="right" mono>{Number(l.credit) > 0 ? formatPeso(l.credit) : ''}</Td>
 </tr>
 ))}
 <tr className={totalsTrCls}>
 <Td align="right" mono colSpan={3}>Totals</Td>
 <Td align="right" mono>{formatPeso(je.total_debit)}</Td>
 <Td align="right" mono>{formatPeso(je.total_credit)}</Td>
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

 <ConfirmDialog
 isOpen={showPost}
 onClose={() => setShowPost(false)}
 onConfirm={() => { postMut.mutate(); setShowPost(false); }}
 title="Post journal entry?"
 description="This will update account balances. Posted entries cannot be edited."
 confirmLabel="Post"
 variant="warning"
 pending={postMut.isPending}
 />
 <ConfirmDialog
 isOpen={showDelete}
 onClose={() => setShowDelete(false)}
 onConfirm={() => { deleteMut.mutate(); setShowDelete(false); }}
 title="Archive this draft?"
 description="This journal entry will be archived and can be restored later."
 confirmLabel="Delete"
 variant="danger"
 pending={deleteMut.isPending}
 />
 <ConfirmDialog
 isOpen={showReverse}
 onClose={() => setShowReverse(false)}
 onConfirm={() => { reverseMut.mutate(); setShowReverse(false); }}
 title="Reverse this posted entry?"
 description="A new reversing entry will be created and posted automatically."
 confirmLabel="Reverse"
 variant="warning"
 pending={reverseMut.isPending}
 />
 </div>
 );
}
