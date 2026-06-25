{{-- ImpEx Packing List — customs clearance document for inbound resin shipments.

     Shipper (Japanese vendor) → Consignee (Ogami Philippines).
     Lists container(s), PO line items, quantities, and weights.
     Pattern follows purchase-request.blade.php layout style. --}}
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Packing List — {{ $shipment->shipment_number }}</title>
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
    .section-title { font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: #666; font-weight: bold; margin-top: 16px; margin-bottom: 6px; border-bottom: 1px solid #ccc; padding-bottom: 2px; }
    .footer { margin-top: 30px; font-size: 9px; color: #888; text-align: center; }
    .party-grid { display: table; width: 100%; margin-bottom: 14px; }
    .party-grid > div { display: table-cell; vertical-align: top; width: 50%; }
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
        <h1>PACKING LIST</h1>
        <div><strong>{{ $shipment->shipment_number }}</strong></div>
        <div class="muted">Date: {{ now()->format('M d, Y') }}</div>
    </div>
</div>

{{-- Shipper / Consignee --}}
<div class="party-grid">
    <div style="padding-right: 16px;">
        <div class="muted" style="font-size:9px;">SHIPPER (SUPPLIER)</div>
        @if($vendor)
            <div><strong>{{ $vendor->name }}</strong></div>
            @if($vendor->address) <div class="muted">{{ $vendor->address }}</div> @endif
            @if($vendor->contact_person) <div class="muted">Attn: {{ $vendor->contact_person }}</div> @endif
        @else
            <div>—</div>
        @endif
    </div>
    <div>
        <div class="muted" style="font-size:9px;">CONSIGNEE</div>
        <div><strong>{{ $company['name'] }}</strong></div>
        <div class="muted">{{ $company['address'] }}</div>
        @if($company['tin']) <div class="muted">TIN: {{ $company['tin'] }}</div> @endif
    </div>
</div>

{{-- Shipment details --}}
<table style="margin-bottom: 14px;">
    <tr>
        <td style="width:25%;">
            <div class="muted" style="font-size:9px;">PO NUMBER</div>
            <div><strong>{{ $po?->po_number ?? '—' }}</strong></div>
        </td>
        <td style="width:25%;">
            <div class="muted" style="font-size:9px;">VESSEL / VOYAGE</div>
            <div>{{ $shipment->vessel ?? '—' }}</div>
        </td>
        <td style="width:25%;">
            <div class="muted" style="font-size:9px;">B/L NUMBER</div>
            <div style="font-family: 'DejaVu Sans Mono', monospace;">{{ $shipment->bl_number ?? '—' }}</div>
        </td>
        <td style="width:25%;">
            <div class="muted" style="font-size:9px;">CARRIER</div>
            <div>{{ $shipment->carrier ?? '—' }}</div>
        </td>
    </tr>
    <tr>
        <td>
            <div class="muted" style="font-size:9px;">INCOTERM</div>
            <div>{{ $po?->incoterm?->value ?? '—' }}</div>
        </td>
        <td>
            <div class="muted" style="font-size:9px;">ETD</div>
            <div style="font-family: 'DejaVu Sans Mono', monospace;">{{ optional($shipment->etd)->format('M d, Y') ?? '—' }}</div>
        </td>
        <td>
            <div class="muted" style="font-size:9px;">ETA</div>
            <div style="font-family: 'DejaVu Sans Mono', monospace;">{{ optional($shipment->eta)->format('M d, Y') ?? '—' }}</div>
        </td>
        <td>
            <div class="muted" style="font-size:9px;">STATUS</div>
            <div>{{ strtoupper(str_replace('_', ' ', (string) ($shipment->status instanceof \BackedEnum ? $shipment->status->value : $shipment->status))) }}</div>
        </td>
    </tr>
</table>

{{-- Containers --}}
@if($containers->isNotEmpty())
<div class="section-title">Container(s)</div>
<table class="lines" style="margin-bottom: 14px;">
    <thead><tr>
        <th style="width:30px;">#</th>
        <th>Container No.</th>
        <th>Seal No.</th>
        <th>Size</th>
        <th>Type</th>
        <th class="right">Gross Wt (kg)</th>
        <th class="right">Net Wt (kg)</th>
        <th class="right">Volume (CBM)</th>
    </tr></thead>
    <tbody>
    @foreach($containers as $i => $c)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td style="font-family: 'DejaVu Sans Mono', monospace;">{{ $c->container_number }}</td>
            <td style="font-family: 'DejaVu Sans Mono', monospace;">{{ $c->seal_number ?? '—' }}</td>
            <td>{{ $c->size?->value ?? '—' }}</td>
            <td>{{ $c->type?->value ?? '—' }}</td>
            <td class="num">{{ $c->gross_weight_kg ? number_format((float) $c->gross_weight_kg, 2) : '—' }}</td>
            <td class="num">{{ $c->net_weight_kg ? number_format((float) $c->net_weight_kg, 2) : '—' }}</td>
            <td class="num">{{ $c->volume_cbm ? number_format((float) $c->volume_cbm, 3) : '—' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif

{{-- Items --}}
<div class="section-title">Items</div>
<table class="lines">
    <thead><tr>
        <th style="width:30px;">#</th>
        <th>Item Code</th>
        <th>Description</th>
        <th class="right">Quantity</th>
        <th>Unit</th>
    </tr></thead>
    <tbody>
    @foreach($items as $i => $line)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td style="font-family: 'DejaVu Sans Mono', monospace;">{{ $line->item?->code ?? '—' }}</td>
            <td>{{ $line->description ?? $line->item?->name ?? '—' }}</td>
            <td class="num">{{ number_format((float) $line->quantity, 2) }}</td>
            <td>{{ $line->unit ?? '—' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="footer">
    Generated on {{ $now->format('M d, Y · H:i') }} ·
    {{ $company['name'] }} · OGAMI ERP
</div>

</body>
</html>
