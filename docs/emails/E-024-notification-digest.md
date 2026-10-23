# E-024 — unread notification digest

## Source and behavior

- Scheduler invokes `NotificationDigestService::run()` once per configured digest run.
- Current schedule is daily at 07:05 in the application timezone (`Asia/Manila` by default).
- Recipients need an enabled global `digest` preference (`*`, `all`, or `digest`), an active user account, and a syntactically valid email address.
- One queued message per recipient. `QueuedMailable` selects the `mail` queue, dispatches after commit, retries with backoff, and records the delivery attempt.
- The service counts unread Laravel notification rows for the user. It does not change their read state.

## Privacy and content

The previous message copied each notification title and message into email. That could expose payroll, HR, financial, or operational details in a forwarded or synced mailbox. The digest now contains only the exact unread count, a secure link to the authenticated `/notifications` SPA page, and the reason the recipient received it. It never reads notification payloads. The number may repeat on later digest runs until items are read.

Subject: `[OGAMI] Notification summary: {count} unread`

The SPA route is protected by `AuthGuard`; it restores the requested path after sign-in. The CTA is navigation only and cannot change application state.

## Presentation

Uses the shared Laravel Markdown mail shell, current company logo variants, company footer, and generated plain-text alternative. Preheader and HTML title are set. No attachment. No notification title, message, raw type, internal ID, or timestamp is included.

Screenshot footer company details are replaced with labels because the test database contains demo settings; production email content still reads the shared company settings.

## Verification

See `PROGRESS.md` for focused tests and visual review. No real email is sent during tests. Real-client rendering remains unverified.
