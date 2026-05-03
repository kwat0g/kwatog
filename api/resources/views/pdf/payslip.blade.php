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
 *   $generator    User model (who clicked Download)
 *   $generatedAt  Carbon timestamp
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
    body  { font-family: 'Helvetica', sans-serif; font-size: 10px; color: #09090B; }
    .mono { font-family: 'Courier', monospace; }
    h1 { font-size: 12px; font-weight: 600; margin: 0 0 4px; letter-spacing: 0.04em; text-transform: uppercase; color: #09090B; }
    h2 { font-size: 10px; font-weight: 600; margin: 12px 0 4px; letter-spacing: 0.05em; text-transform: uppercase; color: #52525B; }
    .header { border-bottom: 1px solid #D4D4D8; padding-bottom: 8px; margin-bottom: 12px; }
    .header .company { font-weight: 600; font-size: 11px; }
    .header .meta    { color: #71717A; font-size: 9px; margin-top: 2px; }
    .grid { width: 100%; border-collapse: collapse; }
    .grid td { padding: 2px 0; vertical-align: top; }
    .grid .label   { color: #52525B; width: 35%; font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; }
    .grid .value   { font-weight: 500; }

    table.tab { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.tab th, table.tab td { padding: 4px 6px; font-size: 10px; }
    table.tab th { text-align: left; color: #71717A; font-size: 8px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 500; border-bottom: 0.5px solid #D4D4D8; }
    table.tab td { border-bottom: 0.5px solid #F4F4F5; }
    table.tab td.amt { text-align: right; }

    .net { margin-top: 10px; padding: 8px 10px; border: 0.5px solid #09090B; border-radius: 4px; display: table; width: 100%; }
    .net .lbl { display: table-cell; width: 60%; font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em; font-size: 9px; }
    .net .amt { display: table-cell; text-align: right; font-size: 14px; font-weight: 600; font-family: 'Courier', monospace; }

    .footer { position: fixed; bottom: 6mm; left: 12mm; right: 12mm; font-size: 8px; color: #A1A1AA; text-align: center; border-top: 0.5px solid #E4E4E7; padding-top: 4px; }
    .watermark { position: fixed; top: 40%; left: 0; right: 0; text-align: center; transform: rotate(-30deg); font-size: 70px; color: rgba(0,0,0,0.04); font-weight: 700; letter-spacing: 0.1em; z-index: 0; }
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

  </div>
  <div class="footer">
    Generated by {{ $generator?->name ?? 'system' }} on {{ $generatedAt->format('M j, Y · g:i a') }} · Confidential
  </div>
</body>
</html>
