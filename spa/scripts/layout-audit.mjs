#!/usr/bin/env node
/**
 * Serial route/viewport layout probe. Writes one screenshot and one metric row
 * per route and viewport; no route or viewport is visited concurrently.
 *
 * Usage:
 *   node scripts/layout-audit.mjs --role admin --routes /inventory/stock-adjustments
 *   node scripts/layout-audit.mjs --role warehouse --routes /inventory/items,/inventory/stock-levels --viewports 375,768,1024
 *
 * Set OGAMI_BASE_URL and AUDIT_PASSWORD to target a different deployment. In
 * this workspace, set LAYOUT_AUDIT_LOCAL_STACK=1 to route ogamiph.dev through
 * the local Caddy container and avoid the external CDN challenge.
 */
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { chromium } from 'playwright';

const BASE = process.env.OGAMI_BASE_URL ?? 'http://127.0.0.1:5174';
const PASSWORD = process.env.AUDIT_PASSWORD ?? 'password';
const SPA_ROOT = path.resolve(import.meta.dirname, '..');
const ROOT = path.resolve(SPA_ROOT, '..');
const ROLE_EMAILS = {
  admin: 'admin@ogami.test',
  hr: 'hr@ogami.test',
  finance: 'finance@ogami.test',
  production: 'production@ogami.test',
  ppc: 'ppc@ogami.test',
  purchasing: 'purchasing@ogami.test',
  vp: 'vp@ogami.test',
  employee: 'employee@ogami.test',
  sales: 'crm@ogami.test',
  warehouse: 'warehouse@ogami.test',
  qc: 'qc@ogami.test',
  maintenance_supervisor: 'maintenance@ogami.test',
  impex: 'impex@ogami.test',
  depthead: 'depthead@ogami.test',
  driver: 'driver@ogami.test',
  customer_service: 'customerservice@ogami.test',
};
const DEFAULT_VIEWPORTS = [375, 768, 1024, 1280, 1440, 1920, 2560];

function arg(name, fallback) {
  const index = process.argv.indexOf(`--${name}`);
  if (index < 0) return fallback;
  const value = process.argv[index + 1];
  if (!value || value.startsWith('--')) throw new Error(`--${name} needs a value`);
  return value;
}

function slug(value) {
  return value.replace(/^\//, '').replace(/[^a-z0-9]+/gi, '-') || 'root';
}

function groupSamples(items, fields) {
  const groups = new Map();
  for (const item of items) {
    const key = JSON.stringify(fields.map((field) => item[field]));
    const group = groups.get(key) ?? { ...item, count: 0, examples: [] };
    group.count += 1;
    const example = item.text ?? item.label;
    if (example && group.examples.length < 3 && !group.examples.includes(example)) group.examples.push(example);
    for (const field of ['scrollWidth', 'clientWidth', 'scrollHeight', 'clientHeight']) {
      if (typeof item[field] !== 'number') continue;
      const aggregate = `max${field[0].toUpperCase()}${field.slice(1)}`;
      group[aggregate] = Math.max(group[aggregate] ?? item[field], item[field]);
    }
    for (const field of ['width', 'height']) {
      if (typeof item[field] !== 'number') continue;
      const title = field[0].toUpperCase() + field.slice(1);
      group[`min${title}`] = Math.min(group[`min${title}`] ?? item[field], item[field]);
      group[`max${title}`] = Math.max(group[`max${title}`] ?? item[field], item[field]);
    }
    groups.set(key, group);
  }
  return [...groups.values()];
}

function compactMetricSamples(metrics) {
  metrics.horizontalOverflow = groupSamples(metrics.horizontalOverflow, [
    'tag', 'className', 'horizontal', 'vertical', 'intentionalHorizontal', 'insideMain', 'insideSidebar', 'overflowX', 'overflowY',
  ]);
  metrics.verticalScrollContainers = groupSamples(metrics.verticalScrollContainers, [
    'tag', 'className', 'insideMain', 'insideSidebar', 'overflowX', 'overflowY',
  ]);
  metrics.typography.samples = groupSamples(metrics.typography.samples, [
    'tag', 'className', 'font', 'size', 'weight', 'lineHeight', 'letterSpacing', 'textTransform', 'color', 'background', 'contrast',
  ]);
  for (const field of ['below13px', 'below12px', 'contrastBelowAA']) {
    metrics.typography[field] = groupSamples(metrics.typography[field], ['tag', 'size', 'contrast', 'color', 'background']);
  }
  metrics.table?.textBelowMinimum && (metrics.table.textBelowMinimum = groupSamples(metrics.table.textBelowMinimum, ['tag', 'size']));
  const groupTargets = (items, colors = false) => groupSamples(
    items.map((item) => ({ ...item, widthBelow44: item.width < 44, heightBelow44: item.height < 44 })),
    ['widthBelow44', 'heightBelow44', ...(colors ? ['background', 'color'] : [])],
  ).map(({ widthBelow44, heightBelow44, ...item }) => item);
  metrics.buttons = groupTargets(metrics.buttons, true);
  metrics.buttonTargetsBelow44 = groupTargets(metrics.buttonTargetsBelow44, true);
  metrics.controls = groupTargets(metrics.controls);
  metrics.controlsBelow44 = groupTargets(metrics.controlsBelow44);
  metrics.recordLinks = groupSamples(metrics.recordLinks, ['color', 'contrast', 'distinctFromBodyText']);
  return metrics;
}

function luminance(rgb) {
  const channel = (value) => {
    const v = value / 255;
    return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * channel(rgb[0]) + 0.7152 * channel(rgb[1]) + 0.0722 * channel(rgb[2]);
}

function contrastRatio(foreground, background) {
  const a = luminance(foreground);
  const b = luminance(background);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

// Probe self-check: known black/white pair is 21:1 within floating-point tolerance.
assert.ok(Math.abs(contrastRatio([0, 0, 0], [255, 255, 255]) - 21) < 0.001);

async function login(page, email) {
  await page.goto(`${BASE}/sign-in`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  await page.locator('input[name="email"], input[type="email"]').first().fill(email);
  await page.locator('input[name="password"], input[type="password"]').first().fill(PASSWORD);
  await page.getByRole('button', { name: /sign in|log in/i }).first().click();
  await page.waitForURL((url) => !url.pathname.startsWith('/sign-in'), { timeout: 30_000 });
}

function localUser(role, themeMode) {
  const slugs = {
    admin: 'system_admin', hr: 'hr_officer', finance: 'finance_officer',
    production: 'production_manager', ppc: 'ppc_head', purchasing: 'purchasing_officer',
    vp: 'vice_president', employee: 'employee', sales: 'sales_officer',
    warehouse: 'warehouse_staff', qc: 'qc_inspector', maintenance_supervisor: 'maintenance_supervisor',
    impex: 'impex_officer', depthead: 'department_head', driver: 'driver',
    customer_service: 'customer_service_officer',
  };
  return {
    id: `layout-audit-${role}`,
    name: `Layout Audit ${role}`,
    email: ROLE_EMAILS[role],
    role: { id: `layout-audit-${role}`, slug: slugs[role], name: slugs[role] },
    permissions: ['inventory.adjust', 'inventory.adjust.approve', 'inventory.view', 'inventory.items.manage', 'inventory.warehouse.manage', 'inventory.issue.create', 'accounting.journal.post', 'production.view', 'production.schedule.view', 'mrp.schedule', 'production.work_orders.view', 'production.wo.create', 'production.wo.record', 'production.routings.view', 'production.routings.manage'],
    delegated_approvals: [],
    features: ['inventory', 'production', 'notifications', 'search', 'b2b_portals'],
    employee: { id: 'layout-audit-employee', employee_no: 'LAYOUT-AUDIT' },
    is_active: true,
    must_change_password: false,
    theme_mode: themeMode,
    sidebar_collapsed: false,
  };
}

function sampleAdjustments(count) {
  return Array.from({ length: count }, (_, index) => ({
    id: `layout-${index + 1}`,
    created_at: `2026-10-${String((index % 28) + 1).padStart(2, '0')}T09:14:00+08:00`,
    requested_by: { name: index === 0 ? 'A very long employee name for width checks' : `Employee ${index + 1}` },
    direction: index % 2 ? 'out' : 'in',
    item: {
      code: index === 1 ? 'RAW-MATERIAL-REFERENCE-0000000000000000001' : `SKU-${String(index + 1).padStart(5, '0')}`,
      name: index === 1 ? 'Long material description that should remain readable or truncate without widening every column' : `Material ${index + 1}`,
    },
    location: { code: `WH-${String((index % 12) + 1).padStart(2, '0')}-A-${String((index % 99) + 1).padStart(2, '0')}` },
    quantity: index === 2 ? '123456789.125' : `${(index + 1) * 1.125}`,
    value: index === 2 ? '999999999999.99' : `${((index + 1) * 145.75).toFixed(2)}`,
    reason: index === 3 ? 'A long audit reason used to observe truncation, tooltip behavior, and row height at all tested viewports.' : `Cycle count correction ${index + 1}`,
    status: index % 3 === 0 ? 'approved' : index % 3 === 1 ? 'pending' : 'rejected',
    status_label: index % 3 === 0 ? 'Approved' : index % 3 === 1 ? 'Pending' : 'Rejected',
    approved_by: { name: 'Finance Approver' },
    approved_at: '2026-10-25T09:14:00+08:00',
    resolved_by: { name: 'Finance Approver' },
    resolved_at: '2026-10-25T09:14:00+08:00',
    resolution_reason: null,
    can_approve: index % 3 === 1,
    can_reject: index % 3 === 1,
    can_withdraw: false,
  }));
}

const sampleItem = {
  id: 'layout-item-001',
  code: 'RAW-MATERIAL-REFERENCE-000001',
  name: 'Premium injection-grade polypropylene resin',
  description: 'Food-safe resin for high-volume molding; retain original supplier packaging and humidity controls.',
  category: { id: 'layout-category-1', name: 'Polymer resins', item_type: 'raw_material' },
  item_type: 'raw_material', item_type_label: 'Raw material', unit_of_measure: 'kg',
  standard_cost: '1234567.8900', reorder_method: 'fixed_quantity', reorder_point: '123456.125',
  safety_stock: '10000.500', minimum_order_quantity: '500.000', lead_time_days: 21,
  is_critical: true, is_active: true, quality_plan_ready: true,
  on_hand_quantity: '123456789.125', reserved_quantity: '234567.250',
  available_quantity: '123222221.875', approved_free_stock: '123000000.000',
  incoming_stock_quantity: '500000.000', quality_held_quantity: '1200.000',
  unusable_zone_quantity: '25.000', usable_quantity: '123222221.875', stock_status: 'low',
  classification_needs_review: false, created_at: '2026-10-01T09:14:00+08:00',
  updated_at: '2026-10-25T16:20:00+08:00',
};

function sampleItems(count) {
  return Array.from({ length: count }, (_, index) => ({
    ...sampleItem,
    id: index === 0 ? sampleItem.id : `layout-item-${String(index + 1).padStart(6, '0')}`,
    code: index === 0 ? sampleItem.code : `SKU-${String(index + 1).padStart(6, '0')}`,
    name: index === 1
      ? 'A long inventory item description used to check truncation, wrapping, and column sizing across the entire table'
      : index === 0 ? sampleItem.name : `Inventory item ${index + 1}`,
    standard_cost: index === 2 ? '123456789.1234' : sampleItem.standard_cost,
    on_hand_quantity: index === 2 ? '987654321.125' : sampleItem.on_hand_quantity,
    available_quantity: index === 2 ? '987654320.875' : sampleItem.available_quantity,
    stock_status: index % 5 === 0 ? 'critical' : index % 3 === 0 ? 'ok' : 'low',
    is_active: index % 7 !== 0,
    quality_plan_ready: index % 4 !== 0,
  }));
}

const sampleWorkOrder = {
  id: 'layout-wo-001', wo_number: 'WO-LAYOUT-202610-001', batch_number: 'BATCH-202610-001',
  material_lot_references: [], work_order_class: 'standard', exception_reason: null,
  exception_authorized_by: null, material_plan_source: null,
  product: { id: 'layout-product-1', part_number: 'PART-001', name: 'Layout test product' },
  parent: { id: 'layout-wo-parent', wo_number: 'WO-PARENT-001' },
  children: [{ id: 'layout-wo-child', wo_number: 'WO-CHILD-001', quantity_target: 25 }],
  sales_order: { id: 'layout-so-001', so_number: 'SO-LAYOUT-202610-001' },
  machine: { id: 'layout-machine-1', machine_code: 'MC-01', name: 'Layout press' },
  mold: { id: 'layout-mold-1', mold_code: 'MOLD-01', name: 'Layout mold' },
  production_purpose: 'stock',
  quantity_target: 200, quantity_produced: 50, quantity_good: 50, quantity_rejected: 0,
  progress_percentage: 25, scrap_rate: '0.00', planned_start: '2026-10-25T08:00:00+08:00',
  planned_end: '2026-10-25T16:00:00+08:00', actual_start: '2026-10-25T08:15:00+08:00',
  actual_end: null, status: 'in_progress', status_label: 'In progress', next_statuses: [],
  pause_reason: null, priority: 50, creator: null, materials: [], material_cost_summary: null,
  outputs: [], inspections: [], rework: [], execution_history: [],
  created_at: '2026-10-25T08:00:00+08:00', updated_at: '2026-10-25T09:00:00+08:00',
};

const operationStatuses = ['pending', 'setup', 'in_progress', 'paused', 'completed', 'skipped'];
function sampleWorkOrderOperations(count) {
  return Array.from({ length: count }, (_, index) => {
    const status = operationStatuses[index % operationStatuses.length];
    return {
      id: `layout-operation-${String(index + 1).padStart(6, '0')}`,
      sequence: (index + 1) * 10,
      operation_name: index === 1
        ? 'Long injection molding and detailed first-article inspection operation name'
        : `Production operation ${index + 1}`,
      status,
      status_label: status.replaceAll('_', ' '),
      machine: {
        id: 'layout-machine-1',
        machine_code: index === 2 ? 'MACHINE-REFERENCE-000000000000000000000001' : 'MC-01',
        name: 'Layout press',
      },
      mold: { id: 'layout-mold-1', mold_code: 'MOLD-01', name: 'Layout mold' },
      operator: status === 'pending' ? null : {
        id: `layout-operator-${index + 1}`,
        first_name: index === 3 ? 'Alexandria-Maria' : 'Ana',
        last_name: index === 3 ? 'Santos-Dela-Cruz' : 'Santos',
      },
      planned_start: '2026-10-25T08:00:00+08:00',
      planned_end: '2026-10-25T16:00:00+08:00',
      actual_start: status === 'pending' ? null : '2026-10-25T08:15:00+08:00',
      actual_end: status === 'completed' ? '2026-10-25T16:00:00+08:00' : null,
      setup_start: null,
      setup_end: null,
      qty_planned: index === 2 ? '123456789.8754' : '200',
      qty_completed: index === 2 ? '98765432.1256' : String(index * 25),
      qty_scrapped: '0',
      scrap_reason: null,
      downtime_minutes: '0',
      notes: null,
      logs: [],
    };
  });
}

for (const count of [0, 1, 10, 50, 500]) {
  assert.equal(sampleWorkOrderOperations(count).length, count);
}

function sampleWorkOrders(count) {
  return Array.from({ length: count }, (_, index) => ({
    ...sampleWorkOrder,
    id: index === 0 ? sampleWorkOrder.id : `layout-wo-${String(index + 1).padStart(6, '0')}`,
    wo_number: index === 0 ? sampleWorkOrder.wo_number : `WO-LAYOUT-${String(index + 1).padStart(6, '0')}`,
    product: {
      ...sampleWorkOrder.product,
      part_number: index === 1 ? 'PART-REFERENCE-000000000000000000000000000001' : `PART-${String(index + 1).padStart(5, '0')}`,
      name: index === 1
        ? 'Long product name for layout stress that should wrap or truncate without forcing unrelated columns to grow'
        : `Layout test product ${index + 1}`,
    },
    sales_order: index === 3
      ? { ...sampleWorkOrder.sales_order, so_number: 'SO-REFERENCE-000000000000000000000000000001' }
      : sampleWorkOrder.sales_order,
    machine: index === 2
      ? { ...sampleWorkOrder.machine, machine_code: 'MACHINE-REFERENCE-000000000000000000000001' }
      : sampleWorkOrder.machine,
    quantity_target: index === 2 ? 123456789 : 200 + index,
    quantity_produced: index === 2 ? 98765432 : index % 2 ? 50 + index : 0,
    progress_percentage: index === 2 ? 79.99 : index % 2 ? 25 : 0,
    planned_end: index === 0 ? sampleWorkOrder.planned_end : '2026-11-25T16:00:00+08:00',
    created_at: `2026-10-${String((index % 28) + 1).padStart(2, '0')}T08:00:00+08:00`,
  }));
}

const sampleRouting = {
  id: 'layout-routing-001',
  product: { id: 'layout-product-1', part_number: 'PART-001', name: 'Layout test product' },
  version: 12, is_active: false, total_cycle_time: '123456.75',
  notes: 'Long routing notes for measuring readable form line lengths across desktop and mobile layouts.',
  operations: [{
    id: 'layout-operation-001', sequence: 10, operation_name: 'Injection molding', work_center: 'Molding cell A',
    machine: { id: 'layout-machine-1', machine_code: 'MC-01', name: 'Layout press' },
    mold: { id: 'layout-mold-1', mold_code: 'MOLD-01', name: 'Layout mold' },
    setup_time_minutes: '15.00', cycle_time_minutes: '123456.75', labor_rate_per_hour: '125.5000',
    machine_rate_per_hour: '123456.7890', overhead_rate_per_hour: '9876.5432',
    description: 'Verify resin temperature and inspect first article before running the complete production batch.',
    qc_required: true,
  }],
  created_at: '2026-10-01T09:14:00+08:00', updated_at: '2026-10-25T16:20:00+08:00',
};

function sampleRoutings(count) {
  return Array.from({ length: count }, (_, index) => ({
    ...sampleRouting,
    id: index === 0 ? sampleRouting.id : `layout-routing-${String(index + 1).padStart(6, '0')}`,
    version: index === 2 ? 123456 : index + 1,
    is_active: index % 3 !== 2,
    total_cycle_time: index === 2 ? '123456789.75' : `${15.25 + index}`,
    product: {
      ...sampleRouting.product,
      part_number: index === 1 ? 'PART-REFERENCE-000000000000000000000000000001' : `PART-${String(index + 1).padStart(5, '0')}`,
      name: index === 1
        ? 'Long product name for routing layout checks that should not widen every table column'
        : `Layout test product ${index + 1}`,
    },
  }));
}

for (const count of [0, 1, 10, 50, 500]) assert.equal(sampleRoutings(count).length, count);

assert.equal(sampleWorkOrders(0).length, 0);
assert.equal(sampleWorkOrders(1)[0].id, sampleWorkOrder.id);
assert.equal(sampleWorkOrders(500).length, 500);

function sampleWarehouseTree(count) {
  return [{
    id: 'layout-warehouse-1', name: 'Main Warehouse', code: 'MW', address: null, is_active: true,
    zones: [{
      id: 'layout-zone-1', name: 'Raw materials', code: 'RM', zone_type: 'raw_materials',
      zone_type_label: 'Raw materials',
      locations: Array.from({ length: count }, (_, index) => ({
        id: `layout-location-${index + 1}`, zone_id: 'layout-zone-1',
        code: index === 1 ? 'LOC-REFERENCE-00001' : `A-${String(Math.floor(index / 100) + 1).padStart(2, '0')}-${String((index % 100) + 1).padStart(2, '0')}`,
        rack: `A-${String(Math.floor(index / 100) + 1).padStart(2, '0')}`,
        bin: String((index % 100) + 1).padStart(2, '0'),
        is_active: true, is_blocked: index % 17 === 0,
        full_code: `MW-RM-A-${String(Math.floor(index / 100) + 1).padStart(2, '0')}-${String((index % 100) + 1).padStart(2, '0')}`,
      })),
    }],
  }];
}

const sampleStockLevel = {
  item: { id: sampleItem.id, code: sampleItem.code, name: sampleItem.name, unit_of_measure: sampleItem.unit_of_measure },
  location: { id: 'layout-location-1', code: 'A-01-01', full_code: 'MW-RM-A-01-01' },
  quantity: '123456789.125', reserved_quantity: '234567.250', available: '123222221.875',
  usable_quantity: '123222221.875', expired_quantity: '0.000', unusable_zone_quantity: '25.000',
  quality_held_quantity: '1200.000', weighted_avg_cost: '1234567.8900', total_value: '152415787517145.00',
  lock_version: 0, last_counted_at: '2026-10-01T09:14:00+08:00',
};

function sampleStockLevels(count) {
  return Array.from({ length: count }, (_, index) => ({
    ...sampleStockLevel,
    item: {
      ...sampleStockLevel.item,
      id: index === 0 ? sampleItem.id : `layout-item-${String(index + 1).padStart(6, '0')}`,
      code: index === 0 ? sampleItem.code : `SKU-${String(index + 1).padStart(6, '0')}`,
      name: index === 1
        ? 'Long inventory material description for stock level column sizing and truncation checks'
        : index === 0 ? sampleItem.name : `Inventory item ${index + 1}`,
    },
    location: {
      id: `layout-location-${index + 1}`,
      code: `A-${String(Math.floor(index / 100) + 1).padStart(2, '0')}-${String((index % 100) + 1).padStart(2, '0')}`,
      full_code: `MW-RM-A-${String(Math.floor(index / 100) + 1).padStart(2, '0')}-${String((index % 100) + 1).padStart(2, '0')}`,
    },
    quantity: index === 2 ? '987654321.125' : sampleStockLevel.quantity,
    reserved_quantity: index === 2 ? '12345678.250' : sampleStockLevel.reserved_quantity,
    available: index === 2 ? '975308642.875' : sampleStockLevel.available,
    usable_quantity: index === 2 ? '975308642.875' : sampleStockLevel.usable_quantity,
    weighted_avg_cost: index === 2 ? '123456789.1234' : sampleStockLevel.weighted_avg_cost,
    total_value: index === 2 ? '999999999999999.99' : sampleStockLevel.total_value,
  }));
}

function sampleUoms(count) {
  return Array.from({ length: count }, (_, index) => ({
    id: index === 0 ? 'layout-uom-kg' : `layout-uom-${String(index + 1).padStart(6, '0')}`,
    code: index === 0 ? 'kg' : `UOM-${String(index + 1).padStart(4, '0')}`,
    name: index === 1
      ? 'Long unit name for layout checks, readability, and narrow table behavior'
      : index === 0 ? 'Kilogram' : `Unit ${index + 1}`,
    deleted_at: null,
  }));
}

const movementTypes = ['grn_receipt', 'material_issue', 'material_return', 'transfer', 'adjustment_in', 'delivery'];
function sampleStockMovements(count) {
  return Array.from({ length: count }, (_, index) => {
    const movementType = movementTypes[index % movementTypes.length];
    return {
      id: `layout-movement-${String(index + 1).padStart(6, '0')}`,
      created_at: `2026-10-${String((index % 28) + 1).padStart(2, '0')}T09:14:00+08:00`,
      movement_type: movementType,
      movement_type_label: movementType.replaceAll('_', ' '),
      item: {
        id: `layout-item-${String(index + 1).padStart(6, '0')}`,
        code: index === 0 ? sampleItem.code : `SKU-${String(index + 1).padStart(6, '0')}`,
        name: index === 1
          ? 'Long material description for checking stock movement table wrapping and truncation behavior'
          : `Inventory item ${index + 1}`,
        unit_of_measure: 'kg',
      },
      from_location: movementType === 'material_issue' || movementType === 'transfer'
        ? { id: 'layout-location-from', code: 'MW-RM-A-01-01' } : null,
      to_location: movementType === 'grn_receipt' || movementType === 'transfer'
        ? { id: 'layout-location-to', code: 'MW-RM-A-02-01' } : null,
      quantity: index === 2 ? '987654321.125' : `${(index + 1) * 1.125}`,
      unit_cost: index === 2 ? '123456789.1234' : '1234.5000',
      total_cost: index === 2 ? '999999999999999.99' : '1388.81',
      lot_number: index % 2 ? `LOT-${String(index + 1).padStart(6, '0')}` : null,
      expiry_date: null,
      gl_handoff: {
        status: index % 3 === 0 ? 'generated' : index % 3 === 1 ? 'manual_required' : 'not_required',
        status_label: index % 3 === 0 ? 'Posted' : index % 3 === 1 ? 'Manual required' : 'Not required',
        message: null,
        at: '2026-10-25T09:14:00+08:00',
      },
      reference_type: 'material_issue',
      reference_id: `REF-${String(index + 1).padStart(6, '0')}`,
      reference_label: index === 1 ? 'Long material reference with a description that should not widen the table' : `Movement reference ${index + 1}`,
      reference_work_order_id: null,
      reference_url: null,
      remarks: index === 2 ? 'Long movement note used to check tooltip and text overflow behavior.' : null,
      creator: { id: 'layout-user-1', name: index === 1 ? 'Alexandria-Maria Santos-Dela-Cruz' : 'Ana Santos' },
    };
  });
}

function sampleReservations(count) {
  return Array.from({ length: count }, (_, index) => ({
    id: `layout-reservation-group-${index + 1}`,
    work_order: {
      id: `layout-work-order-${index + 1}`,
      wo_number: index === 1 ? 'WO-202610-0000000000001' : `WO-202610-${String(index + 1).padStart(6, '0')}`,
    },
    materials: [
      {
        id: `layout-reservation-${index + 1}-1`,
        work_order: { id: `layout-work-order-${index + 1}`, wo_number: `WO-202610-${String(index + 1).padStart(6, '0')}` },
        item: {
          id: `layout-item-${index + 1}`,
          code: index === 0 ? sampleItem.code : `SKU-${String(index + 1).padStart(6, '0')}`,
          name: index === 1 ? 'Long material description for expanded reservation details' : `Inventory item ${index + 1}`,
          unit_of_measure: 'kg',
        },
        location: { id: `layout-location-${index + 1}`, full_code: `MW-RM-A-${String(index + 1).padStart(2, '0')}-01` },
        quantity: index === 2 ? '987654321.125' : `${(index + 1) * 10}.000`,
        status: index % 3 === 0 ? 'reserved' : index % 3 === 1 ? 'issued' : 'released',
        reserved_at: '2026-10-25T09:14:00+08:00',
        ended_at: null,
      },
    ],
  }));
}

for (const count of [0, 1, 10, 50, 500]) {
  assert.equal(sampleItems(count).length, count);
  assert.equal(sampleUoms(count).length, count);
  assert.equal(sampleWarehouseTree(count)[0].zones[0].locations.length, count);
  assert.equal(sampleStockLevels(count).length, count);
  assert.equal(sampleStockMovements(count).length, count);
  assert.equal(sampleReservations(count).length, count);
}

function addDays(date, days) {
  const value = new Date(`${date}T00:00:00Z`);
  value.setUTCDate(value.getUTCDate() + days);
  return value.toISOString().slice(0, 10);
}

function sampleScheduleSnapshot(count, from, to) {
  const days = Math.max(1, Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000));
  const machineCount = Math.max(1, Math.min(10, count));
  const rows = Array.from({ length: machineCount }, (_, index) => ({
    machine_id: `layout-schedule-machine-${index + 1}`,
    machine_code: `IMM-${String(index + 1).padStart(2, '0')}`,
    name: index === 1 ? 'Large-capacity injection molding machine for long label checks' : `Injection machine ${index + 1}`,
    tonnage: 120 + index * 10,
    status: index % 2 ? 'running' : 'idle',
    current_owner: index === 0 ? { id: 'layout-schedule-owner', wo_number: 'WO-202610-0001' } : null,
    bars: [],
  }));
  for (let index = 0; index < count; index += 1) {
    const machine = rows[index % machineCount];
    const date = addDays(from, index % days);
    const hour = 7 + (index % 9);
    const start = `${date}T${String(hour).padStart(2, '0')}:00:00+08:00`;
    const end = `${date}T${String(hour + 1).padStart(2, '0')}:00:00+08:00`;
    const status = index % 5 === 0 ? 'executed' : index % 3 === 0 ? 'confirmed' : 'pending';
    machine.bars.push({
      id: `layout-schedule-${index + 1}`,
      updated_at: '2026-10-27T02:00:00.000Z',
      wo_id: `layout-schedule-wo-${index + 1}`,
      wo_number: index === 0 ? 'WO-202610-000001' : `WO-202610-${String(index + 1).padStart(6, '0')}`,
      product_name: index === 1
        ? 'Very long injection-molded product name for table width and wrapping checks'
        : `Product ${index + 1}`,
      product_part_number: index === 1 ? 'PART-REFERENCE-000000000000000000000001' : `PART-${String(index + 1).padStart(5, '0')}`,
      quantity_target: index === 2 ? 123456789.125 : 250 + index * 10,
      sales_order: index % 3 === 0 ? { id: `layout-schedule-so-${index + 1}`, so_number: `SO-202610-${String(index + 1).padStart(6, '0')}` } : null,
      mold_code: index % 4 === 0 ? null : `MOLD-${String((index % 10) + 1).padStart(3, '0')}`,
      start,
      end,
      status,
      wo_status: status === 'executed' ? 'completed' : status === 'confirmed' ? 'confirmed' : 'planned',
      reserves_capacity: status !== 'executed',
      staffing: {
        worker_names: index === 1 ? ['A very long employee name for schedule width checks', 'Production Worker'] : ['Ana Santos', 'Juan Dela Cruz'],
        worker_count: index === 1 ? 2 : 2,
        minimum_workers: index === 1 ? 3 : 2,
        recorder_name: 'Layout Auditor',
        blockers: index === 1 ? ['Minimum worker count not met'] : [],
        execution_blockers: [],
        expected_crew_id: `layout-schedule-crew-${index + 1}`,
        approval_blockers: [],
      },
    });
  }
  return {
    from: `${from}T00:00:00+08:00`,
    to: `${to}T00:00:00+08:00`,
    timezone: 'Asia/Manila',
    rows,
    conflicts: count ? [{
      work_order_id: 'layout-schedule-conflict',
      wo_number: 'WO-202610-CONFLICT',
      reasons: ['No covered shift', 'No covered shift', 'Machine readiness needs review'],
      checked_at: '2026-10-27T02:00:00.000Z',
    }] : [],
  };
}

assert.equal(addDays('2026-12-31', 1), '2027-01-01');
assert.equal(sampleScheduleSnapshot(0, '2026-10-27', '2026-11-10').rows.flatMap((row) => row.bars).length, 0);
assert.equal(sampleScheduleSnapshot(10, '2026-10-27', '2026-11-10').rows.flatMap((row) => row.bars).length, 10);

async function installLocalMocks(page, role, rowCount, themeMode) {
  const user = localUser(role, themeMode);
  await page.route('**/api/v1/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname;
    let json = { data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } };
    if (pathname.endsWith('/auth/user')) json = { data: user };
    else if (pathname.endsWith('/dashboard/dispatch')) json = { data: { target: { path: '/dashboard/default' }, candidates: [] } };
    else if (pathname.endsWith('/business-policies')) json = { data: { functional_currency_code: 'PHP', reporting_currency_code: 'PHP' } };
    else if (pathname.endsWith('/landing/contact')) json = { legal_name: 'OGAMI ERP' };
    else if (pathname.endsWith('/dashboards/badges')) json = { data: {} };
    else if (pathname.endsWith('/mrp/scheduler/options')) json = { data: { default_horizon_days: 14 } };
    else if (pathname.endsWith('/mrp/scheduler/snapshot')) {
      const query = new URL(route.request().url()).searchParams;
      const from = query.get('from') ?? '2026-10-27';
      const to = query.get('to') ?? addDays(from, 14);
      json = { data: sampleScheduleSnapshot(rowCount, from, to) };
    }
    else if (pathname.endsWith('/inventory/items/options')) json = {
      data: {
        replenishment_basis: { demand_history_days: 90, coverage_buffer_ratio: 1.2, default_lead_time_days: 14 },
        item_types: [{ value: 'raw_material', label: 'Raw material' }],
        reorder_methods: [{ value: 'fixed_quantity', label: 'Fixed quantity' }, { value: 'days_of_supply', label: 'Days of supply' }],
        adjustment_directions: [{ value: 'in', label: 'Increase' }, { value: 'out', label: 'Decrease' }],
        stock_statuses: [{ value: 'ok', label: 'OK' }, { value: 'low', label: 'Low' }, { value: 'critical', label: 'Critical' }],
      },
    };
    else if (pathname.endsWith(`/inventory/items/${sampleItem.id}`)) json = { data: sampleItem };
    else if (pathname.endsWith(`/production/work-orders/${sampleWorkOrder.id}/operations`)) json = { data: sampleWorkOrderOperations(rowCount) };
    else if (pathname.endsWith(`/production/work-orders/${sampleWorkOrder.id}`)) json = { data: sampleWorkOrder };
    else if (pathname.endsWith(`/production/routings/${sampleRouting.id}`)) json = { data: sampleRouting };
    else if (pathname.endsWith('/production/routings')) json = {
      data: sampleRoutings(rowCount),
      meta: { current_page: 1, last_page: 1, per_page: Math.max(rowCount, 25), total: rowCount,
        from: rowCount ? 1 : 0, to: rowCount },
    };
    else if (pathname.endsWith('/crm/products')) json = {
      data: [{ ...sampleWorkOrder.product, unit_of_measure: 'piece', include_forecast_in_mrp: false, image_url: null }],
      meta: { current_page: 1, last_page: 1, per_page: 100, total: 1, from: 1, to: 1 },
    };
    else if (pathname.endsWith('/mrp/machines')) json = {
      data: [{ id: 'layout-machine-1', machine_code: 'MC-01', name: 'Layout press' }],
      meta: { current_page: 1, last_page: 1, per_page: 100, total: 1, from: 1, to: 1 },
    };
    else if (pathname.endsWith('/mrp/molds')) json = {
      data: [{ id: 'layout-mold-1', mold_code: 'MOLD-01', name: 'Layout mold' }],
      meta: { current_page: 1, last_page: 1, per_page: 100, total: 1, from: 1, to: 1 },
    };
    else if (pathname.endsWith('/production/work-orders/form-options')) json = {
      data: {
        products: [{ ...sampleWorkOrder.product, stock_production: { eligible: true, blockers: [] } }],
        machines: [{ id: 'layout-machine-1', machine_code: 'MC-01', name: 'Layout press', tonnage: 120, status: 'active' }],
        molds: [{ id: 'layout-mold-1', mold_code: 'MOLD-01', name: 'Layout mold', product: sampleWorkOrder.product, cavity_count: 2, status: 'active' }],
        shifts: [{ id: 'layout-shift-1', name: 'Day shift' }],
      },
    };
    else if (pathname.endsWith('/production/work-orders/options')) json = {
      data: { statuses: [
        { value: 'planned', label: 'Planned', next_statuses: [] },
        { value: 'in_progress', label: 'In progress', next_statuses: [] },
      ], operation_statuses: [] },
    };
    else if (pathname.endsWith('/production/work-orders')) json = {
      data: sampleWorkOrders(rowCount),
      meta: { current_page: 1, last_page: 1, per_page: Math.max(rowCount, 25), total: rowCount,
        from: rowCount ? 1 : 0, to: rowCount },
    };
    else if (pathname.endsWith(`/production/work-orders/${sampleWorkOrder.id}/crew`)) json = {
      data: { current: null, history: [], minimum_workers: 1, maximum_workers: null, can_assign: false },
    };
    else if (pathname.endsWith(`/production/work-orders/${sampleWorkOrder.id}/chain`)) json = { data: [] };
    else if (pathname.endsWith('/inventory/items')) json = {
      data: sampleItems(rowCount), meta: { current_page: 1, last_page: Math.max(1, Math.ceil(rowCount / 100)), per_page: 100, total: rowCount, from: rowCount ? 1 : 0, to: Math.min(rowCount, 100) },
      links: { first: '', last: '', prev: null, next: null },
    };
    else if (pathname.endsWith('/inventory/warehouse')) json = { data: sampleWarehouseTree(rowCount) };
    else if (pathname.endsWith('/inventory/stock-levels')) json = {
      data: sampleStockLevels(rowCount), meta: { current_page: 1, last_page: Math.max(1, Math.ceil(rowCount / 100)), per_page: 100, total: rowCount, from: rowCount ? 1 : 0, to: Math.min(rowCount, 100) },
      links: { first: '', last: '', prev: null, next: null },
    };
    else if (pathname.endsWith('/inventory/stock-movements/options')) json = {
      data: { movement_types: movementTypes.map((value) => ({ value, label: value.replaceAll('_', ' ') })) },
    };
    else if (/\/inventory\/stock-movements\/[^/]+\/return-options$/.test(pathname)) json = {
      data: {
        eligible: true, source_movement_id: 'layout-movement-000002', issued_quantity: '20.000',
        returned_quantity: '0.000', returned_cost: '0.00', returnable_quantity: '20.000',
        unit_cost: '1234.5000', unit_of_measure: 'kg', total_returned_at_original_cost: '0.00',
        location: 'MW-RM-A-01-01', lot_number: 'LOT-000002', gross_production_units: '0',
        consumption_floor: '0', consumption_basis: 'unlinked_issue', message: null,
      },
    };
    else if (pathname.endsWith('/inventory/stock-movements')) json = {
      data: sampleStockMovements(rowCount),
      meta: { current_page: 1, last_page: Math.max(1, Math.ceil(rowCount / 50)), per_page: 50, total: rowCount, from: rowCount ? 1 : 0, to: Math.min(rowCount, 50) },
    };
    else if (pathname.endsWith('/inventory/material-reservations')) json = {
      data: sampleReservations(rowCount),
      meta: { current_page: 1, last_page: Math.max(1, Math.ceil(rowCount / 50)), per_page: 50, total: rowCount, from: rowCount ? 1 : 0, to: Math.min(rowCount, 50) },
    };
    else if (pathname.endsWith('/inventory/uoms')) json = {
      data: sampleUoms(rowCount),
    };
    else if (pathname.includes('/inventory/stock-adjustments/options')) json = {
      data: {
        statuses: [{ value: 'pending', label: 'Pending' }, { value: 'approved', label: 'Approved' }, { value: 'rejected', label: 'Rejected' }],
        directions: [{ value: 'in', label: 'Increase' }, { value: 'out', label: 'Decrease' }],
        reasons: [{ value: 'cycle_count', label: 'Cycle count correction' }],
        approval_threshold: '1000000.00',
      },
    };
    else if (pathname.endsWith('/inventory/stock-adjustments')) {
      const data = sampleAdjustments(rowCount);
      json = {
        data,
        meta: { current_page: 1, last_page: Math.max(1, Math.ceil(rowCount / 50)), per_page: 50, total: rowCount,
          from: rowCount ? 1 : 0, to: Math.min(rowCount, 50) },
      };
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(json) });
  });
}

async function startLocalServer() {
  const server = spawn(process.execPath, ['node_modules/vite/bin/vite.js', '--host', '127.0.0.1', '--port', '5174', '--strictPort'], {
    cwd: SPA_ROOT,
    env: {
      ...process.env,
      VITE_DISABLE_API_PROXY: 'true',
      VITE_REVERB_APP_KEY: 'ogami_reverb',
      VITE_REVERB_HOST: '127.0.0.1',
      VITE_REVERB_PORT: '8080',
      VITE_REVERB_SCHEME: 'http',
      VITE_HMR_HOST: '127.0.0.1',
      VITE_HMR_PORT: '5174',
    },
    stdio: 'ignore',
  });
  for (let attempt = 0; attempt < 60; attempt += 1) {
    try {
      const response = await fetch(BASE);
      if (response.ok) return server;
    } catch { /* Vite has not opened its port yet. */ }
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  server.kill();
  throw new Error('Vite did not become ready at 127.0.0.1:5174');
}

async function measure(page) {
  return page.evaluate(async () => {
    const rect = (element) => {
      if (!element) return null;
      const box = element.getBoundingClientRect();
      const css = getComputedStyle(element);
      const left = box.left + Number.parseFloat(css.paddingLeft || '0');
      const right = box.right - Number.parseFloat(css.paddingRight || '0');
      return { left, right, top: box.top, bottom: box.bottom, width: box.width, height: box.height };
    };
    const color = (value) => {
      const rgb = value.match(/^rgba?\(([^)]+)\)$/i);
      if (rgb) {
        const parts = rgb[1].split(',').map((part) => Number.parseFloat(part.trim()));
        return parts.length >= 3 && (parts[3] === undefined || parts[3] >= 0.99)
          ? parts.slice(0, 3)
          : null;
      }
      const srgb = value.match(/^color\(srgb\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)(?:\s*\/\s*([\d.]+))?\)$/i);
      if (srgb && (srgb[4] === undefined || Number(srgb[4]) >= 0.99)) {
        return srgb.slice(1, 4).map((part) => Number(part) * 255);
      }
      const hex = value.match(/^#([\da-f]{3}|[\da-f]{6})$/i)?.[1];
      if (!hex) return null;
      const expanded = hex.length === 3 ? [...hex].map((part) => part + part).join('') : hex;
      return [0, 2, 4].map((offset) => Number.parseInt(expanded.slice(offset, offset + 2), 16));
    };
    const opaqueBackground = (element) => {
      for (let node = element; node instanceof HTMLElement; node = node.parentElement) {
        const parsed = color(getComputedStyle(node).backgroundColor);
        if (parsed) return parsed;
      }
      return null;
    };
    const ratio = (fg, bg) => {
      if (!fg || !bg) return null;
      const lum = (rgb) => rgb.map((v) => {
        const n = v / 255;
        return n <= 0.04045 ? n / 12.92 : ((n + 0.055) / 1.055) ** 2.4;
      }).reduce((sum, n, i) => sum + n * [0.2126, 0.7152, 0.0722][i], 0);
      const a = lum(fg);
      const b = lum(bg);
      return Number(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)).toFixed(2));
    };
    const main = document.querySelector('main#main-content, main');
    const dialog = document.querySelector('[role="dialog"]');
    const pageHeader = main?.querySelector('[data-layout="page-header"]')
      ?? main?.querySelector('h1')?.closest('div.px-5.py-4')
      ?? main?.querySelector('h1')?.parentElement?.parentElement;
    const pageRoot = main?.querySelector('[data-layout="page-shell"]')
      ?? main?.firstElementChild?.firstElementChild ?? main?.firstElementChild;
    const flowElements = pageRoot ? [...pageRoot.children] : [];
    const flowBlocks = flowElements.map((element, index) => {
      const css = getComputedStyle(element);
      const box = element.getBoundingClientRect();
      const next = flowElements[index + 1]?.getBoundingClientRect();
      const heading = element.querySelector('h1,h2,h3,h4,[role="heading"]');
      return {
        index,
        tag: element.tagName.toLowerCase(),
        className: typeof element.className === 'string' ? element.className : '',
        heading: (heading?.textContent ?? '').trim().replace(/\s+/g, ' ').slice(0, 100),
        bounds: rect(element),
        margin: { top: css.marginTop, right: css.marginRight, bottom: css.marginBottom, left: css.marginLeft },
        padding: { top: css.paddingTop, right: css.paddingRight, bottom: css.paddingBottom, left: css.paddingLeft },
        gapAfter: next ? Math.round((next.top - box.bottom) * 100) / 100 : null,
        touchesNext: next ? next.top - box.bottom <= 0.5 : false,
        overlapsNext: next ? next.top < box.bottom - 1 : false,
        horizontalOverflow: element.scrollWidth > element.clientWidth + 1,
        verticalOverflow: element.scrollHeight > element.clientHeight + 1,
      };
    });
    const sections = [...(main?.querySelectorAll('[data-layout="section"]') ?? [])].map((section) => {
      const style = getComputedStyle(section);
      const parent = section.parentElement;
      const parentStyle = parent ? getComputedStyle(parent) : null;
      const body = section.lastElementChild;
      const bodyStyle = body ? getComputedStyle(body) : null;
      return {
        title: (section.querySelector('h2,h3,h4')?.textContent ?? '').trim().replace(/\s+/g, ' ').slice(0, 100),
        className: typeof section.className === 'string' ? section.className : '',
        bounds: rect(section),
        display: style.display,
        overflowY: style.overflowY,
        gridParent: parent ? {
          className: typeof parent.className === 'string' ? parent.className : '',
          bounds: rect(parent),
          display: parentStyle.display,
          gridTemplateRows: parentStyle.gridTemplateRows,
        } : null,
        body: body ? {
          className: typeof body.className === 'string' ? body.className : '',
          bounds: rect(body),
          display: bodyStyle.display,
          overflowY: bodyStyle.overflowY,
          scrollHeight: body.scrollHeight,
          clientHeight: body.clientHeight,
        } : null,
      };
    });
    const filterBar = main?.querySelector('[data-layout="filter-bar"]')
      ?? main?.querySelector('[role="search"]')?.parentElement;
    const table = main?.querySelector('[data-layout="table"]') ?? main?.querySelector('table');
    const scheduleScroller = main?.querySelector('[data-layout="schedule-scroll-region"]');
    const scheduleFrame = scheduleScroller?.closest('.scroll-edge-frame');
    const tableCard = table?.closest('[data-layout="table-scroller"]') ?? table?.parentElement;
    const tableSection = table?.closest('[data-layout="section"]');
    const tableAlignmentReference = tableSection?.lastElementChild ?? pageHeader;
    const tableRoot = table?.closest('[data-layout="data-table"]') ?? tableCard?.parentElement;
    const toolbar = tableRoot?.querySelector('[data-layout="table-toolbar"]')
      ?? (tableRoot && [...tableRoot.children].find((element) => element.classList.contains('justify-end')));
    const pagination = tableRoot?.querySelector('[data-layout="pagination"]')
      ?? (tableRoot && [...tableRoot.children].find((element) => element.classList.contains('mt-3')));
    const pageBlocks = {
      header: rect(pageHeader),
      filters: rect(filterBar),
      toolbar: rect(toolbar),
      table: rect(tableCard ?? scheduleScroller),
      pagination: rect(pagination),
    };
    const reference = pageBlocks.header;
    const alignment = Object.fromEntries(Object.entries(pageBlocks).map(([key, box]) => {
      const edgeReference = ['toolbar', 'table', 'pagination'].includes(key) && tableSection
        ? rect(tableAlignmentReference) : reference;
      return [key,
      box && edgeReference
        ? { reference: ['toolbar', 'table', 'pagination'].includes(key) && tableSection ? 'section-content' : 'page-content',
            leftDelta: Math.round((box.left - edgeReference.left) * 100) / 100,
            rightDelta: Math.round((box.right - edgeReference.right) * 100) / 100,
            aligned: Math.abs(box.left - edgeReference.left) <= 1 && Math.abs(box.right - edgeReference.right) <= 1 }
        : null,
    ];
    }));
    const sidebar = document.querySelector('aside[aria-label="Sidebar"]');
    const sidebarBox = sidebar ? sidebar.getBoundingClientRect() : null;
    const firstContentLeft = reference?.left ?? pageBlocks.toolbar?.left ?? main?.getBoundingClientRect().left ?? 0;
    const countSidebarRail = innerWidth >= 768 && sidebarBox && getComputedStyle(sidebar).display !== 'none';
    const sidebarClearanceByBlock = Object.fromEntries(Object.entries(pageBlocks).map(([key, box]) => [
      key,
      countSidebarRail && box
        ? Math.round((box.left - sidebarBox.right) * 100) / 100 : null,
    ]));
    const rows = table ? [...table.querySelectorAll('tbody > tr')]
      .filter((row) => row.closest('table') === table && row.offsetHeight > 0) : [];
    const heights = rows.map((row) => Math.round(row.getBoundingClientRect().height * 100) / 100);
    const unique = (values) => [...new Set(values)];
    const typeNodes = [
      ...Array.from(main?.querySelectorAll('h1,h2,h3,p,label,button,th,tbody td,.text-muted') ?? []),
      ...Array.from(dialog?.querySelectorAll('h1,h2,h3,p,label,button,th,tbody td,.text-muted') ?? []),
    ];
    const typography = typeNodes.map((element) => {
      const css = getComputedStyle(element);
      const fg = color(css.color);
      const bg = opaqueBackground(element);
      return {
        tag: element.tagName.toLowerCase(),
        className: typeof element.className === 'string' ? element.className : '',
        text: (element.textContent ?? '').trim().replace(/\s+/g, ' ').slice(0, 80),
        font: css.fontFamily,
        size: Number.parseFloat(css.fontSize),
        weight: css.fontWeight,
        lineHeight: css.lineHeight,
        letterSpacing: css.letterSpacing,
        textTransform: css.textTransform,
        color: css.color,
        background: css.backgroundColor,
        contrast: ratio(fg, bg),
      };
    });
    const overflow = [...document.querySelectorAll('body,main,*')].flatMap((element) => {
      if (element.clientWidth === 0 || element.clientHeight === 0) return [];
      const css = getComputedStyle(element);
      const horizontal = element.scrollWidth > element.clientWidth + 1;
      const vertical = element.scrollHeight > element.clientHeight + 1 && /auto|scroll/.test(css.overflowY);
      if (!horizontal && !vertical) return [];
      const position = css.position;
      const classText = typeof element.className === 'string' ? element.className : '';
      const intentionalHorizontal = horizontal && (
        element.matches('[data-layout="table-scroller"]')
        || element.matches('[data-layout="schedule-scroll-region"]')
        ||
        /overflow-x-(auto|scroll)/.test(classText)
        || /truncate|sr-only/.test(classText)
        || position === 'absolute'
        || position === 'fixed'
        || [...element.children].some((child) => ['absolute', 'fixed'].includes(getComputedStyle(child).position))
      );
      return [{
        tag: element.tagName.toLowerCase(),
        className: classText,
        horizontal,
        intentionalHorizontal,
        vertical,
        insideMain: Boolean(main?.contains(element)),
        insideSidebar: Boolean(sidebar?.contains(element)),
        overflowX: css.overflowX,
        overflowY: css.overflowY,
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        scrollHeight: element.scrollHeight,
        clientHeight: element.clientHeight,
      }];
    });
    const buttons = [...(main?.querySelectorAll('button') ?? []), ...(dialog?.querySelectorAll('button') ?? [])].map((button) => {
      const css = getComputedStyle(button);
      const box = button.getBoundingClientRect();
      return {
        label: button.getAttribute('aria-label') || button.title || button.textContent?.trim() || '',
        width: Math.round(box.width),
        height: Math.round(box.height),
        background: css.backgroundColor,
        color: css.color,
      };
    });
    const rootStyle = getComputedStyle(document.documentElement);
    const bodyStyle = getComputedStyle(document.body);
    const dropdowns = [...(main?.querySelectorAll('select:not(.sr-only),input[role="combobox"],[role="listbox"]') ?? [])]
      .map((element) => {
        const css = getComputedStyle(element);
        const option = element instanceof HTMLSelectElement ? element.querySelector('option') : null;
        return {
          role: element.getAttribute('role') || element.tagName.toLowerCase(),
          colorScheme: css.colorScheme,
          color: css.color,
          background: css.backgroundColor,
          optionColor: option ? getComputedStyle(option).color : null,
          optionBackground: option ? getComputedStyle(option).backgroundColor : null,
        };
      });
    const controls = [
      ...(main?.querySelectorAll('button:not(.sr-only),a[href],summary,input:not([type="hidden"]):not(.sr-only),select:not(.sr-only),textarea,[role="button"],[role="separator"]') ?? []),
      ...(dialog?.querySelectorAll('button:not(.sr-only),a[href],summary,input:not([type="hidden"]):not(.sr-only),select:not(.sr-only),textarea,[role="button"],[role="separator"]') ?? []),
    ]
      .filter((control) => control.getAttribute('aria-hidden') !== 'true' && control.tabIndex !== -1)
      .map((control) => {
        const target = control instanceof HTMLInputElement && control.type === 'checkbox'
          ? control.closest('label') ?? control
          : control;
        const box = target.getBoundingClientRect();
        return {
          label: control.getAttribute('aria-label') || control.getAttribute('name') || control.textContent?.trim() || control.tagName.toLowerCase(),
          width: Math.round(box.width),
          height: Math.round(box.height),
        };
      })
      .filter((control) => control.width > 0 && control.height > 0);
    const bodyTextColor = getComputedStyle(document.body).color;
    const recordLinks = [...(main?.querySelectorAll('a[href].font-mono,button.font-mono') ?? [])]
      .map((element) => {
        const css = getComputedStyle(element);
        return {
          text: (element.textContent ?? '').trim().replace(/\s+/g, ' ').slice(0, 100),
          color: css.color,
          contrast: ratio(color(css.color), opaqueBackground(element)),
          distinctFromBodyText: css.color !== bodyTextColor,
        };
      });
    const thead = table?.querySelector('thead');
    const ths = table ? [...table.querySelectorAll('thead th')] : [];
    const cells = ths.map((th, index) => {
      const bodyCell = table.querySelector(`tbody tr:not(:has(td[colspan])) td:nth-child(${index + 1})`);
      const h = th.getBoundingClientRect();
      const css = getComputedStyle(th);
      return {
        header: (th.textContent ?? '').trim(),
        width: Math.round(h.width),
        share: th.parentElement?.getBoundingClientRect().width
          ? Number((h.width / th.parentElement.getBoundingClientRect().width).toFixed(3)) : null,
        headerAlign: css.textAlign,
        cellAlign: bodyCell ? getComputedStyle(bodyCell).textAlign : null,
        sticky: css.position === 'sticky',
      };
    });
    const yGap = (a, b) => a && b ? Math.round((b.top - a.bottom) * 100) / 100 : null;
    const tableRows = rows.filter((row) => !row.querySelector('td[colspan]'));
    const tableOwner = table?.closest('[data-layout="data-table"]');
    const tableDensity = tableOwner?.getAttribute('data-density') ?? (table ? 'manual' : 'default');
    const tableTextMinimum = tableDensity === 'compact' || tableDensity === 'manual' ? 13 : 14;
    const firstRow = tableRows[0];
    const lastRow = tableRows.at(-1);
    const doc = document.documentElement;
    const originalHorizontalScroll = doc.scrollLeft;
    doc.scrollLeft = doc.scrollWidth;
    const horizontalPageMaxScroll = doc.scrollLeft;
    doc.scrollLeft = originalHorizontalScroll;
    const pageVerticalScroll = doc.scrollHeight > innerHeight + 1
      || Boolean(pageRoot && pageRoot.scrollHeight > pageRoot.clientHeight + 1
        && /auto|scroll/.test(getComputedStyle(pageRoot).overflowY));
    const internalVerticalScroll = overflow.filter((item) => item.vertical && item.insideMain
      && !item.className.includes('page-shell--viewport'));
    const sidebarVerticalScroll = overflow.some((item) => item.vertical && item.insideSidebar);
    const layoutContext = [...document.querySelectorAll('html, body, #root, .app-shell, .app-content, .app-main, .route-transition, main, [data-layout="page-shell"], aside[aria-label="Sidebar"]')]
      .map((element) => {
        const box = element.getBoundingClientRect();
        const css = getComputedStyle(element);
        return {
          tag: element.tagName.toLowerCase(),
          className: typeof element.className === 'string' ? element.className : '',
          left: Math.round(box.left), right: Math.round(box.right), width: Math.round(box.width),
          scrollWidth: element.scrollWidth, clientWidth: element.clientWidth, scrollLeft: element.scrollLeft,
          overflowX: css.overflowX,
        };
      });
    const elementsPastViewport = [...document.body.querySelectorAll('*')]
      .map((element) => ({ element, box: element.getBoundingClientRect(), css: getComputedStyle(element) }))
      .filter(({ element, box, css }) => {
        if (box.right <= innerWidth + 1 || element.closest('.sr-only') || css.position === 'fixed') return false;
        return true;
      })
      .sort((a, b) => b.box.right - a.box.right)
      .slice(0, 12)
      .map(({ element, box, css }) => ({
        tag: element.tagName.toLowerCase(),
        className: typeof element.className === 'string' ? element.className : '',
        left: Math.round(box.left), right: Math.round(box.right), width: Math.round(box.width),
        overflowX: css.overflowX,
        insideHorizontalScroll: Boolean(element.closest('[data-layout="table-scroller"], [data-layout="schedule-scroll-region"]')
          ?? element.closest('.overflow-x-auto, .overflow-x-scroll')),
      }));
    const rootPseudoElements = ['::before', '::after'].map((pseudo) => {
      const css = getComputedStyle(document.documentElement, pseudo);
      return { pseudo, content: css.content, position: css.position, left: css.left, right: css.right, width: css.width };
    });
    const uncontainedElementsPastViewport = elementsPastViewport
      .filter(({ insideHorizontalScroll }) => !insideHorizontalScroll);
    const actualTableBottom = tableCard?.getBoundingClientRect().bottom;
    const stickyHeaderCss = Boolean(thead && getComputedStyle(thead).position === 'sticky')
      || ths.some((th) => getComputedStyle(th).position === 'sticky');
    const stickyProbe = {
      cssSticky: stickyHeaderCss, tested: false, sticksToScroller: null,
      topAfterScroll: null, scrollerTopAfterScroll: null,
    };
    if (stickyHeaderCss && tableCard && tableCard.scrollHeight > tableCard.clientHeight + 1 && thead) {
      const originalScrollTop = tableCard.scrollTop;
      tableCard.scrollTop = Math.min(tableCard.scrollHeight, originalScrollTop + 120);
      await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
      const headerBox = thead.getBoundingClientRect();
      const scrollerBox = tableCard.getBoundingClientRect();
      stickyProbe.tested = true;
      stickyProbe.topAfterScroll = Math.round(headerBox.top * 100) / 100;
      stickyProbe.scrollerTopAfterScroll = Math.round(scrollerBox.top * 100) / 100;
      stickyProbe.sticksToScroller = Math.abs(headerBox.top - scrollerBox.top - 1) <= 2;
      tableCard.scrollTop = originalScrollTop;
      await new Promise((resolve) => requestAnimationFrame(resolve));
    }
    const scheduleHeader = scheduleScroller?.querySelector('[data-layout="gantt-header"]');
    const scheduleColumns = scheduleHeader ? [...scheduleHeader.children].map((column) => {
      const box = column.getBoundingClientRect();
      return {
        label: (column.textContent ?? '').trim().replace(/\s+/g, ' '),
        width: Math.round(box.width * 100) / 100,
        share: scheduleScroller?.scrollWidth
          ? Number((box.width / scheduleScroller.scrollWidth).toFixed(3)) : null,
      };
    }) : [];
    const ganttRowHeights = [...(scheduleScroller?.querySelectorAll('[data-layout="gantt-bar-row"]') ?? [])]
      .map((row) => Math.round(row.getBoundingClientRect().height * 100) / 100);
    const scheduleStickyProbe = {
      cssSticky: Boolean(scheduleHeader && getComputedStyle(scheduleHeader).position === 'sticky'),
      tested: false,
      sticksToScroller: null,
      topAfterScroll: null,
      scrollerTopAfterScroll: null,
    };
    if (scheduleScroller && scheduleHeader
      && scheduleScroller.scrollHeight > scheduleScroller.clientHeight + 1) {
      const originalScrollTop = scheduleScroller.scrollTop;
      scheduleScroller.scrollTop = Math.min(scheduleScroller.scrollHeight, originalScrollTop + 120);
      await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
      const headerBox = scheduleHeader.getBoundingClientRect();
      const scrollerBox = scheduleScroller.getBoundingClientRect();
      scheduleStickyProbe.tested = true;
      scheduleStickyProbe.topAfterScroll = Math.round(headerBox.top * 100) / 100;
      scheduleStickyProbe.scrollerTopAfterScroll = Math.round(scrollerBox.top * 100) / 100;
      scheduleStickyProbe.sticksToScroller = Math.abs(headerBox.top - scrollerBox.top) <= 2;
      scheduleScroller.scrollTop = originalScrollTop;
      await new Promise((resolve) => requestAnimationFrame(resolve));
    }
    return {
      url: location.pathname,
      title: document.title,
      h1: main?.querySelector('h1')?.textContent?.trim() ?? null,
      browserTimeZone: Intl.DateTimeFormat().resolvedOptions().timeZone,
      appliedTheme: document.documentElement.getAttribute('data-theme'),
      colorScheme: rootStyle.colorScheme,
      canvasColor: rootStyle.getPropertyValue('--bg-canvas').trim(),
      bodyTypography: {
        fontFamily: bodyStyle.fontFamily,
        fontSize: Number.parseFloat(bodyStyle.fontSize),
        fontWeight: Number.parseFloat(bodyStyle.fontWeight),
        lineHeight: bodyStyle.lineHeight,
      },
      viewport: { width: innerWidth, height: innerHeight },
      counts: {
        buttons: buttons.length,
        links: main?.querySelectorAll('a[href]').length ?? 0,
        inputs: main?.querySelectorAll('input:not([type="hidden"]),select,textarea').length ?? 0,
        tables: main?.querySelectorAll('table').length ?? 0,
        rows: tableRows.length,
        dialogs: document.querySelectorAll('[role="dialog"]').length,
        tabs: main?.querySelectorAll('[role="tab"]').length ?? 0,
      },
      main: rect(main),
      sidebar: sidebarBox ? { left: sidebarBox.left, right: sidebarBox.right, width: sidebarBox.width } : null,
      sidebarClearance: countSidebarRail ? Math.round(firstContentLeft - sidebarBox.right) : null,
      sidebarClearanceByBlock,
      blocks: pageBlocks,
      flowBlocks,
      sections,
      edgeAlignment: alignment,
      verticalGaps: {
        headerToFilters: yGap(pageBlocks.header, pageBlocks.filters),
        filtersToToolbar: yGap(pageBlocks.filters, pageBlocks.toolbar),
        toolbarToTable: yGap(pageBlocks.toolbar, pageBlocks.table),
        tableToPagination: pageBlocks.pagination && actualTableBottom !== undefined
          ? Math.round((pageBlocks.pagination.top - actualTableBottom) * 100) / 100 : null,
      },
      horizontalPageOverflow: doc.scrollWidth > doc.clientWidth,
      horizontalPageMaxScroll,
      layoutContext,
      elementsPastViewport,
      uncontainedElementsPastViewport,
      rootPseudoElements,
      horizontalOverflow: overflow.filter((item) => item.horizontal),
      verticalScrollContainers: overflow.filter((item) => item.vertical),
      sidebarVerticalScroll,
      pageVerticalScroll,
      doubleVerticalScroll: pageVerticalScroll && internalVerticalScroll.length > 0,
      scheduleScrollRegion: scheduleScroller ? {
        horizontal: scheduleScroller.scrollWidth > scheduleScroller.clientWidth + 1,
        vertical: scheduleScroller.scrollHeight > scheduleScroller.clientHeight + 1,
        left: scheduleFrame?.getAttribute('data-more-left') === 'true',
        right: scheduleFrame?.getAttribute('data-more-right') === 'true',
        keyboardFocusable: scheduleScroller.tabIndex >= 0,
        labelled: Boolean(scheduleScroller.getAttribute('aria-label')),
        columns: scheduleColumns,
        barRowHeight: {
          min: ganttRowHeights.length ? Math.min(...ganttRowHeights) : null,
          max: ganttRowHeights.length ? Math.max(...ganttRowHeights) : null,
          distinct: [...new Set(ganttRowHeights)],
        },
        stickyHeader: scheduleStickyProbe,
      } : null,
      table: table ? {
        density: tableDensity,
        scrollAffordance: tableCard ? {
          needed: tableCard.scrollWidth - tableCard.offsetWidth
            + (Number.parseFloat(getComputedStyle(tableCard).borderLeftWidth) || 0)
            + (Number.parseFloat(getComputedStyle(tableCard).borderRightWidth) || 0) > 1,
          left: tableCard.closest('.scroll-edge-frame')?.getAttribute('data-more-left') === 'true',
          right: tableCard.closest('.scroll-edge-frame')?.getAttribute('data-more-right') === 'true',
          keyboardFocusable: tableCard.tabIndex >= 0,
          labelled: Boolean(tableCard.getAttribute('aria-label')),
        } : null,
        textBelowMinimum: typography.filter((item) =>
          (item.tag === 'th' && item.size < 13) || (item.tag === 'td' && item.size < tableTextMinimum))
          .map((item) => ({ tag: item.tag, size: item.size, text: item.text })),
        headerHeight: ths[0] ? Math.round(ths[0].getBoundingClientRect().height * 100) / 100 : null,
        rowHeight: { min: heights.length ? Math.min(...heights) : null,
          max: heights.length ? Math.max(...heights) : null, distinct: unique(heights) },
        cellPadding: firstRow?.firstElementChild ? {
          top: getComputedStyle(firstRow.firstElementChild).paddingTop,
          right: getComputedStyle(firstRow.firstElementChild).paddingRight,
          bottom: getComputedStyle(firstRow.firstElementChild).paddingBottom,
          left: getComputedStyle(firstRow.firstElementChild).paddingLeft,
        } : null,
        columns: cells,
        stickyHeader: stickyHeaderCss,
        stickyProbe,
        semantics: {
          columnHeaders: ths.length,
          scopedColumnHeaders: ths.filter((th) => th.getAttribute('scope') === 'col').length,
          sortableHeaders: ths.filter((th) => th.hasAttribute('aria-sort')).length,
          keyboardFocusableRows: tableRows.filter((row) => row.tabIndex >= 0).length,
        },
        truncation: tableRows.flatMap((row) => [...row.querySelectorAll('td')]
          .filter((cell) => cell.scrollWidth > cell.clientWidth + 1)
          .map((cell) => (cell.textContent ?? '').trim().slice(0, 80))).slice(0, 40),
      } : null,
      typography: {
        samples: typography,
        combinations: unique(typography.map((item) => `${item.font}|${item.size}|${item.weight}|${item.lineHeight}|${item.letterSpacing}|${item.textTransform}`)),
      below13px: typography.filter((item) => item.size < 13 && !item.className.split(/\s+/).includes('text-caption'))
        .map((item) => ({ tag: item.tag, size: item.size, text: item.text })),
      below12px: typography.filter((item) => item.size < 12).map((item) => ({ tag: item.tag, size: item.size, text: item.text })),
        contrastBelowAA: typography.filter((item) => item.contrast !== null && item.contrast < 4.5)
          .map((item) => ({ tag: item.tag, text: item.text, contrast: item.contrast, color: item.color, background: item.background })),
      },
      buttons,
      recordLinks,
      dropdowns,
      buttonTargetsBelow44: buttons.filter((button) => button.width < 44 || button.height < 44),
      controls,
      controlsBelow44: controls.filter((control) => control.width < 44 || control.height < 44),
      scrollContextCount: Number(pageVerticalScroll) + internalVerticalScroll.length,
    };
  });
}

async function captureTableSamples(page, out, route, width, rowCount) {
  const scroller = page.locator('[data-layout="table-scroller"]');
  if (await scroller.count() === 0) return [];
  const dimensions = await scroller.evaluate((element) => ({
    maxX: element.scrollWidth - element.clientWidth,
    maxY: element.scrollHeight - element.clientHeight,
  }));
  const vertical = rowCount >= 50 ? [0, 0.5, 1] : [0];
  const horizontal = dimensions.maxX > 1 ? [0, 1] : [0];
  const screenshots = [];
  for (const y of vertical) {
    for (const x of horizontal) {
      const xName = x === 0 ? 'left' : 'right';
      const yName = y === 0 ? 'top' : y === 1 ? 'bottom' : 'middle';
      await scroller.evaluate((element, position) => {
        element.scrollLeft = Math.round((element.scrollWidth - element.clientWidth) * position.x);
        element.scrollTop = Math.round((element.scrollHeight - element.clientHeight) * position.y);
      }, { x, y });
      await page.waitForTimeout(80);
      if (dimensions.maxX > 1) {
        await page.waitForTimeout(300);
        const edges = await scroller.evaluate((element) => {
          const frame = element.closest('.scroll-edge-frame');
          return {
            left: frame?.getAttribute('data-more-left') === 'true',
            right: frame?.getAttribute('data-more-right') === 'true',
            scrollLeft: element.scrollLeft,
            maxScroll: element.scrollWidth - element.clientWidth,
          };
        });
        assert.equal(edges.left, x === 1, `horizontal table scroll must update the left edge cue: ${JSON.stringify(edges)}`);
        assert.equal(edges.right, x === 0, `horizontal table scroll must update the right edge cue: ${JSON.stringify(edges)}`);
      }
      const file = path.join(out, `${slug(route)}-${width}-table-${yName}-${xName}.png`);
      await scroller.screenshot({ path: file });
      screenshots.push(path.relative(ROOT, file));
    }
  }
  return screenshots;
}

async function captureLongTableSamples(page, out, route, width) {
  const scroller = page.locator('[data-layout="table-scroller"]');
  const hasScroller = await scroller.count() > 0;
  const tableMetrics = hasScroller
    ? await scroller.evaluate((element) => ({
      maxY: element.scrollHeight - element.clientHeight,
      maxX: element.scrollWidth - element.clientWidth,
    }))
    : null;
  const useTableScroll = Boolean(tableMetrics && tableMetrics.maxY > 1);
  const screenshots = [];
  for (const [position, name] of [[0, 'top'], [0.5, 'middle'], [1, 'bottom']]) {
    if (useTableScroll) {
      await scroller.evaluate((element, y) => {
        element.scrollTop = Math.round((element.scrollHeight - element.clientHeight) * y);
      }, position);
    } else {
      await page.evaluate((y) => window.scrollTo(0, Math.round((document.documentElement.scrollHeight - innerHeight) * y)), position);
    }
    await page.waitForTimeout(80);
    const file = path.join(out, `${slug(route)}-${width}-long-${name}.png`);
    if (useTableScroll) await scroller.screenshot({ path: file });
    else await page.screenshot({ path: file });
    screenshots.push(path.relative(ROOT, file));
  }
  await page.evaluate(() => window.scrollTo(0, 0));
  if (useTableScroll) await scroller.evaluate((element) => { element.scrollTop = 0; });
  if ((tableMetrics?.maxX ?? 0) > 1) console.log(`${route} @ ${width}: horizontal table scroll ${tableMetrics.maxX}px`);
  return screenshots;
}

async function probeColumnResize(page, screenshotPath) {
  const handle = page.getByRole('separator').first();
  if (!(await handle.count())) return null;
  const before = Number(await handle.getAttribute('aria-valuenow'));
  const box = await handle.boundingBox();
  assert.ok(box, 'column resize handle must be visible');
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width / 2 + 48, box.y + box.height / 2, { steps: 4 });
  await page.mouse.up();
  const after = Number(await handle.getAttribute('aria-valuenow'));
  assert.ok(after > before, 'drag resize must increase width');
  const reset = page.getByRole('button', { name: 'Reset widths' });
  assert.ok(await reset.isVisible(), 'custom width must expose Reset widths');
  const resetBox = await reset.boundingBox();
  assert.ok(resetBox && ((page.viewportSize()?.width ?? 0) > 1024 || resetBox.height >= 44), 'reset target must be at least 44px high on narrow screens');
  await page.screenshot({ path: screenshotPath, fullPage: true });
  await reset.click();
  assert.equal(await page.getByRole('button', { name: 'Reset widths' }).count(), 0);
  return { before, after, reset: true, screenshot: path.relative(ROOT, screenshotPath) };
}

async function captureInventoryDialog(page, out, route, width, themeMode, label, buttonName) {
  if (buttonName) {
    const button = page.getByRole('button', { name: buttonName, exact: true }).first();
    if (!(await button.count())) return null;
    await button.click();
  }
  const dialog = page.getByRole('dialog');
  await dialog.waitFor({ state: 'visible', timeout: 5_000 });
  await page.waitForTimeout(350);
  const screenshot = path.join(out, `${slug(route)}-${width}-${slug(`/${label}`)}-dialog.png`);
  await page.screenshot({ path: screenshot });
  const metrics = await measure(page);
  const sample = {
    label,
    screenshot: path.relative(ROOT, screenshot),
    visible: metrics.counts.dialogs === 1,
    theme: metrics.appliedTheme,
    contrastFailures: metrics.typography.contrastBelowAA.reduce((sum, item) => sum + item.count, 0),
  };
  await page.keyboard.press('Escape');
  await dialog.waitFor({ state: 'detached', timeout: 5_000 });
  assert.equal(sample.visible, true, `${label} dialog must render before screenshot`);
  assert.equal(sample.theme, themeMode, `${label} dialog must retain the active theme`);
  assert.equal(sample.contrastFailures, 0, `${label} dialog text must meet AA contrast`);
  return sample;
}

async function main() {
  const role = arg('role', 'admin');
  const themeMode = arg('theme', 'light');
  if (!['light', 'dark'].includes(themeMode)) throw new Error('--theme must be light or dark');
  const email = ROLE_EMAILS[role];
  if (!email) throw new Error(`Unknown role ${role}; choose ${Object.keys(ROLE_EMAILS).join(', ')}`);
  const routes = arg('routes');
  if (!routes) throw new Error('--routes is required; pass comma-separated paths');
  const routeList = routes.split(',').map((route) => route.trim()).filter(Boolean);
  const viewports = (arg('viewports', DEFAULT_VIEWPORTS.join(',')))
    .split(',').map(Number).filter((width) => Number.isInteger(width) && width > 0);
  if (viewports.length === 0) throw new Error('--viewports must contain positive integer widths');
  const out = path.resolve(arg('out', path.join(ROOT, 'docs/layout/screenshots/before', role)));
  const rowCount = Number(arg('rows', '50'));
  if (!Number.isInteger(rowCount) || rowCount < 0 || rowCount > 500) throw new Error('--rows must be an integer from 0 to 500');
  const strict = process.argv.includes('--strict');
  const tableSamples = process.argv.includes('--table-samples');
  await mkdir(out, { recursive: true });

  const local = new URL(BASE).hostname === '127.0.0.1' || new URL(BASE).hostname === 'localhost';
  const server = local ? await startLocalServer() : null;
  const browser = await chromium.launch({
    headless: true,
    args: process.env.LAYOUT_AUDIT_LOCAL_STACK === '1'
      ? ['--host-resolver-rules=MAP ogamiph.dev 127.0.0.1']
      : [],
  });
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage({ viewport: { width: viewports[0], height: 900 } });
  page.on('pageerror', (error) => console.error(`Browser error: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error') console.error(`Browser console: ${message.text()}`);
  });
  page.on('requestfailed', (request) => console.error(`Request failed: ${request.url()} — ${request.failure()?.errorText}`));
  const rows = [];
  try {
    if (local) await installLocalMocks(page, role, rowCount, themeMode);
    else await login(page, email);
    for (const route of routeList) {
      for (const width of viewports) {
        await page.setViewportSize({ width, height: width < 480 ? 812 : width <= 768 ? 1024 : 900 });
        const response = await page.goto(`${BASE}${route}`, { waitUntil: 'networkidle', timeout: 45_000 });
        try {
          await page.locator('main#main-content, main').first().waitFor({ state: 'visible', timeout: 15_000 });
        } catch (error) {
          console.error(`Main content missing at ${route} @ ${width}: ${page.url()} — ${await page.title()}`);
          console.error((await page.locator('body').innerText()).slice(0, 1000));
          console.error((await page.content()).slice(0, 3000));
          throw error;
        }
        await page.waitForFunction((theme) => document.documentElement.getAttribute('data-theme') === theme, themeMode, { timeout: 10_000 });
        await page.waitForFunction(() => !document.querySelector('main .animate-shimmer'), null, { timeout: 15_000 });
        if (local && route === '/production/routings/create') {
          await page.waitForFunction(
            () => document.querySelector('select[name="product_id"] option[value="layout-product-1"]'),
            null,
            { timeout: 5_000 },
          );
        }
        if (route === '/production/schedule' && process.argv.includes('--schedule-list')) {
          await page.getByRole('tab', { name: /Schedule list/ }).click();
          await page.waitForTimeout(100);
        }
        if (route === `/production/work-orders/${sampleWorkOrder.id}` && process.argv.includes('--work-order-operations')) {
          await page.getByRole('tab', { name: 'Operations' }).click();
          await page.waitForTimeout(150);
        }
        if (process.argv.includes('--expand-first-row') && new URL(route, BASE).searchParams.get('view') === 'reservations') {
          const expand = page.getByRole('button', { name: 'Expand row' }).first();
          if (await expand.count()) {
            await expand.click();
            await page.waitForTimeout(100);
          }
        }
        await page.waitForTimeout(350);
        const screenshot = path.join(out, `${slug(route)}-${width}.png`);
        const resizeSample = process.argv.includes('--resize-sample')
          ? await probeColumnResize(page, path.join(out, `${slug(route)}-${width}-resized.png`))
          : null;
        const metrics = compactMetricSamples(await measure(page));
        await page.screenshot({ path: screenshot, fullPage: rowCount < 300 });
        let scheduleBottomSample = null;
        if (route === '/production/schedule' && width <= 480) {
          const shell = page.locator('[data-layout="page-shell"]');
          const scroll = await shell.evaluate((element) => ({
            max: element.scrollHeight - element.clientHeight,
            top: element.scrollTop,
          }));
          if (scroll.max > 1) {
            await shell.evaluate((element) => { element.scrollTop = element.scrollHeight; });
            await page.waitForTimeout(80);
            const bottomScreenshot = path.join(out, `${slug(route)}-${width}-bottom.png`);
            await page.screenshot({ path: bottomScreenshot });
            scheduleBottomSample = { screenshot: path.relative(ROOT, bottomScreenshot), maxScrollTop: scroll.max };
            await shell.evaluate((element) => { element.scrollTop = 0; });
          }
        }
        if (process.argv.includes('--dropdown-sample')) {
          const combo = page.locator('input[role="combobox"]').first();
          if (await combo.count()) {
            await combo.click();
            await page.waitForTimeout(100);
            const dropdownScreenshot = path.join(out, `${slug(route)}-${width}-dropdown.png`);
            await page.screenshot({ path: dropdownScreenshot });
            metrics.dropdownScreenshot = path.relative(ROOT, dropdownScreenshot);
          }
        }
        const tableSampleScreenshots = tableSamples && width === 1440
          ? await captureTableSamples(page, out, route, width, rowCount)
          : [];
        const longTableScreenshots = rowCount >= 300 && !tableSamples && metrics.counts.tables > 0 && width === 1440
          ? await captureLongTableSamples(page, out, route, width)
          : [];
        let dialogSample = null;
        if (process.argv.includes('--dialog-sample') && width === 1440
          && route === '/production/routings/layout-routing-001') {
          await page.getByRole('button', { name: 'Make active', exact: true }).first().click();
          const dialog = page.getByRole('dialog');
          await dialog.waitFor({ state: 'visible' });
          const dialogScreenshot = path.join(out, `${slug(route)}-${width}-confirm-dialog.png`);
          await page.screenshot({ path: dialogScreenshot });
          const dialogMetrics = compactMetricSamples(await measure(page));
          await page.keyboard.press('Escape');
          await dialog.waitFor({ state: 'detached' });
          dialogSample = {
            screenshot: path.relative(ROOT, dialogScreenshot),
            visible: dialogMetrics.counts.dialogs === 1,
            dismissedWithEscape: true,
            buttons: dialogMetrics.buttons,
          };
          assert.equal(dialogSample.visible, true, 'confirm dialog must render before screenshot');
        }
        let scheduleDialogSample = null;
        if (process.argv.includes('--schedule-dialog-sample') && [375, 1440].includes(width) && route === '/production/schedule') {
          await page.getByRole('button', { name: 'Run scheduler', exact: true }).first().click();
          const dialog = page.getByRole('dialog');
          await dialog.waitFor({ state: 'visible' });
          await page.waitForTimeout(350);
          const dialogScreenshot = path.join(out, `${slug(route)}-${width}-confirm-dialog.png`);
          await page.screenshot({ path: dialogScreenshot });
          const dialogMetrics = compactMetricSamples(await measure(page));
          await page.keyboard.press('Escape');
          await dialog.waitFor({ state: 'detached' });
          scheduleDialogSample = {
            screenshot: path.relative(ROOT, dialogScreenshot),
            visible: dialogMetrics.counts.dialogs === 1,
            dismissedWithEscape: true,
            contrastFailures: dialogMetrics.typography.contrastBelowAA.reduce((sum, sample) => sum + sample.count, 0),
            buttons: dialogMetrics.buttons,
          };
          assert.equal(scheduleDialogSample.visible, true, 'schedule confirmation must render before screenshot');
          assert.equal(scheduleDialogSample.contrastFailures, 0, 'schedule confirmation text must meet AA contrast');
        }
        const inventoryDialogSamples = [];
        if (process.argv.includes('--inventory-dialog-samples') && [375, 1440].includes(width)) {
          const capture = async (label, buttonName) => {
            const sample = await captureInventoryDialog(page, out, route, width, themeMode, label, buttonName);
            if (sample) inventoryDialogSamples.push(sample);
          };
          if (route === '/inventory/items') await capture('categories', 'Categories');
          if (route === '/inventory/uoms') await capture('new-unit', 'New unit');
          if (route === '/inventory/warehouse') {
            await capture('new-warehouse', 'New warehouse');
            await capture('new-zone', 'Zone');
            await capture('new-location', 'Location');
          }
          if (new URL(route, BASE).searchParams.get('view') === 'movements') {
            const expand = page.getByRole('button', { name: 'Expand row' });
            if (await expand.count() > 1) {
              await expand.nth(1).click();
              await page.getByRole('button', { name: 'Receive unused return', exact: true }).click();
              const sample = await captureInventoryDialog(page, out, route, width, themeMode, 'return-unused-material', null);
              if (sample) inventoryDialogSamples.push(sample);
            }
          }
        }
        rows.push({ route, role, theme: themeMode, status: response?.status() ?? null, screenshot: path.relative(ROOT, screenshot), tableSampleScreenshots, longTableScreenshots, resizeSample, dialogSample, scheduleDialogSample, inventoryDialogSamples, scheduleBottomSample, ...metrics });
        console.log(`${route} @ ${width}: ${metrics.counts.tables} table(s), ${metrics.counts.rows} row(s), overflow=${metrics.horizontalPageOverflow}`);
      }
    }
  } finally {
    await browser.close();
    server?.kill();
  }

  const rankedIssues = rows.map((row) => {
    const issues = [];
    if (Object.values(row.edgeAlignment).some((item) => item && !item.aligned)) issues.push('block edges differ by >1px');
    if (Object.values(row.sidebarClearanceByBlock).some((value) => value !== null && value < 16)) issues.push('block clearance under provisional 16px minimum');
    if (row.horizontalPageOverflow) issues.push('horizontal page overflow');
    if (row.doubleVerticalScroll) issues.push('nested vertical scroll');
    if (row.table?.stickyProbe.tested && !row.table.stickyProbe.sticksToScroller) issues.push('sticky header scrolls out of table scroller');
    if (row.table?.scrollAffordance?.needed && (!row.table.scrollAffordance.right || !row.table.scrollAffordance.keyboardFocusable || !row.table.scrollAffordance.labelled)) issues.push('horizontally scrolling table lacks a visible keyboard-accessible affordance');
    if (row.scheduleScrollRegion?.horizontal && (!row.scheduleScrollRegion.right || !row.scheduleScrollRegion.keyboardFocusable || !row.scheduleScrollRegion.labelled)) issues.push('horizontally scrolling timeline lacks a visible keyboard-accessible affordance');
    if (row.scheduleScrollRegion?.stickyHeader.tested && !row.scheduleScrollRegion.stickyHeader.sticksToScroller) issues.push('timeline date header scrolls out of its region');
    const contrastFailures = row.typography.contrastBelowAA.reduce((sum, sample) => sum + sample.count, 0);
    if (contrastFailures) issues.push(`${contrastFailures} sampled text items below 4.5:1`);
    if (row.recordLinks.some((link) => !link.distinctFromBodyText || (link.contrast !== null && link.contrast < 4.5))) issues.push('clickable record identifier lacks distinct AA text color');
    if (row.typography.below12px.length) issues.push(`${row.typography.below12px.length} sampled text items below 12px`);
    const score = issues.reduce((sum, issue) => {
      if (issue.startsWith('block edges') || issue.startsWith('horizontal page') || issue.includes('below 4.5:1')) return sum + 5;
      if (issue.startsWith('block clearance') || issue.startsWith('nested vertical')) return sum + 4;
      if (issue.startsWith('sticky header')) return sum + 3;
      return sum + 2;
    }, 0);
    return { route: row.route, role: row.role, width: row.viewport.width, score, issues };
  }).sort((a, b) => b.score - a.score || a.route.localeCompare(b.route) || a.width - b.width);
  const report = {
    generatedAt: new Date().toISOString(),
    baseUrl: BASE,
    role,
    theme: themeMode,
    fixtureRowsRequested: local ? rowCount : null,
    widths: viewports,
    routes: routeList,
    serial: true,
    rankingMethod: 'edge mismatch, page overflow and contrast=5; clearance and double scroll=4; ineffective sticky header=3; sampled text below 13px=2; per route×viewport',
    sampleGrouping: 'Repeated computed styles and geometry are grouped; count records frequency and examples holds up to three text values.',
    rankedIssues,
    rows,
  };
  await writeFile(path.join(out, 'report.json'), `${JSON.stringify(report, null, 2)}\n`);
  const summary = [
    '# Layout Audit Measurement Summary',
    '',
    `Generated: ${report.generatedAt} · Role: ${role} · Serial captures: ${rows.length}.`,
    '',
    '| Rank | Route | Viewport | Score | Findings |',
    '|---:|---|---:|---:|---|',
    ...rankedIssues.slice(0, 50).map((item, index) =>
      `| ${index + 1} | ${item.route} | ${item.width}px | ${item.score} | ${item.issues.join('; ') || 'none'} |`),
    '',
    'Full block geometry and grouped typography, table, overflow, and button samples: `report.json`; repeated samples retain counts and up to three text examples.',
    '16px sidebar clearance is a provisional audit threshold taken from the brief’s example, not an approved final token.',
  ].join('\n');
  await writeFile(path.join(out, 'summary.md'), `${summary}\n`);

  if (strict) {
    const failures = rows.flatMap((row) => {
      const checks = [
        [Object.values(row.edgeAlignment).some((item) => item && !item.aligned), 'page block edges differ by >1px'],
        [Object.values(row.sidebarClearanceByBlock).some((value) => value !== null && value < 16), 'sidebar clearance below 16px'],
        [row.horizontalPageOverflow, 'horizontal page overflow'],
        [row.doubleVerticalScroll, 'double vertical scroll'],
        [row.table?.stickyProbe.tested && !row.table.stickyProbe.sticksToScroller, 'sticky header misses table scroller'],
        [row.table?.scrollAffordance?.needed && (!row.table.scrollAffordance.right || !row.table.scrollAffordance.keyboardFocusable || !row.table.scrollAffordance.labelled), 'horizontally scrolling table lacks a visible keyboard-accessible affordance'],
        [row.scheduleScrollRegion?.horizontal && (!row.scheduleScrollRegion.right || !row.scheduleScrollRegion.keyboardFocusable || !row.scheduleScrollRegion.labelled), 'horizontally scrolling timeline lacks a visible keyboard-accessible affordance'],
        [row.scheduleScrollRegion?.stickyHeader.tested && !row.scheduleScrollRegion.stickyHeader.sticksToScroller, 'timeline header misses its scroll region'],
        [row.table && row.table.rowHeight.min !== null && row.table.rowHeight.min < ({ default: 48, compact: 36, manual: 36, spacious: 56 }[row.table.density] ?? 48), 'table row below selected density minimum'],
        [row.table?.textBelowMinimum.length > 0, 'table text below selected density minimum'],
        [row.table?.columns.some((column) => column.header && column.cellAlign
          && ({ start: 'left', end: 'right' }[column.cellAlign] ?? column.cellAlign)
            !== ({ start: 'left', end: 'right' }[column.headerAlign] ?? column.headerAlign)), 'table header/cell alignment differs'],
        [row.typography.below12px.length > 0, 'sampled text below 12px'],
        [row.typography.contrastBelowAA.length > 0, 'sampled contrast below 4.5:1'],
        [row.recordLinks.some((link) => !link.distinctFromBodyText || (link.contrast !== null && link.contrast < 4.5)), 'clickable record identifier lacks distinct AA text color'],
        [row.theme === 'dark' && (row.appliedTheme !== 'dark' || row.colorScheme !== 'dark'), 'dark theme/color scheme not applied'],
        [row.theme === 'dark' && row.bodyTypography.fontWeight < 450, 'dark body font weight below 450'],
        [row.theme === 'dark' && row.dropdowns.some((dropdown) => dropdown.colorScheme !== 'dark' || (dropdown.optionBackground && dropdown.optionBackground === 'rgb(255, 255, 255)')), 'dropdown retains light native palette'],
        [row.viewport.width <= 1024 && row.controlsBelow44.length > 0, 'touch control below 44px'],
      ];
      return checks.filter(([failed]) => failed).map(([, issue]) => `${row.route} @ ${row.viewport.width}px: ${issue}`);
    });
    if (failures.length) throw new Error(`Layout audit failed:\n${failures.join('\n')}`);
  }
}

main().catch((error) => {
  console.error(error instanceof Error ? error.stack : error);
  process.exitCode = 1;
});
