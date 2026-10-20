<x-mail::message>
# Purchase Order Cancelled

Hello {{ $purchaseOrder['supplier_name'] }},

The following purchase order has been cancelled by Ogami Philippines. No further deliveries or invoices should be made against this purchase order.

| Detail | Information |
|---|---|
| Purchase order | {{ $purchaseOrder['po_number'] }} |
| Cancellation date | {{ $purchaseOrder['cancelled_at'] }} |
@if ($purchaseOrder['purchase_order_date'])
| Original order date | {{ $purchaseOrder['purchase_order_date'] }} |
@endif
| Original total amount | {{ $purchaseOrder['display_total'] }} |

{{-- The cancel reason lives in the PO's internal remarks, which are never shown to suppliers. --}}
If you have already shipped any items for this order, please contact our Purchasing department immediately to arrange return or credit handling.

<x-mail::button :url="$portalUrl">View Purchase Order Details</x-mail::button>

Regards,<br>
{{ config('mail.from.name', 'Ogami Philippines') }} Purchasing Team
</x-mail::message>
