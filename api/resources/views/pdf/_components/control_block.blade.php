{{--
    Document-control block for controlled/quality documents (IATF forms, CoC,
    8D). Render only when the company uses control metadata; every cell hides
    gracefully when empty.
--}}
@if (!empty($formNo) || !empty($revision) || !empty($effectiveDate))
  <div class="control-block">
    @if (!empty($formNo))<span class="lbl">Form:</span> <span class="mono">{{ $formNo }}</span>&nbsp;&nbsp;@endif
    @if (!empty($revision))<span class="lbl">Rev:</span> <span class="mono">{{ $revision }}</span>&nbsp;&nbsp;@endif
    @if (!empty($effectiveDate))<span class="lbl">Effective:</span> <span class="mono">{{ $effectiveDate }}</span>@endif
  </div>
@endif
