@extends('pdf._layout')
@section('title', 'Invoice ' . ($invoice->invoice_number ?? 'DRAFT'))

@if ($invoice->status?->value === 'draft')
  @section('watermark', 'DRAFT')
@endif

@section('content')
  <div class="doc-title">Sales Invoice</div>

  {{-- OGAMI-008 — BIR ORIGINAL / DUPLICATE marker. --}}
  <div style="text-align:right; margin-top:-18px; margin-bottom:8px;">
    <span class="chip" style="background:#E0E7FF; color:#3730A3;">
      {{ ($invoice->is_original ?? true) ? 'ORIGINAL' : 'DUPLICATE' }}
    </span>
  </div>

  <div class="meta-grid">
    <div class="col">
      <label>Bill To</label>
      <div class="v">{{ $invoice->customer->name }}</div>
      @if ($invoice->customer->contact_person) <div>{{ $invoice->customer->contact_person }}</div> @endif
      @if ($invoice->customer->address)        <div>{{ $invoice->customer->address }}</div>        @endif
      {{-- Buyer TIN: explicit invoice value, else the customer record's TIN. --}}
      @php $buyerTin = $invoice->buyer_tin ?: $invoice->customer->tin; @endphp
      @if ($buyerTin) <div>TIN: {{ $buyerTin }}</div> @endif
    </div>
    <div class="col">
      <label>Invoice No.</label>
      <div class="v">{{ $invoice->invoice_number ?? '— DRAFT —' }}</div>
      <label style="margin-top:6px">Date</label> <div>{{ $invoice->date->format('M d, Y') }}</div>
      <label style="margin-top:6px">Due Date</label> <div>{{ $invoice->due_date->format('M d, Y') }}</div>
      <label style="margin-top:6px">Status</label> <div><span class="chip">{{ $invoice->status?->value }}</span></div>
      {{-- OGAMI-008 — BIR seller / authority-to-print references. --}}
      @if (!empty($company['tin']))
        <label style="margin-top:6px">Seller TIN</label> <div>{{ $company['tin'] }}</div>
      @endif
      @if ($invoice->atp_number)
        <label style="margin-top:6px">ATP No.</label> <div>{{ $invoice->atp_number }}</div>
      @endif
      @if ($invoice->serial_range)
        <label style="margin-top:6px">Serial Range</label> <div>{{ $invoice->serial_range }}</div>
      @endif
      <label style="margin-top:6px">VAT Classification</label>
      <div>{{ $invoice->vat_classification?->label() ?? ($invoice->is_vatable ? 'VATable' : 'VAT-Exempt') }}</div>
    </div>
  </div>

  <table class="lines">
    <thead>
      <tr>
        <th style="width:38px">#</th>
        <th>Description</th>
        <th class="r">Qty</th>
        <th>Unit</th>
        <th class="r">Unit Price</th>
        <th class="r">Total</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($invoice->items as $i => $item)
        <tr>
          <td>{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</td>
          <td>{{ $item->description }}</td>
          <td class="r">{{ number_format((float) $item->quantity, 2) }}</td>
          <td>{{ $item->unit }}</td>
          <td class="r">{{ number_format((float) $item->unit_price, 2) }}</td>
          <td class="r">{{ number_format((float) $item->total, 2) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  @php
    $class = $invoice->vat_classification?->value ?? ($invoice->is_vatable ? 'vatable' : 'vat_exempt');
    $vatableSales   = $class === 'vatable'    ? (float) $invoice->subtotal : 0;
    $zeroRatedSales = $class === 'zero_rated' ? (float) $invoice->subtotal : 0;
    $exemptSales    = $class === 'vat_exempt' ? (float) $invoice->subtotal : 0;
  @endphp

  {{-- OGAMI-008 — BIR VAT breakdown box. --}}
  <table class="lines" style="width:55%; margin-top:12px;">
    <thead>
      <tr><th colspan="2">VAT Summary</th></tr>
    </thead>
    <tbody>
      <tr><td>VATable Sales</td><td class="r">{{ number_format($vatableSales, 2) }}</td></tr>
      <tr><td>Zero-Rated Sales</td><td class="r">{{ number_format($zeroRatedSales, 2) }}</td></tr>
      <tr><td>VAT-Exempt Sales</td><td class="r">{{ number_format($exemptSales, 2) }}</td></tr>
      <tr><td>VAT (12%)</td><td class="r">{{ number_format((float) $invoice->vat_amount, 2) }}</td></tr>
    </tbody>
  </table>

  <table class="totals">
    <tr><td class="label">Subtotal</td><td class="v">{{ number_format((float) $invoice->subtotal, 2) }}</td></tr>
    @if ((float) ($invoice->senior_pwd_discount ?? 0) > 0)
      <tr><td class="label">Less: Senior/PWD Discount</td><td class="v">({{ number_format((float) $invoice->senior_pwd_discount, 2) }})</td></tr>
    @endif
    @if ($class === 'vatable')
      <tr><td class="label">VAT (12%)</td><td class="v">{{ number_format((float) $invoice->vat_amount, 2) }}</td></tr>
    @endif
    <tr class="grand"><td class="label">Total Due</td><td class="v">PHP {{ number_format((float) $invoice->total_amount, 2) }}</td></tr>
    @if ((float) $invoice->amount_paid > 0)
      <tr><td class="label">Paid</td><td class="v">{{ number_format((float) $invoice->amount_paid, 2) }}</td></tr>
      <tr><td class="label">Balance</td><td class="v">{{ number_format((float) $invoice->balance, 2) }}</td></tr>
    @endif
  </table>

  <p style="margin-top:24px;font-size:9px;color:#555;">
    Please remit payment within {{ $invoice->customer->payment_terms_days }} days of invoice date.
  </p>

  <table style="width:100%; margin-top:32px; border-collapse:collapse; font-size:9pt;">
    <tr>
      <td style="width:50%; vertical-align:bottom; padding:0 8px;">
        <div style="height:32px; border-bottom:1px solid #444;">&nbsp;</div>
        <div style="margin-top:4px; text-align:center; font-weight:500;">{{ $preparedBy ?? '—' }}</div>
        <div style="text-align:center; color:#777; font-size:8pt;">Prepared by · Accounting</div>
      </td>
      <td style="width:50%; vertical-align:bottom; padding:0 8px;">
        <div style="height:32px; border-bottom:1px solid #444;">&nbsp;</div>
        <div style="margin-top:4px; text-align:center; font-weight:500;">{{ $approvedBy ?? '—' }}</div>
        <div style="text-align:center; color:#777; font-size:8pt;">Approved by · Finance</div>
      </td>
    </tr>
  </table>
@endsection
