# Quality — Specs, Inspections, NCRs & Certificates

**Demo login:** `qc@ogami.test` (QC Inspector) · password: `password`

Log in at `/sign-in`, click **Sign in**.

## 1. The big picture (30 seconds)

Every product has an **inspection spec** (dimensions + tolerances). Every lot
gets **inspected** against it (incoming resin / in-process / outgoing parts).
Every failure becomes an **NCR**. Every passing outgoing lot earns a
**Certificate of Conformance**. This is the thesis differentiator.

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
2. Click **New inspection** → page **Open inspection**: choose the Stage
   (incoming), the lot, and the sample plan; click **Open inspection**.
3. On the detail page (`/quality/inspections/:id`), record each check
   (pass/fail + actual measurements for dimensional checks).
4. Lot-checklist mode offers **Pass all**, then **Submit result**
   (a **Submit with failed checks?** dialog with **Submit anyway** appears if
   anything failed).
5. Finish with **Complete** (dialog **Complete inspection?**) or **Cancel**
   (**Cancel inspection?**).

## 4. In-process inspection (on the shop floor)

1. Repeat section 3 with the in-process stage.
2. Failed checks here also feed defect counts and can auto-create NCRs —
   show the **Linked records** panel on the inspection detail page.

## 5. Outgoing inspection + Certificate of Conformance

1. Repeat section 3 with the outgoing stage (AQL 0.65 Level II sampling —
   the **Sample plan** panel shows batch, sample size, and Ac/Re numbers).
2. If the result needs a second pair of eyes, a checker sees **Approve pass**,
   **Fail inspection**, **Pass as override**, or **Confirm failure**
   (maker-checker: the checker cannot be the recorder).
3. Once the outgoing inspection is **passed**, a **Certificate of Conformance**
   button appears on the detail page — click it to generate the CoC.

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
