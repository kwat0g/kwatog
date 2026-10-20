<x-mail::message>
# Invoice {{ $snapshot['invoice_number'] }} is ready

Hello {{ $snapshot['customer_name'] }},

Your invoice has been issued. Review the invoice and payment details in the customer portal.

| Invoice detail | Value |
|:--|--:|
| Status | Issued |
| Invoice date | {{ $snapshot['invoice_date'] }} |
| Due date | {{ $snapshot['due_date'] }} |
| Invoice total | {{ $snapshot['total_amount'] }} |
| Balance due at issue | {{ $snapshot['balance'] }} |
| Sales order | {{ $snapshot['sales_order_number'] }} |

The values above reflect the invoice when it was issued. A payment recorded afterward may not appear in this message.

## Invoice items

@forelse ($snapshot['items'] as $item)
- **{{ $item['description'] }}** — {{ $item['quantity'] }} {{ $item['unit'] }} × {{ $item['unit_price'] }} = {{ $item['total'] }}
@empty
No line items were available when this message was prepared. Review the invoice in the customer portal.
@endforelse

@if ($snapshot['additional_item_count'] > 0)

{{ $snapshot['additional_item_count'] }} additional item(s) are listed in the portal invoice.
@endif

<x-mail::button :url="$snapshot['portal_url']">View invoice and download PDF</x-mail::button>

For questions or to report a payment, contact Accounts Receivable using the company contact details below. Include the invoice number so we can locate the record.

Regards,<br>
Accounts Receivable

You received this email because an invoice was issued to your customer account.
</x-mail::message>
