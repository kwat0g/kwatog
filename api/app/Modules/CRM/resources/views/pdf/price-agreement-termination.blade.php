@extends('pdf._layout')
@section('title', $termination['kind'] === 'scheduled_cancellation' ? 'Scheduled Price Agreement Cancellation' : 'Price Agreement Early Termination')

@section('content')
  <div class="doc-title">{{ $termination['kind'] === 'scheduled_cancellation' ? 'Scheduled Price Agreement Cancellation' : 'Price Agreement Early Termination' }}</div>

  <div class="control-block" style="border-color:#d6a15d; background:#fff8e9; color:#704313;">
    <strong>Customer signature required</strong><br>
    The price agreement remains unchanged until an authorized customer signatory signs this document in the customer portal.
  </div>

  <table class="doc-head">
    <tr>
      <td>
        <div class="doc-number">{{ $termination['reference'] }}</div>
        <div class="doc-refs">Document purpose: {{ $termination['kind'] === 'scheduled_cancellation' ? 'Cancel before start' : 'End current price terms early' }}</div>
      </td>
      <td style="text-align:right;">
        <div class="doc-refs">Prepared</div>
        <div class="doc-refs val">{{ now()->format('F j, Y') }}</div>
      </td>
    </tr>
  </table>

  <h2>Parties and price agreement</h2>
  <div class="control-block">
    <div><strong>Customer:</strong> {{ $termination['customer'] }}</div>
    <div><strong>Product:</strong> {{ $termination['part_number'] }} · {{ $termination['product_name'] }}</div>
    <div><strong>Current unit price (VAT excluded):</strong> ₱{{ number_format((float) $termination['price'], 2) }}</div>
    <div><strong>Current agreement period:</strong> {{ $termination['effective_from'] }} through {{ $termination['effective_to'] }}</div>
  </div>

  @if ($termination['kind'] === 'scheduled_cancellation')
    <h2>Requested change</h2>
    <p>The parties are asked to cancel this scheduled price agreement before its effective date of {{ $termination['cancellation_date'] }}. If signed, this agreement will not take effect.</p>
  @else
    <h2>Requested change</h2>
    <p>If signed, the current price remains valid through <strong>{{ $termination['last_valid_date'] }}</strong>, inclusive. New orders delivered after that date will use another valid agreement or the product's base price, if available.</p>
  @endif

  <div class="control-block">
    <div><strong>Reason for the requested change:</strong></div>
    <div style="margin-top:5px; white-space:pre-wrap;">{{ $termination['reason'] }}</div>
  </div>

  <h2>Price availability after this change</h2>
  <p>For orders on or after {{ $termination['coverage_date'] }}:
    @if ($termination['coverage_status'] === 'none')
      no valid agreement or base selling price is currently configured. New order submission may be blocked until Sales configures an applicable price.
    @elseif ($termination['coverage_status'] === 'agreement')
      another price agreement is currently available.
    @else
      the product's base selling price is currently available.
    @endif
  </p>

  <h2>Customer confirmation</h2>
  <p>By signing through the customer portal, the authorized signatory confirms they have reviewed this change and are authorized to accept it for the customer. The system records the signatory account, typed name, signing time, and document hash with this agreement.</p>
  <div class="control-block">
    <div><strong>Authorized customer signatory:</strong> To be recorded when signed in the customer portal</div>
    <div style="margin-top:6px;"><strong>Signature date and time:</strong> To be recorded when signed in the customer portal</div>
  </div>
@endsection
