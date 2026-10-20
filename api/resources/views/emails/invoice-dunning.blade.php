<x-mail::message>
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
This invoice is **30+ days overdue**. Please remit payment immediately to
avoid further escalation.
@elseif ($tier >= 15)
This invoice is **{{ $daysOverdue }} days overdue**. Kindly settle the
balance at your earliest convenience.
@else
A friendly reminder to settle this balance soon.
@endif

If payment has already been made, please disregard this notice and reply
with proof of payment so we can update our records.

Thanks,
{{ config('mail.from.name', 'Ogami Philippines') }} Accounts Receivable
</x-mail::message>
