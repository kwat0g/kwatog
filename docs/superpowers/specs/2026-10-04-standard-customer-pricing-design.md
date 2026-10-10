# Standard customer pricing

Approved direction: standard products use a base selling price without requiring an agreement for each customer. Applicable customer agreements override the base price. Private products remain available only to assigned customers. Cost remains separate from selling price.

Add nullable base selling and minimum selling prices to products, plus standard/restricted portal visibility and explicit customer assignments. Existing products default to restricted; customers already linked through agreements receive assignments during additive migration. Do not infer or publish base prices from cost or negotiated prices. Missing base prices remain visible as setup requirements internally.

Reuse PriceAgreementService as the single quote owner: verify eligibility, select the agreement applicable to requested delivery date and quantity, fall back to an explicit base price, enforce a configured floor, and return price source. Reject newly configured zero prices and prices below a configured floor. The floor is explicitly supplied by the business; standard cost alone does not prove profit. A manager approval subsystem is outside this slice; unsafe prices are blocked instead.

Customer order forms fetch server quotes for date and quantity, display the source and exact VAT totals, and send a quote signature to detect changes before saving. Existing unchanged commercial lines retain frozen prices. Delivery rescheduling retains accepted prices. SO lines record the selected price source and agreement/tier/date snapshots.

Use native inputs and existing form/table patterns. Product forms provide base/floor fields, standard/private selection and customer assignments. Agreement forms explain optional overrides and show floor context. Order creation/editing stays compact and exposes pricing errors at the item level.

Validation: restricted product isolation, standard/base orders without agreements, applicable agreement overrides, expired agreement fallback, no fallback from an unsafe applicable agreement, floor enforcement, exact volume/date preview totals, changed quote rejection, historical price preservation, migration preservation, and role-based headless browser flows.
