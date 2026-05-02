// Common unit-of-measure choices shared across Accounting line items
// (bill items, invoice items). Keep this list short and meaningful for a
// plastic-injection-molding manufacturer; users can add more later by editing
// this file or migrating to a database table when needs grow.

export interface UnitOption {
  value: string;
  label: string;
}

export const UNIT_OPTIONS: UnitOption[] = [
  { value: 'pcs',     label: 'pcs (pieces)' },
  { value: 'set',     label: 'set' },
  { value: 'pack',    label: 'pack' },
  { value: 'box',     label: 'box' },
  { value: 'kg',      label: 'kg (kilogram)' },
  { value: 'g',       label: 'g (gram)' },
  { value: 'ton',     label: 'ton' },
  { value: 'L',       label: 'L (liter)' },
  { value: 'mL',      label: 'mL (milliliter)' },
  { value: 'm',       label: 'm (meter)' },
  { value: 'cm',      label: 'cm (centimeter)' },
  { value: 'mm',      label: 'mm (millimeter)' },
  { value: 'roll',    label: 'roll' },
  { value: 'sheet',   label: 'sheet' },
  { value: 'lot',     label: 'lot' },
  { value: 'service', label: 'service' },
  { value: 'hour',    label: 'hour' },
  { value: 'day',     label: 'day' },
  { value: 'month',   label: 'month' },
];
