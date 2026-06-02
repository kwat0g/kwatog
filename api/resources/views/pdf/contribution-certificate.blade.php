@extends('pdf._layout')

@section('title', $cert_title)

@section('content')

<h1 class="doc-title">{{ $cert_title }}</h1>

<div class="meta-grid">
  <div class="col">
    <label>Employee</label>
    <div class="v">{{ $employee_name }}</div>
    <label style="margin-top:8px;">Employee No.</label>
    <div class="v">{{ $employee_no }}</div>
  </div>
  <div class="col">
    <label>{{ $id_label }}</label>
    <div class="v">{{ $id_value ?? '—' }}</div>
    <label style="margin-top:8px;">Calendar Year</label>
    <div class="v">{{ $year }}</div>
  </div>
</div>

<p style="font-size:11px; line-height:1.7; margin: 4px 0 12px;">
  This certifies that the following {{ $contribution_label }} contributions were
  withheld from the above employee during calendar year {{ $year }}:
</p>

<table class="lines">
  <thead>
    <tr>
      <th>Period</th>
      <th class="r">Employee Share</th>
    </tr>
  </thead>
  <tbody>
    @forelse ($rows as $row)
      <tr>
        <td>{{ $row['period'] }}</td>
        <td class="r">₱ {{ number_format((float) $row['amount'], 2) }}</td>
      </tr>
    @empty
      <tr><td colspan="2" style="color:#999;">No contributions recorded for this year.</td></tr>
    @endforelse
  </tbody>
</table>

<table class="totals">
  <tr class="grand">
    <td class="label">Total Employee Share</td>
    <td class="v">₱ {{ number_format((float) $total, 2) }}</td>
  </tr>
</table>

<p style="font-size:10px; line-height:1.6; margin: 16px 0; color:#555;">
  This certification is issued upon the employee's request for whatever legal
  purpose it may serve.
</p>

<div class="signatures" style="margin-top:40px;">
  <div class="sig">
    <div class="line">{{ $hr_signatory ?? 'HR Officer / HR Manager' }}</div>
  </div>
  <div class="sig">
    <div class="line">{{ $company['name'] ?? 'Philippine Ogami Corporation' }}</div>
  </div>
</div>

@endsection
