import { firefox } from '../../spa/node_modules/playwright/index.mjs';
import fs from 'node:fs/promises';

const base = 'http://localhost';
const artifactDir = '/home/kwat0g/Desktop/kwatog/audit/live-2026-09-14/artifacts';
const password = 'password';
const stamp = Date.now().toString().slice(-8);
const results = [];
const evidence = [];
const ids = {};
const residuals = [];
let actor = 'signed-out';
let guard = 'none';
let lastLogin = 0;
let requestNo = 0;

const browser = await firefox.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const dataOf = (body) => body?.data ?? body;
const rowsOf = (body) => {
  const value = dataOf(body);
  if (Array.isArray(value)) return value;
  if (Array.isArray(value?.data)) return value.data;
  return [];
};
const idOf = (body) => dataOf(body)?.id ?? dataOf(body)?.hash_id ?? null;
const short = (value) => JSON.stringify(value ?? '').slice(0, 2400);
const statusOf = (row) => String(row?.status?.value ?? row?.status ?? '');
const hasStatus = (row, values) => values.includes(statusOf(row));
const safeName = (name) => name.replace(/[^a-z0-9_-]+/gi, '-').slice(0, 100);

function record(status, name, detail = '') {
  results.push({ status, actor, name, detail });
  console.log(`${status} ${actor} ${name}${detail ? ` :: ${detail}` : ''}`);
}

function observed(name, detail = '') { record('OBSERVED', name, detail); }
function blocked(name, detail = '') { record('BLOCKED', name, detail); }
function retain(type, id, detail = '') {
  if (id) residuals.push({ type, id, detail });
}

async function shot(name) {
  await page.screenshot({ path: `${artifactDir}/l15-l17-${safeName(name)}.png`, fullPage: true }).catch(() => {});
}

async function api(path, options = {}) {
  const result = await page.evaluate(async ({ path, options, requestId }) => {
    const sanitize = (value) => {
      if (Array.isArray(value)) return value.map(sanitize);
      if (!value || typeof value !== 'object') return value;
      return Object.fromEntries(Object.entries(value).map(([key, item]) => [
        key,
        /password|secret|token|cookie/i.test(key) ? '[REDACTED]' : sanitize(item),
      ]));
    };
    const xsrf = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    const headers = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-Request-ID': requestId,
      ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
      ...(options.multipart ? {} : options.body === undefined ? {} : { 'Content-Type': 'application/json' }),
      ...(options.headers ?? {}),
    };
    let body;
    if (options.multipart) {
      const form = new FormData();
      for (const [key, value] of Object.entries(options.fields ?? {})) form.append(key, String(value));
      form.append(
        options.fileField ?? 'file',
        new Blob([new Uint8Array(options.bytes ?? [37, 80, 68, 70, 45, 49, 46, 52, 10, 76, 49, 53])], { type: options.mime ?? 'application/pdf' }),
        options.filename ?? 'live-l15-shipping-document.pdf',
      );
      body = form;
    } else if (options.body !== undefined) {
      body = JSON.stringify(options.body);
    }
    const target = path.startsWith('/api/') ? path : `/api/v1${path}`;
    const response = await fetch(target, {
      method: options.method ?? 'GET',
      credentials: 'include',
      headers,
      body,
    });
    const text = await response.text();
    let parsed;
    try { parsed = JSON.parse(text); } catch { parsed = text; }
    return {
      status: response.status,
      body: sanitize(parsed),
      contentType: response.headers.get('content-type'),
      disposition: response.headers.get('content-disposition'),
      requestId: response.headers.get('x-request-id') ?? response.headers.get('x-correlation-id') ?? requestId,
    };
  }, { path, options, requestId: `LIVE-B13-${++requestNo}` });
  evidence.push({ actor, method: options.method ?? 'GET', path, status: result.status, requestId: result.requestId, body: result.body, contentType: result.contentType });
  return result;
}

async function file(path, accept = 'application/pdf') {
  const result = await page.evaluate(async ({ path, accept, requestId }) => {
    const xsrf = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    const response = await fetch(`/api/v1${path}`, {
      credentials: 'include',
      headers: { Accept: accept, 'X-Requested-With': 'XMLHttpRequest', 'X-Request-ID': requestId, ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}) },
    });
    return { status: response.status, contentType: response.headers.get('content-type'), disposition: response.headers.get('content-disposition'), bytes: (await response.arrayBuffer()).byteLength, requestId: response.headers.get('x-request-id') ?? requestId };
  }, { path, accept, requestId: `LIVE-B13-${++requestNo}` });
  evidence.push({ actor, method: 'GET', path, status: result.status, requestId: result.requestId, file: result });
  return result;
}

async function expect(name, response, accepted, detail = '') {
  const statuses = Array.isArray(accepted) ? accepted : [accepted];
  const ok = statuses.includes(response.status);
  record(ok ? 'PASS' : 'FAIL', name, `HTTP ${response.status}; expected ${statuses.join('/')}${detail ? ` ${detail}` : ''}; body=${short(response.body)}`);
  if (!ok) await shot(`failure-${name}`);
  return ok;
}

async function ui(path, name) {
  const response = await page.goto(`${base}${path}`, { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => null);
  await page.locator('body').waitFor({ state: 'visible', timeout: 20000 }).catch(() => {});
  await page.waitForFunction(() => document.body.innerText.trim().length > 30, null, { timeout: 25000 }).catch(() => {});
  const text = await page.locator('body').innerText().catch(() => '');
  await shot(name);
  const bad = /application error|internal server error|something went wrong/i.test(text);
  record(bad ? 'FAIL' : 'PASS', `${name} UI`, `document=${response?.status() ?? 'none'} uiUrl=${page.url()}`);
  return text;
}

async function logout() {
  if (actor !== 'signed-out') {
    const route = guard === 'supplier' ? '/b2b/supplier/logout' : guard === 'customer' ? '/b2b/customer/logout' : '/auth/logout';
    await api(route, { method: 'POST' }).catch(() => {});
  }
  await context.clearCookies();
  actor = 'signed-out';
  guard = 'none';
}

async function login(alias, email) {
  await logout();
  const wait = Math.max(0, 13000 - (Date.now() - lastLogin));
  if (wait) await sleep(wait);
  await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.locator('input[type="email"], input[name="email"]').first().fill(email);
  await page.locator('input[type="password"]').first().fill(password);
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 }).catch(() => {});
  actor = alias;
  guard = 'internal';
  lastLogin = Date.now();
  await expect(`${alias} authenticated`, await api('/auth/user'), 200, `uiUrl=${page.url()}`);
}

async function loginSupplier() {
  await logout();
  const wait = Math.max(0, 13000 - (Date.now() - lastLogin));
  if (wait) await sleep(wait);
  await page.goto(`${base}/portal/supplier/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.locator('input[type="email"], input[name="email"]').first().fill('portal@supp.test');
  await page.locator('input[type="password"]').first().fill(password);
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 }).catch(() => {});
  actor = 'supplier-portal';
  guard = 'supplier';
  lastLogin = Date.now();
  await expect('supplier portal authenticated', await api('/b2b/supplier/me'), 200, `uiUrl=${page.url()}`);
}

async function loginCustomer() {
  await logout();
  const wait = Math.max(0, 13000 - (Date.now() - lastLogin));
  if (wait) await sleep(wait);
  await page.goto(`${base}/portal/customer/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.locator('input[type="email"], input[name="email"]').first().fill('portal@cust.test');
  await page.locator('input[type="password"]').first().fill(password);
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 }).catch(() => {});
  actor = 'customer-portal';
  guard = 'customer';
  lastLogin = Date.now();
  await expect('customer portal authenticated', await api('/b2b/customer/me'), 200, `uiUrl=${page.url()}`);
}

function findRow(rows, idsOrPredicate) {
  const idsToFind = Array.isArray(idsOrPredicate) ? idsOrPredicate : [];
  return rows.find((row) => idsToFind.includes(row.id)) ?? (typeof idsOrPredicate === 'function' ? rows.find(idsOrPredicate) : null);
}

try {
  await fs.mkdir(artifactDir, { recursive: true });

  /* L15 — resolve the one portal tenant and a safe disposable PO. */
  await loginSupplier();
  await ui('/portal/supplier', 'l15-supplier-dashboard');
  const supplierMe = dataOf((await api('/b2b/supplier/me')).body);
  ids.vendor = supplierMe?.vendor?.id ?? supplierMe?.vendor_id ?? null;
  observed('L15 supplier tenant identity', `vendor=${ids.vendor}; me=${short(supplierMe)}`);
  const supplierPos = await api('/b2b/supplier/purchase-orders?per_page=100');
  await expect('L15 supplier own PO list', supplierPos, 200);
  const supplierPoRows = rowsOf(supplierPos.body);
  const supplierPo = supplierPoRows.find((row) => ['sent', 'acknowledged', 'supplier_proposed', 'partially_received'].includes(statusOf(row)));
  if (supplierPo?.id) ids.po = supplierPo.id;
  observed('L15 supplier PO fixture inventory', `rows=${supplierPoRows.length}; candidate=${ids.po ?? 'none'}; statuses=${supplierPoRows.map((row) => `${row.id}:${statusOf(row)}`).join(',')}`);

  await login('purchasing', 'purchasing@ogami.test');
  await ui('/purchasing/purchase-orders', 'l15-purchasing-orders');
  const internalPos = await api('/purchasing/purchase-orders?per_page=100');
  await expect('L15 internal PO list', internalPos, 200);
  const internalPoRows = rowsOf(internalPos.body);
  const safeIds = ['35Mbaeb49K', 'MYAbJ3wBl7'];
  const internalCandidate = internalPoRows.find((row) => row.id === ids.po)
    ?? internalPoRows.find((row) => safeIds.includes(row.id) && ['approved', 'sent', 'acknowledged', 'supplier_proposed', 'partially_received'].includes(statusOf(row)))
    ?? internalPoRows.find((row) => row.vendor?.id === ids.vendor && ['approved', 'sent', 'acknowledged', 'supplier_proposed', 'partially_received'].includes(statusOf(row)) && /LIVE|disposable|audit/i.test(JSON.stringify(row)));
  if (internalCandidate?.id) {
    ids.po = internalCandidate.id;
    const beforePo = await api(`/purchasing/purchase-orders/${ids.po}`);
    await expect('L15 internal safe PO detail', beforePo, 200);
    const beforeData = dataOf(beforePo.body);
    ids.poNumber = beforeData?.po_number;
    const vendorMatch = !ids.vendor || beforeData?.vendor?.id === ids.vendor || beforeData?.vendor_id === ids.vendor;
    const eligible = vendorMatch && ['approved', 'sent', 'acknowledged', 'supplier_proposed', 'partially_received'].includes(statusOf(beforeData));
    if (!eligible) {
      blocked('L15 supplier PO action loop', `Candidate ${ids.po} is not a safe portal-owned PO: vendor_match=${vendorMatch}; status=${statusOf(beforeData)}; number=${ids.poNumber}`);
    } else {
      retain('purchase-order', ids.po, `L15 supplier portal loop; ${ids.poNumber}`);
      if (statusOf(beforeData) === 'approved') {
        const sent = await api(`/purchasing/purchase-orders/${ids.po}/send`, { method: 'PATCH', body: {} });
        await expect('L15 purchasing sends disposable approved PO', sent, 200);
      } else {
        observed('L15 PO already sent or receiving-stage', `po=${ids.po}; status=${statusOf(beforeData)}`);
      }
      const afterSend = await api(`/purchasing/purchase-orders/${ids.po}`);
      await expect('L15 PO dispatch ledger after send', afterSend, 200);
      observed('L15 PO dispatch before portal session', `po=${ids.po}; number=${ids.poNumber}; status=${statusOf(dataOf(afterSend.body))}; dispatch=${short(dataOf(afterSend.body)?.supplier_dispatch)}`);
    }
  } else {
    blocked('L15 supplier PO action loop', `Supplier tenant has no sent/approved PO and no safe disposable internal candidate; supplier_rows=${supplierPoRows.length}; internal_rows=${internalPoRows.length}`);
  }

  if (ids.po) {
    await loginSupplier();
    await ui('/portal/supplier/purchase-orders', 'l15-supplier-purchase-orders');
    const poDetail = await api(`/b2b/supplier/purchase-orders/${ids.po}`);
    await expect('L15 supplier reads own PO detail', poDetail, 200);
    const poData = dataOf(poDetail.body);
    const poPdf = await file(`/b2b/supplier/purchase-orders/${ids.po}/pdf`);
    await expect('L15 supplier PO PDF', poPdf, 200);
    observed('L15 supplier PO detail scope', `po=${ids.po}; status=${statusOf(poData)}; vendor=${poData?.vendor?.id}; items=${short(poData?.items)}; dispatch=${short(poData?.supplier_dispatch)}`);

    if (['sent', 'acknowledged'].includes(statusOf(poData))) {
      const ack = await api(`/b2b/supplier/purchase-orders/${ids.po}/acknowledge`, { method: 'POST', body: { expected_delivery_date: new Date(Date.now() + 14 * 86400000).toISOString().slice(0, 10), notes: `LIVE-B13 supplier acknowledgement ${stamp}` } });
      await expect('L15 supplier acknowledges PO', ack, [200, 201, 422]);
    }
    const responseBody = { type: 'accept', notes: `LIVE-B13 supplier response ${stamp}` };
    const response = await api(`/b2b/supplier/purchase-orders/${ids.po}/respond`, { method: 'POST', body: responseBody });
    if ([200, 201].includes(response.status)) {
      await expect('L15 supplier response', response, [200, 201]);
      const replay = await api(`/b2b/supplier/purchase-orders/${ids.po}/respond`, { method: 'POST', body: responseBody });
      await expect('L15 supplier response exact replay', replay, [200, 201, 409, 422]);
      observed('L15 supplier response IDs', `first=${idOf(response.body)}; replay=${idOf(replay.body)}; first_body=${short(response.body)}; replay_body=${short(replay.body)}`);
    } else {
      blocked('L15 supplier response loop', `PO status=${statusOf(poData)}; response HTTP ${response.status}; no response replay sent.`);
    }

    const latestPo = await api(`/b2b/supplier/purchase-orders/${ids.po}`);
    const latestData = dataOf(latestPo.body);
    if (['sent', 'acknowledged', 'supplier_proposed', 'partially_received'].includes(statusOf(latestData))) {
      if (!latestData?.supplier_shipment) {
        const shipment = await api(`/b2b/supplier/purchase-orders/${ids.po}/shipment-update`, { method: 'POST', body: { shipped_date: new Date().toISOString().slice(0, 10), carrier: 'LIVE-B13 Carrier', tracking_number: `B13-${stamp}`, estimated_arrival: new Date(Date.now() + 14 * 86400000).toISOString().slice(0, 10), notes: 'LIVE-B13 structured shipment snapshot' } });
        await expect('L15 supplier structured shipment update', shipment, [200, 201]);
      } else {
        observed('L15 supplier shipment snapshot already exists', short(latestData.supplier_shipment));
      }
      const docsBefore = await api(`/b2b/supplier/purchase-orders/${ids.po}/shipping-documents`);
      await expect('L15 supplier shipping document list', docsBefore, 200);
      const docRowsBefore = rowsOf(docsBefore.body);
      if (!docRowsBefore.length) {
        const options = await api('/b2b/supplier/purchase-orders/shipping-documents/options');
        await expect('L15 supplier shipping document options', options, 200);
        const type = dataOf(options.body)?.document_types?.[0]?.value ?? dataOf(options.body)?.types?.[0]?.value ?? 'bill_of_lading';
        const upload = await api(`/b2b/supplier/purchase-orders/${ids.po}/shipping-documents`, { method: 'POST', multipart: true, fields: { document_type: type, notes: 'LIVE-B13 disposable shipping document' }, filename: `live-b13-${stamp}.pdf` });
        await expect('L15 supplier uploads shipping document', upload, 201);
        ids.shippingDocument = idOf(upload.body);
        const replayUpload = await api(`/b2b/supplier/purchase-orders/${ids.po}/shipping-documents`, { method: 'POST', multipart: true, fields: { document_type: type, notes: 'LIVE-B13 disposable shipping document' }, filename: `live-b13-${stamp}.pdf` });
        await expect('L15 supplier shipping document exact replay', replayUpload, 201);
        observed('L15 shipping document replay', `first=${ids.shippingDocument}; replay=${idOf(replayUpload.body)}; before_count=${docRowsBefore.length}`);
      } else {
        ids.shippingDocument = docRowsBefore[0].id;
        const download = await file(`/b2b/supplier/shipping-documents/${ids.shippingDocument}/download`, '*/*');
        await expect('L15 supplier shipping document download', download, 200);
        observed('L15 existing supplier shipping documents', `count=${docRowsBefore.length}; first=${ids.shippingDocument}`);
      }
      const docsAfter = await api(`/b2b/supplier/purchase-orders/${ids.po}/shipping-documents`);
      await expect('L15 supplier shipping document post-replay count', docsAfter, 200);
      observed('L15 supplier document idempotency count', `before=${docRowsBefore.length}; after=${rowsOf(docsAfter.body).length}; ids=${rowsOf(docsAfter.body).map((row) => row.id).join(',')}`);
    } else {
      blocked('L15 shipment/document handoff', `PO became status=${statusOf(latestData)}; supplier shipment update is not allowed for this state.`);
    }

    const supplierInvoices = await api('/b2b/supplier/invoices?per_page=100');
    await expect('L15 supplier invoice list', supplierInvoices, 200);
    observed('L15 supplier invoice fixture', `rows=${rowsOf(supplierInvoices.body).length}; rows=${short(supplierInvoices.body)}`);
    if (!rowsOf(supplierInvoices.body).some((row) => row.purchase_order?.id === ids.po || row.purchase_order_id === ids.po)) {
      blocked('L15 supplier invoice/AP handoff', `No existing supplier invoice for PO ${ids.po}; accepted GRN is required and no invoice draft was manufactured. Duplicate vendor invoice-number replay remains blocked.`);
    }
    await expect('L15 supplier cannot use internal auth guard', await api('/auth/user'), 401);
    await expect('L15 supplier opposite customer guard', await api('/b2b/customer/me'), 401);
  }

  /* Internal response review and recipient notification evidence. */
  await login('purchasing', 'purchasing@ogami.test');
  if (ids.po) {
    const responses = await api(`/purchasing/purchase-orders/${ids.po}/responses`);
    await expect('L15 purchasing reads supplier response queue', responses, 200);
    const responseRows = rowsOf(responses.body);
    const pending = responseRows.find((row) => statusOf(row) === 'pending');
    if (pending?.id) {
      const accepted = await api(`/purchasing/purchase-order-responses/${pending.id}/accept`, { method: 'PATCH', body: {} });
      await expect('L15 purchasing accepts supplier response', accepted, 200);
      const replay = await api(`/purchasing/purchase-order-responses/${pending.id}/accept`, { method: 'PATCH', body: {} });
      await expect('L15 purchasing supplier response replay', replay, [200, 409, 422]);
    } else {
      observed('L15 supplier response internal state', `rows=${responseRows.length}; no pending response to approve; statuses=${responseRows.map(statusOf).join(',')}`);
    }
  }
  const purchasingNotifications = await api('/notifications?per_page=100');
  await expect('L15 purchasing notification feed', purchasingNotifications, 200);
  const poNotifications = rowsOf(purchasingNotifications.body).filter((row) => String(row.type ?? '').includes('supplier') || JSON.stringify(row).includes(ids.po ?? 'never'));
  observed('L15 supplier dispatch notification evidence', `notification_total=${dataOf(purchasingNotifications.body)?.total ?? rowsOf(purchasingNotifications.body).length}; matching_ids=${poNotifications.map((row) => row.id).join(',')}; matching=${short(poNotifications)}`);

  /* L16 — customer tenant, existing SO/delivery/complaint/RMA evidence only. */
  await loginCustomer();
  await ui('/portal/customer', 'l16-customer-dashboard');
  const customerMe = dataOf((await api('/b2b/customer/me')).body);
  ids.customer = customerMe?.customer?.id ?? customerMe?.customer_id ?? null;
  observed('L16 customer tenant identity', `customer=${ids.customer}; me=${short(customerMe)}`);
  const [orders, invoices, deliveries, complaints, rmas, sources] = await Promise.all([
    api('/b2b/customer/orders?per_page=100'),
    api('/b2b/customer/invoices?per_page=100'),
    api('/b2b/customer/deliveries?per_page=100'),
    api('/b2b/customer/complaints?per_page=100'),
    api('/b2b/customer/return-requests?per_page=100'),
    api('/b2b/customer/return-requests/source-options'),
  ]);
  await expect('L16 customer own orders', orders, 200);
  await expect('L16 customer own invoices', invoices, 200);
  await expect('L16 customer own deliveries', deliveries, 200);
  await expect('L16 customer own complaints', complaints, 200);
  await expect('L16 customer own RMA list', rmas, [200, 404]);
  await expect('L16 customer RMA source options', sources, [200, 404]);
  const customerOrderRows = rowsOf(orders.body);
  const customerDeliveryRows = rowsOf(deliveries.body);
  const customerComplaintRows = rowsOf(complaints.body);
  const customerRmaRows = rowsOf(rmas.body);
  observed('L16 customer tenant row inventory', `orders=${customerOrderRows.length}; invoices=${rowsOf(invoices.body).length}; deliveries=${customerDeliveryRows.length}; complaints=${customerComplaintRows.length}; rmas=${customerRmaRows.length}; source_options=${short(sources.body)}`);

  const responseOrder = findRow(customerOrderRows, ['v7ONK2p2jd', 'XKEbGkbgWk'], (row) => /requested|pending|open/i.test(JSON.stringify(row)));
  if (responseOrder?.id) {
    ids.customerResponseSo = responseOrder.id;
    const orderDetail = await api(`/b2b/customer/orders/${responseOrder.id}`);
    await expect('L16 customer reads response SO detail', orderDetail, 200);
    const responsePayload = { type: 'accept', notes: 'LIVE-B13 customer response replay' };
    const firstResponse = await api(`/b2b/customer/orders/${responseOrder.id}/respond`, { method: 'POST', body: responsePayload });
    if ([200, 201].includes(firstResponse.status)) {
      const replayResponse = await api(`/b2b/customer/orders/${responseOrder.id}/respond`, { method: 'POST', body: responsePayload });
      await expect('L16 customer SO response replay', replayResponse, [200, 201, 409, 422]);
      observed('L16 customer response replay effect', `order=${responseOrder.id}; first=${idOf(firstResponse.body)}; replay=${idOf(replayResponse.body)}; first=${short(firstResponse.body)}; replay=${short(replayResponse.body)}`);
    } else {
      blocked('L16 customer SO response replay', `Existing order ${responseOrder.id} is not response-actionable: HTTP ${firstResponse.status}; body=${short(firstResponse.body)}`);
    }
  } else {
    blocked('L16 customer SO response replay', `No existing customer order is open for a portal response; rows=${customerOrderRows.length}`);
  }

  const delivery = findRow(customerDeliveryRows, ['dGypLxpvAg'], (row) => statusOf(row) === 'delivered');
  if (delivery?.id) {
    ids.delivery = delivery.id;
    const deliveryBefore = await api(`/b2b/customer/deliveries/${ids.delivery}`);
    await expect('L16 customer reads own delivery detail', deliveryBefore, 200);
    const deliveryData = dataOf(deliveryBefore.body);
    const proofId = deliveryData?.proofs?.[0]?.id ?? deliveryData?.proof?.id;
    if (proofId) {
      const proof = await file(`/b2b/customer/deliveries/${ids.delivery}/proofs/${proofId}/view`, '*/*');
      await expect('L16 customer reads own delivery proof', proof, 200);
    } else {
      blocked('L16 customer delivery proof', `Delivery ${ids.delivery} detail returned no proof hash.`);
    }
    const confirmPayload = { receiver_name: 'LIVE-B13 replay receiver', receiver_position: 'Receiving', delivery_remarks: 'LIVE-B13 exact confirmation replay' };
    const confirmed = await api(`/b2b/customer/deliveries/${ids.delivery}/confirm`, { method: 'POST', body: confirmPayload });
    await expect('L16 customer delivery confirmation replay', confirmed, 200);
    const replayConfirmed = await api(`/b2b/customer/deliveries/${ids.delivery}/confirm`, { method: 'POST', body: confirmPayload });
    await expect('L16 customer delivery confirmation second replay', replayConfirmed, 200);
    const beforeInvoice = deliveryData?.invoice?.id ?? deliveryData?.invoice_id;
    const afterData = dataOf(replayConfirmed.body);
    const afterInvoice = afterData?.invoice?.id ?? afterData?.invoice_id;
    observed('L16 delivery confirmation/invoice handoff', `delivery=${ids.delivery}; before_status=${statusOf(deliveryData)}; after_status=${statusOf(afterData)}; before_invoice=${beforeInvoice}; after_invoice=${afterInvoice}; handoff=${afterData?.invoice_handoff_status}; first_body=${short(confirmed.body)}; replay_body=${short(replayConfirmed.body)}`);
    if (beforeInvoice && afterInvoice && beforeInvoice !== afterInvoice) record('FAIL', 'L16 delivery confirmation duplicate invoice guard', `invoice changed from ${beforeInvoice} to ${afterInvoice}`);
  } else {
    blocked('L16 customer delivery confirmation replay', `No existing delivered customer delivery in own tenant; rows=${customerDeliveryRows.length}`);
  }

  const complaint = customerComplaintRows[0];
  if (complaint?.id) {
    ids.customerComplaint = complaint.id;
    const complaintDetail = await api(`/b2b/customer/complaints/${complaint.id}/8d-report`);
    await expect('L16 customer reads complaint 8D handoff', complaintDetail, [200, 404]);
    observed('L16 customer complaint/NCR handoff evidence', `complaint=${complaint.id}; status=${statusOf(complaint)}; report=${short(complaintDetail.body)}`);
  } else {
    blocked('L16 customer complaint/NCR handoff', 'No existing customer-tenant complaint evidence; no portal complaint was created solely for audit coverage.');
  }
  if (customerRmaRows[0]?.id) {
    ids.customerRma = customerRmaRows[0].id;
    const rmaDetail = await api(`/b2b/customer/return-requests/${ids.customerRma}`);
    await expect('L16 customer reads own RMA detail', rmaDetail, 200);
    observed('L16 customer RMA handoff evidence', `rma=${ids.customerRma}; status=${statusOf(dataOf(rmaDetail.body))}; body=${short(rmaDetail.body)}`);
  } else {
    blocked('L16 customer RMA handoff', 'No existing customer-portal RMA evidence; no source-backed return was created solely for audit coverage.');
  }
  await expect('L16 customer cannot use internal auth guard', await api('/auth/user'), 401);
  await expect('L16 customer opposite supplier guard', await api('/b2b/supplier/me'), 401);
  if (ids.po) await expect('L16 customer cannot read supplier PO as customer order', await api(`/b2b/customer/orders/${ids.po}`), [403, 404]);

  /* L17 — narrow durable recovery/replay probes. */
  await login('production', 'production@ogami.test');
  if (ids.output ?? true) {
    const outputList = await api(`/production/work-orders/0ldwDmpVa9/outputs`);
    await expect('L17 production output handoff inventory', outputList, [200, 403, 404]);
    const outputRow = rowsOf(outputList.body).find((row) => row.id === 'GqkbAVwxd1') ?? rowsOf(outputList.body).find((row) => row.production_receipt_handoff_status === 'manual_required');
    if (outputRow?.id) {
      ids.output = outputRow.id;
      const before = await api(`/production/work-orders/0ldwDmpVa9/outputs`);
      const beforeRows = rowsOf(before.body);
      const beforeMovementIds = beforeRows.map((row) => row.production_receipt_movement_id ?? row.receipt_movement_id).filter(Boolean);
      observed('L17 finished-goods receipt before retry', `output=${ids.output}; status=${outputRow.production_receipt_handoff_status}; movement=${outputRow.production_receipt_movement_id}; rows=${beforeRows.length}`);
      if (outputRow.production_receipt_handoff_status === 'manual_required') {
        const retry = await api(`/production/work-orders/0ldwDmpVa9/outputs/${ids.output}/retry-receipt`, { method: 'POST', body: {} });
        await expect('L17 narrow finished-goods receipt retry', retry, [200, 422]);
        const after = await api(`/production/work-orders/0ldwDmpVa9/outputs`);
        const afterRows = rowsOf(after.body);
        const afterOutput = afterRows.find((row) => row.id === ids.output);
        const afterMovementIds = afterRows.map((row) => row.production_receipt_movement_id ?? row.receipt_movement_id).filter(Boolean);
        observed('L17 finished-goods receipt retry reconciliation', `output=${ids.output}; before_movements=${beforeMovementIds.join(',')}; after_movements=${afterMovementIds.join(',')}; after=${short(afterOutput)}`);
        const movementDelta = afterMovementIds.filter((id) => !beforeMovementIds.includes(id));
        if (movementDelta.length > 1) record('FAIL', 'L17 finished-goods receipt duplicate stock guard', `new movement IDs=${movementDelta.join(',')}`);
        if (retry.status === 200) {
          const replay = await api(`/production/work-orders/0ldwDmpVa9/outputs/${ids.output}/retry-receipt`, { method: 'POST', body: {} });
          await expect('L17 finished-goods receipt exact retry replay', replay, [200, 422]);
        }
      } else {
        blocked('L17 finished-goods receipt retry', `Output ${ids.output} is not manual_required; no recovery action sent.`);
      }
    } else {
      blocked('L17 finished-goods receipt retry', 'No retained output with an explicit manual_required receipt handoff was readable.');
    }
  }

  await login('warehouse', 'warehouse@ogami.test');
  const grns = await api('/inventory/grn?per_page=100');
  await expect('L17 GRN recovery inventory', grns, 200);
  const grnRows = rowsOf(grns.body);
  const manualGrn = grnRows.find((row) => statusOf(row) === 'pending_qc' && String(row.incoming_qc_handoff_status ?? row.incoming_qc_handoff?.status) === 'manual_required');
  observed('L17 incoming QC handoff inventory', `rows=${grnRows.length}; manual_required=${manualGrn?.id ?? 'none'}; statuses=${grnRows.map((row) => `${row.id}:${statusOf(row)}:${row.incoming_qc_handoff_status ?? row.incoming_qc_handoff?.status ?? ''}`).join(',')}`);
  if (manualGrn?.id) {
    ids.grn = manualGrn.id;
    await login('qc', 'qc@ogami.test');
    const grnBefore = await api(`/inventory/grn/${ids.grn}`);
    await expect('L17 QC reads manual-required GRN', grnBefore, 200);
    const retryQc = await api(`/inventory/grn/${ids.grn}/retry-incoming-qc`, { method: 'POST', body: {} });
    await expect('L17 narrow incoming QC retry', retryQc, [200, 422]);
    const grnAfter = await api(`/inventory/grn/${ids.grn}`);
    await expect('L17 incoming QC retry reconciliation', grnAfter, 200);
    observed('L17 incoming QC status/effect', `grn=${ids.grn}; before=${short(grnBefore.body)}; after=${short(grnAfter.body)}`);
  } else {
    blocked('L17 narrow incoming QC retry', 'No pending_qc GRN with explicit manual_required handoff was present; no GRN was created or changed.');
  }

  await login('finance', 'finance@ogami.test');
  const movements = await api('/inventory/stock-movements?per_page=200');
  await expect('L17 stock GL handoff inventory', movements, 200);
  const movementRows = rowsOf(movements.body);
  const manualMovement = movementRows.find((row) => ['manual_required', 'failed'].includes(String(row.gl_handoff_status ?? row.gl_status ?? row.journal_status ?? '')));
  observed('L17 stock GL handoff state', `rows=${movementRows.length}; manual_or_failed=${manualMovement?.id ?? 'none'}; sample=${short(movementRows.slice(0, 5))}`);
  if (manualMovement?.id && String(manualMovement.gl_handoff_status ?? manualMovement.gl_status ?? manualMovement.journal_status) === 'manual_required') {
    ids.stockMovement = manualMovement.id;
    const movementBefore = await api(`/inventory/stock-movements?movement_id=${ids.stockMovement}`);
    const retryGl = await api(`/inventory/stock-movements/${ids.stockMovement}/retry-gl`, { method: 'POST', body: {} });
    await expect('L17 narrow stock GL retry', retryGl, [200, 422]);
    const movementAfter = await api(`/inventory/stock-movements?movement_id=${ids.stockMovement}`);
    observed('L17 stock GL retry reconciliation', `movement=${ids.stockMovement}; before=${short(movementBefore.body)}; after=${short(movementAfter.body)}; retry=${short(retryGl.body)}`);
  } else {
    blocked('L17 narrow stock GL retry', 'No stock movement with explicit manual_required GL handoff was present; no GL retry was sent.');
  }

  const periods = await api('/payroll-periods?per_page=100');
  await expect('L17 payroll recovery inventory', periods, 200);
  const periodRows = rowsOf(periods.body);
  const manualPayroll = periodRows.find((row) => String(row.gl_handoff_status ?? row.gl_status ?? row.payroll_gl_status ?? '') === 'manual_required');
  const processingPeriod = periodRows.find((row) => statusOf(row) === 'processing');
  observed('L17 payroll GL/bank handoff state', `periods=${periodRows.length}; manual_gl=${manualPayroll?.id ?? 'none'}; processing=${processingPeriod?.id ?? 'none'}; sample=${short(periodRows.slice(0, 5))}`);
  if (manualPayroll?.id) {
    ids.payrollPeriod = manualPayroll.id;
    const payrollBefore = await api(`/payroll-periods/${ids.payrollPeriod}`);
    const retryPayrollGl = await api(`/payroll-periods/${ids.payrollPeriod}/retry-gl`, { method: 'POST', body: {} });
    await expect('L17 narrow payroll GL retry', retryPayrollGl, 202);
    const payrollAfter = await api(`/payroll-periods/${ids.payrollPeriod}`);
    observed('L17 payroll GL retry reconciliation', `period=${ids.payrollPeriod}; before=${short(payrollBefore.body)}; after=${short(payrollAfter.body)}; retry=${short(retryPayrollGl.body)}`);
  } else {
    blocked('L17 narrow payroll GL retry', 'No payroll period with explicit manual_required GL handoff was present; no payroll GL retry was sent.');
  }
  if (processingPeriod?.id) {
    blocked('L17 payroll processing failure visibility', `Processing period ${processingPeriod.id} remains a durable failed/blocked residual; no force-unlock or compute replay was sent in this batch.`);
  } else {
    observed('L17 payroll processing failure visibility', 'No processing payroll period remained in the current list.');
  }

  await login('crm', 'crm@ogami.test');
  const crmComplaints = await api('/crm/complaints?per_page=100');
  await expect('L17 complaint NCR handoff inventory', crmComplaints, 200);
  const crmComplaintRows = rowsOf(crmComplaints.body);
  const manualComplaint = crmComplaintRows.find((row) => String(row.ncr_handoff_status ?? row.ncr_handoff?.status) === 'manual_required');
  observed('L17 complaint NCR durable state', `rows=${crmComplaintRows.length}; manual_required=${manualComplaint?.id ?? 'none'}; sample=${short(crmComplaintRows.slice(0, 5))}`);
  if (manualComplaint?.id) {
    ids.manualComplaint = manualComplaint.id;
    const complaintBefore = await api(`/crm/complaints/${ids.manualComplaint}`);
    const retryNcr = await api(`/crm/complaints/${ids.manualComplaint}/retry-ncr`, { method: 'POST', body: {} });
    await expect('L17 narrow complaint NCR retry', retryNcr, 200);
    const complaintAfter = await api(`/crm/complaints/${ids.manualComplaint}`);
    observed('L17 complaint NCR retry reconciliation', `complaint=${ids.manualComplaint}; before=${short(complaintBefore.body)}; after=${short(complaintAfter.body)}; retry=${short(retryNcr.body)}`);
  } else {
    const generated = crmComplaintRows.find((row) => row.ncr?.id || row.ncr_id || row.ncr_handoff_status === 'generated');
    if (generated?.id) {
      const retryGenerated = await api(`/crm/complaints/${generated.id}/retry-ncr`, { method: 'POST', body: {} });
      await expect('L17 generated complaint NCR replay is idempotent', retryGenerated, 200);
      observed('L17 complaint NCR replay without duplicate', `complaint=${generated.id}; ncr=${generated.ncr?.id ?? generated.ncr_id}; response=${short(retryGenerated.body)}`);
    } else {
      blocked('L17 narrow complaint NCR retry', 'No manual_required or generated complaint-to-NCR handoff was present; no complaint was created.');
    }
  }
  const crmNotifications = await api('/notifications?per_page=100');
  await expect('L17 CRM notification feed', crmNotifications, 200);
  observed('L17 complaint notification evidence', `total=${dataOf(crmNotifications.body)?.total ?? rowsOf(crmNotifications.body).length}; ids=${rowsOf(crmNotifications.body).map((row) => row.id).join(',')}`);

  await login('admin', 'admin@ogami.test');
  await ui('/admin/operations-health', 'l17-admin-operations-health');
  const adminDashboard = await api('/dashboards/admin');
  await expect('L17 admin durable failure dashboard', adminDashboard, 200);
  const adminData = dataOf(adminDashboard.body);
  const queueHealth = adminData?.queue_health ?? adminData?.queueHealth ?? adminData?.widgets?.queue_health;
  const failedCount = queueHealth?.failed_jobs ?? queueHealth?.failed ?? queueHealth?.failed_count ?? null;
  observed('L17 non-zero failure reporting', `queue_health=${short(queueHealth)}; failed_count=${failedCount}; dashboard=${short(adminData)}`);
  if (failedCount !== null && Number(failedCount) === 0) record('FAIL', 'L17 non-zero failure reporting', `admin dashboard reported zero failed jobs despite the live recovery audit requiring failure visibility; queue=${short(queueHealth)}`);
  const rollout = await api('/dashboards/rollout-health');
  await expect('L17 rollout/recovery health read', rollout, 200);
  const actionCenter = await api('/dashboards/action-center');
  await expect('L17 operational exception queue read', actionCenter, 200);
  observed('L17 action center durable handoffs', `action_center=${short(actionCenter.body)}; rollout=${short(rollout.body)}`);
} catch (error) {
  record('FAIL', 'L15-L17 audit worker harness', error instanceof Error ? error.stack ?? error.message : String(error));
  await shot('failure-harness').catch(() => {});
} finally {
  await logout().catch(() => {});
  await browser.close();
  await fs.writeFile(`${artifactDir}/l15-l17-results.json`, JSON.stringify({ generated_at: new Date().toISOString(), ids, residuals, results, evidence }, null, 2));
}

console.log(`COUNTS ${JSON.stringify(results.reduce((out, row) => ({ ...out, [row.status]: (out[row.status] ?? 0) + 1 }), {}))}`);
console.log(`IDS ${JSON.stringify(ids)}`);
