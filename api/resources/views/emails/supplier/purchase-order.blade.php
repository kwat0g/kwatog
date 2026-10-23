<x-mail::message :preheader="$preheader" :title="$title">
# {{ $title }}

Hello {{ $purchaseOrderEmail['vendor_name'] }},

Purchase order **{{ $purchaseOrderEmail['po_number'] }}** has been approved and is ready for your review. Please acknowledge it and review shipment instructions in the supplier portal before arranging delivery.

| Purchase order detail | Information |
|---|---:|
| Purchase order | {{ $purchaseOrderEmail['po_number'] }} |
@if ($purchaseOrderEmail['date'])
| Order date | {{ $purchaseOrderEmail['date'] }} |
@endif
| Expected delivery | {{ $purchaseOrderEmail['expected_delivery_date'] ?? 'To be confirmed' }} |
| Order total | {{ $purchaseOrderEmail['total_amount'] }} |
| Payment terms | {{ $purchaseOrderEmail['payment_terms_days'] !== null ? $purchaseOrderEmail['payment_terms_days'].' days' : 'See supplier portal' }} |

## Ordered items

@forelse ($purchaseOrderEmail['items'] as $item)
**{{ $item['description'] }}**

{{ $item['quantity'] }} {{ $item['unit'] }} × {{ $item['unit_price'] }} · **Line total: {{ $item['total'] }}**

@empty
Item details are available in the supplier portal.
@endforelse

@if ($purchaseOrderEmail['remainingItemCount'] > 0)
{{ $purchaseOrderEmail['remainingItemCount'] }} more {{ $purchaseOrderEmail['remainingItemCount'] === 1 ? 'item is' : 'items are' }} available in the supplier portal.
@endif

<x-mail::button :url="$purchaseOrderEmail['portal_url']">Review purchase order</x-mail::button>

Regards,<br>
{{ $brand['name'] }} Purchasing Team

You received this message because your supplier account is associated with this purchase order. To verify it, open the supplier portal using the address you normally use. This email is a summary; the approved purchase order in the portal contains the current record.
</x-mail::message>
