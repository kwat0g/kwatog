<x-mail::message :preheader="$preheader" :title="$title">
# Sales order confirmed

Hello {{ $order['customer_name'] }},

Your sales order **{{ $order['so_number'] }}** has been confirmed and is being processed.

<x-mail::panel>
Confirmed — the details below reflect the order when it was confirmed.
</x-mail::panel>

| Order detail | Information |
|---|---:|
| Order number | {{ $order['so_number'] }} |
| Order date | {{ $order['date'] ?? '—' }} |
@if ($order['subtotal'] !== null)
| Subtotal | {{ $order['subtotal'] }} |
@endif
@if ($order['vat_amount'] !== null)
| VAT | {{ $order['vat_amount'] }} |
@endif
| Order total | {{ $order['total_amount'] }} |
| Payment terms | {{ $order['payment_terms_days'] !== null ? $order['payment_terms_days'].' days' : '—' }} |
| Delivery terms | {{ $order['delivery_terms'] ?: '—' }} |

## Order items

| Item | Quantity | Unit price | Line total |
|---|---:|---:|---:|
@forelse ($items as $item)
| {{ $item['name'] }}@if ($item['part_number'])<br><small>{{ $item['part_number'] }}</small>@endif | {{ $item['quantity'] }} | {{ $item['unit_price'] }} | {{ $item['total'] }} |
@empty
| Item details are available in the customer portal. | — | — | — |
@endforelse

@if ($remainingItemCount > 0)
And {{ $remainingItemCount }} more {{ \Illuminate\Support\Str::plural('item', $remainingItemCount) }} are listed in the customer portal.
@endif

<x-mail::button :url="$order['portal_url']">View your sales order</x-mail::button>

Regards,<br>
{{ $brand['name'] }} Customer Support

You received this email because your customer account is associated with this sales order. To verify the request, open the customer portal using the address you normally use.
</x-mail::message>
