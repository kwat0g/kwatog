{{--
    Document title block: scannable number, status badge from the REAL status
    enum value, issue date, and links to related documents.

    Required: $docNumber (string). Optional:
      $docStatus (raw enum value or string; humanized + uppercased here —
        pass the model value, never a rewritten label),
      $docStatusVariant (success|warning|danger|info; default info),
      $docDateLabel / $docDate, $references (array of strings).
--}}
<table class="doc-head">
  <tr>
    <td>
      <div class="doc-number mono">{{ $docNumber }}</div>
      @if (!empty($references))
        <div class="doc-refs">Refs:
          @foreach ($references as $ref)<span class="val mono">{{ $ref }}</span>@if (!$loop->last), @endif
          @endforeach
        </div>
      @endif
    </td>
    <td style="text-align:right;">
      @if (!empty($docStatus))
        <span class="chip chip-{{ $docStatusVariant ?? 'info' }}">{{ str_replace('_', ' ', strtoupper((string) $docStatus)) }}</span>
      @endif
      @if (!empty($docDate))
        <div class="doc-refs">{{ $docDateLabel ?? 'Date' }}: <span class="val mono">{{ $docDate }}</span></div>
      @endif
    </td>
  </tr>
</table>
