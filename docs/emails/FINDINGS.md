# Email audit findings

Date: 2026-10-20. Static findings only; reachability and runtime impact remain unverified. Fixes belong in separate functional/security changes, not the email-template work.

| ID | Finding | Evidence / impact | Follow-up |
|---|---|---|---|
| EF-001 | Local development mail initially could reach external SMTP | `.env` and `api/.env` used SMTP with AWS Mail Manager credentials. Both now use `MAIL_MAILER=log`, credentials are blank, and Horizon was restarted. The shared-foundation branch adds redirect-or-block enforcement. | Keep local mail on log or a verified sink; verify the deployed guard before any non-production send. |
| EF-002 | Company settings need owner verification | Shared foundation now requires `company.legal_name` and `company.address` instead of using fallback literals, and makes the other optional identity values settings-only. The local settings rows exist; sales inbox and VAT status match the old fallback strings. | Owner verifies settings before external send. Accountant reviews statutory fields. |
| EF-003 | Welcome/reset messages include temporary passwords | Auth and HR notification constructors accept temporary password strings and their mail bodies include them. Exact active call paths need confirmation. | Trace reachability; replace with secure activation/reset flow. Security review. |
| EF-004 | Several send paths are synchronous | Payslip job uses `Mail::send`; customer sales-order decision and supplier PO decision services use `Mail::send`. SMTP failure/latency can hold a job/request and retry may duplicate. | Review caller transaction/retry semantics and queue/after-commit behavior in functional reliability changes. |
| EF-005 | Generic emails vary in sensitivity but share one body path | `NotificationService` can queue `UserNotificationMail` with arbitrary caller title/message/link for opted-in notification types. | Classify runtime type keys and remove sensitive body details or restrict eligibility as a separate privacy/RBAC change. |
| EF-017 | Markdown email preheaders were dropped before reaching the HTML layout | Email views could provide `preheader`, but Laravel's shared `mail::message` component did not pass it into `mail::layout`; hidden preview text was absent from rendered messages. | Fixed in shared foundation PR #213 by forwarding the value; verified with shell and portal-email renders. |
| EF-018 | Shared email CSS blocked dark colors and forced 600px mobile overflow | Important light-mode styles overrode dark-mode media rules after CSS inlining, and the 600px table width exceeded a 390px viewport. | Fixed in shared foundation PR #213; browser previews verify responsive width and dark colors. Real email-client behavior remains unverified. |
| EF-020 | Dark-mode fallback links had low contrast | Browser preview showed rust-colored text links too dim against the dark email background. | Shared foundation PR #213 now uses a brighter dark-mode link color; browser screenshots rechecked. |

| EF-021 | Candidate status preview uses invalid stage fixture | E-013 captures pass `updated` as both stage values, but `updated` is not an `ApplicationStage`; the fallback renders “Application updated” twice and does not verify real stage labels. This is preview-data quality, not evidence of a production trigger defect. | Use valid, distinct stage values before refreshing E-013 screenshots. |

| EF-022 | Supplier PO decision details are awkward on mobile | E-017 390px captures show the two-column Markdown table wrapping labels and values into narrow columns, reducing scanability. No clipping observed. | Replace table with stacked labeled facts in the E-017 template and rerender mobile captures. |

| EF-023 | Published PO preview is long at the 10-item limit (closed; not a defect) | Source review confirmed `SupplierPurchaseOrderMail` shows at most 10 items and reports the remaining count; the fixture contains exactly 10, so no remainder line appears. Long content is bounded and complete for the selected rows. | No change. Keep the existing 10-item cap and remaining-count link. |

No code-level fix was applied as part of Phase 0/1.
