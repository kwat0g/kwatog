# Required size dimensions and duplicate check prevention

Size/Measurement specifications now require a nonblank unit, target, minimum and maximum. Validation applies in the editor and API; direct checklist publication uses the same shared validation. Written references no longer bypass these fields. Visual, document and gauge-based functional checks remain available without numeric dimensions. Existing saved specifications and inspection history were not rewritten.

The editor hides Add check when all five standard checks are present. Removing a standard check restores the button. Already selected standard options are disabled in other rows; existing normalized name validation continues to reject duplicates, including custom names, on submission.

Verification: 46 backend tests with 380 assertions and 35 frontend tests passed. Real Playwright headless against the isolated audit API confirmed four-field validation, API 422 rejection, duplicate option disabling, button visibility after removal/readdition and successful publication of a valid five-check specification. Desktop 1440 px and mobile 390 px had no document overflow. TypeScript, targeted ESLint, PHP formatting, the production build and the Impeccable detector passed. The build retains its existing NotFound import warning.

Evidence: [artifacts](evidence/inspection-spec-required-dimensions-20261006/).
