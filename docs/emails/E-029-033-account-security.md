# E-029–E-033 account-security email update

Status: local implementation verified; PR CI and shared plain-text branding fix remain pending. HR/legal/data-protection review is required before production. No email was sent.

## Trigger and recipient audit

| ID | Trigger and path | Recipient rule | Data and delivery |
|---|---|---|---|
| E-029 | Self-service forgot-password endpoint → `PasswordResetService::sendResetLink()` | The active `User` matching the normalized account email. Unknown and inactive accounts receive no message; endpoint response stays generic. | `PasswordResetService::createResetLink()` stores only a SHA-256 token hash, configured expiry, and request IP; sends `PasswordResetLinkNotification` to that user. |
| E-030 | No active constructor call found for `PasswordResetNotification`; retained for already queued payload compatibility. | Original notifiable user from the queued job. | Old temporary-password payload is ignored. If its reset URL is absent, rendering creates a fresh, hashed, expiring one-time link. |
| E-031 | Admin user creation with `send_welcome` enabled → `UserAdminController::store()` | The newly created `User`'s account email. | Creates a one-time setup link after account creation; `WelcomeNotification` is queued. Existing API response still returns the one-time temporary password to the authorized administrator. |
| E-032 | Admin or HR account password reset → `UserProvisioningService::resetPasswordForUser()` | The affected account's `User` email. | After the password/session transaction commits, queues `AdminPasswordResetNotification` with the generated temporary password. User must change it at sign-in. The queue payload is encrypted. |
| E-033 | HR employee-account provisioning → `UserProvisioningService::provisionForEmployee()` | The created account's resolved email (employee email or existing configured generation rule). | After account commit, creates a one-time setup link and queues `EmployeeWelcomeNotification`. |

## Message behavior

- Account setup and self-service reset messages use `emails.account-access-link`; admin reset uses Laravel's escaped Markdown mail shell to show the temporary password. There are no attachments or user-supplied URLs.
- Subjects use configured `email.brand_name`. Copy is English. No amounts, dates, enums, or record snapshots appear.
- CTA and fallback URL point to the SPA `/reset-password` route. The route exists at `spa/src/routes/authRoutes.tsx:38`; password reset remains an authenticated token-confirmation flow and does not mutate state on GET.
- Link lifetime comes from `security.password_reset_expiry_minutes`. Tokens are random, single use, stored by hash, and invalidated on redemption or replacement. Email delivery logs do not store message bodies.
- Queue policy stays on the `mail` lane with after-commit dispatch and bounded retries. Legacy queued Auth welcome/reset notifications keep link-only behavior; new admin resets use encrypted queue payloads for the requested temporary-password email.
- Exhausted queue retries for Auth reset, admin reset, and Admin welcome create an in-app delivery-failure notice for active `admin.users.manage` users. Legacy HR notification subclasses retain their existing `hr.employees.view` fallback. Failure notices exclude credentials and raw provider exceptions.
- The Admin > Users API continues returning the generated temporary password to its authorized caller. Admin reset email also contains it; the account is flagged to require a password change at sign-in.
- Admin and HR account-provisioning toggles say “Send account setup email,” matching the one-time setup-link message.

## Verification and open checks

- Source audit found no active caller of generic `PasswordResetNotification`; HR uses the subclass. Historical queued jobs remain a compatibility reason to retain safe fallback behavior.
- Automated tests cover one-time token hashing/replacement, expiry, serialization, admin/HR provisioning/reset links, legacy payload password removal, HTML/text MIME parts, From/Reply-To/recipient redirection, and non-production sink behavior. Focused run passed: 32 tests, 216 assertions, isolated `ogami_test_email_e029_033` database, `MAIL_MAILER=array`; the updated MIME/sink class also passed separately (3 tests, 15 assertions). No real email was sent.
- Rendered all four active message variants and inspected 16 Chromium screenshots at desktop/mobile and light/dark. HTML sizes are 18–19 KB. Real Outlook, Gmail, Apple Mail, and mobile client rendering remain unverified.
- Text-part render revealed EF-060: Laravel's default text layout appends API app name/URL and an unapproved “All rights reserved” claim. A separate shared-foundation PR must fix this before these messages count as complete. HTML and text outputs are captured from the array transport; identity values in HTML previews are redacted.
- Account-security messages contain personal account data. HR/legal/data-protection review remains required; no compliance claim is made.
