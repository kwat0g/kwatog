import { Checkbox } from '@/components/ui/Checkbox';
import { Textarea } from '@/components/ui/Textarea';

export function MaterialHandoverNotice({
  pending,
  canAcknowledge = false,
  acknowledged = false,
  reason = '',
  onAcknowledged,
  onReason,
  disabled = false,
}: {
  pending: number;
  canAcknowledge?: boolean;
  acknowledged?: boolean;
  reason?: string;
  onAcknowledged?: (value: boolean) => void;
  onReason?: (value: string) => void;
  disabled?: boolean;
}) {
  if (pending <= 0) return null;
  return (
    <div className="space-y-3 rounded-md border border-warning bg-warning-bg p-3 text-sm">
      <p role="alert" className="text-warning-fg">
        Warehouse handover is pending. Ask Warehouse to confirm before recording output.
      </p>
      {canAcknowledge && (
        <>
          <Checkbox
            label="Materials were received; Warehouse confirmation is pending."
            checked={acknowledged}
            onChange={(event) => onAcknowledged?.(event.target.checked)}
            disabled={disabled}
          />
          {acknowledged && (
            <Textarea
              label="Material handover reason"
              required
              rows={3}
              maxLength={500}
              value={reason}
              onChange={(event) => onReason?.(event.target.value)}
              disabled={disabled}
              helper="Explain who received the materials and why Warehouse confirmation is still pending. This applies only to this recording."
              placeholder="Warehouse delivered the resin and bags to Manuel; confirmation is waiting for Carlos."
            />
          )}
        </>
      )}
    </div>
  );
}
