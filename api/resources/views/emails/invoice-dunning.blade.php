<x-mail::message>
# Payment Reminder

Hi {{ $customer->contact_person ?? $customer->name ?? 'there' }},

Our records show that invoice **{{ $invoice->invoice_number }}** is currently
**{{ $daysOverdue }} days past due**.

| Field        | Value                                                |
|--------------|------------------------------------------------------|
| Invoice No.  | {{ $invoice->invoice_number }}                       |
| Issue Date   | {{ optional($invoice->date)->format('M d, Y') }}     |
| Due Date     | {{ optional($invoice->due_date)->format('M d, Y') }} |
| Balance Due  | ₱{{ number_format((float) $invoice->balance, 2) }}   |

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
{{ config('app.name') }} Accounts Receivable
</x-mail::message>
