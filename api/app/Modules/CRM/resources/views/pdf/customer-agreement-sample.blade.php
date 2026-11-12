@extends('pdf._layout')
@section('title', 'Draft Sample Customer Supply Agreement')

@section('content')
  <div class="doc-title">Customer Supply Agreement</div>

  <div class="control-block" style="border-color:#d6a15d; background:#fff8e9; color:#704313;">
    <strong>FICTIONAL SAMPLE — NOT FOR SIGNING OR BUSINESS USE</strong><br>
    Every download contains newly generated demo contact details. This document is for thesis demonstration only.
  </div>

  <table class="doc-head">
    <tr>
      <td>
        <div class="doc-number">{{ $sample['reference'] }}</div>
        <div class="doc-refs">Document type: Demonstration agreement · Status: Sample only</div>
      </td>
      <td style="text-align:right;">
        <div class="doc-refs">Generated</div>
        <div class="doc-refs val">{{ now()->format('F j, Y') }}</div>
      </td>
    </tr>
  </table>

  <h2>Parties and customer contact</h2>
  <div class="meta-grid">
    <div class="col">
      <label>Supplier</label>
        <div class="v">{{ $company['name'] ?: 'Supplier' }}</div>
      @if (!empty($company['address'])) <div>{{ $company['address'] }}</div> @endif
      @if (!empty($company['phone'])) <div>{{ $company['phone'] }}</div> @endif
    </div>
    <div class="col">
      <label>Customer</label>
      <div class="v">{{ $sample['customer_name'] }}</div>
      <div>{{ $sample['contact_person'] }} · {{ $sample['contact_role'] }}</div>
      <div>{{ $sample['contact_email'] }} · {{ $sample['contact_phone'] }}</div>
    </div>
  </div>

  <div class="control-block">
    <div><strong>Customer legal name:</strong> {{ $sample['customer_name'] }}</div>
    <div><strong>Authorized contact person:</strong> {{ $sample['contact_person'] }}</div>
    <div><strong>Contact role / title:</strong> {{ $sample['contact_role'] }}</div>
    <div><strong>Business email:</strong> {{ $sample['contact_email'] }}</div>
    <div><strong>Business phone:</strong> {{ $sample['contact_phone'] }}</div>
    <div><strong>Billing address:</strong> {{ $sample['billing_address'] }}</div>
    <div><strong>Delivery / receiving address:</strong> {{ $sample['delivery_address'] }}</div>
  </div>

  <h2>Agreement term and commercial conditions</h2>
  <div class="control-block">
    <div><strong>Effective date (YYYY-MM-DD):</strong> {{ $sample['starts_on'] }}</div>
    <div><strong>Expiry date (YYYY-MM-DD):</strong> {{ $sample['ends_on'] }}</div>
    <div><strong>Customer default payment terms:</strong> Net {{ $sample['payment_terms_days'] }} calendar days from accepted invoice</div>
    <div><strong>Payment methods accepted under this agreement:</strong> {{ $sample['payment_methods'] }}</div>
    <div><strong>Freight paid by:</strong> {{ $sample['shipping_payer'] }}</div>
  </div>

  <h2>Products currently authorized for this customer</h2>
  <table class="lines">
    <thead>
      <tr><th style="width:22%">Part number</th><th>Product and order quantity</th></tr>
    </thead>
    <tbody>
      @foreach ($sample['products'] as $product)
        <tr>
          <td class="mono">{{ $product['part_number'] }}</td>
          <td>{{ $product['part_number'] }} · {{ $product['name'] }} | Minimum order quantity: {{ $product['minimum_order_quantity'] }} units | Maximum order quantity per sales order: {{ $product['maximum_order_quantity'] }} units</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <h2>Warranty terms by product and available option</h2>
  <div class="control-block">
    @foreach ($sample['products'] as $product)
      <div style="margin-bottom:5px;"><strong>{{ $product['part_number'] }} · {{ $product['warranty_label'] }}:</strong>
        {{ $product['duration_days'] }} days from delivery; report within {{ $product['report_window_days'] }} days;
        {{ $product['issues'] }}; {{ $product['remedies'] }}.
      </div>
    @endforeach
  </div>

  <h2>Demonstration notes</h2>
  <p>This generated draft demonstrates the customer, delivery, payment, product access, and warranty fields supported by the customer agreement importer. Its generated customer data and sample terms are fictional and do not establish a real purchase, delivery, inspection, or warranty obligation.</p>

  <h2>Illustrative acknowledgment</h2>
  <table class="signatures" style="width:100%; border-collapse:collapse; margin-top:10px;">
    <tr>
      <td style="width:50%; padding:8px 16px 8px 0; vertical-align:bottom;">
        <div style="height:38px; border-bottom:1px solid #6b6259;"></div>
        <div class="sig-meta">Supplier representative · Sample only</div>
      </td>
      <td style="width:50%; padding:8px 0 8px 16px; vertical-align:bottom;">
        <div style="height:38px; border-bottom:1px solid #6b6259;"></div>
        <div class="sig-meta">Customer representative · Sample only</div>
      </td>
    </tr>
  </table>
@endsection
