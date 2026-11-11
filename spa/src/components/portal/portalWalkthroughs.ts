export interface PortalGuideItem {
  to: string;
  label: string;
  description?: string;
}

export interface PortalGuideSection {
  label: string;
  items: PortalGuideItem[];
}

export interface PortalGuideStep {
  title: string;
  description: string;
  links: PortalGuideItem[];
  sectionLabel?: string;
  target?: PortalGuideTarget;
  targetIndex?: number;
}

export type PortalGuideTarget = 'page-header' | 'page-actions' | 'filters' | 'records' | 'form' | 'section' | 'navigation';

export interface PortalPageGuide {
  title: string;
  description: string;
  steps: PortalGuideStep[];
}

const sectionCopy: Record<'customer' | 'supplier', Record<string, string>> = {
  customer: {
    Overview: 'Check account activity, read portal notices, and review or sign agreements.',
    Orders: 'Browse approved products, submit orders, track order decisions, and review agreed prices.',
    Billing: 'Review invoices, submit bank payment proof, and reconcile your account statement.',
    Logistics: 'Track delivery schedules and confirm what arrived.',
    Quality: 'Follow return requests and send a problem report to the Ogami team.',
  },
  supplier: {
    Overview: 'Check purchasing activity, read portal notices, and review or sign supplier agreements.',
    Orders: 'Respond to purchase orders and RFQs, then keep your item listings current.',
    Billing: 'Submit invoices and reconcile your account statement.',
    Logistics: 'Share delivery updates and follow shipment or problem reports.',
  },
};

const itemCopy: Record<'customer' | 'supplier', Record<string, string>> = {
  customer: {
    Dashboard: 'See a summary of your recent orders, invoices, and deliveries.',
    Notifications: 'Read updates from Ogami and mark notices as handled.',
    Agreements: 'Open the customer agreements issued to your account.',
    Orders: 'Review order status, delivery schedules, payment terms, and related records.',
    'Place Order': 'Choose an approved product, enter an available quantity, and submit your request.',
    'Pricing agreements': 'Review product pricing approved for your customer account.',
    Invoices: 'Open invoice details and submit bank transfer evidence for review.',
    'Account Statement': 'Compare invoices, payments, and the current balance for your account.',
    Deliveries: 'Track planned shipments and confirm delivery quantities or receipt documents.',
    Returns: 'Create and track a return request for a delivered order.',
    'Problem reports': 'Report a product or delivery issue and follow its resolution.',
  },
  supplier: {
    Dashboard: 'See a summary of purchasing, invoices, and recent delivery activity.',
    Notifications: 'Read updates from Ogami and mark notices as handled.',
    Agreements: 'Open supplier agreements issued to your company.',
    'Purchase Orders': 'Review order lines, due dates, and response actions from Ogami.',
    'Supplier RFQs': 'Review requests for quotation and send your response.',
    'Item Listings': 'Maintain the item details and images buyers use to identify your products.',
    Invoices: 'Track submitted invoices and their review status.',
    'Account Statement': 'Reconcile invoices and payments recorded for your supplier account.',
    Deliveries: 'Share shipment updates and review planned or received deliveries.',
    'Problem reports': 'Follow purchasing or delivery issues that need your response.',
  },
};

export function portalGuideStorageKey(realm: 'customer' | 'supplier', userId: string): string {
  return `ogami:portal-walkthrough:${realm}:${userId}:v1`;
}

export function portalWalkthroughSteps(
  realm: 'customer' | 'supplier',
  sections: PortalGuideSection[],
): PortalGuideStep[] {
  const intro = realm === 'customer'
    ? 'Use the customer portal to manage orders, invoices, deliveries, and returns.'
    : 'Use the supplier portal to manage purchase orders, listings, deliveries, and invoices.';

  return [
    { title: `Welcome to your ${realm} portal`, description: intro, links: [] },
    ...sections.map((section) => ({
      title: section.label,
      sectionLabel: section.label,
      description: sectionCopy[realm][section.label] ?? `Use ${section.label.toLowerCase()} to complete the related account tasks.`,
      links: section.items.map((item) => ({
        ...item,
        description: itemCopy[realm][item.label] ?? `Open ${item.label.toLowerCase()} to view and manage this part of your account.`,
      })),
    })),
  ];
}

const guideStep = (
  title: string,
  description: string,
  target?: PortalGuideTarget,
  targetIndex?: number,
): PortalGuideStep => ({
  title,
  description,
  links: [],
  target,
  ...(targetIndex === undefined ? {} : { targetIndex }),
});

const pageGuide = (
  title: string,
  description: string,
  steps: PortalGuideStep[],
): PortalPageGuide => ({ title, description, steps });

interface PortalGuideRoute {
  path: string;
  guide: PortalPageGuide;
}

const customerPageGuides: PortalGuideRoute[] = [
  {
    path: '/portal/customer',
    guide: pageGuide('Customer dashboard', 'A quick view of your account activity and the records that may need your attention.', [
      guideStep('Account overview', 'Open a summary card to jump to open orders, pending deliveries, unpaid invoices, or your account balance.', 'section', 0),
      guideStep('Account terms', 'Review your payment defaults, agreement dates, product order limits, and warranty terms.', 'section', 1),
      guideStep('Recent activity', 'Recent Orders links to each order; the neighboring invoice and delivery panels show the latest account activity.', 'section', 2),
      guideStep('Go to a task', 'Use the customer navigation to place an order, review pricing, check billing, or follow a delivery.', 'navigation'),
    ]),
  },
  {
    path: '/portal/customer/notifications',
    guide: pageGuide('Customer notifications', 'Read updates from Ogami and open the related record when you need more detail.', [
      guideStep('Filter notifications', 'Choose All, Unread, Approvals, Alerts, or System to narrow the notices.', 'filters'),
      guideStep('Open a notification', 'Select a notice to mark it read and open its related portal record when a link is available.', 'records'),
      guideStep('Manage read status', 'Use Mark all read for unread notices or Clear read to remove notices you have already reviewed.', 'page-actions'),
    ]),
  },
  {
    path: '/portal/customer/agreements',
    guide: pageGuide('Customer agreements', 'Review agreements issued to your organization and check which ones need your response.', [
      guideStep('Agreement status', 'Use the agreement list to see what is active, awaiting signature, or already complete.', 'records'),
      guideStep('Review the agreement', 'Open the agreement document from its row. Download a PDF or sign when those actions are available.', 'records'),
    ]),
  },
  {
    path: '/portal/customer/pricing',
    guide: pageGuide('Customer pricing agreements', 'Review volume price requests and respond to offers for your account.', [
      guideStep('Review requests and offers', 'Each row shows the requested quantity, offered terms, status, and any response options.', 'records'),
      guideStep('Respond to an offer', 'Use Accept terms or Decline when those buttons are available in the Your decision column.', 'records'),
      guideStep('Request volume pricing', 'Request pricing opens a review form. A request does not place an order or reserve stock.', 'page-actions'),
      guideStep('Complete the pricing request', 'Choose a product and planned quantity, then add an optional target price and the reason for your request.', 'form'),
    ]),
  },
  {
    path: '/portal/customer/orders/new',
    guide: pageGuide('Place an order', 'Choose products, set quantities and delivery details, then send the order to Sales for review.', [
      guideStep('Set delivery and payment details', 'Choose the requested delivery date and payment terms. Pricing is checked against the date and your account agreement.', 'form'),
      guideStep('Add products and quantities', 'Search approved products, add the ones you need, then review quantities, availability, and prices.', 'form'),
      guideStep('Review and send the order', 'Check the order summary and any validation messages before submitting your request.', 'form'),
    ]),
  },
  {
    path: '/portal/customer/orders/:id/edit',
    guide: pageGuide('Edit a customer order', 'Update a submitted order while it remains open for changes.', [
      guideStep('Review order details', 'The delivery date, payment terms, and notes describe the request Sales will review.', 'form'),
      guideStep('Update order items', 'Change quantities or remove items, then check the refreshed price and availability.', 'form'),
      guideStep('Save your changes', 'Review the page messages and save the updated order before Sales confirms it.', 'form'),
    ]),
  },
  {
    path: '/portal/customer/orders/:id',
    guide: pageGuide('Customer order details', 'Follow the order from review through fulfillment and open related delivery or payment information.', [
      guideStep('Order progress', 'The progress line shows the latest order stage and what happens next.', 'page-header'),
      guideStep('Order totals and terms', 'Check the product total, payment terms, and delivery terms saved on this order.', 'section', 0),
      guideStep('Line items and prices', 'Each row shows an ordered product, quantity, unit price, and line total. Agreed prices are labeled.', 'records'),
      guideStep('Available actions', 'Edit or cancel an order while those actions are available. If a deposit is due, submit its payment evidence here.', 'page-actions'),
    ]),
  },
  {
    path: '/portal/customer/orders',
    guide: pageGuide('Customer orders', 'Review submitted orders and open one to follow its approval and delivery progress.', [
      guideStep('Search and filter orders', 'Use the available search and filters to narrow the order list.', 'filters'),
      guideStep('Open an order', 'Choose an order row to see its items, status, payment terms, and delivery schedule.', 'records'),
      guideStep('Place an order', 'Use the page action to start a new order request.', 'page-actions'),
    ]),
  },
  {
    path: '/portal/customer/invoices/:id',
    guide: pageGuide('Customer invoice details', 'Review an invoice and send bank transfer evidence when payment is ready to reconcile.', [
      guideStep('Invoice summary', 'Check the invoice number, dates, amount due, and current payment status.', 'page-header'),
      guideStep('Invoice records', 'Review the billed items and any payment activity attached to this invoice.', 'section'),
      guideStep('Submit payment evidence', 'Use the payment action to upload proof of a bank transfer for Finance to review.', 'page-actions'),
    ]),
  },
  {
    path: '/portal/customer/invoices',
    guide: pageGuide('Customer invoices', 'Track invoices, spot overdue or unpaid balances, and open an invoice for payment details.', [
      guideStep('Find an invoice', 'Search or filter by invoice details and status to narrow the list.', 'filters'),
      guideStep('Review invoice status', 'The list shows amounts and payment state. Open an invoice to see its full details.', 'records'),
      guideStep('Follow payment signals', 'Use the invoice badges in the sidebar to jump directly to overdue or unpaid invoices.', 'navigation'),
    ]),
  },
  {
    path: '/portal/customer/statement-of-account',
    guide: pageGuide('Customer account statement', 'Compare invoices, payments, credits, and the current balance recorded for your account.', [
      guideStep('Outstanding balance', 'The summary shows total outstanding and aging amounts as of the date in the page heading.', 'section'),
      guideStep('Statement entries', 'Each row is a posted invoice, payment, or adjustment contributing to the balance.', 'records'),
    ]),
  },
  {
    path: '/portal/customer/deliveries/:id',
    guide: pageGuide('Customer delivery details', 'Check what was shipped, review delivery proof, and confirm receipt when the shipment arrives.', [
      guideStep('Delivery status', 'The header shows the delivery reference and its current state.', 'page-header'),
      guideStep('Items and proof', 'Compare delivered quantities with the order and open the attached delivery documents.', 'section'),
      guideStep('Confirm or report', 'Confirm what arrived, or report a missing shipment or product issue when the related action is available.', 'page-actions'),
    ]),
  },
  {
    path: '/portal/customer/deliveries',
    guide: pageGuide('Customer deliveries', 'Track planned and completed shipments linked to your customer orders.', [
      guideStep('Find a delivery', 'Search or filter the list to locate a shipment by reference, order, or status.', 'filters'),
      guideStep('Open delivery details', 'A delivery record shows its items, schedule, and any proof of receipt.', 'records'),
    ]),
  },
  {
    path: '/portal/customer/delivery-schedules',
    guide: pageGuide('Customer delivery schedules', 'Submit monthly delivery requirements and review how Ogami acknowledged each plan.', [
      guideStep('Start a monthly schedule', 'Use New schedule to open the form for a delivery month.', 'page-actions'),
      guideStep('Add monthly requirements', 'Choose a month, add products and quantities, and include notes for each line before submitting.', 'form'),
      guideStep('Review submitted plans', 'The history shows planned products, quantities, notes, and any review or rejection message.', 'records'),
    ]),
  },
  {
    path: '/portal/customer/complaints',
    guide: pageGuide('Customer quality complaints', 'Review quality complaints linked to your deliveries and follow their status.', [
      guideStep('Complaint details', 'The list connects reported issues with the affected product or delivery.', 'records'),
      guideStep('Start a complaint', 'Select New complaint to reveal the report fields. Link an order or delivered item if it helps identify the issue.', 'page-actions'),
      guideStep('Complete the complaint', 'Describe the issue, choose its severity, and enter the affected quantity before submitting.', 'form'),
    ]),
  },
  {
    path: '/portal/customer/returns/new',
    guide: pageGuide('Create a return request', 'Choose an eligible delivered line, explain why it is being returned, then send the request for review.', [
      guideStep('Select a delivered line', 'Choose an invoice, order, or delivery line with remaining quantity, then check how much is available to return.', 'form'),
      guideStep('Describe the return', 'Add the reason and supporting details so the quality team can route the request.', 'form'),
      guideStep('Send the request', 'Review the item quantities and explanation, then submit the return request.', 'form'),
    ]),
  },
  {
    path: '/portal/customer/returns/:id',
    guide: pageGuide('Return request details', 'Follow a return request through review and see the response from the quality team.', [
      guideStep('Return status', 'The current state shows whether the request is waiting for review or has a decision.', 'page-header'),
      guideStep('Request details', 'Review the return reason and any notes submitted with the request.', 'section', 0),
      guideStep('Returned items', 'This table shows the source item and quantity requested for return.', 'section', 1),
    ]),
  },
  {
    path: '/portal/customer/returns',
    guide: pageGuide('Customer returns', 'Create and track return requests for delivered products.', [
      guideStep('Review return requests', 'The table lists each RMA, its status, reason, and submission date.', 'records'),
      guideStep('Open a request', 'Select an RMA number to review its returned items, reason, and current decision.', 'records'),
      guideStep('Start a return', 'Use the page action to create a request for a delivered order.', 'page-actions'),
    ]),
  },
  {
    path: '/portal/customer/problems/new',
    guide: pageGuide('Create a problem report', 'Send Ogami a clear report about an order, delivery, or product issue.', [
      guideStep('Describe the issue', 'Choose the affected source record, select the relevant lines, and explain what happened.', 'form'),
      guideStep('Add details and send', 'Add affected quantities, evidence, and follow-up details, then submit the report for review.', 'form'),
    ]),
  },
  {
    path: '/portal/customer/problems/:id',
    guide: pageGuide('Problem report details', 'Check the report status and follow the latest response from the Ogami team.', [
      guideStep('Report status', 'The heading identifies this report and shows its current status.', 'page-header'),
      guideStep('Report history', 'Review the issue details, linked order or delivery, and any follow-up messages.', 'section'),
    ]),
  },
  {
    path: '/portal/customer/problems',
    guide: pageGuide('Customer problem reports', 'Track issues you have reported about orders, products, or deliveries.', [
      guideStep('Find a report', 'Search or filter the list to find an issue by reference or status.', 'filters'),
      guideStep('Open a report', 'Open a row to review the original description and the latest response.', 'records'),
      guideStep('Report a problem', 'Use the page action to start a report linked to the affected order or delivery.', 'page-actions'),
    ]),
  },
];

const supplierPageGuides: PortalGuideRoute[] = [
  {
    path: '/portal/supplier',
    guide: pageGuide('Supplier dashboard', 'A quick view of purchasing activity, invoices, and delivery updates for your company.', [
      guideStep('Account overview', 'The summary cards link to open RFQs, purchase orders, deliveries, unpaid invoices, and your outstanding balance.', 'section', 0),
      guideStep('Recent purchasing activity', 'Open a purchase order number for its status and response actions. Use View all to see the full list.', 'section', 1),
      guideStep('Recent invoices', 'The invoice panel links to each recent invoice and its review status.', 'section', 2),
      guideStep('Go to a task', 'Use the supplier navigation to respond to purchase orders and RFQs, update listings, and review billing.', 'navigation'),
    ]),
  },
  {
    path: '/portal/supplier/notifications',
    guide: pageGuide('Supplier notifications', 'Read updates from Ogami and open the related purchase order, RFQ, or invoice.', [
      guideStep('Filter notifications', 'Choose All, Unread, Approvals, Alerts, or System to narrow the notices.', 'filters'),
      guideStep('Open a notification', 'Select a notice to mark it read and open its related portal record when a link is available.', 'records'),
      guideStep('Manage read status', 'Use Mark all read for unread notices or Clear read to remove notices you have already reviewed.', 'page-actions'),
    ]),
  },
  {
    path: '/portal/supplier/agreements',
    guide: pageGuide('Supplier agreements', 'Review agreements issued to your company and check which ones need your response.', [
      guideStep('Agreement status', 'Use the agreement list to see what is active, awaiting signature, or complete.', 'records'),
      guideStep('Review the agreement', 'Open the agreement document from its row. Download a PDF or sign when those actions are available.', 'records'),
    ]),
  },
  {
    path: '/portal/supplier/purchase-orders/:id',
    guide: pageGuide('Purchase order details', 'Review order lines, due dates, and shipment activity, then respond to the order.', [
      guideStep('Order status and response', 'Check the current status and use an available action to accept or respond to the purchase order.', 'page-actions'),
      guideStep('Compare order lines', 'Review each requested item, ordered quantity, received amount, and remaining balance.', 'records', 1),
      guideStep('Shipment updates', 'Review recorded dispatches or add a shipment after dispatch with its quantity, expected arrival, and photo.', 'records', 0),
    ]),
  },
  {
    path: '/portal/supplier/purchase-orders',
    guide: pageGuide('Supplier purchase orders', 'Review orders sent by Ogami and open a record to accept or respond to it.', [
      guideStep('Find a purchase order', 'Search or filter to locate an order by number, buyer, due date, or status.', 'filters'),
      guideStep('Open an order', 'The detail page includes requested items, due dates, and response actions.', 'records'),
    ]),
  },
  {
    path: '/portal/supplier/rfqs/:id/quote',
    guide: pageGuide('Submit an RFQ quotation', 'Enter your prices and delivery terms, attach requested documents, and send the quotation before the RFQ closes.', [
      guideStep('Enter prices and terms', 'Add a price for each requested item, then review tax, freight, and delivery details.', 'form'),
      guideStep('Attach documents', 'Add the quotation files requested by Ogami so the buyer can compare your offer.', 'form'),
      guideStep('Send your quotation', 'Review the complete response and submit it before the closing time shown on the RFQ.', 'form'),
    ]),
  },
  {
    path: '/portal/supplier/rfqs/:id',
    guide: pageGuide('Supplier RFQ details', 'Review the buyer’s requirements and manage your response to the request for quotation.', [
      guideStep('RFQ requirements', 'Check the invitation, due date, requested quantities, and supporting documents.', 'section'),
      guideStep('Prepare your response', 'Use the quotation action in the invitation panel to enter prices and delivery terms before the RFQ closes.', 'section'),
    ]),
  },
  {
    path: '/portal/supplier/rfqs',
    guide: pageGuide('Supplier requests for quotation', 'Review invitations and respond to RFQs that are still open.', [
      guideStep('Find an RFQ', 'Search or filter by buyer, closing date, or response status.', 'filters'),
      guideStep('Open an invitation', 'The detail page explains the requirements and links to your quotation.', 'records'),
    ]),
  },
  {
    path: '/portal/supplier/item-listings',
    guide: pageGuide('Supplier item listings', 'Keep product information and images accurate for buyers reviewing your catalog.', [
      guideStep('Review listing status', 'The table shows which item offers are active, awaiting review, or need changes.', 'records'),
      guideStep('Open the listing actions', 'New listing opens one item offer. Bulk submit lets you create several offers using shared price and lead-time terms.', 'page-actions'),
      guideStep('Complete a listing', 'Choose an Ogami catalog item, enter your item details and price, then submit the offer for review.', 'form'),
    ]),
  },
  {
    path: '/portal/supplier/invoices/:id',
    guide: pageGuide('Supplier invoice details', 'Review the invoice, its matched purchase order items, and payment status.', [
      guideStep('Invoice summary', 'Check the invoice number, amount, due date, and review status.', 'page-header'),
      guideStep('Items and payment activity', 'Compare the billed items with matched receipts and review payments recorded for this invoice.', 'section'),
      guideStep('Download the invoice', 'Use the download action to save a copy of the invoice PDF.', 'page-actions'),
    ]),
  },
  {
    path: '/portal/supplier/invoices',
    guide: pageGuide('Supplier invoices', 'Track invoices submitted to Ogami and follow their review status.', [
      guideStep('Find an invoice', 'Search or filter invoices by reference, date, or status.', 'filters'),
      guideStep('Review invoice status', 'Open a row to see invoice details, matched receipts, and payments.', 'records'),
    ]),
  },
  {
    path: '/portal/supplier/statement-of-account',
    guide: pageGuide('Supplier account statement', 'Review open supplier invoices and the outstanding amounts recorded for your account.', [
      guideStep('Outstanding balance', 'The summary shows total outstanding and aging amounts as of the date in the page heading.', 'section'),
      guideStep('Open invoices', 'Each row lists an open bill with its purchase order, due date, remaining balance, status, and aging bucket.', 'records'),
    ]),
  },
  {
    path: '/portal/supplier/delivery-schedules',
    guide: pageGuide('Supplier delivery schedules', 'Review a read-only history of monthly delivery plans shared with Ogami.', [
      guideStep('Schedule history', 'The table shows submitted plans, their products and quantities, and any review notes.', 'records'),
      guideStep('Report actual dispatch', 'This history cannot be edited. Record an actual dispatch from the related purchase order.', 'navigation'),
    ]),
  },
  {
    path: '/portal/supplier/deliveries',
    guide: pageGuide('Supplier deliveries', 'Share shipment updates and follow delivery records for Ogami purchase orders.', [
      guideStep('Find a delivery', 'Search or filter shipment records by order, reference, or delivery status.', 'filters'),
      guideStep('Review delivery records', 'The table shows shipment and receipt details. Expand a row to compare received, accepted, or rejected quantities and remarks.', 'records'),
      guideStep('Report a dispatch', 'Use the related purchase order to enter actual dispatch details and attach shipping documents.', 'navigation'),
    ]),
  },
  {
    path: '/portal/supplier/problems/:id',
    guide: pageGuide('Supplier problem report details', 'Review an issue raised about a purchase order or delivery and follow its resolution.', [
      guideStep('Problem status', 'The status shows whether the issue needs a response or is being resolved.', 'page-header'),
      guideStep('Issue details and response', 'Review the linked order or delivery, report description, and latest follow-up.', 'section'),
    ]),
  },
  {
    path: '/portal/supplier/problems',
    guide: pageGuide('Supplier problem reports', 'Follow purchasing or delivery issues that need your company’s response.', [
      guideStep('Find a report', 'Search or filter reports by reference or status.', 'filters'),
      guideStep('Open an issue', 'Open a row to review the reported problem and its current status.', 'records'),
    ]),
  },
];

function matchesPortalPath(pattern: string, pathname: string): boolean {
  const patternParts = pattern.split('/').filter(Boolean);
  const pathParts = pathname.split('/').filter(Boolean);
  return patternParts.length === pathParts.length
    && patternParts.every((part, index) => part.startsWith(':') || part === pathParts[index]);
}

export function portalPageGuideForPath(
  realm: 'customer' | 'supplier',
  pathname: string,
): PortalPageGuide | undefined {
  const normalizedPath = pathname.replace(/\/+$/, '') || '/';
  const routes = realm === 'customer' ? customerPageGuides : supplierPageGuides;
  return routes.find(({ path }) => matchesPortalPath(path, normalizedPath))?.guide;
}
