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
