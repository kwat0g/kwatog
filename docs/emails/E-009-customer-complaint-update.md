# E-009 — Customer complaint update

## Trigger and recipient

`CustomerComplaintUpdated` is dispatched after complaint creation, lifecycle changes, and 8D report finalization. The existing queued listener emails the customer contact address; absent/invalid addresses or send failures notify users with `crm.complaints.manage`. The SPA target is the authenticated customer complaints list.

## Data policy

Snapshot the recipient address/name, complaint number, current status label, received date, product reference, and frontend portal URL when the event is created. Do not put internal severity, full description, affected quantity, 8D analysis, or internal remarks in email. Show only reference/status and link the customer to the authenticated portal for detail.

Old queued events with no snapshot are handled by generating a current-value snapshot for compatibility. Privacy review by the company's data protection contact remains required.

## Verification status

Focused tests verify allowed fields, excluded complaint details, event-time recipient/status after source-record edits, title, preheader, frontend portal route, plain-text version, escaping, and HTML size. Three tests passed, 19 assertions; mailer forced to `array`. Browser render was about 21 KB, 600px desktop and 390px mobile with no overflow; logo switches in light/dark. Screenshots: `screenshots/e009-customer-complaint-{desktop,mobile}-{light,dark}.png`. Real email-client rendering remains unverified; no email was sent.
