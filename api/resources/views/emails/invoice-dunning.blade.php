<x-mail::message :preheader="'Payment reminder for invoice '.$invoice->invoice_number.'. Balance due: ₱'.number_format((float) $invoice->balance, 2).'.'" :title="'Payment reminder: invoice '.$invoice->invoice_number">
# Payment reminder

Hi {{ $customer->contact_person ?? $customer->name ?? 'there' }},

Our records show that invoice **{{ $invoice->invoice_number }}** is **{{ $daysOverdue }} days past due**. Please review the balance and due date below.

| Invoice summary | Details |
|---|---|
| Invoice number | **{{ $invoice->invoice_number }}** |
| Invoice date | {{ optional($invoice->date)->format('M d, Y') }} |
| Due date | **{{ optional($invoice->due_date)->format('M d, Y') }}** |
| Days overdue | **{{ $daysOverdue }}** |
| Balance due | **₱{{ number_format((float) $invoice->balance, 2) }}** |

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
{{ config('mail.from.name', config('app.name')) }} Accounts Receivable
</x-mail::message>
