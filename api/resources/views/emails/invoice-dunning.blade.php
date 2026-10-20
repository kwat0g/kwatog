<x-mail::message>
# Payment reminder: {{ $snapshot['invoice_number'] }}

Hello {{ $snapshot['customer_name'] }},

Invoice **{{ $snapshot['invoice_number'] }}** was due on **{{ $snapshot['due_date'] }}** and is {{ $daysOverdue }} days overdue. Please review its payment status and balance in the customer portal.

| Invoice detail | Value |
|:--|--:|
| Status | Overdue |
| Invoice date | {{ $snapshot['invoice_date'] }} |
| Due date | {{ $snapshot['due_date'] }} |
| Days overdue | {{ $daysOverdue }} |
| Balance due when reminder was generated | {{ $snapshot['balance'] }} |

The balance above reflects the invoice when this reminder was generated. A recent payment may not yet appear. Please check the portal before making another payment.

<x-mail::button :url="$snapshot['portal_url']">Review invoice</x-mail::button>

If the payment status still needs attention, contact the company using the details below and include the invoice number.

Regards,<br>
Accounts Receivable

You received this reminder because the invoice was overdue when the dunning scan ran.
</x-mail::message>
