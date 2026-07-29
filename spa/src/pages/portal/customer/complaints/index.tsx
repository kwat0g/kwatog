import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import toast from 'react-hot-toast';
import { Plus, X, FileText } from 'lucide-react';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { formatDateTime } from '@/lib/formatDate';
import type { EightDReportData } from '@/types/b2b';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { LinkButton } from '@/components/ui/LinkButton';

export default function CustomerComplaintsPage() {
  const queryClient = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [severity, setSeverity] = useState('minor');
  const [description, setDescription] = useState('');
  const [affectedQty, setAffectedQty] = useState('0');
  const [viewing8d, setViewing8d] = useState<EightDReportData | null>(null);

  const { data: complaints, isLoading } = useQuery({
    queryKey: ['portal', 'customer', 'complaints'],
    queryFn: () => customerPortalApi.listComplaints(),
    placeholderData: (prev) => prev,
  });

  const createMut = useMutation({
    mutationFn: () => customerPortalApi.createComplaint({
      severity,
      description,
      affected_quantity: parseInt(affectedQty, 10) || 0,
    }),
    onSuccess: (res) => {
      toast.success(res.message ?? 'Complaint submitted.');
      setShowForm(false);
      setDescription('');
      setSeverity('minor');
      setAffectedQty('0');
      queryClient.invalidateQueries({ queryKey: ['portal', 'customer', 'complaints'] });
    },
    onError: () => toast.error('Failed to submit complaint.'),
  });

  const open8d = async (complaintId: string) => {
    try {
      const data = await customerPortalApi.get8dReport(complaintId);
      setViewing8d(data);
    } catch {
      toast.error('No 8D report available for this complaint.');
    }
  };

  if (isLoading) return <SkeletonBlock className="h-64 rounded-md" />;

  return (
    <div className="space-y-4 max-w-4xl">
      <div className="flex items-center justify-between">
        <h2 className="text-sm font-medium">Complaints</h2>
        <Button variant="primary" size="sm" icon={showForm ? <X size={14} /> : <Plus size={14} />} onClick={() => setShowForm(!showForm)}>
          {showForm ? 'Cancel' : 'New complaint'}
        </Button>
      </div>

      {/* New complaint form */}
      {showForm && (
        <Panel title="Submit a complaint">
          <form onSubmit={(e) => { e.preventDefault(); createMut.mutate(); }} className="flex flex-col gap-3">
            <Select label="Severity" value={severity} onChange={(e) => setSeverity(e.target.value)}>
              <option value="minor">Minor</option>
              <option value="major">Major</option>
              <option value="critical">Critical</option>
            </Select>
            <Textarea
              label="Description"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              rows={3}
              required
              placeholder="Describe the issue…"
            />
            <Input
              label="Affected quantity"
              type="number"
              min={0}
              value={affectedQty}
              onChange={(e) => setAffectedQty(e.target.value)}
              className="font-mono tabular-nums"
            />
            <Button type="submit" variant="primary" size="sm" loading={createMut.isPending} className="self-start">
              Submit complaint
            </Button>
          </form>
        </Panel>
      )}

      {/* Complaints list */}
      <Panel title="Your complaints">
        {complaints && complaints.length > 0 ? (
          <table className={tableCls}>
            <thead>
              <tr className={theadTrCls}>
                <Th>#</Th>
                <Th>Severity</Th>
                <Th>Description</Th>
                <Th align="right">Qty</Th>
                <Th>Date</Th>
                <Th align="right">Status</Th>
                <Th align="right">8D</Th>
              </tr>
            </thead>
            <tbody>
              {complaints.map((c) => (
                <tr key={c.id} className={trCls}>
                  <Td mono className="text-muted">{c.complaint_number}</Td>
                  <Td>
                    <span className={`inline-block px-2 py-0.5 rounded-full text-2xs font-medium ${
                      c.severity === 'critical' ? 'bg-danger/10 text-danger' :
                      c.severity === 'major' ? 'bg-warning/10 text-warning' :
                      'bg-subtle text-muted'
                    }`}>{c.severity}</span>
                  </Td>
                  <Td className="max-w-xs truncate">{c.description}</Td>
                  <Td align="right" mono>{c.affected_quantity}</Td>
                  <Td className="text-muted">{c.received_date ?? '—'}</Td>
                  <Td align="right" mono>
                    <span className={`inline-block px-2 py-0.5 rounded-full text-2xs font-medium uppercase ${
                      c.status === 'closed' ? 'bg-success/10 text-success' :
                      c.status === 'resolved' ? 'bg-accent/10 text-accent' :
                      'bg-warning/10 text-warning'
                    }`}>{c.status}</span>
                  </Td>
                  <Td align="right" mono>
                    {(c.status === 'resolved' || c.status === 'closed') && (
                      <LinkButton
                        onClick={() => open8d(c.id)}
                        icon={<FileText size={12} />}
                        className="text-2xs"
                        title="View 8D report"
                      >
                        8D
                      </LinkButton>
                    )}
                  </Td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <EmptyState icon="message-square" title="No complaints" description="Any reported issues will appear here." />
        )}
      </Panel>

      {/* 8D Report Modal */}
      {viewing8d && (
        <div className="fixed inset-0 z-50 flex items-start justify-center pt-12 pb-8 bg-black/40 overflow-y-auto" onClick={() => setViewing8d(null)}>
          <div className="bg-canvas border border-default rounded-md max-w-2xl w-full mx-4" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between px-5 py-3 border-b border-default">
              <div>
                <h3 className="text-sm font-medium">8D Report &mdash; {viewing8d.complaint_number}</h3>
                <p className="text-2xs text-muted mt-0.5">
                  {viewing8d.severity} &middot; {viewing8d.complaint_status}
                </p>
              </div>
              <Button
                variant="ghost"
                size="sm"
                iconOnly
                icon={<X size={16} />}
                aria-label="Close 8D report"
                onClick={() => setViewing8d(null)}
                className="text-muted hover:text-primary"
              />
            </div>
            <div className="p-5 space-y-4 max-h-[70vh] overflow-y-auto">
              <p className="text-xs text-muted">{viewing8d.description}</p>

              {viewing8d.report ? (
                <div className="space-y-3">
                  {[
                    { key: 'd1_team', label: 'D1: Team Members', val: viewing8d.report.d1_team },
                    { key: 'd2_problem', label: 'D2: Problem Description', val: viewing8d.report.d2_problem },
                    { key: 'd3_containment', label: 'D3: Containment Actions', val: viewing8d.report.d3_containment },
                    { key: 'd4_root_cause', label: 'D4: Root Cause Analysis', val: viewing8d.report.d4_root_cause },
                    { key: 'd5_corrective_action', label: 'D5: Corrective Actions', val: viewing8d.report.d5_corrective_action },
                    { key: 'd6_verification', label: 'D6: Verification of Effectiveness', val: viewing8d.report.d6_verification },
                    { key: 'd7_prevention', label: 'D7: Preventive Actions', val: viewing8d.report.d7_prevention },
                    { key: 'd8_recognition', label: 'D8: Recognition & Closure', val: viewing8d.report.d8_recognition },
                  ].map((d) => (
                    <div key={d.key} className="border border-default rounded-md p-3">
                      <h4 className="text-2xs font-medium uppercase tracking-wide text-muted mb-1.5">{d.label}</h4>
                      <p className="text-xs whitespace-pre-wrap">{d.val ?? '—'}</p>
                    </div>
                  ))}
                  {viewing8d.report.finalized_at && (
                    <p className="text-2xs text-muted text-right">
                      Finalized: {formatDateTime(viewing8d.report.finalized_at)}
                    </p>
                  )}
                </div>
              ) : (
                <p className="text-xs text-muted text-center py-4">No 8D report data available yet.</p>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
