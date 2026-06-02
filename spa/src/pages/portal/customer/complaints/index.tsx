import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import toast from 'react-hot-toast';
import { Plus, X, FileText } from 'lucide-react';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import type { EightDReportData } from '@/types/b2b';

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

  if (isLoading) return <SkeletonBlock className="h-64 rounded-lg" />;

  return (
    <div className="space-y-4 max-w-4xl">
      <div className="flex items-center justify-between">
        <h2 className="text-sm font-semibold">Complaints</h2>
        <Button variant="primary" size="sm" icon={showForm ? <X size={14} /> : <Plus size={14} />} onClick={() => setShowForm(!showForm)}>
          {showForm ? 'Cancel' : 'New Complaint'}
        </Button>
      </div>

      {/* New complaint form */}
      {showForm && (
        <Panel title="Submit a Complaint">
          <form onSubmit={(e) => { e.preventDefault(); createMut.mutate(); }} className="flex flex-col gap-3">
            <div>
              <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Severity</label>
              <select value={severity} onChange={(e) => setSeverity(e.target.value)}
                className="w-full rounded-md border border-border bg-canvas px-3 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-accent">
                <option value="minor">Minor</option>
                <option value="major">Major</option>
                <option value="critical">Critical</option>
              </select>
            </div>
            <div>
              <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Description</label>
              <textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={3} required
                className="w-full rounded-md border border-border bg-canvas px-3 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-accent resize-none"
                placeholder="Describe the issue…" />
            </div>
            <div>
              <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Affected Quantity</label>
              <input type="number" value={affectedQty} onChange={(e) => setAffectedQty(e.target.value)} min={0}
                className="w-full rounded-md border border-border bg-canvas px-3 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-accent" />
            </div>
            <Button type="submit" variant="primary" size="sm" loading={createMut.isPending}>
              Submit Complaint
            </Button>
          </form>
        </Panel>
      )}

      {/* Complaints list */}
      <Panel title="Your Complaints">
        {complaints && complaints.length > 0 ? (
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b border-border text-muted">
                <th className="text-left py-2 px-3 font-medium">#</th>
                <th className="text-left py-2 px-3 font-medium">Severity</th>
                <th className="text-left py-2 px-3 font-medium">Description</th>
                <th className="text-right py-2 px-3 font-medium">Qty</th>
                <th className="text-left py-2 px-3 font-medium">Date</th>
                <th className="text-right py-2 px-3 font-medium">Status</th>
                <th className="text-right py-2 px-3 font-medium">8D</th>
              </tr>
            </thead>
            <tbody>
              {complaints.map((c) => (
                <tr key={c.id} className="border-b border-border/50 hover:bg-subtle/50 transition-colors">
                  <td className="py-2.5 px-3 font-mono text-muted">{c.complaint_number}</td>
                  <td className="py-2.5 px-3">
                    <span className={`inline-block px-2 py-0.5 rounded-full text-2xs font-medium ${
                      c.severity === 'critical' ? 'bg-danger/10 text-danger' :
                      c.severity === 'major' ? 'bg-warning/10 text-warning' :
                      'bg-subtle text-muted'
                    }`}>{c.severity}</span>
                  </td>
                  <td className="py-2.5 px-3 max-w-xs truncate">{c.description}</td>
                  <td className="py-2.5 px-3 text-right">{c.affected_quantity}</td>
                  <td className="py-2.5 px-3 text-muted">{c.received_date ?? '—'}</td>
                  <td className="py-2.5 px-3 text-right">
                    <span className={`inline-block px-2 py-0.5 rounded-full text-2xs font-medium uppercase ${
                      c.status === 'closed' ? 'bg-success/10 text-success' :
                      c.status === 'resolved' ? 'bg-accent/10 text-accent' :
                      'bg-warning/10 text-warning'
                    }`}>{c.status}</span>
                  </td>
                  <td className="py-2.5 px-3 text-right">
                    {(c.status === 'resolved' || c.status === 'closed') && (
                      <button
                        onClick={() => open8d(c.id)}
                        className="inline-flex items-center gap-1 text-accent hover:underline text-2xs"
                        title="View 8D Report"
                      >
                        <FileText size={12} /> 8D
                      </button>
                    )}
                  </td>
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
          <div className="bg-canvas border border-default rounded-lg shadow-xl max-w-2xl w-full mx-4" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between px-5 py-3 border-b border-default">
              <div>
                <h3 className="text-sm font-semibold">8D Report &mdash; {viewing8d.complaint_number}</h3>
                <p className="text-2xs text-muted mt-0.5">
                  {viewing8d.severity} &middot; {viewing8d.complaint_status}
                </p>
              </div>
              <button onClick={() => setViewing8d(null)} className="p-1 text-muted hover:text-primary transition-colors">
                <X size={16} />
              </button>
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
                      <h4 className="text-2xs font-semibold uppercase tracking-wide text-muted mb-1.5">{d.label}</h4>
                      <p className="text-xs whitespace-pre-wrap">{d.val ?? '—'}</p>
                    </div>
                  ))}
                  {viewing8d.report.finalized_at && (
                    <p className="text-2xs text-muted text-right">
                      Finalized: {new Date(viewing8d.report.finalized_at).toLocaleString()}
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
