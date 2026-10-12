{{--
    Phase 2 (PDF foundation) letterhead — Atelier chrome.
    Reads from `$company` (injected by PdfRenderService). Every element hides
    gracefully when its setting is empty; a missing logo never breaks layout
    (DomPDF has enable_remote=false, so only an embedded data URI is used).
--}}
<div class="brand-bar"></div>
<table class="header-table">
  <tr>
    <td style="width: 58%;">
      @if (!empty($company['logo_data_uri']))
        <img class="company-logo" src="{{ $company['logo_data_uri'] }}" alt="Company logo">
      @endif
      @if (!empty($company['name']))
        <div class="company-title">{{ $company['name'] }}</div>
      @endif
      @if (!empty($company['tagline']))
        <div class="company-tagline">{{ $company['tagline'] }}</div>
      @endif
      <div class="company-sub">
        @if (!empty($company['address']))
          {{ $company['address'] }}<br>
        @endif
        @if (!empty($company['phone']) || !empty($company['email']))
          @if (!empty($company['phone'])) Tel: {{ $company['phone'] }} @endif
          @if (!empty($company['phone']) && !empty($company['email'])) &middot; @endif
          @if (!empty($company['email'])) {{ $company['email'] }} @endif
          <br>
        @endif
        @if (!empty($company['public_url']))
          {{ $company['public_url'] }}<br>
        @endif
        @if (!empty($company['tin']))
          TIN: {{ $company['tin'] }}
          @if (!empty($company['vat_status'])) &middot; {{ $company['vat_status'] }} @endif
          &middot;
        @endif
        @if (!empty($company['certification']))
          <span style="font-weight:bold; color:#4a4239;">{{ $company['certification'] }}</span>
        @endif
      </div>
    </td>
    <td style="width: 42%;" class="doc-badge-box">
      @if (!empty($docTitle))
        <div class="doc-title">{{ $docTitle }}</div>
      @endif
      <div class="doc-meta">
        Issued: <span class="val">{{ $generated['at_text'] ?? now()->format('M d, Y g:i A') }}</span><br>
        Issued By: <span class="val">{{ $generated['by'] ?? 'System' }}</span>
      </div>
    </td>
  </tr>
</table>
