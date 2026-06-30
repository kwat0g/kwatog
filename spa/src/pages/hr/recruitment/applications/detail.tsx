import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowRight, XCircle, Calendar, Download, MessageSquare, UserPlus } from 'lucide-react';
import { cn } from '@/lib/cn';
import { recruitmentApi } from '@/api/recruitment';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate, formatDateTime } from '@/lib/formatDate';
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

const STAGE_LABEL: Record<ApplicationStage, string> = {
  new: 'New',
  screening: 'Screening',
  interview: 'Interview',
  offer: 'Offer',
  hired: 'Hired',
  rejected: 'Rejected',
};

const PIPELINE_STAGES: ApplicationStage[] = ['new', 'screening', 'interview', 'offer', 'hired'];

const NEXT_STAGE_LABEL: Partial<Record<ApplicationStage, string>> = {
  new: 'Move to Screening',
  screening: 'Move to Interview',
  interview: 'Move to Offer',
  offer: 'Mark as Hired',
};

export default function ApplicationDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { can } = usePermission();

  const [showRejectDialog, setShowRejectDialog] = useState(false);
  const [rejectionReason, setRejectionReason] = useState('');
  const [showAdvanceConfirm, setShowAdvanceConfirm] = useState(false);
  const [showAdvanceInterview, setShowAdvanceInterview] = useState(false);
  const [showInterviewForm, setShowInterviewForm] = useState(false);
  const [interviewData, setInterviewData] = useState({ scheduled_at: '', location: '', interviewer_name: '' });
  const [noteBody, setNoteBody] = useState('');

  const { data: application, isLoading, isError, refetch } = useQuery({
    queryKey: ['recruitment-application', id],
    queryFn: () => recruitmentApi.showApplication(id!).then((r) => r.data.data),
    enabled: !!id,
  });

  const advanceMutation = useMutation({
    mutationFn: (interview?: { scheduled_at: string; location?: string; interviewer_name: string }) =>
      recruitmentApi.changeStage(id!, { action: 'advance', interview }),
    onSuccess: () => {
      setShowAdvanceConfirm(false);
      setShowAdvanceInterview(false);
      setInterviewData({ scheduled_at: '', location: '', interviewer_name: '' });
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

  if (isLoading) return <SkeletonDetail />;
  if (isError || !application) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Application not found"
        description="The record may have been deleted or you don't have access."
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
      />
    );
  }

  const isTerminal = application.stage === 'hired' || application.stage === 'rejected';
  const currentIdx = PIPELINE_STAGES.indexOf(application.stage as ApplicationStage);

  return (
    <div>
      <PageHeader
        title={
          <span className="flex items-center gap-2">
            {application.full_name}
            <Chip variant={STAGE_CHIP[application.stage]}>{STAGE_LABEL[application.stage]}</Chip>
          </span>
        }
        subtitle={<span className="font-mono">{application.application_number} · {application.job_posting?.title ?? 'Unknown'}</span>}
        backTo="/hr/recruitment/applications"
        backLabel="Applications"
        breadcrumbs={[
          { label: 'HR', href: '/hr' },
          { label: 'Recruitment', href: '/hr/recruitment' },
          { label: 'Applications', href: '/hr/recruitment/applications' },
          { label: application.full_name },
        ]}
        actions={
          can('hr.recruitment.applications') && !isTerminal ? (
            <>
              <Button
                size="sm"
                icon={<ArrowRight size={12} />}
                onClick={() => application.stage === 'screening' ? setShowAdvanceInterview(true) : setShowAdvanceConfirm(true)}
                disabled={advanceMutation.isPending}
                loading={advanceMutation.isPending}
              >
                {NEXT_STAGE_LABEL[application.stage] ?? 'Advance'}
              </Button>
              <Button variant="danger" size="sm" icon={<XCircle size={12} />} onClick={() => setShowRejectDialog(true)}>
                Reject
              </Button>
            </>
          ) : application.stage === 'hired' && can('hr.recruitment.hire') && !application.converted_employee ? (
            <Button size="sm" icon={<UserPlus size={12} />} onClick={() => navigate(`/hr/employees/create?from_application=${id}`)}>
              Convert to Employee
            </Button>
          ) : undefined
        }
      />

      {/* Pipeline stepper */}
      <div className="px-5 py-3">
        <div className="flex items-center gap-1">
          {PIPELINE_STAGES.map((stage, idx) => {
            const isActive = idx === currentIdx;
            const isDone = idx < currentIdx;
            return (
              <div key={stage} className="flex items-center gap-1">
                <div
                  className={cn(
                    'rounded-full px-3 py-1 text-xs font-medium transition-colors',
                    isActive
                      ? 'bg-accent text-white'
                      : isDone
                      ? 'bg-success/10 text-success'
                      : 'bg-elevated text-muted',
                  )}
                >
                  {STAGE_LABEL[stage]}
                </div>
                {idx < PIPELINE_STAGES.length - 1 && (
                  <div className={cn('h-0.5 w-4', isDone ? 'bg-success/40' : 'bg-border')} />
                )}
              </div>
            );
          })}
          {application.stage === 'rejected' && (
            <Chip variant="danger" className="ml-2">Rejected at {STAGE_LABEL[application.rejected_at_stage as ApplicationStage] ?? application.rejected_at_stage}</Chip>
          )}
        </div>
        {!isTerminal && (
          <p className="mt-1.5 text-xs text-muted px-5">
            {application.stage === 'new' && 'Review the application and advance to screening when ready.'}
            {application.stage === 'screening' && 'Screen the candidate. You\'ll need to schedule an interview to advance.'}
            {application.stage === 'interview' && 'Schedule and conduct interviews. Advance to offer when interviews are complete.'}
            {application.stage === 'offer' && 'Extend an offer to the candidate. Mark as hired once accepted.'}
          </p>
        )}
      </div>

      {/* Reject dialog */}
      {showRejectDialog && (
        <div className="mx-5 mb-4 rounded-md border border-danger/30 bg-danger/5 p-4">
          <p className="text-sm font-medium">Rejection reason:</p>
          <Textarea
            className="mt-2"
            value={rejectionReason}
            onChange={(e) => setRejectionReason(e.target.value)}
            rows={3}
            placeholder="Provide a reason..."
          />
          <div className="mt-3 flex gap-2">
            <Button variant="danger" size="sm" onClick={() => rejectMutation.mutate(rejectionReason)} disabled={rejectMutation.isPending} loading={rejectMutation.isPending}>
              Confirm Reject
            </Button>
            <Button variant="ghost" size="sm" onClick={() => setShowRejectDialog(false)}>Cancel</Button>
          </div>
        </div>
      )}

      {/* Advance to interview — schedule interview form */}
      {showAdvanceInterview && (
        <div className="mx-5 mb-4 rounded-md border border-accent/30 bg-accent/5 p-4">
          <p className="text-sm font-medium mb-3">Schedule an interview to move this applicant to the interview stage:</p>
          <div className="space-y-2">
            <Input
              label="Date & Time"
              type="datetime-local"
              required
              value={interviewData.scheduled_at}
              onChange={(e) => setInterviewData((d) => ({ ...d, scheduled_at: e.target.value }))}
            />
            <Input
              label="Location"
              value={interviewData.location}
              onChange={(e) => setInterviewData((d) => ({ ...d, location: e.target.value }))}
              placeholder="Room 201 / Zoom link"
            />
            <Input
              label="Interviewer"
              required
              value={interviewData.interviewer_name}
              onChange={(e) => setInterviewData((d) => ({ ...d, interviewer_name: e.target.value }))}
              placeholder="Full name"
            />
          </div>
          <div className="mt-3 flex gap-2">
            <Button
              size="sm"
              onClick={() => advanceMutation.mutate({
                scheduled_at: new Date(interviewData.scheduled_at).toISOString(),
                location: interviewData.location || undefined,
                interviewer_name: interviewData.interviewer_name,
              })}
              disabled={!interviewData.scheduled_at || !interviewData.interviewer_name || advanceMutation.isPending}
              loading={advanceMutation.isPending}
            >
              Schedule & Move to Interview
            </Button>
            <Button variant="ghost" size="sm" onClick={() => setShowAdvanceInterview(false)}>Cancel</Button>
          </div>
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4 px-5 pb-4">
        <div className="space-y-4">
          {/* Contact */}
          <Panel title="Contact Information">
            <dl className="grid gap-3 sm:grid-cols-2 text-sm">
              <DetailItem label="Email">{application.email}</DetailItem>
              <DetailItem label="Phone">{application.phone}</DetailItem>
            </dl>
            {application.cover_letter && (
              <div className="mt-4 pt-3 border-t border-default">
                <dt className="text-2xs uppercase tracking-wider text-muted font-medium">Cover Letter</dt>
                <dd className="mt-1 whitespace-pre-line text-sm">{application.cover_letter}</dd>
              </div>
            )}
            <div className="mt-4 pt-3 border-t border-default">
              <Button
                variant="secondary"
                size="sm"
                icon={<Download size={12} />}
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
                Download Resume
              </Button>
            </div>
          </Panel>

          {/* Interviews */}
          <Panel
            title={`Interviews (${application.interviews?.length ?? 0})`}
            actions={
              can('hr.recruitment.applications') && application.stage === 'interview' ? (
                <Button variant="ghost" size="sm" icon={<Calendar size={12} />} onClick={() => setShowInterviewForm(!showInterviewForm)}>
                  Schedule
                </Button>
              ) : undefined
            }
          >
            {showInterviewForm && (
              <div className="mb-4 space-y-2 rounded-md border border-default p-3 bg-elevated">
                <Input
                  label="Date & Time"
                  type="datetime-local"
                  required
                  value={interviewData.scheduled_at}
                  onChange={(e) => setInterviewData((d) => ({ ...d, scheduled_at: e.target.value }))}
                />
                <Input
                  label="Location"
                  value={interviewData.location}
                  onChange={(e) => setInterviewData((d) => ({ ...d, location: e.target.value }))}
                  placeholder="Room 201 / Zoom link"
                />
                <Input
                  label="Interviewer"
                  required
                  value={interviewData.interviewer_name}
                  onChange={(e) => setInterviewData((d) => ({ ...d, interviewer_name: e.target.value }))}
                  placeholder="Full name"
                />
                <div className="flex gap-2 pt-1">
                  <Button
                    size="sm"
                    onClick={() => interviewMutation.mutate({
                      scheduled_at: new Date(interviewData.scheduled_at).toISOString(),
                      location: interviewData.location || undefined,
                      interviewer_name: interviewData.interviewer_name,
                    })}
                    disabled={!interviewData.scheduled_at || !interviewData.interviewer_name || interviewMutation.isPending}
                    loading={interviewMutation.isPending}
                  >
                    Save
                  </Button>
                  <Button variant="ghost" size="sm" onClick={() => setShowInterviewForm(false)}>Cancel</Button>
                </div>
              </div>
            )}

            {application.interviews?.length ? (
              <ul className="divide-y divide-default">
                {application.interviews.map((iv: ApplicationInterview) => (
                  <li key={iv.id} className="flex items-center justify-between py-2.5">
                    <div>
                      <p className="text-sm font-medium">{iv.interviewer_name}</p>
                      <p className="text-xs text-muted font-mono tabular-nums">
                        {formatDateTime(iv.scheduled_at)}
                        {iv.location && ` · ${iv.location}`}
                      </p>
                    </div>
                    {iv.outcome && (
                      <Chip variant={iv.outcome === 'passed' ? 'success' : iv.outcome === 'failed' ? 'danger' : 'neutral'}>
                        {iv.outcome}
                      </Chip>
                    )}
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-muted">No interviews scheduled.</p>
            )}
          </Panel>

          {/* Notes */}
          <Panel
            title={
              <span className="flex items-center gap-1.5">
                <MessageSquare size={14} />
                Notes ({application.notes?.length ?? 0})
              </span>
            }
          >
            {can('hr.recruitment.applications') && (
              <div className="mb-4 flex gap-2">
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
                  loading={noteMutation.isPending}
                >
                  Add
                </Button>
              </div>
            )}

            {application.notes?.length ? (
              <ul className="divide-y divide-default">
                {application.notes.map((note) => (
                  <li key={note.id} className="py-2.5">
                    <p className="text-sm">{note.body}</p>
                    <p className="mt-1 text-xs text-muted">
                      {note.user.name} · <span className="font-mono tabular-nums">{formatDateTime(note.created_at)}</span>
                    </p>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-muted">No notes yet.</p>
            )}
          </Panel>
        </div>

        {/* Sidebar */}
        <div className="space-y-4">
          <Panel title="At a glance">
            <dl className="text-sm space-y-2">
              <DetailItem label="Stage">
                <Chip variant={STAGE_CHIP[application.stage]}>{STAGE_LABEL[application.stage]}</Chip>
              </DetailItem>
              <DetailItem label="Tracking Code">
                <span className="font-mono text-xs">{application.tracking_code}</span>
              </DetailItem>
              <DetailItem label="Applied">
                <span className="font-mono tabular-nums">{formatDate(application.applied_at)}</span>
              </DetailItem>
            </dl>

            {application.rejection_reason && (
              <div className="mt-3 rounded-md bg-danger/5 p-3 border border-danger/20">
                <span className="text-2xs uppercase tracking-wider text-danger font-medium">Rejection reason</span>
                <p className="mt-1 text-xs">{application.rejection_reason}</p>
              </div>
            )}

            {application.converted_employee && (
              <div className="mt-3 rounded-md bg-success/5 p-3 border border-success/20">
                <span className="text-2xs uppercase tracking-wider text-success font-medium">Converted to employee</span>
                <p className="mt-1 text-xs font-mono">{application.converted_employee.employee_no}</p>
              </div>
            )}
          </Panel>
        </div>
      </div>

      <ConfirmDialog
        isOpen={showAdvanceConfirm}
        onClose={() => setShowAdvanceConfirm(false)}
        onConfirm={() => advanceMutation.mutate(undefined)}
        title="Advance to next stage?"
        description="The applicant will move to the next recruitment stage."
        confirmLabel="Advance"
        variant="warning"
        pending={advanceMutation.isPending}
      />
    </div>
  );
}

function DetailItem({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-2xs uppercase tracking-wider text-muted font-medium">{label}</dt>
      <dd className="mt-0.5">{children}</dd>
    </div>
  );
}
