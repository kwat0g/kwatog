{{--
    Phase 2 (PDF foundation) stamp layer. Renders only when $watermark is set.
    The text is driven by REAL document state in the service layer, never by a
    manual toggle: DRAFT (unapproved), VOID/CANCELLED, PAID, REPRINT/DUPLICATE
    COPY, CONFIDENTIAL (PdfRenderService sets CONFIDENTIAL automatically when
    the confidential flag is on). Diagonal, light, behind content.
--}}
@if (!empty($watermark))
  <div class="watermark"
       style="position:fixed; top:38%; left:8%; right:8%;
              font-size:96px; color:#E4E4E7;
              transform:rotate(-30deg);
              text-align:center; font-weight:bold;
              letter-spacing:12px; z-index:-1;
              opacity:0.55;">
    {{ $watermark }}
  </div>
@endif
