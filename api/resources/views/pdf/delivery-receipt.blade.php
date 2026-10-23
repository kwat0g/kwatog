{{--
    Chain 1 (Order to Cash) — Delivery Receipt.

    The paper the customer signs at the gate. Its reason for existing is the
    DELIVER TO block: the ship-to address and the person who receives there,
    resolved once on the Delivery model (snapshot → customer ship-to → customer
    billing address) so this sheet cannot disagree with the driver's phone.

    Deliberately carries no prices: this is a receiving document, not an
    invoice, and a driver does not need commercial data on the shop floor.
--}}
@extends('pdf._layout')

@section('title', 'Delivery Receipt ' . $delivery->delivery_number)

@section('content')
  <div class="doc-title">Delivery Receipt</div>

  @if ($delivery->status?->value === 'cancelled')
    <div class="control-block">
      <span class="lbl">Cancelled</span> — this delivery was cancelled. The receipt is kept for
      traceability only and must not be used to accept goods.
    </div>
  @endif

  <table class="info-grid">
    <tr>
      <td>
        <div class="info-card">
          <div class="info-card-title">Deliver to</div>
          <div style="font-weight:bold; font-size:10pt; margin-bottom:2px;">
            {{ $customer?->name ?? '—' }}
          </div>
          @if ($address)
            <div style="font-size:8.5pt; margin-bottom:4px;">{{ $address }}</div>
          @else
            <div style="font-size:8.5pt; margin-bottom:4px; color:#7a2820;">
              No delivery address on file for this customer — confirm the destination with dispatch
              before releasing the goods.
            </div>
          @endif
          @if ($contactPerson || $contactPhone)
            <div style="font-size:8pt; color:#4a4239;">
              @if ($contactPerson) Attn: {{ $contactPerson }} @endif
              @if ($contactPerson && $contactPhone) &middot; @endif
              @if ($contactPhone) Tel: {{ $contactPhone }} @endif
            </div>
          @endif
        </div>
      </td>
      <td>
        <div class="info-card last">
          <div class="info-card-title">Ship from</div>
          <div style="font-weight:bold; font-size:10pt; margin-bottom:2px;">{{ $company['name'] }}</div>
          @if (!empty($company['address']))
            <div style="font-size:8.5pt;">{{ $company['address'] }}</div>
          @endif
          @if (!empty($company['phone']))
            <div style="font-size:8pt; color:#4a4239;">Tel: {{ $company['phone'] }}</div>
          @endif
        </div>
      </td>
    </tr>
  </table>

  <table style="width:100%; border-collapse:collapse; margin-bottom:14px;">
    <tr>
      <td style="width:25%;">
        <div class="meta-grid" style="margin:0; display:block;">
          <label>DR No.</label>
          <div class="v" style="font-size:10pt;">{{ $delivery->delivery_number }}</div>
        </div>
      </td>
      <td style="width:25%;">
        <label style="display:block; font-size:7.5pt; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px; color:#6b6259;">Ship date</label>
        <div class="mono">{{ optional($delivery->scheduled_date)->format('M d, Y') ?? '—' }}</div>
      </td>
      <td style="width:25%;">
        <label style="display:block; font-size:7.5pt; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px; color:#6b6259;">Sales order</label>
        <div class="mono">{{ $delivery->salesOrder?->so_number ?? '—' }}</div>
      </td>
      <td style="width:25%;">
        <label style="display:block; font-size:7.5pt; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px; color:#6b6259;">Status</label>
        <div><span class="chip">{{ strtoupper(str_replace('_', ' ', (string) ($delivery->status?->value ?? $delivery->status))) }}</span></div>
      </td>
    </tr>
    <tr>
      <td>
        <label style="display:block; font-size:7.5pt; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px; color:#6b6259;">Driver</label>
        <div>{{ $delivery->driver?->name ?? '—' }}</div>
      </td>
      <td>
        <label style="display:block; font-size:7.5pt; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px; color:#6b6259;">Vehicle</label>
        <div class="mono">
          {{ $delivery->vehicle ? $delivery->vehicle->plate_number : '—' }}
        </div>
      </td>
      <td>
        <label style="display:block; font-size:7.5pt; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px; color:#6b6259;">Carrier</label>
        <div>{{ $delivery->carrier ?: '—' }}</div>
      </td>
      <td>
        <label style="display:block; font-size:7.5pt; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px; color:#6b6259;">Expected arrival</label>
        <div class="mono">{{ optional($delivery->expected_delivery_date)->format('M d, Y') ?? '—' }}</div>
      </td>
    </tr>
  </table>

  <table class="lines">
    <thead>
      <tr>
        <th style="width:36px">#</th>
        <th style="width:110px">Part number</th>
        <th>Description</th>
        <th class="r" style="width:90px">Quantity</th>
        <th style="width:60px">Unit</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($items as $i => $item)
        @php
          $product = $item->salesOrderItem?->product;
        @endphp
        <tr>
          <td>{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</td>
          <td class="mono">{{ $product?->part_number ?? '—' }}</td>
          <td>{{ $product?->name ?? '—' }}</td>
          <td class="r mono">{{ number_format((float) $item->quantity, 2) }}</td>
          <td>{{ $product?->unit_of_measure ?? 'pcs' }}</td>
        </tr>
      @empty
        <tr>
          <td colspan="5" style="text-align:center; color:#6b6259;">No items on this delivery.</td>
        </tr>
      @endforelse
    </tbody>
  </table>

  @php
    $quantities = $items->groupBy(fn ($item) => $item->salesOrderItem?->product?->unit_of_measure ?? 'pcs')
      ->map(fn ($group, $unit) => number_format((float) $group->sum(fn ($item) => (float) $item->quantity), 2) . ' ' . $unit);
  @endphp
  @if ($quantities->isNotEmpty())
    <div class="notes-block">
      <div class="notes-title">Total quantity</div>
      {{ $quantities->values()->implode(' &middot; ') }}
    </div>
  @endif

  @if (trim((string) $delivery->delivery_remarks))
    <div class="notes-block">
      <div class="notes-title">Remarks</div>
      {{ $delivery->delivery_remarks }}
    </div>
  @endif

  <div class="notes-block">
    <div class="notes-title">Received in good order and condition</div>
    Goods listed above were received complete and undamaged unless noted on this receipt.
  </div>

  <table class="signatures">
    <tr>
      <td>
        <div class="sig-card">
          <div class="sig-title">Received by (customer)</div>
          <div class="sig-line">{{ $delivery->receiver_name ?: ($contactPerson ?? '') ?: ' ' }}</div>
          <div class="sig-meta">{{ $delivery->receiver_position ?: 'Name and signature / position' }}</div>
          <div class="sig-meta mono">
            {{ optional($delivery->received_at)->format('M d, Y g:i A') ?? 'Date and time' }}
          </div>
        </div>
      </td>
      <td>
        <div class="sig-card">
          <div class="sig-title">Delivered by (driver)</div>
          <div class="sig-line">{{ $delivery->driver?->name ?? ' ' }}</div>
          <div class="sig-meta">Name and signature</div>
          <div class="sig-meta mono">
            {{ optional($delivery->departed_at)->format('M d, Y g:i A') ?? 'Date and time' }}
          </div>
        </div>
      </td>
    </tr>
  </table>
@endsection
