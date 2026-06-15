<x-mail::message>
# Your Payslip is Ready

Hi {{ $employee->first_name ?? 'there' }},

Your payslip for the period
**{{ optional($period->period_start)->format('M d, Y') }} – {{ optional($period->period_end)->format('M d, Y') }}**
is attached to this email as a PDF.

You can also view it any time from the Self-Service portal.

<x-mail::button :url="config('app.url') . '/self-service/payslips'">
View Payslips
</x-mail::button>

Thanks,
{{ config('app.name') }}
</x-mail::message>
