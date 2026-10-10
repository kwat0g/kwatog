# Production attendance connection fixes

Approved scope: separate clock-in evidence from shift eligibility; preserve overnight and re-entry punch sessions; batch worker-selection reads; notify staffing changes and guard new operations; protect committed schedules and attendance history from HR edits; explain missing calendars and overtime coverage.

Implementation order: regression tests, shared attendance/worker guards, HR protections and staffing notifications, existing Start/crew UI recovery, focused backend/frontend checks and browser review.

Preservation rules: payroll retains daily first-in/last-out; archived/manual/absent records remain authoritative; safety, leave, resource conflicts, deadlines and permissions cannot be overridden by presence confirmation; earlier output remains reportable with the existing manager reason. Overtime hours do not define a production shift interval. No automatic machine stop or silent crew replacement.

Verification uses the isolated test database. No live attendance or scheduling records will be changed for test setup.
