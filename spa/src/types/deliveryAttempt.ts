export interface DeliveryAttemptLine {
  delivery_item_id: string;
  customer_received_quantity: string;
  customer_received_damaged_quantity: string;
  truck_return_quantity: string;
  truck_return_damaged_quantity: string;
  unaccounted_quantity: string;
  warehouse_received_quantity?: string | null;
}

export interface ReportDeliveryAttempt {
  expected_version?: number;
  correction_reason?: string;
  request_key: string;
  reason_code: string;
  notes: string;
  lines: DeliveryAttemptLine[];
}

export interface DeliveryAttemptOutcome {
  version?: number;
  can_amend?: boolean;
  return_requests?: Array<{ id: string; rma_number: string; status: string }>;
  revisions?: Array<{ id: string; kind: string; reason: string; created_at: string; actor_name: string }>;
  id: string;
  reason_code: string;
  reason_label: string;
  notes: string | null;
  reported_at: string;
  reconciled_at?: string | null;
  variance_reason?: string | null;
  lines: DeliveryAttemptLine[];
  return_request?: { id: string; rma_number: string; status: string } | null;
}

export interface DeliveryAttemptSourceLine {
  id: string;
  quantity: string | number;
  product?: { part_number: string; name: string } | null;
  unit_of_measure?: string;
}

export interface DeliveryAttemptFields {
  can_report_attempt_outcome?: boolean;
  attempt_outcome_reasons?: Array<{ value: string; label: string }>;
  attempt_outcome?: DeliveryAttemptOutcome | null;
  can_receive_truck_return?: boolean;
}

export interface ReceiveTruckReturn {
  request_key: string;
  quarantine_location_id?: string | null;
  variance_reason?: string;
  lines: Array<{ delivery_item_id: string; received_quantity: string }>;
}
