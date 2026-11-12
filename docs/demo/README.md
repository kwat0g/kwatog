# Thesis demo customer agreement

The PDFs in this folder use fictional parties, products, addresses, prices, and
terms. They are demonstration specimens, not approved legal forms and not for
signature.

- `sample-customer-supply-agreement.pdf` is the populated visual example. It can
  be attached to a demo customer record as a staff-only draft. Its `BB-001` and
  `BU-001` product schedules match the live demo catalog so product access and
  per-product warranty choices can be prefilled during customer creation. The
  product schedule also shows illustrative minimum and maximum quantities per
  order line.
- `customer-supply-agreement-template.pdf` is the reusable AcroForm template.
  Add it under Portal access → Contract templates with realm **Customer**, then
  map its discovered fields to the matching customer record fields. Use the
  template-generation action on a customer record to demonstrate auto-fill.

The template deliberately uses `[MAP THIS FIELD]` values for mapped data. Map
the fields shown in the contract template screen; leave optional fields unmapped
only when the agreement does not need them. The product access and warranty
summary fields come from the customer’s configured catalog and product-specific
warranty choices.

Rebuild both PDFs with Python and ReportLab:

```sh
python -m pip install -r docs/demo/requirements.txt
python docs/demo/generate_customer_agreement.py
```

Before using this layout for a real transaction, replace the fictional supplier,
review every commercial clause with qualified counsel, approve the exact
product schedules, and confirm the authorized signatories.
