{{--
    Series E (E1) shared PDF chrome — executive corporate design system.
    Every per-document Blade extends or @includes from this layout.

    Variables provided by App\Common\Services\Pdf\PdfRenderService:
      - $company       (array — name/address/phone/email/tin/disclaimer)
      - $generated     (array — by/by_user/at/at_text)
      - $confidential  (bool)
      - $watermark     (string|null)
      - $docTitle      (string|null)
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>@yield('title', $docTitle ?? 'Document')</title>
<style>
  @page {
    margin: 15mm 15mm 20mm 15mm;
  }
  * { box-sizing: border-box; }
  html, body {
    font-family: 'Helvetica Neue', 'Helvetica', Arial, sans-serif;
    font-size: 9pt;
    line-height: 1.5;
    color: #334155; /* Slate 700 */
    margin: 0;
    padding: 0;
    background: #FFFFFF;
  }
  .mono { font-family: 'Courier New', Courier, monospace; }
  
  /* Brand Header & Letterhead */
  .brand-bar {
    width: 100%;
    height: 6px;
    background: #0f172a; /* Slate 900 */
    border-radius: 3px 3px 0 0;
    margin-bottom: 15px;
  }
  
  .header-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
    border-bottom: 2px solid #f1f5f9; /* Slate 100 */
    padding-bottom: 15px;
  }
  .header-table td { vertical-align: middle; }
  
  .company-title {
    font-size: 16pt;
    font-weight: 800;
    color: #0f172a; /* Slate 900 */
    letter-spacing: -0.5px;
    margin: 0 0 4px;
    text-transform: uppercase;
  }
  
  .company-sub {
    font-size: 8pt;
    color: #64748b; /* Slate 500 */
    line-height: 1.4;
  }
  
  .doc-badge-box {
    text-align: right;
  }
  
  .doc-title {
    font-size: 20pt;
    font-weight: 800;
    color: #0f172a; /* Slate 900 */
    text-transform: uppercase;
    letter-spacing: 1px;
    margin: 0 0 4px;
  }
  
  .doc-meta {
    font-size: 8.5pt;
    color: #64748b;
    line-height: 1.4;
  }
  .doc-meta .val {
    font-weight: 600;
    color: #334155;
  }

  /* Meta Grid (Two-column info headers) */
  .meta-grid {
    width: 100%;
    display: table;
    table-layout: fixed;
    margin-bottom: 24px;
  }
  .meta-grid .col {
    display: table-cell;
    vertical-align: top;
    padding-right: 20px;
  }
  .meta-grid .col:last-child {
    padding-right: 0;
  }
  .meta-grid label,
  .meta-grid .label {
    display: block;
    font-size: 7.5pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #94a3b8; /* Slate 400 */
    margin-bottom: 2px;
  }
  .meta-grid .v {
    font-size: 9.5pt;
    font-weight: 600;
    color: #0f172a; /* Slate 900 */
    margin-bottom: 6px;
  }

  /* Info Grid (Alternative layout) */
  .info-grid {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
  }
  .info-grid td {
    width: 50%;
    vertical-align: top;
    padding: 0;
  }
  .info-card {
    background: #f8fafc; /* Slate 50 */
    border: 1px solid #e2e8f0; /* Slate 200 */
    border-radius: 6px;
    padding: 12px 14px;
    margin-right: 8px;
  }
  .info-card.last {
    margin-right: 0;
    margin-left: 8px;
  }
  .info-card-title {
    font-size: 7.5pt;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #64748b; /* Slate 500 */
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 4px;
    margin-bottom: 8px;
  }
  .info-row {
    margin-bottom: 4px;
    font-size: 8.5pt;
  }
  .info-row .lbl {
    color: #64748b;
    display: inline-block;
    width: 90px;
    font-size: 8pt;
  }
  .info-row .val {
    color: #0f172a;
    font-weight: 600;
  }

  /* Line Items Table */
  table.lines {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
    margin-bottom: 20px;
  }
  table.lines th {
    background: #0f172a; /* Slate 900 */
    color: #ffffff;
    font-size: 8pt;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 8px 10px;
    text-align: left;
    border: none;
  }
  table.lines th:first-child { border-top-left-radius: 4px; border-bottom-left-radius: 4px; }
  table.lines th:last-child { border-top-right-radius: 4px; border-bottom-right-radius: 4px; }
  
  table.lines td {
    padding: 8px 10px;
    border-bottom: 1px solid #e2e8f0; /* Slate 200 */
    font-size: 9pt;
    vertical-align: top;
    color: #334155;
  }
  table.lines tr:last-child td {
    border-bottom: 2px solid #e2e8f0;
  }
  table.lines td.r, table.lines th.r { text-align: right; }
  table.lines td.c, table.lines th.c { text-align: center; }

  /* Summary & Totals */
  table.totals,
  .totals-box {
    width: 320px;
    margin-left: auto;
    border-collapse: collapse;
    margin-top: 10px;
    margin-bottom: 20px;
  }
  table.totals td,
  .totals-box td {
    padding: 6px 10px;
    font-size: 9pt;
  }
  table.totals td.label,
  table.totals td.lbl,
  .totals-box td.lbl {
    color: #64748b;
    text-align: right;
  }
  table.totals td.v,
  table.totals td.val,
  .totals-box td.val {
    text-align: right;
    font-weight: 600;
    color: #0f172a;
  }
  table.totals tr.grand td,
  .totals-box tr.grand-total td {
    border-top: 2px solid #0f172a;
    background: #f8fafc;
    font-size: 11pt;
    font-weight: 800;
    color: #0f172a;
    padding: 10px;
  }
  table.totals tr.grand td:first-child,
  .totals-box tr.grand-total td:first-child {
    border-top-left-radius: 0;
    border-bottom-left-radius: 4px;
  }
  table.totals tr.grand td:last-child,
  .totals-box tr.grand-total td:last-child {
    border-top-right-radius: 0;
    border-bottom-right-radius: 4px;
  }

  /* Status Chips */
  .chip {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 7.5pt;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #e2e8f0;
  }
  .chip-success { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
  .chip-warning { background: #fffbeb; color: #92400e; border-color: #fde68a; }
  .chip-danger  { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
  .chip-info    { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }

  /* Signatures */
  .signatures {
    width: 100%;
    display: table;
    table-layout: fixed;
    margin-top: 30px;
    page-break-inside: avoid;
  }
  .signatures .sig,
  .signatures td {
    display: table-cell;
    vertical-align: bottom;
    padding: 0 15px;
    text-align: center;
  }
  .sig-card {
    background: #ffffff;
    border-radius: 6px;
    padding: 10px;
  }
  .sig-title {
    font-size: 7.5pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    margin-bottom: 30px;
  }
  .sig-line,
  .signatures .line {
    border-top: 1px solid #0f172a;
    padding-top: 6px;
    font-size: 8.5pt;
    font-weight: 700;
    color: #0f172a;
  }
  .sig-meta {
    font-size: 7.5pt;
    color: #64748b;
    margin-top: 2px;
  }
  
  /* Utilities */
  .text-right { text-align: right; }
  .text-center { text-align: center; }
  .font-bold { font-weight: 700; }
  .text-muted { color: #64748b; }

</style>
</head>
<body>

@include('pdf._components.watermark')

@include('pdf._components.letterhead')

@yield('content')

@include('pdf._components.footer')

</body>
</html>
