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
    permissions: ['inventory.adjust', 'inventory.adjust.approve', 'inventory.view', 'inventory.items.manage', 'production.work_orders.view', 'production.wo.create'],
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

const sampleWarehouse = [{
  id: 'layout-warehouse-1', name: 'Main Warehouse', code: 'MW', address: null, is_active: true,
  zones: [{
    id: 'layout-zone-1', name: 'Raw materials', code: 'RM', zone_type: 'raw_materials',
    locations: [{
      id: 'layout-location-1', zone_id: 'layout-zone-1', code: 'A-01-01', rack: 'A-01', bin: '01',
      is_active: true, is_blocked: false, full_code: 'MW-RM-A-01-01',
    }],
  }],
}];

const sampleStockLevel = {
  item: { id: sampleItem.id, code: sampleItem.code, name: sampleItem.name, unit_of_measure: sampleItem.unit_of_measure },
  location: { id: 'layout-location-1', code: 'A-01-01', full_code: 'MW-RM-A-01-01' },
  quantity: '123456789.125', reserved_quantity: '234567.250', available: '123222221.875',
  quality_held_quantity: '1200.000', weighted_avg_cost: '1234567.8900', total_value: '152415787517145.00',
  lock_version: 0, last_counted_at: '2026-10-01T09:14:00+08:00',
};

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
    else if (pathname.endsWith(`/production/work-orders/${sampleWorkOrder.id}`)) json = { data: sampleWorkOrder };
    else if (pathname.endsWith('/production/work-orders/options')) json = {
      data: { statuses: [
        { value: 'planned', label: 'Planned', next_statuses: [] },
        { value: 'in_progress', label: 'In progress', next_statuses: [] },
      ], operation_statuses: [] },
    };
    else if (pathname.endsWith('/production/work-orders')) json = {
      data: [sampleWorkOrder],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
    };
    else if (pathname.endsWith(`/production/work-orders/${sampleWorkOrder.id}/crew`)) json = {
      data: { current: null, history: [], minimum_workers: 1, maximum_workers: null, can_assign: false },
    };
    else if (pathname.endsWith(`/production/work-orders/${sampleWorkOrder.id}/chain`)) json = { data: [] };
    else if (pathname.endsWith('/inventory/items')) json = {
      data: [sampleItem], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1, from: 1, to: 1 },
      links: { first: '', last: '', prev: null, next: null },
    };
    else if (pathname.endsWith('/inventory/warehouse')) json = { data: sampleWarehouse };
    else if (pathname.endsWith('/inventory/stock-levels')) json = {
      data: [sampleStockLevel], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1, from: 1, to: 1 },
      links: { first: '', last: '', prev: null, next: null },
    };
    else if (pathname.endsWith('/inventory/uoms')) json = {
      data: [{ id: 'layout-uom-kg', code: 'kg', name: 'Kilogram', deleted_at: null }],
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
    const filterBar = main?.querySelector('[data-layout="filter-bar"]')
      ?? main?.querySelector('[role="search"]')?.parentElement;
    const table = main?.querySelector('[data-layout="table"]') ?? main?.querySelector('table');
    const tableCard = table?.closest('[data-layout="table-scroller"]') ?? table?.parentElement;
    const tableRoot = table?.closest('[data-layout="data-table"]') ?? tableCard?.parentElement;
    const toolbar = tableRoot?.querySelector('[data-layout="table-toolbar"]')
      ?? (tableRoot && [...tableRoot.children].find((element) => element.classList.contains('justify-end')));
    const pagination = tableRoot?.querySelector('[data-layout="pagination"]')
      ?? (tableRoot && [...tableRoot.children].find((element) => element.classList.contains('mt-3')));
    const pageBlocks = {
      header: rect(pageHeader),
      filters: rect(filterBar),
      toolbar: rect(toolbar),
      table: rect(tableCard),
      pagination: rect(pagination),
    };
    const reference = pageBlocks.header;
    const alignment = Object.fromEntries(Object.entries(pageBlocks).map(([key, box]) => [key,
      box && reference
        ? { leftDelta: Math.round((box.left - reference.left) * 100) / 100,
            rightDelta: Math.round((box.right - reference.right) * 100) / 100,
            aligned: Math.abs(box.left - reference.left) <= 1 && Math.abs(box.right - reference.right) <= 1 }
        : null,
    ]));
    const sidebar = document.querySelector('aside[aria-label="Sidebar"]');
    const sidebarBox = sidebar ? sidebar.getBoundingClientRect() : null;
    const firstContentLeft = reference?.left ?? pageBlocks.toolbar?.left ?? main?.getBoundingClientRect().left ?? 0;
    const countSidebarRail = innerWidth >= 768 && sidebarBox && getComputedStyle(sidebar).display !== 'none';
    const sidebarClearanceByBlock = Object.fromEntries(Object.entries(pageBlocks).map(([key, box]) => [
      key,
      countSidebarRail && box
        ? Math.round((box.left - sidebarBox.right) * 100) / 100 : null,
    ]));
    const rows = table ? [...table.querySelectorAll('tbody tr')].filter((row) => row.offsetHeight > 0) : [];
    const heights = rows.map((row) => Math.round(row.getBoundingClientRect().height * 100) / 100);
    const unique = (values) => [...new Set(values)];
    const typeNodes = [
      ...Array.from(main?.querySelectorAll('h1,h2,h3,p,label,button,th,tbody td,.text-muted') ?? []),
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
    const buttons = [...(main?.querySelectorAll('button') ?? [])].map((button) => {
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
    const controls = [...(main?.querySelectorAll('button:not(.sr-only),input:not([type="hidden"]):not(.sr-only),select:not(.sr-only),textarea,[role="button"]') ?? [])]
      .filter((control) => control.getAttribute('aria-hidden') !== 'true' && control.tabIndex !== -1)
      .map((control) => {
        const box = control.getBoundingClientRect();
        return {
          label: control.getAttribute('aria-label') || control.getAttribute('name') || control.textContent?.trim() || control.tagName.toLowerCase(),
          width: Math.round(box.width),
          height: Math.round(box.height),
        };
      });
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
    const tableDensity = table?.closest('[data-layout="data-table"]')?.getAttribute('data-density') ?? 'default';
    const tableTextMinimum = tableDensity === 'compact' ? 13 : 14;
    const firstRow = tableRows[0];
    const lastRow = tableRows.at(-1);
    const doc = document.documentElement;
    const pageVerticalScroll = doc.scrollHeight > innerHeight + 1;
    const internalVerticalScroll = overflow.filter((item) => item.vertical && item.insideMain);
    const sidebarVerticalScroll = overflow.some((item) => item.vertical && item.insideSidebar);
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
      edgeAlignment: alignment,
      verticalGaps: {
        headerToFilters: yGap(pageBlocks.header, pageBlocks.filters),
        filtersToToolbar: yGap(pageBlocks.filters, pageBlocks.toolbar),
        toolbarToTable: yGap(pageBlocks.toolbar, pageBlocks.table),
        tableToPagination: pageBlocks.pagination && actualTableBottom !== undefined
          ? Math.round((pageBlocks.pagination.top - actualTableBottom) * 100) / 100 : null,
      },
      horizontalPageOverflow: doc.scrollWidth > doc.clientWidth,
      horizontalOverflow: overflow.filter((item) => item.horizontal),
      verticalScrollContainers: overflow.filter((item) => item.vertical),
      sidebarVerticalScroll,
      pageVerticalScroll,
      doubleVerticalScroll: pageVerticalScroll && internalVerticalScroll.length > 0,
      table: table ? {
        density: tableDensity,
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
  const vertical = rowCount >= 500 ? [0, 0.5, 1] : [0];
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
      const file = path.join(out, `${slug(route)}-${width}-table-${yName}-${xName}.png`);
      await scroller.screenshot({ path: file });
      screenshots.push(path.relative(ROOT, file));
    }
  }
  return screenshots;
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
  const rows = [];
  try {
    if (local) await installLocalMocks(page, role, rowCount, themeMode);
    else await login(page, email);
    for (const route of routeList) {
      for (const width of viewports) {
        await page.setViewportSize({ width, height: width < 480 ? 812 : width <= 768 ? 1024 : 900 });
        const response = await page.goto(`${BASE}${route}`, { waitUntil: 'networkidle', timeout: 45_000 });
        await page.locator('main#main-content, main').first().waitFor({ state: 'visible', timeout: 15_000 });
        await page.waitForFunction((theme) => document.documentElement.getAttribute('data-theme') === theme, themeMode, { timeout: 10_000 });
        await page.waitForTimeout(350);
        const metrics = await measure(page);
        const screenshot = path.join(out, `${slug(route)}-${width}.png`);
        await page.screenshot({ path: screenshot, fullPage: true });
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
        rows.push({ route, role, theme: themeMode, status: response?.status() ?? null, screenshot: path.relative(ROOT, screenshot), tableSampleScreenshots, ...metrics });
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
    if (row.typography.contrastBelowAA.length) issues.push(`${row.typography.contrastBelowAA.length} sampled text items below 4.5:1`);
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
    'Full block geometry, typography, table and button measurements: `report.json`.',
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
        [row.table && row.table.rowHeight.min !== null && row.table.rowHeight.min < ({ default: 48, compact: 36, spacious: 56 }[row.table.density] ?? 48), 'table row below selected density minimum'],
        [row.table?.textBelowMinimum.length > 0, 'table text below selected density minimum'],
        [row.table?.columns.some((column) => column.cellAlign && column.headerAlign !== column.cellAlign), 'table header/cell alignment differs'],
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
