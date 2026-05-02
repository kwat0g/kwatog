<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $po->po_number }}</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #111; }
    h1 { font-size: 14px; margin: 0 0 8px; }
    .muted { color: #666; }
    .right { text-align: right; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 6px 8px; vertical-align: top; }
    .header { display: table; width: 100%; margin-bottom: 16px; }
    .header > div { display: table-cell; vertical-align: top; }
    .header .right { text-align: right; }
    .lines th, .lines td { border-bottom: 0.5px solid #ddd; }
    .lines th { font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; color: #666; text-align: left; border-bottom: 1px solid #999; }
    .lines td.num { font-family: 'DejaVu Sans Mono', monospace; text-align: right; }
    .totals { margin-top: 12px; width: 320px; margin-left: auto; }
    .totals td { padding: 3px 6px; font-family: 'DejaVu Sans Mono', monospace; }
    .totals .label { color: #666; text-align: right; }
    .totals .amt { text-align: right; }
    .totals .total { border-top: 1px solid #999; font-weight: bold; }
    .signatures { margin-top: 40px; display: table; width: 100%; }
    .sig { display: table-cell; padding: 0 16px; text-align: center; font-size: 10px; }
    .sig .line { border-top: 1px solid #999; margin-top: 30px; padding-top: 4px; }
    .footer { margin-top: 30px; font-size: 9px; color: #888; text-align: center; }
</style>
</head>
<body>

<div class="header">
    <div>
        <h1>{{ $company['name'] }}</h1>
        <div class="muted">{{ $company['address'] }}</div>
        @if($company['tin']) <div class="muted">TIN: {{ $company['tin'] }}</div> @endif
    </div>
    <div class="right">
        <h1>PURCHASE ORDER</h1>
        <div><strong>{{ $po->po_number }}</strong></div>
        <div class="muted">Date: {{ optional($po->date)->format('M d, Y') }}</div>
        @if($po->expected_delivery_date)
            <div class="muted">Expected: {{ optional($po->expected_delivery_date)->format('M d, Y') }}</div>
        @endif
    </div>
</div>

<table>
    <tr>
        <td style="width:50%; padding-right:16px;">
            <div class="muted" style="font-size:9px;">SUPPLIER</div>
            <div><strong>{{ $po->vendor->name }}</strong></div>
            <div class="muted">{{ $po->vendor->address }}</div>
            <div class="muted">{{ $po->vendor->phone }} · {{ $po->vendor->email }}</div>
        </td>
        <td style="width:50%;">
            <div class="muted" style="font-size:9px;">STATUS</div>
            <div>{{ str_replace('_', ' ', strtoupper((string) $po->status)) }}</div>
        </td>
    </tr>
</table>

<table class="lines" style="margin-top:14px;">
    <thead><tr>
        <th style="width:30px;">#</th>
        <th>Item</th>
        <th>Description</th>
        <th class="right">Qty</th>
        <th class="right">Unit Price</th>
        <th class="right">Total</th>
    </tr></thead>
    <tbody>
    @foreach($po->items as $i => $line)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ $line->item?->code ?? '—' }}</td>
            <td>{{ $line->description }}</td>
            <td class="num">{{ number_format((float) $line->quantity, 2) }} {{ $line->unit }}</td>
            <td class="num">{{ number_format((float) $line->unit_price, 2) }}</td>
            <td class="num">{{ number_format((float) $line->total, 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td class="label">Subtotal</td><td class="amt">{{ number_format((float) $po->subtotal, 2) }}</td></tr>
    @if($po->is_vatable)
        <tr><td class="label">VAT (12%)</td><td class="amt">{{ number_format((float) $po->vat_amount, 2) }}</td></tr>
    @endif
    <tr class="total"><td class="label">Total (PHP)</td><td class="amt">{{ number_format((float) $po->total_amount, 2) }}</td></tr>
</table>

<div class="signatures">
    <div class="sig"><div class="line">Prepared by</div></div>
    <div class="sig"><div class="line">Approved by</div></div>
    @if($po->requires_vp_approval)
        <div class="sig"><div class="line">Endorsed by VP</div></div>
    @endif
</div>

<div class="footer">
    Generated on {{ $now->format('M d, Y · H:i') }} ·
    {{ $company['name'] }} · OGAMI ERP
</div>

</body>
</html>
