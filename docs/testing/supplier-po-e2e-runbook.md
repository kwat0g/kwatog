# Purchase request to supplier payment walkthrough

Use separate role accounts. Do not use the system administrator account for the thesis demonstration. Use test records and a supplier portal account linked to the PO vendor.

1. **Department head:** Create a purchase request with an item, quantity, and needed-by date. Choose Direct PO. Submit it.
2. **Finance officer:** Approve the PR. Purchasing converts it to a PO, assigns the approved supplier, and checks the inherited required delivery date. Finance approves the PO. Approval sends it to that supplier's portal automatically.
3. **Supplier:** Open **Purchase Orders**, accept the PO, dispatch goods, and create a shipment with quantity, arrival estimate, and required dispatch photo. A partial dispatch needs a reason; later shipments may cover the remaining ordered quantity.
4. **Warehouse:** Open the expected GRN, match the supplier shipment, enter only the quantity physically received, select the storage bin, and finalize receiving. Receipt quantity is authoritative. A shortage or defect follows the warehouse/quality discrepancy process; it does not become accepted stock automatically.
5. **Quality inspector and checker:** Complete the incoming full-count inspection. Record defective pieces. The independent checker reviews the result. Warehouse acceptance moves only good pieces into available inventory and stages one **Draft** AP bill for that accepted receipt portion.
6. **Supplier:** Open the PO and select **Submit invoice**. Select the accepted receipt portions covered by one invoice. One available portion is selected automatically; multiple portions can be selected together or invoiced separately. Enter the supplier's invoice number, date, and gross total before withholding tax. The total must equal the selected AP draft totals. An optional original invoice file can be attached. Submit once.
7. **Finance officer:** Open **Finance → Accounts Payable Bills**. The supplier reference appears on every linked receipt-level bill. Review each three-way match and post each Draft. A bill cannot be posted or paid before supplier invoice submission. Record payment on each posted bill; another Finance approver and Vice President complete the existing payment approval chain. Check the supplier portal invoice: its balance is the sum of linked bill balances.

For a 20,000-piece PO delivered as 12,000 and 8,000 pieces, Warehouse creates two GRNs and Quality accepts each. Two AP drafts are staged, while the supplier may submit **one** invoice covering both. OGAMI keeps two AP matches and two bill-level payments. One payment leaves the grouped invoice **Partially paid**; the second leaves it **Paid**. The supplier may instead invoice 12,000 pieces now and 8,000 pieces later under two distinct invoice numbers. No full-PO delivery wait is required.

For the panel: “The GRN proves physical receipt, QC establishes accepted quantity, and the supplier invoice is the supplier's payment claim. The system stages AP drafts after acceptance but blocks posting and payment until the claim arrives and its gross total matches the selected receipts. Grouping one supplier invoice does not merge receipt-level controls or bank transfers.”

If the supplier corrects an invoice before any linked bill is posted or paid, Finance can void it with a reason and the drafts become available for a replacement submission. If a posted linked bill is cancelled, its invoice requires correction and further group payment is blocked. The supplier cannot silently edit a submitted invoice.

## Pre-cutover audit

Run this **read-only** query before enabling the posting/payment gate in production. Finance must review paid PO/GRN stock bills without a supplier invoice link; do not relabel or reverse them automatically.

```sql
SELECT b.id, b.bill_number, b.vendor_id, b.purchase_order_id,
       b.goods_receipt_note_id, b.total_amount, b.amount_paid, b.status
FROM bills AS b
WHERE b.provenance_type = 'stock'
  AND b.purchase_order_id IS NOT NULL
  AND b.goods_receipt_note_id IS NOT NULL
  AND b.status = 'paid'
  AND b.supplier_invoice_id IS NULL
ORDER BY b.id;
```

Payments remain per AP bill. One bank transfer allocated across several bills is outside this workflow.
