import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowRight, XCircle, Calendar, Download, MessageSquare, UserPlus } from 'lucide-react';
import { recruitmentApi } from '@/api/recruitment';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { usePermission } from '@/hooks/usePermission';
import toast from 'react-hot-toast';
import type { ApplicationStage, ApplicationInterview } from '@/types/recruitment';

const STAGE_CHIP: Record<ApplicationStage, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
  new: 'neutral',
  screening: 'info',
  interview: 'warning',
  offer: 'info',
  hired: 'success',
  rejected: 'danger',
};

const PIPELINE_STAGES: ApplicationStage[] = ['new', 'screening', 'interview', 'offer', 'hired'];

export default function ApplicationDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { can } = usePermission();

  const [showRejectDialog, setShowRejectDialog] = useState(false);
  const [rejectionReason, setRejectionReason] = useState('');
  const [showInterviewForm, setShowInterviewForm] = useState(false);
  const [interviewData, setInterviewData] = useState({ scheduled_at: '', location: '', interviewer_name: '' });
  const [noteBody, setNoteBody] = useState('');

  const { data: application, isLoading } = useQuery({
    queryKey: ['recruitment-application', id],
    queryFn: () => recruitmentApi.showApplication(id!).then((r) => r.data.data),
    enabled: !!id,
  });

  const advanceMutation = useMutation({
    mutationFn: () => recruitmentApi.changeStage(id!, { action: 'advance' }),
    onSuccess: () => {
      toast.success('Application advanced.');
      queryClient.invalidateQueries({ queryKey: ['recruitment-application', id] });
    },
    onError: () => toast.error('Failed to advance.'),
  });

  const rejectMutation = useMutation({
    mutationFn: (reason: string) => recruitmentApi.changeStage(id!, { action: 'reject', rejection_reason: reason }),
    onSuccess: () => {
      toast.success('Application rejected.');
      setShowRejectDialog(false);
      queryClient.invalidateQueries({ queryKey: ['recruitment-application', id] });
    },
    onError: () => toast.error('Failed to reject.'),
  });

  const interviewMutation = useMutation({
    mutationFn: (data: { scheduled_at: string; location?: string; interviewer_name: string }) =>
      recruitmentApi.scheduleInterview(id!, data),
    onSuccess: () => {
      toast.success('Interview scheduled.');
      setShowInterviewForm(false);
      setInterviewData({ scheduled_at: '', location: '', interviewer_name: '' });
      queryClient.invalidateQueries({ queryKey: ['recruitment-application', id] });
    },
    onError: () => toast.error('Failed to schedule interview.'),
  });

  const noteMutation = useMutation({
    mutationFn: (body: string) => recruitmentApi.addNote(id!, body),
    onSuccess: () => {
      toast.success('Note added.');
      setNoteBody('');
      queryClient.invalidateQueries({ queryKey: ['recruitment-application', id] });
    },
    onError: () => toast.error('Failed to add note.'),
  });

  if (isLoading) return <SkeletonTable rows={5} columns={3} />;
  if (!application) return <p className="text-muted">Application not found.</p>;

  const isTerminal = application.stage === 'hired' || application.stage === 'rejected';
  const currentIdx = PIPELINE_STAGES.indexOf(application.stage as ApplicationStage);

  return (
    <div>
      <PageHeader
        title={application.full_name}
        subtitle={`${application.application_number} · Applied for ${application.job_posting?.title ?? 'Unknown'}`}
        actions={
          can('hr.recruitment.applications') && !isTerminal ? (
            <div className="flex gap-2">
              <Button size="sm" onClick={() => advanceMutation.mutate()} disabled={advanceMutation.isPending}>
                <ArrowRight size={14} /> Advance
              </Button>
              <Button variant="danger" size="sm" onClick={() => setShowRejectDialog(true)}>
                <XCircle size={14} /> Reject
              </Button>
            </div>
          ) : application.stage === 'hired' && can('hr.recruitment.hire') && !application.converted_employee ? (
            <Button onClick={() => navigate(`/hr/employees/create?from_application=${id}`)}>
              <UserPlus size={14} /> Convert to Employee
            </Button>
          ) : undefined
        }
      />

      {/* Pipeline stepper */}
      <div className="mt-6 flex items-center gap-1">
        {PIPELINE_STAGES.map((stage, idx) => {
          const isActive = idx === currentIdx;
          const isDone = idx < currentIdx;
          return (
            <div key={stage} className="flex items-center gap-1">
              <div
                className={`rounded-full px-3 py-1 text-xs font-medium ${
                  isActive
                    ? 'bg-foreground text-background'
                    : isDone
                    ? 'bg-emerald-100 text-emerald-800'
                    : 'bg-muted/30 text-muted'
                }`}
              >
                {stage}
              </div>
              {idx < PIPELINE_STAGES.length - 1 && (
                <div className={`h-0.5 w-4 ${isDone ? 'bg-emerald-300' : 'bg-border'}`} />
              )}
            </div>
          );
        })}
        {application.stage === 'rejected' && (
          <Chip variant="danger" className="ml-2">Rejected at {application.rejected_at_stage}</Chip>
        )}
      </div>

      {/* Reject dialog */}
      {showRejectDialog && (
        <div className="mt-4 rounded-lg border border-danger/30 bg-danger/5 p-4">
          <p className="text-sm font-medium">Rejection reason:</p>
          <Textarea
            className="mt-2"
            value={rejectionReason}
            onChange={(e) => setRejectionReason(e.target.value)}
            rows={3}
            placeholder="Provide a reason..."
          />
          <div className="mt-3 flex gap-2">
            <Button variant="danger" size="sm" onClick={() => rejectMutation.mutate(rejectionReason)} disabled={rejectMutation.isPending}>
              Confirm Reject
            </Button>
            <Button variant="ghost" size="sm" onClick={() => setShowRejectDialog(false)}>Cancel</Button>
          </div>
        </div>
      )}

      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        {/* Main info */}
        <div className="space-y-6 lg:col-span-2">
          <section className="rounded-lg border border-border p-4">
            <h3 className="text-sm font-semibold uppercase tracking-wide text-muted">Contact Information</h3>
            <div className="mt-3 grid gap-2 sm:grid-cols-2 text-sm">
              <div><span className="text-muted">Email:</span> {application.email}</div>
              <div><span className="text-muted">Phone:</span> {application.phone}</div>
            </div>
            {application.cover_letter && (
              <div className="mt-3">
                <span className="text-sm text-muted">Cover Letter:</span>
                <p className="mt-1 whitespace-pre-line text-sm">{application.cover_letter}</p>
              </div>
            )}
            <div className="mt-3">
              <Button
                variant="secondary"
                size="sm"
                onClick={() => {
                  recruitmentApi.downloadResume(id!).then((res) => {
                    const url = URL.createObjectURL(res.data);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'resume';
                    a.click();
                    URL.revokeObjectURL(url);
                  });
                }}
              >
                <Download size={14} /> Download Resume
              </Button>
            </div>
          </section>

          {/* Interviews */}
          <section className="rounded-lg border border-border p-4">
            <div className="flex items-center justify-between">
              <h3 className="text-sm font-semibold uppercase tracking-wide text-muted">Interviews</h3>
              {can('hr.recruitment.applications') && (
                <Button variant="ghost" size="sm" onClick={() => setShowInterviewForm(!showInterviewForm)}>
                  <Calendar size={14} /> Schedule
                </Button>
              )}
            </div>

            {showInterviewForm && (
              <div className="mt-3 space-y-2 rounded border border-border p-3">
                <Input
                  type="datetime-local"
                  value={interviewData.scheduled_at}
                  onChange={(e) => setInterviewData((d) => ({ ...d, scheduled_at: e.target.value }))}
                  placeholder="Date & time"
                />
                <Input
                  value={interviewData.location}
                  onChange={(e) => setInterviewData((d) => ({ ...d, location: e.target.value }))}
                  placeholder="Location (optional)"
                />
                <Input
                  value={interviewData.interviewer_name}
                  onChange={(e) => setInterviewData((d) => ({ ...d, interviewer_name: e.target.value }))}
                  placeholder="Interviewer name"
                />
                <Button
                  size="sm"
                  onClick={() => interviewMutation.mutate({
                    scheduled_at: new Date(interviewData.scheduled_at).toISOString(),
                    location: interviewData.location || undefined,
                    interviewer_name: interviewData.interviewer_name,
                  })}
                  disabled={!interviewData.scheduled_at || !interviewData.interviewer_name || interviewMutation.isPending}
                >
                  Save Interview
                </Button>
              </div>
            )}

            <div className="mt-3 space-y-2">
              {application.interviews?.length ? (
                application.interviews.map((iv: ApplicationInterview) => (
                  <div key={iv.id} className="flex items-center justify-between rounded bg-muted/20 px-3 py-2 text-sm">
                    <div>
                      <p className="font-medium">{iv.interviewer_name}</p>
                      <p className="text-xs text-muted">
                        {new Date(iv.scheduled_at).toLocaleString()}
                        {iv.location && ` · ${iv.location}`}
                      </p>
                    </div>
                    {iv.outcome && (
                      <Chip variant={iv.outcome === 'passed' ? 'success' : iv.outcome === 'failed' ? 'danger' : 'neutral'}>
                        {iv.outcome}
                      </Chip>
                    )}
                  </div>
                ))
              ) : (
                <p className="text-sm text-muted">No interviews scheduled.</p>
              )}
            </div>
          </section>

          {/* Notes */}
          <section className="rounded-lg border border-border p-4">
            <h3 className="text-sm font-semibold uppercase tracking-wide text-muted">
              <MessageSquare size={14} className="inline mr-1" /> Notes
            </h3>

            {can('hr.recruitment.applications') && (
              <div className="mt-3 flex gap-2">
                <Textarea
                  value={noteBody}
                  onChange={(e) => setNoteBody(e.target.value)}
                  placeholder="Add a note..."
                  rows={2}
                  className="flex-1"
                />
                <Button
                  size="sm"
                  onClick={() => { if (noteBody.trim()) noteMutation.mutate(noteBody.trim()); }}
                  disabled={!noteBody.trim() || noteMutation.isPending}
                >
                  Add
                </Button>
              </div>
            )}

            <div className="mt-3 space-y-2">
              {application.notes?.length ? (
                application.notes.map((note) => (
                  <div key={note.id} className="rounded bg-muted/20 px-3 py-2 text-sm">
                    <p>{note.body}</p>
                    <p className="mt-1 text-xs text-muted">
                      {note.user.name} · {new Date(note.created_at).toLocaleString()}
                    </p>
                  </div>
                ))
              ) : (
                <p className="text-sm text-muted">No notes yet.</p>
              )}
            </div>
          </section>
        </div>

        {/* Sidebar info */}
        <div className="space-y-3 rounded-lg border border-border p-4 h-fit">
          <div className="flex items-center justify-between">
            <span className="text-sm text-muted">Stage</span>
            <Chip variant={STAGE_CHIP[application.stage]}>{application.stage_label}</Chip>
          </div>
          <div className="flex items-center justify-between">
            <span className="text-sm text-muted">Tracking Code</span>
            <span className="font-mono text-xs">{application.tracking_code}</span>
          </div>
          <div className="flex items-center justify-between">
            <span className="text-sm text-muted">Applied</span>
            <span className="font-mono text-xs tabular-nums">{new Date(application.applied_at).toLocaleDateString()}</span>
          </div>
          {application.rejection_reason && (
            <div className="mt-2 rounded bg-danger/5 p-2">
              <span className="text-xs font-medium text-danger">Rejection reason:</span>
              <p className="mt-1 text-xs">{application.rejection_reason}</p>
            </div>
          )}
          {application.converted_employee && (
            <div className="mt-2 rounded bg-emerald-50 p-2">
              <span className="text-xs font-medium text-emerald-800">Converted to employee:</span>
              <p className="mt-1 text-xs font-mono">{application.converted_employee.employee_no}</p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
