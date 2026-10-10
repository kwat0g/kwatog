# Quality — Specs, Inspections, NCRs & Certificates

**Demo login:** `qc@ogami.test` (QC Inspector) · password: `password`

Log in at `/sign-in`, click **Sign in**.

## 1. The big picture (30 seconds)

Every product has an **inspection spec** (dimensions + tolerances). Every lot
gets **inspected** against it (incoming resin / in-process / outgoing parts).
Every finalized failure becomes an **NCR**. A checked outgoing pass with complete
measurement evidence can generate a **Certificate of Conformance**.

## 2. Inspection specs + revisions

1. Open `/quality/inspection-specs` (**Inspection Specifications** in sidebar).
2. Click **New spec** → page **New inspection spec**: pick the product, add
   check items (dimensions with tolerances), click **Create spec**.
3. Reopen a spec. Editing and saving creates a new version — the submit
   button reads **Save new version** when a spec exists.
4. The **Revision history** section lists every version; use the
   **Revision to inspect** dropdown to view an older revision.

## 3. Incoming inspection (resin arrives)

1. Open `/quality/inspections` (**Quality Inspections** in the sidebar).
2. Open the inspection created from the receipt. Use **New inspection** only
   when a manual inspection is needed; choose the stage, product and batch quantity.
3. Each specification parameter appears once in the **Inspection checklist**,
   with its own **PASS** and **FAIL** buttons. Repeated sample measurements do not
   create repeated checklist questions.
4. Mark each check **PASS** or **FAIL**. A failed check requires its problem
   description. After verifying the other checks, use **PASS all remaining**
   to answer them together; existing failures stay failed. **Save draft** keeps
   partial progress. **Submit inspection** requires every check answered and
   each failed problem described. Any failed check makes the inspection fail;
   reported problems carry into its NCR.
5. Receipt inspections await an independent checker before inventory is released.
   **Details** shows record information and links. There are no measurement,
   sample-count or per-piece entry forms. **Cancel** still requires a reason.

## 4. In-process inspection (on the shop floor)

1. Repeat section 3 with the in-process stage.
2. In-process results finalize directly. A failure creates an NCR. The same
   PASS / FAIL workflow also applies to supplier-return and customer-return inspections.
   A reported decision does not invent measurement readings or defective-piece counts.

## 5. Outgoing inspection + Certificate of Conformance

1. Repeat section 3 with the outgoing stage. Batch quantity comes from the selected
   output batch. Use the same checklist buttons; no numeric entry is required.
2. If the result needs a second pair of eyes, a checker sees **Approve pass**,
   **Fail inspection**, **Pass as override**, or **Confirm failure**
   (maker-checker: the checker cannot be the recorder).
3. **Certificate of Conformance** remains available for eligible historical
   inspections with complete saved evidence. Checklist answers do not fabricate
   measured evidence; the existing certificate checks still apply.

## 6. NCR lifecycle + dispositions

1. Open `/quality/ncrs` (**Nonconformance Reports (NCRs)** in the sidebar).
2. Click **New NCR** → page **Open NCR** ("Inspection failures auto-create
   NCRs" — manual NCRs are for things inspections didn't catch). Submit with
   **Open NCR**.
3. Open the NCR (`/quality/ncrs/:id`). Set the **Disposition** — one of
   `scrap`, `rework`, `use_as_is`, `return_to_supplier` — then click
   **Save disposition** (toast: "Disposition saved").
4. Click **Close NCR** and confirm in the **Close NCR?** dialog.
   Closing can auto-create replacement/rework work
   orders — see them in **Linked records**.

## 7. What this login can and cannot do

- `qc@ogami.test` holds the whole quality module: specs, inspections,
  NCRs, calibration register, plus quarantine (MRB) holds.
- It can **view** customer complaints but the 8D finale belongs to Customer
  Service (see CRM guide).

## 8. If something looks wrong

- **Close NCR** blocked? A disposition must be saved first.
- Complaint won't resolve? Its linked NCR must be closed with a disposition.
