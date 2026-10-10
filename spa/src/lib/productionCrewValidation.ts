import type { EmployeeOption } from '@/types/production-crew';

export function crewValidation(
  members: EmployeeOption[],
  recorder: string,
  minimum: string,
  maximum: string,
  reason: string,
) {
  if (
    !/^\d+$/.test(minimum) ||
    Number(minimum) < 1 ||
    Number(minimum) > 4 ||
    (maximum !== '' &&
      (!/^\d+$/.test(maximum) || Number(maximum) > 4 || Number(maximum) < Number(minimum)))
  )
    return 'Choose the number of workers from 1–4.';
  if (members.length < Number(minimum) || members.length > Number(maximum || 4))
    return minimum === maximum
      ? `Select ${minimum} workers before applying.`
      : 'Selected worker count must be within the crew limits.';
  if (!members.some((member) => member.id === recorder && member.has_active_account))
    return 'Choose one worker with an active account to record output.';
  if (!reason.trim()) return 'Enter an assignment or handover reason.';
}
export function workerCountError(
  good: string,
  reject: string,
  defect: string,
  remaining: number,
  repair: boolean,
) {
  if (
    !/^\d+$/.test(good) ||
    !/^\d+$/.test(reject) ||
    !Number.isSafeInteger(Number(good)) ||
    !Number.isSafeInteger(Number(reject))
  )
    return 'Enter whole, non-negative counts.';
  if (Number(good) + Number(reject) < 1) return 'Enter at least one piece.';
  if ((repair ? Number(good) + Number(reject) : Number(good)) > remaining)
    return 'Count exceeds remaining quantity.';
  if (Number(reject) > 0 && !defect) return 'Choose a defect type for rejected pieces.';
}
