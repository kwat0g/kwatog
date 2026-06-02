@extends('pdf._layout')

@section('title', 'BIR Form 2316')

@section('content')

<h1 class="doc-title">Certificate of Compensation Payment / Tax Withheld</h1>
<p style="font-size:9px; color:#555; margin:0 0 12px;">BIR Form No. 2316 — For Compensation Payment With or Without Tax Withheld · Calendar Year {{ $year }}</p>

<div class="meta-grid">
  <div class="col">
    <label>Employee</label>
    <div class="v">{{ $employee_name }}</div>
    <label style="margin-top:6px;">Employee No.</label>
    <div class="v">{{ $employee_no }}</div>
    <label style="margin-top:6px;">TIN</label>
    <div class="v">{{ $tin ?? '—' }}</div>
  </div>
  <div class="col">
    <label>Employer</label>
    <div class="v">{{ $company['name'] ?? 'Philippine Ogami Corporation' }}</div>
    <label style="margin-top:6px;">Employer TIN</label>
    <div class="v">{{ $company['tin'] ?? '—' }}</div>
    <label style="margin-top:6px;">Address</label>
    <div class="v" style="font-weight:normal;">{{ $company['address'] ?? '' }}</div>
  </div>
</div>

<table class="lines" style="margin-bottom:12px;">
  <thead>
    <tr>
      <th>Compensation &amp; Tax Summary</th>
      <th class="r">Amount</th>
    </tr>
  </thead>
  <tbody>
    <tr><td>Gross Compensation Income</td><td class="r">₱ {{ number_format((float) $gross, 2) }}</td></tr>
    <tr><td>Less: Mandatory Contributions (SSS, PhilHealth, Pag-IBIG)</td><td class="r">₱ {{ number_format((float) $mandatory, 2) }}</td></tr>
    <tr><td><strong>Taxable Compensation Income</strong></td><td class="r"><strong>₱ {{ number_format((float) $taxable, 2) }}</strong></td></tr>
    <tr><td>Tax Withheld</td><td class="r">₱ {{ number_format((float) $tax_withheld, 2) }}</td></tr>
  </tbody>
</table>

<h2 style="font-size:10px; text-transform:uppercase; letter-spacing:0.05em; color:#555; margin:16px 0 4px;">Breakdown of Mandatory Contributions</h2>
<table class="lines">
  <thead>
    <tr><th>Contribution</th><th class="r">Employee Share</th></tr>
  </thead>
  <tbody>
    <tr><td>SSS</td><td class="r">₱ {{ number_format((float) $sss, 2) }}</td></tr>
    <tr><td>PhilHealth</td><td class="r">₱ {{ number_format((float) $philhealth, 2) }}</td></tr>
    <tr><td>Pag-IBIG</td><td class="r">₱ {{ number_format((float) $pagibig, 2) }}</td></tr>
  </tbody>
</table>

<p style="font-size:10px; line-height:1.6; margin: 16px 0; color:#555;">
  I declare, under the penalties of perjury, that this certificate has been
  made in good faith, verified by me, and to the best of my knowledge is true
  and correct, pursuant to the provisions of the National Internal Revenue
  Code, as amended, and the regulations issued under authority thereof.
</p>

<div class="signatures" style="margin-top:40px;">
  <div class="sig">
    <div class="line">Employee Signature — {{ $employee_name }}</div>
  </div>
  <div class="sig">
    <div class="line">{{ $hr_signatory ?? 'Authorized Representative' }}</div>
  </div>
</div>

@endsection
