<x-mail::message :preheader="$preheader" :title="$title">
# Your payslip is ready

Hello,

Your payslip for **{{ $periodLabel }}** is ready in the {{ $companyName }} employee portal.

For your privacy, the payslip PDF is not attached to this email. Sign in to the employee account that owns the record before downloading it.

<x-mail::button :url="$payslipUrl">Open your payslip</x-mail::button>

If the button does not work, use the link above and sign in to the employee portal.

Regards,<br>
{{ $companyName }} Payroll Team

You received this automated message because payroll finalized a payslip for your employee account. This email does not contain payroll amounts.
</x-mail::message>
