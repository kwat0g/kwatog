# Supplier RFQ — Simplified Process

> Status: simplified 2026-09-25. Replaces the original "sealed bidding" plan,
> whose extra machinery (quote versions, split awards, quote-stage QC review,
> addenda, reconfirmation) produced three defects and a stranded-remainder dead
> end. Scope owner: Purchasing / Chain 2 (Procure to Pay).

## 1. The process in one paragraph

An approved purchase request that needs competitive pricing becomes an RFQ. The
buyer invites suppliers and sets a deadline. Suppliers quote in the portal (or
the buyer types in a phone/email quote). Prices stay hidden until the RFQ
closes — automatically at the deadline, or early once every invited supplier
has submitted. The buyer compares delivered cost, lead time, validity and
supplier quality history, picks **one winner per line**, and the system creates
one draft PO per winning supplier. Anything not awarded goes back to the PR for
a Direct PO or a new RFQ. POs then follow the normal Finance → VP approval,
GRN, Incoming QC, Bill and Payment chain.

## 2. When an RFQ can start

| PR situation | Start RFQ allowed |
|---|---|
| Auto PR (MRP / reorder / sales order) with sourcing method **Competitive RFQ**, approved | yes |
| Any approved PR whose Direct PO conversion fell to **manual_required** (no preferred supplier or price) | yes — sourcing method switches to RFQ |
| PR after an RFQ was **awarded with a remainder** or **cancelled** | yes (new RFQ) — or Direct PO for what is left |
| A PR with an active RFQ (draft / open / closed) | no — one active RFQ per PR |

Internal (manual) PRs are still created with sourcing method Direct PO.

## 3. States

```
RFQ:        draft ─publish→ open ─deadline or "close now"→ closed ─award→ awarded
            draft/open/closed ─cancel→ cancelled
            open ─deadline with zero submitted quotes→ cancelled (auto, reason recorded)
Quote:      draft ⇄ submitted (withdraw returns it to draft) → awarded | not_awarded
Invitation: invited → viewed → submitted → awarded | not_awarded
```

Only **submitted** quotes are ever evaluated. Drafts are never shown internally.

## 4. Rules

1. One quote per supplier per RFQ, edited in place until the deadline.
2. Submitting requires a quotation PDF on file for that supplier and at least one quoted line.
3. Offered quantity ≤ requested quantity; a supplier may quote part of a line or mark it "no quote".
4. VAT treatment is declared per quote: `exclusive`, `inclusive` or `none` (non-VAT-registered).
   One freight amount per quote. VAT and totals are derived server-side:
   - base = Σ(offered qty × unit price) + freight
   - exclusive: VAT = base × rate; total = base + VAT
   - inclusive: VAT = base × rate ÷ (1 + rate); total = base
   - none: VAT = 0; total = base
5. "Close now" is allowed only while open and after **every** invited supplier has submitted.
6. Award: one winning quote line per RFQ line; awarded quantity = the supplier's offered quantity.
   Expired quotes (`quote_valid_until` < today) cannot be awarded. One award reason for the decision.
7. PO commercial carry-over (per winning supplier): freight is shared by awarded goods value;
   inclusive quotes are split into net prices + VAT; the PO totals exactly what the comparison showed.
8. Anything not awarded stays on the PR (status `approved`, conversion `partial`/`not_started`)
   with a note; the buyer converts it by Direct PO or starts a new RFQ.
9. Ranking: when Ogami is VAT-registered the comparison ranks suppliers on cost **excluding VAT**
   (input VAT is recoverable), so a non-VAT supplier never wins merely because its price carries no VAT.
   Totals are still shown as quoted.
10. Manual entry is for phone/email quotes only; it can never replace a quote the supplier entered in the portal.
11. Sealed prices never reach the audit log (quote and quote-line money fields are recorded as `[sealed]`).
12. An RFQ past its deadline is closed on the next read as well as by the scheduler, so a stopped
    scheduler never leaves it "open".
13. The PR requester may open the RFQ from the PR or a notice (status only; prices stay sealed for them).
14. PR lines must be linked to an inventory item before an RFQ starts (an award becomes a PO line).
15. RFQ numbers and titles are in global search, scoped by `RequestForQuoteAccessPolicy::visibleTo()`
    (the same row scope as the RFQ list).
16. Quality: supplier quality history (quality pass rate, NCR rate, on-time rate) is shown on the
   comparison and suppliers upload CoA / datasheet with the quote. Incoming QC after delivery is
   the acceptance gate. There is no quote-stage QC approval.

## 5. Permissions

| Slug | Holders | Grants |
|---|---|---|
| `purchasing.rfq.view` | purchasing officer, finance officer, vice president, QC inspector | list/detail; after close: comparison, quote documents |
| `purchasing.rfq.manage` | purchasing officer | create, edit, publish, extend, close now, cancel, manual quote, upload, award |

`system_admin` holds everything by wildcard but is refused award (IT role, not a business approver).

## 6. Internal API (`/api/v1/purchasing`)

All money/quantities are decimal **strings**; all ids are HashIDs.

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/rfqs?status=&search=&page=&per_page=` | – | paginated `Rfq` (list fields) |
| GET | `/purchase-requests/{pr}/rfq-setup` | – | `{ data: { lines: RfqSetupLine[], suppliers: RfqSupplierOption[] } }` |
| POST | `/purchase-requests/{pr}/rfqs` | `RfqWrite` + `publish?: boolean` | 201 `Rfq` |
| GET | `/rfqs/{rfq}` | – | `Rfq` |
| PUT | `/rfqs/{rfq}` (draft only) | partial `RfqWrite` (invitations replace the set; date/spec overrides keyed by **RFQ line id**) | `Rfq` |
| POST | `/rfqs/{rfq}/publish` | – | `Rfq` |
| POST | `/rfqs/{rfq}/extend` | `{ closes_at, reason }` | `Rfq` |
| POST | `/rfqs/{rfq}/close` | – | `Rfq` |
| POST | `/rfqs/{rfq}/cancel` | `{ reason }` | `Rfq` |
| POST | `/rfqs/{rfq}/documents` | multipart `file`, `document_type`, `vendor_id?` | 201 `RfqDocument` |
| POST | `/rfqs/{rfq}/quotes/manual` | `QuoteWrite` + `vendor_id` | 201 `SupplierQuote` |
| GET | `/rfqs/{rfq}/comparison` | – | `Rfq` with `quotes` (closed/awarded only) |
| POST | `/rfqs/{rfq}/award` | `{ award_reason, lines: [{ request_for_quote_item_id, supplier_quote_item_id }] }` | `{ data: Rfq, purchase_orders: PurchaseOrder[] }` |
| GET | `/rfqs/{rfq}/purchase-orders` | – | `PurchaseOrder[]` |
| GET | `/rfq-documents/{document}/download` | – | file |

Internal `document_type`: `requirement_document` (shared with all invitees, no `vendor_id`),
`quotation_pdf` / `certificate_of_analysis` / `resin_datasheet` (require `vendor_id`, for manual quotes).
Manual capture uses the latest `quotation_pdf` uploaded for that vendor, so the UI uploads first,
then posts the quote — one button.

```ts
type RfqWrite = {
  title: string; instructions?: string | null; closes_at: string /* ISO */;
  invitations: { vendor_id: string; exception_reason?: string }[];
  required_delivery_dates?: Record<string /* PR item id */, string /* YYYY-MM-DD */>;
  specifications?: Record<string /* PR item id */, string>;
};

type RfqSetupLine = { id: string; description: string; item_code: string | null; unit: string | null;
  remaining_quantity: string /* not yet on a PO */; has_item: boolean };

type RfqSupplierOption = {
  id: string; name: string; qualified: boolean; lead_time_days: number | null;
  reach: 'portal' | 'email' | 'none'; email: string | null;
};

type QuoteWrite = {
  vat_treatment: 'exclusive' | 'inclusive' | 'none';
  freight_amount?: string; quote_valid_until?: string | null; payment_terms?: string | null; notes?: string | null;
  items: { request_for_quote_item_id: string; response_status: 'quoted' | 'no_quote';
           offered_quantity?: string; unit_price?: string; lead_time_days?: number | null;
           proposed_delivery_date?: string | null }[];
};

type Rfq = {
  id: string; rfq_number: string; status: 'draft'|'open'|'closed'|'awarded'|'cancelled'; status_label: string;
  title: string; instructions: string | null;
  issued_at: string | null; closes_at: string; closed_at: string | null; resolved_at: string | null;
  cancellation_reason: string | null; last_extension_reason: string | null;
  purchase_request: { id: string; pr_number: string } | null;
  creator: { id: string; name: string } | null;
  invited_count: number; responded_count: number;
  items: RfqItem[]; invitations: RfqInvitation[];
  quotes: SupplierQuote[];          // [] while draft/open (sealed)
  awards: RfqAward[]; documents: RfqDocument[];
  actions: { can_edit: boolean; can_publish: boolean; can_extend: boolean; can_close_now: boolean;
             can_cancel: boolean; can_capture_quote: boolean; can_upload_document: boolean;
             can_compare: boolean; can_award: boolean };
};
type RfqItem = { id: string; description: string; specification: string | null; quantity: string; unit: string | null;
  required_delivery_date: string | null; awarded_quantity: string; remaining_quantity: string;
  item: { id: string; code: string; name: string; unit_of_measure: string | null } | null };
type RfqInvitation = { id: string; status: 'invited'|'viewed'|'submitted'|'awarded'|'not_awarded';
  invited_at: string; viewed_at: string | null; exception_reason: string | null;
  vendor: { id: string; name: string } | null; reach: 'portal'|'email'|'none';
  portal_notified_at: string | null; email_notified_at: string | null; last_notification_error: string | null };
type SupplierQuote = { id: string; status: 'draft'|'submitted'|'awarded'|'not_awarded'; submitted_at: string | null;
  vat_treatment: 'exclusive'|'inclusive'|'none'; goods_amount: string; freight_amount: string; vat_amount: string;
  total_delivered_cost: string; quote_valid_until: string | null; is_expired: boolean; payment_terms: string | null;
  notes: string | null; quotation_original_filename: string | null; captured_manually: boolean;
  vendor: { id: string; name: string } | null; items: SupplierQuoteItem[];
  documents: { id: string; document_type: string; original_filename: string }[];
  supplier_performance?: { overall_score: string|null; tier: string|null; on_time_delivery_rate: string|null;
    quality_pass_rate: string|null; ncr_rate: string|null; period: string } | null };
type SupplierQuoteItem = { id: string; request_for_quote_item_id: string; response_status: 'quoted'|'no_quote';
  offered_quantity: string | null; unit_price: string | null; line_total: string;
  allocated_delivered_cost: string | null;   // comparison only: line + its share of freight and VAT
  unit_delivered_cost: string | null;        // comparison only: allocated ÷ offered quantity
  lead_time_days: number | null; proposed_delivery_date: string | null;
  meets_required_date: boolean | null;       // comparison only
  is_recommended: boolean };
type RfqAward = { id: string; awarded_quantity: string; awarded_unit_price: string; awarded_total_delivered_cost: string;
  award_reason: string; awarded_at: string; vendor: { id: string; name: string } | null;
  rfq_item: { id: string; description: string } | null; purchase_order: { id: string; po_number: string } | null };
type RfqDocument = { id: string; document_type: string; original_filename: string; mime_type: string; size_bytes: number;
  vendor: { id: string; name: string } | null };
```

## 7. Supplier portal API (`/api/v1/b2b/supplier`)

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/rfqs?status=&search=&sort=closes_at|rfq_number&direction=` | – | paginated `SupplierRfq` |
| GET | `/rfqs/{rfq}` | – | `{ data: SupplierRfq }` (marks invitation viewed) |
| PUT | `/rfqs/{rfq}/quote` | `QuoteWrite` + `submit: boolean` | `{ data: SupplierQuote }` |
| POST | `/rfqs/{rfq}/quote/withdraw` | – | `{ data: SupplierQuote }` (back to draft) |
| POST | `/rfqs/{rfq}/documents` | multipart `file`, `document_type` | 201 `{ data: { id, document_type, original_filename } }` |
| GET | `/rfqs/{rfq}/documents/{document}/download` | – | file |

`PUT …/quote` with `submit: false` saves a draft (only while the quote is a draft); with
`submit: true` it saves and submits atomically (also used to update an already-submitted quote).
Supplier `document_type`: `quotation_pdf`, `certificate_of_analysis`, `resin_datasheet`, `safety_document`.
Upload documents first, then `PUT … submit: true`.

```ts
type SupplierRfq = {
  id: string; rfq_number: string; title: string; instructions: string | null;
  status: Rfq['status']; status_label: string; closes_at: string; closed_at: string | null;
  invitation_status: RfqInvitation['status']; can_quote: boolean;
  outcome: 'awarded' | 'not_awarded' | 'cancelled' | null;
  items: RfqItem[];                       // awarded_/remaining_quantity omitted
  documents: { id: string; document_type: string; original_filename: string }[];  // shared requirement docs
  quote: SupplierQuote | null;            // this supplier's own quote (no supplier_performance)
  my_documents: { id: string; document_type: string; original_filename: string }[];
  awards: { id: string; rfq_item: { id: string; description: string } | null; awarded_quantity: string; awarded_unit_price: string }[];
};
```

## 8. Notifications

| Event | Internal (in-app) | Supplier (email) |
|---|---|---|
| published | – | invitation, deadline, portal link |
| extended | – | new deadline + reason |
| closed | RFQ creator: "ready to compare and award" | – |
| awarded | RFQ creator + PR requester | winners: awarded lines; others: not selected |
| cancelled | RFQ creator + PR requester | cancellation notice |

Competitor prices and rankings are never disclosed to suppliers.

## 9. Removed from the original plan

Quote versions / supersede / versions endpoint, split awards across suppliers and partial award
quantities, per-line QC compliance review, addenda, quote reconfirmation, RFQ-level budget warning
(the PO budget gate covers it), `under_evaluation` / `partially_awarded` / `no_award` statuses, five
of seven permissions, the four-step wizard, line-level VAT/freight/other charges, header "other
charges", single-response justification field.
