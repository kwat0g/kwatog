{{--
    Totals block. Values arrive PRE-FORMATTED from the same service that owns
    the numbers (never recomputed in the template). Optional $amountInWords
    prints the Philippine peso/centavo phrasing for money documents.

    $rows: array of ['label' => string, 'value' => string, 'grand' => bool].
--}}
<div class="totals-container">
  <table class="totals-box">
    @foreach ($rows as $row)
      <tr @if (!empty($row['grand'])) class="grand-total" @endif>
        <td class="lbl">{{ $row['label'] }}:</td>
        <td class="val mono">{{ $row['value'] }}</td>
      </tr>
    @endforeach
  </table>
  @if (!empty($amountInWords))
    <div class="amount-words">{{ $amountInWords }}</div>
  @endif
</div>
