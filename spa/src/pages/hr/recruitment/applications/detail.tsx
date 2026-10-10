import { reportMutationError, onFormInvalid } from '@/lib/formErrors';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { LuArrowRight, LuCircleX, LuCircleCheck, LuCalendar, LuDownload, LuMessageSquare, LuUserPlus } from '@/lib/icons';
import { recruitmentApi } from '@/api/recruitment';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { Select } from '@/components/ui/Select';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { ActivityStream } from '@/components/ui/ActivityStream';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { localIsoDateTime, formatDate, formatDateTime } from '@/lib/formatDate';
import toast from 'react-hot-toast';
import { cn } from '@/lib/cn';
import type {
 ApplicationStage,
 ApplicationInterview,
 InterviewOutcome,
 RecruitmentApplicationEvent,
} from '@/types/recruitment';

const STAGE_CHIP: Record<ApplicationStage, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
 new: 'neutral',
 screening: 'info',
 interview: 'warning',
 offer: 'info',
 hired: 'success',
 rejected: 'danger',
};

export default function ApplicationDetailPage() {
 const { id } = useParams<{ id: string }>();
 const navigate = useNavigate();
 const queryClient = useQueryClient();
 const { can } = usePermission();

 const [showRejectDialog, setShowRejectDialog] = useState(false);
 const [rejectionReason, setRejectionReason] = useState('');
 const [showAdvanceConfirm, setShowAdvanceConfirm] = useState(false);
 const [interviewMode, setInterviewMode] = useState<'advance' | 'schedule' | null>(null);
 const [noteBody, setNoteBody] = useState('');
 const [isDownloading, setIsDownloading] = useState(false);

 const { data: application, isLoading, isError, refetch } = useQuery({
 queryKey: ['recruitment-application', id],
 queryFn: () => recruitmentApi.showApplication(id!).then((r) => r.data.data),
 enabled: !!id,
 });
 const { data: recruitmentOptions } = useQuery({
 queryKey: ['recruitment', 'options'],
 queryFn: () => recruitmentApi.options().then((r) => r.data.data),
 staleTime: 5 * 60 * 1000,
 });

 const advanceMutation = useMutation({
 mutationFn: () => recruitmentApi.changeStage(id!, { action: 'advance' }),
 onSuccess: () => {
 setShowAdvanceConfirm(false);
 toast.success('Application advanced.');
 queryClient.invalidateQueries({ queryKey: ['recruitment-application', id] });
 queryClient.invalidateQueries({ queryKey: ['recruitment-posting'] });
 queryClient.invalidateQueries({ queryKey: ['recruitment-postings'] });
 },
 onError: (error) => reportMutationError(error, 'Failed to advance.'),
 });

 const rejectMutation = useMutation({
 mutationFn: (reason: string) => recruitmentApi.changeStage(id!, { action: 'reject', rejection_reason: reason }),
 onSuccess: () => {
 toast.success('Application rejected.');
 setShowRejectDialog(false);
 queryClient.invalidateQueries({ queryKey: ['recruitment-application', id] });
 },
 onError: (error) => reportMutationError(error, 'Failed to reject.'),
 });

 const interviewOutcomeMutation = useMutation({
  mutationFn: ({ id: interviewId, outcome }: { id: string; outcome: InterviewOutcome }) =>
   recruitmentApi.updateInterview(interviewId, { outcome }),
  onSuccess: () => {
   toast.success('Interview outcome saved.');
   queryClient.invalidateQueries({ queryKey: ['recruitment-application', id] });
  },
  onError: (error) => reportMutationError(error, 'Failed to save interview outcome.'),
 });

 const noteMutation = useMutation({
 mutationFn: (body: string) => recruitmentApi.addNote(id!, body),
 onSuccess: () => {
 toast.success('Note added.');
 setNoteBody('');
 queryClient.invalidateQueries({ queryKey: ['recruitment-application', id] });
 },
 onError: (error) => reportMutationError(error, 'Failed to add note.'),
 });

 if (isLoading) return <SkeletonDetail />;
 if (isError || !application) {
 return (
 <EmptyState
 icon="file-question"
 title="Application not found"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 );
 }

 const isTerminal = application.stage === 'hired' || application.stage === 'rejected';
 const pipelineStages = (recruitmentOptions?.application_stages ?? []).filter((stage) => !stage.is_terminal);
 const stageLabel = new Map((recruitmentOptions?.application_stages ?? []).map((stage) => [stage.value, stage.label]));
 const currentIdx = pipelineStages.findIndex((stage) => stage.value === (application.stage === 'rejected' ? application.rejected_at_stage : application.stage));
 const nextStage = recruitmentOptions?.application_stages?.find((stage) => stage.value === application.stage)?.next;

 return (
 <div>
 <PageHeader
 title={
 <span className="flex flex-wrap items-center gap-2">
 {application.full_name}
 <Chip variant={STAGE_CHIP[application.stage]}>{stageLabel.get(application.stage) ?? application.stage}</Chip>
 </span>
 }
 subtitle={<span><span className="font-mono">{application.application_number}</span> · {application.job_posting?.title ?? '—'}</span>}
 backTo="/hr/recruitment/applications"
 backLabel="Applications"
 actions={
 can('hr.recruitment.applications') && !isTerminal ? (
 <>
 <Button
 size="sm"
 icon={<LuArrowRight size={12} />}
 onClick={() => application.stage === 'screening' ? setInterviewMode('advance') : setShowAdvanceConfirm(true)}
 disabled={advanceMutation.isPending}
 loading={advanceMutation.isPending}
 >
 {nextStage ? `Move to ${stageLabel.get(nextStage) ?? nextStage}` : 'Advance'}
 </Button>
 <Button variant="secondary" size="sm" className="text-danger-fg" icon={<LuCircleX size={12} />} onClick={() => setShowRejectDialog(true)}>
 Reject
 </Button>
 </>
 ) : application.stage === 'hired' && can('hr.recruitment.hire') && !application.converted_employee ? (
 <Button size="sm" icon={<LuUserPlus size={12} />} onClick={() => navigate(`/hr/employees/create?from_application=${id}`)}>
 Convert to Employee
 </Button>
 ) : undefined
 }
 />

 <div className="border-b border-default bg-surface px-5 py-4">
 <ol aria-label="Recruitment progress" className="grid grid-cols-2 gap-x-4 gap-y-3 sm:flex sm:items-center">
 {pipelineStages.map((stage, idx) => {
 const done = application.stage === 'hired' || idx < currentIdx;
 const active = !isTerminal && idx === currentIdx;
 return (
 <li key={stage.value} aria-current={active ? 'step' : undefined} className="flex min-w-0 items-center gap-2 sm:flex-1 last:sm:flex-none">
 <span className={cn('flex h-5 w-5 shrink-0 items-center justify-center rounded-full border', done ? 'border-success text-success-fg bg-success-bg' : active ? 'border-accent bg-accent text-accent-fg' : 'border-strong text-muted')} aria-hidden="true">
 {done ? <LuCircleCheck size={14} /> : <span className="h-1.5 w-1.5 rounded-full bg-current" />}
 </span>
 <span className={cn('text-xs', active || done ? 'font-medium text-primary' : 'text-muted')}>{stage.label}</span>
 {idx < pipelineStages.length - 1 && <span className="mx-2 hidden h-px flex-1 bg-border-default sm:block" aria-hidden="true" />}
 </li>
 );
 })}
 </ol>
 {!isTerminal && (
 <p className="mt-3 text-xs text-muted">
 {application.stage === 'new' && 'Review the application and advance to screening when ready.'}
 {application.stage === 'screening' && 'Screen the candidate. You\'ll need to schedule an interview to advance.'}
 {application.stage === 'interview' && 'Schedule and conduct interviews. Advance to offer when interviews are complete.'}
 {application.stage === 'offer' && 'Extend an offer to the candidate. Mark as hired once accepted.'}
 </p>
 )}
 </div>

 <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_320px] gap-4 px-5 py-4">
 <div className="space-y-4">
 {/* Contact */}
 <Panel title="Contact Information">
 <dl className="grid gap-3 sm:grid-cols-2 text-sm">
 <DetailItem label="Email"><a className="text-link underline-offset-2 hover:underline break-all" href={`mailto:${application.email}`}>{application.email}</a></DetailItem>
 <DetailItem label="Phone"><a className="text-link underline-offset-2 hover:underline" href={`tel:${application.phone}`}>{application.phone}</a></DetailItem>
 </dl>
 {application.cover_letter && (
 <div className="mt-4 pt-3 border-t border-default">
 <h4 className="text-xs font-medium text-muted">Cover Letter</h4>
 <p className="mt-1 max-w-prose whitespace-pre-line break-words text-sm leading-relaxed">{application.cover_letter}</p>
 </div>
 )}
 <div className="mt-4 pt-3 border-t border-default">
 <Button
 variant="secondary"
 size="sm"
 icon={<LuDownload size={12} />}
 onClick={() => {
 setIsDownloading(true);
 recruitmentApi.downloadResume(id!).then((res) => {
  const url = URL.createObjectURL(res.data);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'resume';
  a.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
 }).catch(() => toast.error('Unable to download the resume. Please try again.'))
 .finally(() => setIsDownloading(false));
 }}
 loading={isDownloading}
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
 <Button variant="ghost" size="sm" icon={<LuCalendar size={12} />} onClick={() => setInterviewMode('schedule')}>
 Schedule
 </Button>
 ) : undefined
 }
 >
 {application.interviews?.length ? (
 <ul className="divide-y divide-default">
 {application.interviews.map((iv: ApplicationInterview) => (
 <li key={iv.id} className="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between">
 <div className="min-w-0">
 <p className="text-sm font-medium break-words">{iv.interviewer_name}</p>
 <p className="mt-1 text-xs text-muted font-mono tabular-nums">{formatDateTime(iv.scheduled_at)}</p>
 {iv.location && <p className="mt-1 text-xs text-muted break-words">{iv.location}</p>}
 </div>
 <div className="flex items-center gap-2">
 {can('hr.recruitment.applications') ? (
  <Select
   fieldSize="sm"
   aria-label={'Outcome for interview with ' + iv.interviewer_name}
   value={iv.outcome ?? 'pending'}
   onChange={(event) => interviewOutcomeMutation.mutate({
    id: iv.id,
    outcome: event.target.value as InterviewOutcome,
   })}
   disabled={interviewOutcomeMutation.isPending}
  >
   <option value="pending">Pending</option>
   <option value="passed">Passed</option>
   <option value="failed">Failed</option>
  </Select>
 ) : iv.outcome ? (
  <Chip variant={iv.outcome === 'passed' ? 'success' : iv.outcome === 'failed' ? 'danger' : 'neutral'}>
   {iv.outcome_label ?? iv.outcome}
  </Chip>
 ) : null}
 </div>
 </li>
 ))}
 </ul>
 ) : (
 <p className="text-sm text-muted">No interviews scheduled.</p>
 )}
 </Panel>

 <Panel title="Decision history">
 {application.history?.length ? (
  <ActivityStream
   items={application.history.map((event: RecruitmentApplicationEvent) => ({
    id: event.id,
    dot: event.event_type.includes('rejected')
      ? 'danger'
      : event.event_type.includes('converted')
        ? 'success'
        : 'info',
    text: (
     <>
      {historyLabel(event)}
      <span className="text-muted block mt-0.5">
       {event.actor?.name ?? 'System'} · {event.actor_type}
      </span>
     </>
    ),
    time: formatDateTime(event.created_at),
   }))}
  />
 ) : (
  <p className="text-sm text-muted">No workflow decisions recorded yet.</p>
 )}
 </Panel>
 </div>

 {/* Sidebar */}
 <aside aria-label="Application summary and notes" className="min-w-0 space-y-4">
 <Panel title="At a glance">
 <dl className="text-sm space-y-2">
 <DetailItem label="Stage">
 <Chip variant={STAGE_CHIP[application.stage]}>{stageLabel.get(application.stage) ?? application.stage}</Chip>
 </DetailItem>
 <DetailItem label="Tracking Code">
 <span className="font-mono text-xs">{application.tracking_code}</span>
 </DetailItem>
 <DetailItem label="Applied">
 <span className="font-mono tabular-nums">{formatDate(application.applied_at)}</span>
 </DetailItem>
 </dl>

 {application.rejection_reason && (
 <div className="mt-3 rounded-md bg-danger-bg p-3 border border-danger/20">
 <span className="text-2xs uppercase tracking-wider text-danger-fg font-medium">Rejection reason</span>
 <p className="mt-1 text-xs">{application.rejection_reason}</p>
 </div>
 )}

 {application.converted_employee && (
 <div className="mt-3 rounded-md bg-success-bg p-3 border border-success/20">
 <span className="text-2xs uppercase tracking-wider text-success-fg font-medium">Converted to employee</span>
 <p className="mt-1 text-xs font-mono">{application.converted_employee.employee_no}</p>
 </div>
 )}
 </Panel>
 {/* Notes */}
 <Panel
 title={
 <span className="flex items-center gap-1.5">
 <LuMessageSquare size={14} />
 Notes ({application.notes?.length ?? 0})
 </span>
 }
 >
 {can('hr.recruitment.applications') && (
 <div className="mb-4 space-y-2">
 <Textarea
 label="Add a note"
 value={noteBody}
 onChange={(e) => setNoteBody(e.target.value)}
 placeholder="Add a note..."
 rows={3}
 maxLength={5000}
 />
 <Button
 size="sm"
 onClick={() => { if (noteBody.trim()) noteMutation.mutate(noteBody.trim()); }}
 disabled={!noteBody.trim() || noteMutation.isPending}
 loading={noteMutation.isPending}
 >
 Add note
 </Button>
 </div>
 )}

 {application.notes?.length ? (
 <ActivityStream
 className="mt-4 break-words"
 items={application.notes.map((note) => ({
 id: note.id,
 dot: 'info',
 text: (
 <>
 {note.body}
 <span className="text-muted block mt-0.5">{note.user?.name}</span>
 </>
 ),
 time: formatDateTime(note.created_at),
 }))}
 />
 ) : (
 <p className="text-sm text-muted">No notes yet.</p>
 )}
 </Panel>

 </aside>
 </div>

 {interviewMode && <InterviewModal
 applicationId={id!}
 applicantName={application.full_name}
 mode={interviewMode}
 onClose={() => setInterviewMode(null)}
 />}

 <Modal isOpen={showRejectDialog} onClose={() => { if (!rejectMutation.isPending) setShowRejectDialog(false); }} title="Reject application" closeOnOverlayClick={!rejectMutation.isPending}>
 <form onSubmit={(event) => { event.preventDefault(); if (rejectionReason.trim() && !rejectMutation.isPending) rejectMutation.mutate(rejectionReason.trim()); }}>
 <p className="mb-4 text-sm text-muted">Record the reason for rejecting {application.full_name}.</p>
 <Textarea label="Rejection reason" required maxLength={2000} value={rejectionReason} onChange={(event) => setRejectionReason(event.target.value)} rows={3} placeholder="Provide a reason..." />
 <ModalFooter>
 <Button type="button" variant="secondary" disabled={rejectMutation.isPending} onClick={() => setShowRejectDialog(false)}>Cancel</Button>
 <Button type="submit" variant="danger" disabled={!rejectionReason.trim() || rejectMutation.isPending} loading={rejectMutation.isPending}>Confirm Reject</Button>
 </ModalFooter>
 </form>
 </Modal>

 <ConfirmDialog
 isOpen={showAdvanceConfirm}
 onClose={() => setShowAdvanceConfirm(false)}
 onConfirm={() => advanceMutation.mutate()}
 title="Advance to next stage?"
 description="The applicant will move to the next recruitment stage."
 confirmLabel="Advance"
 variant="warning"
 pending={advanceMutation.isPending}
 />
 </div>
 );
}

const interviewSchema = z.object({
 scheduled_at: z.string().min(1, 'Interview date and time is required.').refine(
  (value) => new Date(value).getTime() > Date.now(),
  'Interview must be scheduled in the future.',
 ),
 location: z.string().trim().max(200, 'Location must be 200 characters or fewer.'),
 interviewer_name: z.string().trim().min(1, 'Interviewer name is required.').max(200, 'Interviewer name must be 200 characters or fewer.'),
});
type InterviewForm = z.infer<typeof interviewSchema>;

function InterviewModal({ applicationId, applicantName, mode, onClose }: {
 applicationId: string;
 applicantName: string;
 mode: 'advance' | 'schedule';
 onClose: () => void;
}) {
 const queryClient = useQueryClient();
 const { register, handleSubmit, setError, formState: { errors } } = useForm<InterviewForm>({
  resolver: zodResolver(interviewSchema),
  defaultValues: { scheduled_at: '', location: '', interviewer_name: '' },
 });
 const mutation = useMutation({
  mutationFn: async (values: InterviewForm) => {
   const interview = {
    scheduled_at: new Date(values.scheduled_at).toISOString(),
    location: values.location || undefined,
    interviewer_name: values.interviewer_name,
   };
   await (mode === 'advance'
    ? recruitmentApi.changeStage(applicationId, { action: 'advance', interview })
    : recruitmentApi.scheduleInterview(applicationId, interview));
  },
  onSuccess: () => {
   toast.success(mode === 'advance' ? 'Application advanced.' : 'Interview scheduled.');
   queryClient.invalidateQueries({ queryKey: ['recruitment-application', applicationId] });
   onClose();
  },
  onError: (error) => {
   if (error instanceof AxiosError && error.response?.status === 422) {
    const body = error.response.data as { errors?: Record<string, string[]>; message?: string };
    for (const [key, messages] of Object.entries(body.errors ?? {})) {
     const field = key.replace(/^interview\./, '');
     if (field === 'scheduled_at' || field === 'location' || field === 'interviewer_name') {
      setError(field, { type: 'server', message: messages[0] });
     } else {
      setError('root.server', { message: messages[0] });
     }
    }
    if (!body.errors) setError('root.server', { message: body.message ?? 'Unable to schedule interview. Please try again.' });
   } else {
    setError('root.server', { message: 'Unable to schedule interview. Please try again.' });
   }
   reportMutationError(error, 'Failed to schedule interview.');
  },
 });

 return (
 <Modal isOpen title="Schedule interview" onClose={() => { if (!mutation.isPending) onClose(); }} closeOnOverlayClick={!mutation.isPending}>
 <form onSubmit={handleSubmit((values) => { if (!mutation.isPending) mutation.mutate(values); }, onFormInvalid<InterviewForm>())}>
 <p className="mb-4 text-sm text-muted">{applicantName}{mode === 'advance' ? ' will move to Interview when this schedule is saved.' : ' · Additional interview'}</p>
 <fieldset disabled={mutation.isPending} className="space-y-4">
 <Input label="Date & Time" type="datetime-local" required min={localIsoDateTime()} {...register('scheduled_at')} error={errors.scheduled_at?.message} />
 <Input label="Interviewer" required maxLength={200} {...register('interviewer_name')} error={errors.interviewer_name?.message} placeholder="Full name" />
 <Input label="Location" maxLength={200} {...register('location')} error={errors.location?.message} placeholder="Room 201 / Zoom link" />
 </fieldset>
 {errors.root?.server && <p role="alert" className="mt-3 text-sm text-danger-fg">{errors.root.server.message}</p>}
 <ModalFooter className="flex-wrap">
 <Button type="button" variant="secondary" disabled={mutation.isPending} onClick={onClose}>Cancel</Button>
 <Button type="submit" disabled={mutation.isPending} loading={mutation.isPending}>{mode === 'advance' ? 'Schedule & Move to Interview' : 'Schedule interview'}</Button>
 </ModalFooter>
 </form>
 </Modal>
 );
}

function DetailItem({ label, children }: { label: string; children: React.ReactNode }) {
 return (
 <div>
 <dt className="text-2xs uppercase tracking-wider text-muted font-medium">{label}</dt>
 <dd className="mt-0.5 break-words">{children}</dd>
 </div>
 );
}

function historyLabel(event: RecruitmentApplicationEvent): string {
 const fromStage = event.from_stage ?? 'unknown';
 const toStage = event.to_stage ?? (
  typeof event.after?.stage === 'string' ? event.after.stage : 'unknown'
 );

 switch (event.event_type) {
  case 'stage.advanced':
   return 'Advanced from ' + fromStage + ' to ' + toStage;
  case 'application.rejected':
   return 'Rejected from ' + fromStage;
  case 'interview.scheduled':
   return 'Interview scheduled';
  case 'interview.updated':
   return 'Interview details or outcome updated';
  case 'note.added':
   return 'Recruitment note added';
  case 'application.converted':
   return 'Application converted to an employee';
  default:
   return event.event_type;
 }
}
