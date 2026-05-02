@extends('pdf._layout')
@section('title', 'Invoice ' . ($invoice->invoice_number ?? 'DRAFT'))

@if ($invoice->status?->value === 'draft')
  @section('watermark', 'DRAFT')
@endif

@section('content')
  <div class="doc-title">Sales Invoice</div>

  <div class="meta-grid">
    <div class="col">
      <label>Bill To</label>
      <div class="v">{{ $invoice->customer->name }}</div>
      @if ($invoice->customer->contact_person) <div>{{ $invoice->customer->contact_person }}</div> @endif
      @if ($invoice->customer->address)        <div>{{ $invoice->customer->address }}</div>        @endif
    </div>
    <div class="col">
      <label>Invoice No.</label>
      <div class="v">{{ $invoice->invoice_number ?? '— DRAFT —' }}</div>
      <label style="margin-top:6px">Date</label> <div>{{ $invoice->date->format('M d, Y') }}</div>
      <label style="margin-top:6px">Due Date</label> <div>{{ $invoice->due_date->format('M d, Y') }}</div>
      <label style="margin-top:6px">Status</label> <div><span class="chip">{{ $invoice->status?->value }}</span></div>
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

  <table class="totals">
    <tr><td class="label">Subtotal</td><td class="v">{{ number_format((float) $invoice->subtotal, 2) }}</td></tr>
    @if ($invoice->is_vatable)
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
@endsection
