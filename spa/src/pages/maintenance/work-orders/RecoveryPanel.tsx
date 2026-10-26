import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import { Button } from '@/components/ui/Button';
import { Panel } from '@/components/ui/Panel';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { usePermission } from '@/hooks/usePermission';
import { reportMutationError } from '@/lib/formErrors';
import { formatDateTime } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import type { MaintenanceWorkOrder, MaintenanceWorkOrderLog, RepairEvidence } from '@/types/maintenance';

const resultLabels = { in_progress: 'In progress', passed: 'Passed', needs_follow_up: 'Needs follow-up' };

export function WorkLogContent({ log }: { log: MaintenanceWorkOrderLog }) {
  if (!log.finding) return <p className="text-sm whitespace-pre-wrap">{log.description}</p>;
  return <dl className="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[6rem_1fr]">
    <dt className="text-muted">Finding</dt><dd className="whitespace-pre-wrap">{log.finding}</dd>
    <dt className="text-muted">Action taken</dt><dd className="whitespace-pre-wrap">{log.action_taken}</dd>
    <dt className="text-muted">Result</dt><dd>{log.result ? resultLabels[log.result] : '—'}</dd>
  </dl>;
}

export function WorkLogControl({ order }: { order: MaintenanceWorkOrder }) {
  const { can } = usePermission();
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [finding, setFinding] = useState('');
  const [actionTaken, setActionTaken] = useState('');
  const [result, setResult] = useState<'' | keyof typeof resultLabels>('');
  const mutation = useMutation({
    mutationFn: () => workOrdersApi.recordWork(order.id, {
      finding: finding.trim(), action_taken: actionTaken.trim(), result: result as keyof typeof resultLabels,
    }),
    onSuccess: () => {
      setFinding('');
      setActionTaken('');
      setResult('');
      setOpen(false);
      qc.invalidateQueries({ queryKey: ['maintenance', 'work-order', order.id] });
      toast.success('Work recorded.');
    },
    onError: (error) => reportMutationError(error, 'Could not record work.'),
  });
  const actions = order.available_actions ?? [];
  const canRecord = order.can_record_work ?? (can('maintenance.wo.complete') && (actions.includes('complete') || actions.includes('submit_repair')));
  if (!canRecord || order.repair_submitted_at) return null;
  const canCloseWork = order.closure_readiness?.can_close_work ?? false;
  return <>
    <Button type="button" variant={canCloseWork ? 'secondary' : 'primary'} size="sm" className="min-h-11 md:min-h-7" onClick={() => setOpen(true)}>Record work</Button>
    <Modal isOpen={open} onClose={() => setOpen(false)} title="Record work" size="md">
      <form onSubmit={(event) => { event.preventDefault(); mutation.mutate(); }} className="space-y-4 pt-3">
        <Textarea label="Finding" value={finding} onChange={(event) => setFinding(event.target.value)} required rows={3} maxLength={5000} />
        <Textarea label="Action taken" value={actionTaken} onChange={(event) => setActionTaken(event.target.value)} required rows={3} maxLength={5000} />
        {order.parts_handover?.blocker && <p role="status" className="text-sm text-warning-fg">{order.parts_handover.blocker}</p>}
        <Select label="Result" value={result} onChange={(event) => setResult(event.target.value as keyof typeof resultLabels)} required>
          <option value="">Select result</option>
          {Object.entries(resultLabels).map(([value, label]) => <option key={value} value={value} disabled={value === 'passed' && (order.parts_handover?.pending ?? 0) > 0}>{label}</option>)}
        </Select>
        <ModalFooter>
          <Button type="button" variant="secondary" className="min-h-11 md:min-h-8" onClick={() => setOpen(false)}>Cancel</Button>
          <Button type="submit" variant="primary" className="min-h-11 md:min-h-8" disabled={!finding.trim() || !actionTaken.trim() || !result || result === 'passed' && (order.parts_handover?.pending ?? 0) > 0} loading={mutation.isPending}>Save work record</Button>
        </ModalFooter>
      </form>
    </Modal>
  </>;
}

export function RecoveryPanel({ order }: { order: MaintenanceWorkOrder }) {
  const { can } = usePermission();
  const qc = useQueryClient();
  const [evidence, setEvidence] = useState<RepairEvidence>({ diagnosis: '', repair_action: '', functional_test_result: '' });
  const [reason, setReason] = useState('');
  const [elapsed, setElapsed] = useState(0);
  useEffect(() => {
    const receivedAt = Date.now();
    setElapsed(0);
    if (!order.open_downtime_started_at || !order.server_time) return;
    const timer = window.setInterval(() => setElapsed(Date.now() - receivedAt), 1000);
    return () => window.clearInterval(timer);
  }, [order.open_downtime_started_at, order.server_time]);
  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['maintenance', 'work-order', order.id] });
    qc.invalidateQueries({ queryKey: ['maintenance', 'work-orders'] });
  };
  const submit = useMutation({
    mutationFn: () => workOrdersApi.submitRepair(order.id, evidence),
    onSuccess: () => { refresh(); toast.success('Repair submitted for verification.'); },
    onError: (error) => reportMutationError(error, 'Could not submit repair evidence.'),
  });
  const verify = useMutation({
    mutationFn: (decision: 'accept' | 'reject') => workOrdersApi.verifyRepair(order.id, { decision, reason: decision === 'reject' ? reason.trim() : undefined }),
    onSuccess: (_, decision) => { refresh(); setReason(''); toast.success(decision === 'accept' ? 'Recovery verified.' : 'Repair rejected. Technician can continue work.'); },
    onError: (error) => reportMutationError(error, 'Could not verify this repair.'),
  });
  const liveMinutes = order.open_downtime_started_at && order.server_time
    ? Math.max(0, (Date.parse(order.server_time) - Date.parse(order.open_downtime_started_at) + elapsed) / 60000)
    : null;
  const actions = order.available_actions ?? [];
  const canCloseParts = order.parts_readiness?.can_close_parts ?? order.spare_parts?.length === 0;
  const canSubmitRepair = order.closure_readiness?.can_close_work === true && canCloseParts;
  const fields: Array<{ key: keyof RepairEvidence; label: string }> = [
    { key: 'diagnosis', label: 'Diagnosis' },
    { key: 'repair_action', label: 'Repair action' },
    { key: 'functional_test_result', label: 'Functional test result' },
  ];
  return <div className="space-y-4">
    {order.readiness && <Panel title="Maintenance readiness">
      <p className="text-sm">Equipment status: {order.readiness.equipment_status ?? 'Unknown'}</p>
      {order.readiness.blockers.length ? order.readiness.blockers.map((blocker) => <p key={blocker.code} role="status" className="mt-2 text-sm text-secondary">{blocker.message} {blocker.required_action}</p>) : <p className="mt-2 text-sm text-secondary">No equipment blockers.</p>}
      {order.readiness.production_work_order?.link && <Link className="inline-flex min-h-hit items-center text-sm text-accent" to={order.readiness.production_work_order.link}>Production order {order.readiness.production_work_order.wo_number}</Link>}
    </Panel>}
    <Panel title="Recovery measurements">
      <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
        <div><dt className="text-muted">Recovery downtime{liveMinutes !== null ? ' (provisional)' : ''}</dt><dd className="font-mono">{liveMinutes !== null ? liveMinutes.toFixed(1) : order.downtime_minutes} min</dd></div>
        <div><dt className="text-muted">Time to start</dt><dd>{order.response_minutes == null ? '—' : `${order.response_minutes} min`}</dd></div>
        <div><dt className="text-muted">Start to repair submission</dt><dd>{order.technician_repair_minutes == null ? '—' : `${order.technician_repair_minutes} min`}</dd></div>
        <div><dt className="text-muted">Maintenance elapsed{order.maintenance_elapsed_provisional ? ' (provisional)' : ''}</dt><dd>{order.maintenance_elapsed_minutes == null ? '—' : `${order.maintenance_elapsed_minutes} min`}</dd></div>
        <div><dt className="text-muted">Parts cost</dt><dd className="font-mono">{formatPeso(order.cost)}</dd></div>
      </dl>
      {can('maintenance.view') && <Link className="inline-flex min-h-hit items-center text-sm text-accent" to="/maintenance/downtime">Downtime analytics</Link>}
    </Panel>
    {order.verification_required && <Panel title={order.verified_at ? 'Verified recovery' : order.repair_submitted_at ? 'Awaiting verification' : 'Repair evidence'}>
      {order.repair_evidence && <dl className="space-y-3 text-sm">{fields.map(({ key, label }) => <div key={key}><dt className="text-muted">{label}</dt><dd className="whitespace-pre-wrap">{order.repair_evidence?.[key]}</dd></div>)}</dl>}
      <div className="mt-3 space-y-2 text-xs text-muted">
        {order.repair_submitted_at && <p>Submitted {formatDateTime(order.repair_submitted_at)} · {order.submitted_by?.name ?? '—'}</p>}
        {order.verified_at && <p>Verified {formatDateTime(order.verified_at)} · {order.verifier?.name ?? '—'}</p>}
      </div>
      {actions.includes('submit_repair') && !canSubmitRepair && <p className="mt-3 text-sm text-muted">{!order.closure_readiness?.can_close_work ? order.closure_readiness?.blocker : order.parts_readiness?.blocker ?? 'Parts readiness is unavailable. Reload this work order.'}</p>}
      {actions.includes('submit_repair') && canSubmitRepair && (can('maintenance.wo.complete') || can('maintenance.wo.assign')) && <form className="mt-3 space-y-3" onSubmit={(event) => { event.preventDefault(); submit.mutate(); }}>
        {fields.map(({ key, label }) => <Textarea key={key} label={label} required rows={3} value={evidence[key]} onChange={(event) => setEvidence({ ...evidence, [key]: event.target.value })} />)}
        <p className="text-xs text-muted">For the functional test, name the check, expected behavior, and observed behavior.</p>
        <p className="text-xs text-muted">Submission locks work logs and parts until a verifier accepts or rejects the repair.</p>
        <Button type="submit" variant="primary" className="min-h-hit" disabled={fields.some(({ key }) => !evidence[key].trim())} loading={submit.isPending}>Submit repair</Button>
      </form>}
      {actions.includes('verify_repair') && can('maintenance.wo.verify') && <div className="mt-3 space-y-3">
        <p className="text-sm text-secondary">Confirm physical recovery before acceptance. Production resume remains a separate action.</p>
        <Textarea label="Rejection reason" value={reason} onChange={(event) => setReason(event.target.value)} rows={3} />
        <div className="flex flex-wrap gap-3">
          <Button variant="primary" className="min-h-hit" onClick={() => verify.mutate('accept')} loading={verify.isPending}>Accept recovery</Button>
          <Button variant="danger" className="min-h-hit" disabled={!reason.trim() || verify.isPending} onClick={() => verify.mutate('reject')}>Reject repair</Button>
        </div>
      </div>}
    </Panel>}
  </div>;
}
