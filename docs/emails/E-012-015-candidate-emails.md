# E-012–E-015: Candidate email lifecycle

| ID | Message | Trigger | Recipient | Snapshot source |
|---|---|---|---|---|
| E-012 | Application received | Public application accepted | Candidate-provided email | Candidate name, posting title, tracking code, and frontend tracking URL captured when queued |
| E-013 | Application status updated | Recruitment stage changes | Candidate-provided email | Caller already passes scalar name, title, old/new stage, code, and application hash ID |
| E-014 | Interview scheduled | HR schedules an interview | Candidate-provided email | Candidate name, posting title, schedule, location, interviewer, tracking code, and frontend tracking URL |
| E-015 | Interview details changed | Candidate-visible schedule/location/interviewer changes | Candidate-provided email | Same fields as E-014, captured at update time |

E-012, E-014, and E-015 queue after database commit through the shared queued mailable base. E-013 already snapshots values in its constructor arguments. No message includes a resume or attachment. All links target the public SPA route `/careers/track`; the tracking code remains a bearer credential that exposes application status and needs HR/legal/data-protection review for handling and retention.

Interview outcome is internal assessment data. Outcome-only changes no longer send a candidate email, and E-015 content excludes outcomes. HR notes and scoring are not included. The existing E-014 copy's arrival instruction and company-wide address are unverified for each interview location; remove them in the template redesign unless HR confirms a real policy and location-specific source.

Recipient remains the application email supplied by the candidate. Invalid/missing address behavior and queue-failure alerts use existing recruitment handling; no role-based internal recipient is added. Confirm candidate-data handling and rejection/offer wording with HR and the company's legal/data-protection contact before external use.
