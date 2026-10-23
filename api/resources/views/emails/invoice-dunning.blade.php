<x-mail::message :preheader="$preheader" :title="'Payment reminder for invoice '.$snapshot['invoice_number']">
# Payment Reminder

Hi {{ $snapshot['customer_name'] }},

Our records show that invoice **{{ $snapshot['invoice_number'] }}** is currently
**{{ $daysOverdue }} days past due**.

| Field        | Value |
|--------------|------:|
| Invoice No.  | {{ $snapshot['invoice_number'] }} |
| Issue Date   | {{ $snapshot['invoice_date'] }} |
| Due Date     | {{ $snapshot['due_date'] }} |
| Balance Due  | {{ $snapshot['balance'] }} |

@if ($tier >= 30)
This invoice is more than 30 days overdue. Please review the balance and contact Accounts Receivable if you need help reconciling payment.
@elseif ($tier >= 15)
This invoice is {{ $daysOverdue }} days overdue. Please review the balance and contact Accounts Receivable if you need help reconciling payment.
@else
A reminder to review the balance shown above.
@endif

If payment has already been made, please disregard this notice and reply
with proof of payment so we can update our records.

Regards,<br>
{{ config('mail.from.name', config('app.name')) }} Accounts Receivable
</x-mail::message>
