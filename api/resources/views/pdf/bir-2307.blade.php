@extends('pdf._layout')

@section('title', 'BIR Form 2307')

@section('content')

<h1 class="doc-title">Certificate of Creditable Tax Withheld at Source</h1>
<p style="font-size:9px; color:#555; margin:0 0 12px;">BIR Form No. 2307 · Q{{ $year_quarter }} {{ $year }} · Period {{ $quarter_start }} to {{ $quarter_end }}</p>

<div class="meta-grid">
  <div class="col">
    <label>Payee</label>
    <div class="v">{{ $vendor['name'] }}</div>
    <label style="margin-top:6px;">TIN</label>
    <div class="v">{{ $vendor['tin'] }}</div>
    <label style="margin-top:6px;">Address</label>
    <div class="v" style="font-weight:normal;">{{ $vendor['address'] }}</div>
  </div>
  <div class="col">
    <label>Payor (Withholding Agent)</label>
    <div class="v">{{ $payor['name'] }}</div>
    <label style="margin-top:6px;">TIN</label>
    <div class="v">{{ $payor['tin'] }}</div>
    <label style="margin-top:6px;">Address</label>
    <div class="v" style="font-weight:normal;">{{ $payor['address'] }}</div>
  </div>
</div>

@if(count($income_payments) > 0)
<table class="lines" style="margin-bottom:12px;">
  <thead>
    <tr>
      <th>Month</th>
      <th>ATC</th>
      <th class="r">Income Payment</th>
      <th class="r">Tax Withheld</th>
    </tr>
  </thead>
  <tbody>
    @foreach($income_payments as $payment)
    <tr>
      <td>{{ $payment['month_name'] }}</td>
      <td>{{ $payment['atc'] ?? '—' }}</td>
      <td class="r">₱ {{ number_format((float)$payment['gross_amount'], 2) }}</td>
      <td class="r">₱ {{ number_format((float)$payment['tax_withheld'], 2) }}</td>
    </tr>
    @endforeach
    <tr>
      <td colspan="2"><strong>Total</strong></td>
      <td class="r"><strong>₱ {{ number_format((float)$summary['total_gross'], 2) }}</strong></td>
      <td class="r"><strong>₱ {{ number_format((float)$summary['total_tax_withheld'], 2) }}</strong></td>
    </tr>
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
    <div class="line">Payor / Authorized Representative</div>
  </div>
  <div class="sig">
    <div class="line">Payee Signature</div>
  </div>
</div>
@else
<p style="font-size:10px; color:#555; text-align:center; margin:20px 0;">
  No tax withheld from this vendor in Q{{ $year_quarter }} {{ $year }}.
</p>
@endif

@endsection
