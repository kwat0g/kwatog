<x-mail::message>
# Purchase Order Cancelled

Hello {{ $purchaseOrder->vendor?->contact_person ?: $purchaseOrder->vendor?->name ?: 'Supplier' }},

The following purchase order has been cancelled by Ogami Philippines. No further deliveries or invoices should be made against this purchase order.

| Detail | Information |
|---|---|
| Purchase order | {{ $purchaseOrder->po_number }} |
| Cancellation date | {{ now()->format('M d, Y') }} |
| Original order date | {{ optional($purchaseOrder->date)->format('M d, Y') }} |
| Original total amount | ₱{{ number_format((float) $purchaseOrder->total_amount, 2) }} |

{{-- The cancel reason lives in the PO's internal remarks, which are never shown to suppliers. --}}
If you have already shipped any items for this order, please contact our Purchasing department immediately to arrange return or credit handling.

<x-mail::button :url="$portalUrl">View Purchase Order Details</x-mail::button>

Regards,<br>
{{ config('mail.from.name', 'Ogami Philippines') }} Purchasing Team
</x-mail::message>
