<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Production Summary — {{ $summary['date'] }}</title>
</head>
<body style="margin:0;padding:0;background:#fafafa;font-family:'Geist','Helvetica Neue',Arial,sans-serif;color:#09090b;font-size:13px;">
<div style="max-width:720px;margin:0 auto;padding:20px;background:#ffffff;">

  <h1 style="font-size:18px;font-weight:500;margin:0 0 4px;color:#09090b;">Production Summary</h1>
  <p style="margin:0 0 20px;color:#71717a;font-family:'Geist Mono',Menlo,Consolas,monospace;font-size:11px;">
    {{ $summary['date'] }}
  </p>

  {{-- KPI tiles row --}}
  <table cellpadding="0" cellspacing="0" border="0" style="width:100%;margin-bottom:20px;border-collapse:separate;border-spacing:8px 0;">
    <tr>
      @foreach ([
        ['label' => 'Good',        'value' => number_format($summary['totals']['good']),    'color' => '#059669'],
        ['label' => 'Reject',      'value' => number_format($summary['totals']['reject']),  'color' => '#DC2626'],
        ['label' => 'Total Units', 'value' => number_format($summary['totals']['total_units']), 'color' => '#09090b'],
        ['label' => 'Scrap Rate',  'value' => $summary['totals']['scrap_rate'].'%',          'color' => $summary['totals']['scrap_rate'] > 5 ? '#DC2626' : '#09090b'],
      ] as $tile)
      <td style="border:1px solid #e4e4e7;border-radius:6px;padding:12px;background:#fafafa;width:25%;">
        <div style="font-size:10px;text-transform:uppercase;letter-spacing:0.05em;color:#71717a;margin-bottom:6px;">{{ $tile['label'] }}</div>
        <div style="font-family:'Geist Mono',Menlo,monospace;font-variant-numeric:tabular-nums;font-size:22px;font-weight:500;color:{{ $tile['color'] }};">
          {{ $tile['value'] }}
        </div>
      </td>
      @endforeach
    </tr>
  </table>

  {{-- Work order table --}}
  @if (! empty($summary['wos']))
  <h2 style="font-size:14px;font-weight:500;margin:24px 0 8px;color:#09090b;">Work Orders</h2>
  <table cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;font-size:12px;">
    <thead>
      <tr style="border-bottom:1px solid #e4e4e7;">
        <th style="text-align:left;padding:6px 10px;font-size:10px;text-transform:uppercase;letter-spacing:0.05em;color:#71717a;font-weight:500;">WO</th>
        <th style="text-align:left;padding:6px 10px;font-size:10px;text-transform:uppercase;letter-spacing:0.05em;color:#71717a;font-weight:500;">Product</th>
        <th style="text-align:right;padding:6px 10px;font-size:10px;text-transform:uppercase;letter-spacing:0.05em;color:#71717a;font-weight:500;">Target</th>
        <th style="text-align:right;padding:6px 10px;font-size:10px;text-transform:uppercase;letter-spacing:0.05em;color:#71717a;font-weight:500;">Good</th>
        <th style="text-align:right;padding:6px 10px;font-size:10px;text-transform:uppercase;letter-spacing:0.05em;color:#71717a;font-weight:500;">Reject</th>
        <th style="text-align:left;padding:6px 10px;font-size:10px;text-transform:uppercase;letter-spacing:0.05em;color:#71717a;font-weight:500;">Status</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($summary['wos'] as $wo)
      <tr style="border-bottom:1px solid #f4f4f5;height:32px;">
        <td style="padding:0 10px;font-family:'Geist Mono',monospace;font-variant-numeric:tabular-nums;color:#4F46E5;">{{ $wo['wo_number'] }}</td>
        <td style="padding:0 10px;">{{ $wo['product_name'] ?? '—' }}</td>
        <td style="padding:0 10px;text-align:right;font-family:'Geist Mono',monospace;font-variant-numeric:tabular-nums;">{{ number_format($wo['quantity_target']) }}</td>
        <td style="padding:0 10px;text-align:right;font-family:'Geist Mono',monospace;font-variant-numeric:tabular-nums;">{{ number_format($wo['good']) }}</td>
        <td style="padding:0 10px;text-align:right;font-family:'Geist Mono',monospace;font-variant-numeric:tabular-nums;color:{{ $wo['reject'] > 0 ? '#DC2626' : '#71717a' }};">{{ number_format($wo['reject']) }}</td>
        <td style="padding:0 10px;font-size:11px;color:#52525b;">{{ str_replace('_',' ', (string) $wo['status']) }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif

  {{-- Breakdowns --}}
  @if (! empty($summary['breakdowns']))
  <h2 style="font-size:14px;font-weight:500;margin:24px 0 8px;color:#DC2626;">Active Breakdowns</h2>
  <ul style="margin:0;padding:0 0 0 16px;font-size:12px;line-height:1.6;">
    @foreach ($summary['breakdowns'] as $b)
    <li>
      <span style="font-family:'Geist Mono',monospace;color:#09090b;">{{ $b['machine_code'] }}</span>
      — {{ $b['machine_name'] }} · {{ $b['duration_min'] }} min · {{ $b['description'] ?? 'no description' }}
    </li>
    @endforeach
  </ul>
  @endif

  {{-- Defects pareto --}}
  @if (! empty($summary['defects']))
  <h2 style="font-size:14px;font-weight:500;margin:24px 0 8px;color:#09090b;">Top Defects</h2>
  <table cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;font-size:12px;">
    <tbody>
      @foreach ($summary['defects'] as $d)
      <tr style="border-bottom:1px solid #f4f4f5;height:28px;">
        <td style="padding:0 10px;font-family:'Geist Mono',monospace;color:#71717a;width:80px;">{{ $d['code'] }}</td>
        <td style="padding:0 10px;">{{ $d['name'] }}</td>
        <td style="padding:0 10px;text-align:right;font-family:'Geist Mono',monospace;font-variant-numeric:tabular-nums;">{{ number_format($d['count']) }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif

  {{-- QC --}}
  <h2 style="font-size:14px;font-weight:500;margin:24px 0 8px;color:#09090b;">QC Inspections</h2>
  <p style="margin:0;font-size:12px;color:#52525b;">
    <span style="color:#059669;font-family:'Geist Mono',monospace;">{{ $summary['qc']['passed'] }} passed</span>
    ·
    <span style="color:#DC2626;font-family:'Geist Mono',monospace;">{{ $summary['qc']['failed'] }} failed</span>
    ·
    <span style="color:#71717a;font-family:'Geist Mono',monospace;">{{ $summary['qc']['total'] }} total</span>
  </p>

  <hr style="margin:32px 0 12px;border:0;border-top:1px solid #e4e4e7;" />
  <p style="margin:0;font-size:10px;color:#a1a1aa;font-family:'Geist Mono',monospace;">
    Generated by Ogami ERP at {{ now()->toDateTimeString() }}
  </p>
</div>
</body>
</html>
