# Recorder workflow audit and polish

The recorder's current task is now separate from exceptional historical reporting. Current output appears before the collapsed earlier-production form. Recovery of an unresolved request takes priority over entering another batch.

## Findings and changes

| Gap | Result |
|---|---|
| Temporary attendance, schedule, or QC restrictions made a recorder appear to be a view-only worker. | Assignment lists identify the recorder independently of readiness. Restrictions remain visible. |
| Work history shared the assigned-work page number and had no controls of its own. | Independent history pagination and bounded scroll areas. |
| Network failures lost the frozen request after refresh or navigation. | Normal output, startup samples, and earlier reports store the original request and submission key in account-scoped tab storage before sending. Recovery never automatically resubmits. Storage failure prevents sending an unprotected request. |
| A missing receipt returned 404 and appeared to be a failed check. | Receipt lookup treats only 404 as missing. Authorization and server errors remain errors. A missing receipt does not unlock counts or create a new request. |
| Late-report validation errors could discard an already uncertain request. | The original request remains frozen until resolved. |
| Long forms and history dominated the daily recorder task. | Clear remaining/recorded/rejected counts, collapsed crew/equipment and optional remarks, current output first, bounded recent receipts, earlier reporting disclosed separately. |
| Reload could hide an unresolved late report inside its collapsed section. | Pending reports and recoverable drafts open the section. Other recording forms remain blocked while a late proposal is unresolved. |
| Receipts selected the oldest 20 rows, with no deterministic tie order. | Latest 20 receipts sorted by recorded time and ID descending. |
| History embedded every owned late report. | Total count plus latest 20 embedded rows. The separately paginated report endpoint retains older records. |
| Duplicate defect selections and oversized integer entry caused avoidable server rejections. | Frontend prevents duplicate selections and validates safe whole-piece counts; existing transactional backend validation remains authoritative. Remarks match the server's 500-character limit. |
| Closed work orders implied a historical proposal could post ordinary output. | Worker copy explicitly states that a closed-order report documents a correction and cannot post output through normal approval. |

## Verification

- Isolated backend history/late-report suite: 17 tests, 150 assertions; PHP formatting passed.
- Frontend recorder, API, trial, draft, navigation, crew and start-dialog checks passed. Final recorder scope: 30 tests; the broader preservation run passed 71 tests; final recovery/correction cases passed separately.
- Type checking, scoped lint and final production build passed.
- Role-based Playwright exercises the recorder, support worker and former recorder against a disposable database. It verifies whole-row navigation, draft restore, reload recovery with unchanged request key, no automatic repost, missing receipt behavior, retained view-only restrictions, late-report access, and desktop/mobile overflow.
- Browser submissions used intercepted network failures; no physical production was invented or posted. Evidence: [recorder browser results](evidence/recorder-polish-20261008/results.json).

## Remaining limits

Tab storage survives reload, but closing the tab or clearing browser storage removes its pending request. Reconcile saved receipts and management records before entering the same physical pieces again. Drafts are suggestions only; current role, attendance, schedule, QC and warehouse checks still apply.

The latest-output list intentionally shows 20 receipts. Older earlier-production reports are independently paginated. History may include a current crew because it also provides that crew's historical reporting context.

The full repository suite, prolonged load testing, and hardware scanner testing were not run. No new automatic quality release, attendance bypass, offline submission queue, or schedule relaxation was added.
