@extends('pdf._layout')

@section('title', '8D Report')

@section('content')

<h1 class="doc-title">8D Corrective Action Report</h1>

<div class="meta-grid">
  <div class="col">
    <label>Complaint No.</label>
    <div class="v">{{ $complaint->complaint_number }}</div>
    <label style="margin-top:8px;">Customer</label>
    <div class="v">{{ $complaint->customer?->name ?? '—' }}</div>
  </div>
  <div class="col">
    <label>Severity</label>
    <div class="v">{{ ucfirst($complaint->severity) }}</div>
    <label style="margin-top:8px;">Received</label>
    <div class="v">{{ optional($complaint->received_date)?->format('M d, Y') }}</div>
    @if ($complaint->product)
      <label style="margin-top:8px;">Product</label>
      <div class="v">{{ $complaint->product->part_number }} — {{ $complaint->product->name }}</div>
    @endif
  </div>
</div>

@php
$sections = [
  ['D1 — Establish the team',                 $report->d1_team],
  ['D2 — Describe the problem',               $report->d2_problem],
  ['D3 — Interim containment actions',        $report->d3_containment],
  ['D4 — Define + verify root cause',         $report->d4_root_cause],
  ['D5 — Permanent corrective action',        $report->d5_corrective_action],
  ['D6 — Implement + validate corrective',    $report->d6_verification],
  ['D7 — Prevent recurrence',                 $report->d7_prevention],
  ['D8 — Recognise team contributions',       $report->d8_recognition],
];
@endphp

@foreach ($sections as [$heading, $body])
  <div style="margin-bottom: 14px;">
    <div style="font-weight:bold; font-size:11px; border-bottom:1px solid #999; padding-bottom:3px; margin-bottom:5px;">
      {{ $heading }}
    </div>
    <div style="font-size:10px; line-height:1.5; white-space:pre-line; color:#222;">
      {{ $body ?: '—' }}
    </div>
  </div>
@endforeach

<div class="signatures">
  <div class="sig">
    <div class="line">QA Manager</div>
  </div>
  <div class="sig">
    <div class="line">Operations Head</div>
  </div>
  <div class="sig">
    <div class="line">{{ $report->finalizer?->name ?? 'Approved by' }}</div>
  </div>
</div>

@if ($report->finalized_at)
  <p style="text-align:right; font-size:9px; color:#777; margin-top:12px;">
    Finalised on {{ $report->finalized_at->format('M d, Y H:i') }}
  </p>
@endif

@endsection
