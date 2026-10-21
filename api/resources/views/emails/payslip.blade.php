<x-mail::message :preheader="'Your payslip for '.optional($period->period_start)->format('M d, Y').' – '.optional($period->period_end)->format('M d, Y').' is ready.'" title="Payslip ready">
# Your payslip is ready

Hi {{ $employee->first_name ?? 'there' }},

Your payslip for the period below is ready to view in the secure Self-Service portal.

<x-mail::panel>
**Pay period**<br>
{{ optional($period->period_start)->format('M d, Y') }} – {{ optional($period->period_end)->format('M d, Y') }}

**Status**<br>
Available in Self-Service
</x-mail::panel>

Sign in with your employee account to view your payslip. This email does not contain salary details.

<x-mail::button :url="$payslipUrl">Open secure payslip portal</x-mail::button>

You received this email because payroll was finalized for your employee account.
</x-mail::message>
