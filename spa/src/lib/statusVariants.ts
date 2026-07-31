import { type ChipVariant } from '@/components/ui/Chip';
import type { WorkOrderStatus } from '@/types/production';
import type { DeliveryStatus } from '@/types/supplyChain';
import type { MaintenancePriority, MaintenanceWorkOrderStatus } from '@/types/maintenance';

/**
 * Status → chip variant maps shared between a record's desktop and touch views.
 *
 * The touch PWAs used to keep their own copies, and they had drifted away from
 * the desktop tables: a work order mid-run showed emerald on the floor tablet and
 * indigo at the planner's desk, and `completed` was grey on one and emerald on
 * the other. Same record, same status, different colour depending on the device
 * you happened to be holding — so the colour stopped carrying meaning.
 *
 * These follow the status → variant table in docs/DESIGN-SYSTEM.md. Keyed by the
 * status union rather than `string`, so adding a status to the enum is a type
 * error here instead of a silent fall-through to grey.
 */

/** Production work orders — floor tablet (`/factory`) + planner table. */
export const workOrderStatusVariant: Record<WorkOrderStatus, ChipVariant> = {
  planned: 'neutral',
  confirmed: 'info',
  in_progress: 'info',
  paused: 'warning',
  completed: 'success',
  closed: 'success',
  cancelled: 'danger',
};

/**
 * Deliveries — driver PWA + supply-chain table. `DriverDeliveryStatus` is a
 * structurally identical union, so the driver pages index this with theirs.
 */
export const deliveryStatusVariant: Record<DeliveryStatus, ChipVariant> = {
  scheduled: 'neutral',
  loading: 'info',
  in_transit: 'info',
  delivered: 'warning',
  confirmed: 'success',
  cancelled: 'neutral',
};

/** Maintenance work orders — tech PWA + maintenance table. */
export const maintenanceStatusVariant: Record<MaintenanceWorkOrderStatus, ChipVariant> = {
  open: 'warning',
  assigned: 'info',
  in_progress: 'info',
  completed: 'success',
  cancelled: 'neutral',
};

/** Maintenance priority — tech PWA + maintenance table. */
export const maintenancePriorityVariant: Record<MaintenancePriority, ChipVariant> = {
  critical: 'danger',
  high: 'warning',
  medium: 'info',
  low: 'neutral',
};
