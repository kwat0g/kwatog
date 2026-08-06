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
    margin: 12mm 14mm 16mm 14mm;
  }
  * { box-sizing: border-box; }
  html, body {
    font-family: 'DejaVu Sans', 'Helvetica', Arial, sans-serif;
    font-size: 9.5pt;
    line-height: 1.4;
    color: #0F172A;
    margin: 0;
    padding: 0;
    background: #FFFFFF;
  }
  .mono { font-family: 'DejaVu Sans Mono', 'Courier', monospace; }
  
  /* Brand Header & Letterhead */
  .brand-bar {
    width: 100%;
    height: 4px;
    background: #4F46E5;
    margin-bottom: 10px;
  }
  .header-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 14px;
    border-bottom: 1.5px solid #E2E8F0;
    padding-bottom: 8px;
  }
  .header-table td { vertical-align: top; }
  .company-title {
    font-size: 13pt;
    font-weight: bold;
    color: #0F172A;
    letter-spacing: -0.2px;
    margin: 0 0 2px;
  }
  .company-sub {
    font-size: 7.5pt;
    color: #64748B;
    line-height: 1.3;
  }
  .doc-badge-box {
    text-align: right;
  }
  .doc-title {
    font-size: 14pt;
    font-weight: 800;
    color: #1E1B4B;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin: 0 0 2px;
  }
  .doc-meta {
    font-size: 8pt;
    color: #64748B;
    line-height: 1.3;
  }
  .doc-meta .val {
    font-weight: 600;
    color: #0F172A;
  }

  /* Two-column info cards */
  .info-grid {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 14px;
  }
  .info-grid td {
    width: 50%;
    vertical-align: top;
    padding: 0;
  }
  .info-card {
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    border-radius: 4px;
    padding: 8px 10px;
    margin-right: 6px;
  }
  .info-card.last {
    margin-right: 0;
    margin-left: 6px;
  }
  .info-card-title {
    font-size: 7.5pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #475569;
    border-bottom: 1px solid #E2E8F0;
    padding-bottom: 3px;
    margin-bottom: 6px;
  }
  .info-row {
    margin-bottom: 3px;
    font-size: 8.5pt;
  }
  .info-row .lbl {
    color: #64748B;
    display: inline-block;
    width: 90px;
    font-size: 8pt;
  }
  .info-row .val {
    color: #0F172A;
    font-weight: 500;
  }

  /* Line Items Table */
  table.lines {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
    margin-bottom: 14px;
  }
  table.lines th {
    background: #F1F5F9;
    color: #334155;
    font-size: 7.5pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 6px 8px;
    border-top: 1px solid #CBD5E1;
    border-bottom: 1.5px solid #94A3B8;
    text-align: left;
  }
  table.lines td {
    padding: 6px 8px;
    border-bottom: 1px solid #E2E8F0;
    font-size: 8.5pt;
    vertical-align: top;
  }
  table.lines tr:nth-child(even) td {
    background: #F8FAFC;
  }
  table.lines td.r, table.lines th.r { text-align: right; }
  table.lines td.c, table.lines th.c { text-align: center; }

  /* Summary & Totals */
  .totals-container {
    width: 100%;
    margin-top: 10px;
    margin-bottom: 16px;
  }
  .totals-box {
    width: 260px;
    margin-left: auto;
    border-collapse: collapse;
  }
  .totals-box td {
    padding: 3px 6px;
    font-size: 8.5pt;
  }
  .totals-box td.lbl {
    color: #64748B;
    text-align: right;
  }
  .totals-box td.val {
    text-align: right;
    font-weight: 600;
    color: #0F172A;
  }
  .totals-box tr.grand-total td {
    border-top: 1.5px solid #0F172A;
    border-bottom: 2px double #0F172A;
    font-size: 10pt;
    font-weight: 700;
    color: #1E1B4B;
    padding-top: 5px;
    padding-bottom: 5px;
  }

  /* Status Chips */
  .chip {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 7.5pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    background: #F1F5F9;
    color: #475569;
  }
  .chip-success { background: #DCFCE7; color: #166534; }
  .chip-warning { background: #FEF3C7; color: #92400E; }
  .chip-danger  { background: #FEE2E2; color: #991B1B; }
  .chip-info    { background: #DBEAFE; color: #1E40AF; }

  /* Signatures */
  .signatures {
    width: 100%;
    border-collapse: collapse;
    margin-top: 24px;
    page-break-inside: avoid;
  }
  .signatures td {
    vertical-align: top;
    padding: 0 8px;
    text-align: center;
  }
  .sig-card {
    border: 1px solid #E2E8F0;
    background: #FAFAFA;
    border-radius: 4px;
    padding: 8px;
  }
  .sig-title {
    font-size: 7pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748B;
    margin-bottom: 24px;
  }
  .sig-line {
    border-top: 1px solid #94A3B8;
    padding-top: 4px;
    font-size: 8pt;
    font-weight: 600;
    color: #0F172A;
  }
  .sig-meta {
    font-size: 7pt;
    color: #64748B;
  }
</style>
</head>
<body>

@include('pdf._components.watermark')

@include('pdf._components.letterhead')

@yield('content')

@include('pdf._components.footer')

</body>
</html>
