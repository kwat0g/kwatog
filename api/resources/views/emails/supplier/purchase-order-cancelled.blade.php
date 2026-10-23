<x-mail::message :preheader="$preheader" title="Purchase order cancelled">
# Purchase order cancelled

Hello {{ $purchaseOrder['supplier_name'] }},

{{ $brand['name'] }} cancelled the purchase order below. Do not ship or invoice further items against this order.

| Detail | Information |
|---|---|
| Purchase order | {{ $purchaseOrder['po_number'] }} |
| Cancellation date | {{ $purchaseOrder['cancelled_at'] }} |
@if ($purchaseOrder['purchase_order_date'])
| Original order date | {{ $purchaseOrder['purchase_order_date'] }} |
@endif
| Original total amount | {{ $purchaseOrder['display_total'] }} |

{{-- The cancel reason lives in the PO's internal remarks, which are never shown to suppliers. --}}
If a shipment has already departed, contact your usual purchasing representative to coordinate next steps.

<x-mail::button :url="$portalUrl">View purchase order</x-mail::button>

Regards,<br>
{{ $brand['name'] }} Purchasing Team

You received this email because this purchase order had previously been sent to your supplier account. To verify the request, open the supplier portal using the address you normally use.
</x-mail::message>
