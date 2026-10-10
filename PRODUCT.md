# OGAMI ERP

OGAMI includes a customer portal for placing sales orders and reviewing linked order records.

Customer purchasing staff select products available under their account pricing, enter a quantity for each product, choose one requested delivery date for the entire order, review subtotal, VAT and total, and submit the order for sales review. Product identity includes name and part number. The total must state whether VAT is included.

Order entry must remain manageable when an order contains many products. Keep quantity editing accessible, validate required dates and positive quantities, retain entered items after a failed submission, and reuse the request key when retrying the same payload.

The established Atelier visual system is documented in docs/DESIGN-SYSTEM.md and implemented by shared UI components and spa/src/styles/tokens.css. This surface rebuild preserves that system.
