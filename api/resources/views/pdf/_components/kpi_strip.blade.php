{{--
    KPI strip for reports: up to 4 headline figures above grouped sections.
    $kpis: array of ['label' => string, 'value' => string].
--}}
@if (!empty($kpis))
  <table class="kpi-strip">
    <tr>
      @foreach (array_slice($kpis, 0, 4) as $kpi)
        <td>
          <div class="kpi-val mono">{{ $kpi['value'] }}</div>
          <div class="kpi-lbl">{{ $kpi['label'] }}</div>
        </td>
      @endforeach
    </tr>
  </table>
@endif
