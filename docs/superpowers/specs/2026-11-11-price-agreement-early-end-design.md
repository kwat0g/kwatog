# Mutual price-agreement termination

## Decision

Use the existing customer agreement PDF and portal e-signature workflow for both early termination of an active agreement and cancellation of an agreement that has not started. A CRM manager prepares and issues a termination PDF. An active agreement's price and dates remain unchanged until an authorized customer portal signatory signs. A scheduled agreement is cancelled only after its authorized signatory signs. The original customer agreement remains available as a signed document.

The manager must provide a reason. For an active agreement, the manager also selects the final date on which its price remains valid. The system checks the next date for an alternative customer agreement or a valid base price. If no price covers that date, the manager must acknowledge that new orders may be blocked before sending the document.

The customer receives an in-app notice and an email when signature is requested. After signature or CRM cancellation, active customer portal users receive an in-app notice and an email. A manager can cancel a pending request; cancellation leaves the agreement terms unchanged.

The CRM list shows pending signature, early termination, request cancellation, and cancellation-before-start separately and includes the recorded reason. Staff can open the latest 50 audit events for an agreement in a compact history dialog. Existing sales orders keep their captured price. New orders continue to use the pricing rules for their requested delivery date.

## Existing-schema implementation

No migration or database reset is needed. Termination documents use `customer_agreement_documents`. The document storage path includes the customer and agreement hash IDs. `audit_logs` records the request, cancellation, signed outcome, reason, date, coverage result, and portal actor. The portal signature record stores the authorized portal user, typed name, timestamp, and document hash.

A scheduled cancellation is represented by an effective end date one day before the scheduled start. The audit event supplies the clear `cancelled_before_start` status; the CRM hides the inverted dates in the list. The document and audit history remain available.

## Authorization and concurrency

- Only `crm.price_agreements.manage` can prepare or cancel a termination request.
- Only an active customer portal user marked as an authorized signatory can sign it.
- The document hash and pending request audit event are checked before applying the signed change. Price coverage is checked again at signing; if its availability changed since the PDF was issued, the signature transaction is rejected and CRM must issue an updated document.
- A stale or cancelled request cannot change price-agreement dates. A signature and its date update share a database transaction, so either both persist or neither does.
- Document locking follows the portal signature lock order to avoid cancellation/signature deadlocks.
- Pending or customer-signed termination records cannot be edited as ordinary price agreements. A future price change must use a new dated agreement.

## Risks and limits

- The signature is captured through the existing typed-name portal workflow and linked to the exact PDF hash. It is an auditable application record; it is not a qualified digital-signature service.
- Email is queued after commit. Existing email-delivery logging and internal failure notices report delivery failures, but they cannot guarantee that a recipient reads the message.
- Coverage is checked for the first date after the chosen last-valid date (or the scheduled start), then checked again when the customer signs. Even with a successful second check, a later price or inventory change can still affect order availability; each order quote rechecks the live price policy.
- The history dialog loads only when requested and caps the response at 50 events. It indicates when older events exist; older entries remain in the immutable audit log.
- A request can become stale while waiting for signature. In that case signing is rejected, and CRM must cancel it and issue an updated request.
- No finance approval is added to termination. The CRM manager permission gates the request; the customer signatory supplies assent.

## Verification scope

Verify CRM permission, required date/reason and coverage acknowledgement, unchanged agreement terms while pending, authorized customer signing, scheduled cancellation, pending-request cancellation, audit attribution, portal visibility, notification email queueing, and price-coverage status. Run only focused tests; do not run tests against the shared live development database or execute schema changes.
