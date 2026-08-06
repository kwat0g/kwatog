@php
/**
 * Payslip — A5 portrait. Two payslips fit on a duplex A4 sheet.
 *
 * Inputs (Blade):
 *   $payroll      Payroll model (with employee.department, employee.position, period, deductionDetails)
 *   $employee     Employee model
 *   $period       PayrollPeriod model
 *   $companyName  string
 *   $companyAddress string
 *   $companyTin   string
 *   $generated    array from PdfRenderService: ['by' => string, 'by_user' => ?User, 'at' => CarbonImmutable, 'at_text' => string]
 *   $details      Collection<PayrollDeductionDetail>
 */
$money = fn ($v) => '₱ ' . number_format((float) $v, 2);
$periodLabel = $period->period_start?->format('M j') . ' – ' . $period->period_end?->format('M j, Y');
@endphp
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Payslip · {{ $employee->employee_no }} · {{ $period->period_start?->format('Y-m-d') }}</title>
  <style>
    @page { margin: 14mm 12mm; }
    body  { font-family: 'Helvetica Neue', 'Helvetica', sans-serif; font-size: 10px; color: #334155; }
    .mono { font-family: 'Courier New', Courier, monospace; }
    h1 { font-size: 14px; font-weight: 800; margin: 0 0 6px; letter-spacing: 0.05em; text-transform: uppercase; color: #0f172a; }
    h2 { font-size: 11px; font-weight: 700; margin: 14px 0 6px; letter-spacing: 0.05em; text-transform: uppercase; color: #64748b; border-bottom: 1px solid #e2e8f0; padding-bottom: 3px; }
    .header { border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; margin-bottom: 14px; }
    .header .company { font-weight: 800; font-size: 13px; color: #0f172a; text-transform: uppercase; }
    .header .meta    { color: #64748b; font-size: 9.5px; margin-top: 3px; }
    .grid { width: 100%; border-collapse: collapse; }
    .grid td { padding: 3px 0; vertical-align: top; }
    .grid .label   { color: #94a3b8; width: 35%; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; }
    .grid .value   { font-weight: 600; color: #0f172a; }

    table.tab { width: 100%; border-collapse: collapse; margin-top: 6px; }
    table.tab th, table.tab td { padding: 5px 8px; font-size: 10px; }
    table.tab th { text-align: left; color: #ffffff; background: #0f172a; font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; border: none; }
    table.tab td { border-bottom: 1px solid #e2e8f0; color: #334155; }
    table.tab td.amt { text-align: right; font-weight: 600; }

    .net { margin-top: 14px; padding: 10px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; display: table; width: 100%; }
    .net .lbl { display: table-cell; width: 60%; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; font-size: 10px; color: #0f172a; }
    .net .amt { display: table-cell; text-align: right; font-size: 15px; font-weight: 800; font-family: 'Courier New', monospace; color: #0f172a; }

    .footer { position: fixed; bottom: 6mm; left: 12mm; right: 12mm; font-size: 8px; color: #94a3b8; text-align: center; border-top: 1px solid #e2e8f0; padding-top: 6px; }
    .watermark { position: fixed; top: 40%; left: 0; right: 0; text-align: center; transform: rotate(-30deg); font-size: 70px; color: rgba(15, 23, 42, 0.03); font-weight: 800; letter-spacing: 0.1em; z-index: 0; }
    .container { position: relative; z-index: 1; }
  </style>
</head>
<body>
  <div class="watermark">CONFIDENTIAL</div>
  <div class="container">

    <div class="header">
      <div class="company">{{ $companyName }}</div>
      <div class="meta">{{ $companyAddress }} · TIN {{ $companyTin }}</div>
    </div>

    <h1>Payslip</h1>
    <table class="grid">
      <tr><td class="label">Employee</td><td class="value">{{ $employee->full_name }}</td></tr>
      <tr><td class="label">Employee No.</td><td class="value mono">{{ $employee->employee_no }}</td></tr>
      <tr><td class="label">Department</td><td class="value">{{ $employee->department?->name ?? '—' }}</td></tr>
      <tr><td class="label">Position</td><td class="value">{{ $employee->position?->title ?? '—' }}</td></tr>
      <tr><td class="label">Pay Type</td><td class="value">{{ ucfirst($payroll->pay_type) }}</td></tr>
      <tr><td class="label">Period</td><td class="value mono">{{ $periodLabel }}</td></tr>
      <tr><td class="label">Payroll Date</td><td class="value mono">{{ $period->payroll_date?->format('M j, Y') }}</td></tr>
    </table>

    <h2>Earnings</h2>
    <table class="tab">
      <thead>
        <tr><th>Item</th><th class="amt" style="text-align:right">Amount</th></tr>
      </thead>
      <tbody>
        <tr><td>Basic Pay</td><td class="amt mono">{{ $money($payroll->basic_pay) }}</td></tr>
        @if ((float) $payroll->leave_pay > 0)
          <tr><td>Leave Pay</td><td class="amt mono">{{ $money($payroll->leave_pay) }}</td></tr>
        @endif
        @if ((float) $payroll->overtime_pay > 0)
          <tr><td>Overtime Pay</td><td class="amt mono">{{ $money($payroll->overtime_pay) }}</td></tr>
        @endif
        @if ((float) $payroll->night_diff_pay > 0)
          <tr><td>Night Differential</td><td class="amt mono">{{ $money($payroll->night_diff_pay) }}</td></tr>
        @endif
        @if ((float) $payroll->holiday_pay > 0)
          <tr><td>Holiday Premium</td><td class="amt mono">{{ $money($payroll->holiday_pay) }}</td></tr>
        @endif
        <tr><td><strong>Gross Pay</strong></td><td class="amt mono"><strong>{{ $money($payroll->gross_pay) }}</strong></td></tr>
      </tbody>
    </table>

    <h2>Deductions</h2>
    <table class="tab">
      <thead>
        <tr><th>Item</th><th class="amt" style="text-align:right">Amount</th></tr>
      </thead>
      <tbody>
        @forelse ($details as $d)
          <tr>
            <td>{{ $d->description ?? $d->deduction_type?->label() }}</td>
            <td class="amt mono">{{ $money($d->amount) }}</td>
          </tr>
        @empty
          <tr><td colspan="2" style="color:#A1A1AA">No deductions for this period.</td></tr>
        @endforelse
        <tr><td><strong>Total Deductions</strong></td><td class="amt mono"><strong>{{ $money($payroll->total_deductions) }}</strong></td></tr>
      </tbody>
    </table>

    @if (abs((float) $payroll->adjustment_amount) > 0.001)
      <h2>Adjustments</h2>
      <table class="tab">
        <tr>
          <td>Period adjustment ({{ (float) $payroll->adjustment_amount > 0 ? 'refund' : 'recovery' }})</td>
          <td class="amt mono">{{ $money($payroll->adjustment_amount) }}</td>
        </tr>
      </table>
    @endif

    <div class="net">
      <div class="lbl">Net Pay</div>
      <div class="amt">{{ $money($payroll->net_pay) }}</div>
    </div>

    <table style="width:100%; margin-top:32px; border-collapse:collapse; font-size:9pt;">
      <tr>
        <td style="width:50%; vertical-align:bottom; padding:0 8px;">
          <div style="height:32px; border-bottom:1px solid #444;">&nbsp;</div>
          <div style="margin-top:4px; text-align:center; font-weight:500;">{{ $preparedBy ?? '—' }}</div>
          <div style="text-align:center; color:#777; font-size:8pt;">Prepared by · HR</div>
        </td>
        <td style="width:50%; vertical-align:bottom; padding:0 8px;">
          <div style="height:32px; border-bottom:1px solid #444;">&nbsp;</div>
          <div style="margin-top:4px; text-align:center; font-weight:500;">{{ $verifiedBy ?? '—' }}</div>
          <div style="text-align:center; color:#777; font-size:8pt;">Verified by · Finance</div>
        </td>
      </tr>
    </table>

    @if ($period->disbursement_status === 'disbursed')
    {{-- ADV1 — Salary Disbursement Certification Footer --}}
    <table style="width:100%; margin-top:24px; border-collapse:collapse; border-top:0.5px solid #D4D4D8;">
      <tr>
        <td colspan="2" style="padding-top:8px; font-size:9px; letter-spacing:0.04em; text-transform:uppercase; color:#52525B; font-weight:600;">
          Disbursement Certification
        </td>
      </tr>
      <tr>
        <td style="padding-top:4px; font-size:8px; color:#71717A;">
          I certify that the above payroll has been processed and disbursed.
        </td>
      </tr>
    </table>
    <table style="width:100%; margin-top:16px; border-collapse:collapse; font-size:8pt;">
      <tr>
        <td style="width:50%; vertical-align:bottom; padding:0 8px;">
          <div style="height:28px; border-bottom:0.5px solid #A1A1AA;">&nbsp;</div>
          <div style="margin-top:4px; text-align:center; font-weight:500; color:#52525B;">Prepared by</div>
          <div style="text-align:center; color:#A1A1AA; font-size:7pt;">Date: ___________</div>
        </td>
        <td style="width:50%; vertical-align:bottom; padding:0 8px;">
          <div style="height:28px; border-bottom:0.5px solid #A1A1AA;">&nbsp;</div>
          <div style="margin-top:4px; text-align:center; font-weight:500; color:#52525B;">Finance Officer</div>
          <div style="text-align:center; color:#A1A1AA; font-size:7pt;">Date: ___________</div>
        </td>
      </tr>
    </table>
    @endif

  </div>
  <div class="footer">
    Generated by {{ $generated['by'] ?? 'system' }} on {{ ($generated['at'] ?? now())->format('M j, Y · g:i a') }} · Confidential
  </div>
</body>
</html>
