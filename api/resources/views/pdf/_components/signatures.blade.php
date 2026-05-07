{{--
    Series E (E1) shared signature block. Pass `$signatures` as an array of
    ['label' => string, 'name' => string|null, 'date' => string|null].
    Falls back to a 4-level approval block (Staff → Dept Head → Manager → VP).
--}}
@php
    $signatures = $signatures ?? [
        ['label' => 'Prepared by',  'name' => null, 'date' => null],
        ['label' => 'Checked by',   'name' => null, 'date' => null],
        ['label' => 'Approved by',  'name' => null, 'date' => null],
        ['label' => 'Authorized by','name' => null, 'date' => null],
    ];
@endphp

<div class="signatures" style="display:table; width:100%; margin-top:32px;">
  @foreach ($signatures as $sig)
    <div class="sig" style="display:table-cell; width:{{ (int) (100 / count($signatures)) }}%; padding:0 8px; vertical-align:top;">
      <div style="height:32px;"></div>
      <div class="line" style="border-top:1px solid #555; padding-top:4px;
           font-size:8px; color:#555; text-align:center;">
        {{ $sig['label'] }}
        @if (!empty($sig['name']))
          <br><span style="color:#09090B; font-weight:bold;">{{ $sig['name'] }}</span>
        @endif
        @if (!empty($sig['date']))
          <br><span style="font-size:7px;">{{ $sig['date'] }}</span>
        @endif
      </div>
    </div>
  @endforeach
</div>
