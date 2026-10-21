<x-mail::message>
# Your Payslip is Ready

Hi {{ $employee->first_name ?? 'there' }},

Your payslip for the period
**{{ optional($period->period_start)->format('M d, Y') }} – {{ optional($period->period_end)->format('M d, Y') }}**
is ready in the secure Self-Service portal. Sign in with your employee account to view it.

<x-mail::button :url="$payslipUrl">
View Payslips
</x-mail::button>
</x-mail::message>
