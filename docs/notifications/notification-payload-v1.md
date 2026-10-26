# Notification payload schema, version 1

New rows written through `NotificationService::send()`, `sendInApp()`, and
`sendPortalInApp()` retain the existing `title`, `message`, `link_to`, and
caller-specific fields. They add these fields to the existing JSON `data`
object:

| Field | Meaning |
| --- | --- |
| `schema_version` | Integer `1` for this payload contract. |
| `event_type` | Same event key stored in the notification row's `type` column. |
| `subject_type` | Optional machine-readable subject type. Uses explicit `subject_type`, or falls back to legacy `entity_type`. |
| `subject_id` | Optional string subject identifier. Uses explicit `subject_id`, or falls back to legacy `entity_id`. |
| `actor_id` | Optional caller-supplied string user identifier. It is not inferred from the current session because dispatch may run in a job or under a portal guard. Callers must use the application's public identifier, not a database key. |

The top-level notification row continues to own its recipient, dedupe key, read
state, and timestamps. Existing rows have no `schema_version`; clients keep
rendering their legacy `title`, `message`, and `link_to` fields. Direct Laravel
mail/account notifications also remain on their current payload format.

Version 1 does not invent route keys, severity, category, resolution state,
actor display names, or a message template key. Those require the approved
registries and recipient/event migration. `subject_type` and `subject_id` are
metadata only and must not be displayed as user-facing text.
