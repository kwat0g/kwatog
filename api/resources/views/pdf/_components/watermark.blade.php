{{--
    Series E (E1) confidential watermark. Renders only when
    $confidential === true. Diagonal 30°, 8% opacity gray.
--}}
@if (!empty($confidential))
  <div class="watermark"
       style="position:fixed; top:38%; left:8%; right:8%;
              font-size:96px; color:#E4E4E7;
              transform:rotate(-30deg);
              text-align:center; font-weight:bold;
              letter-spacing:12px; z-index:-1;
              opacity:0.55;">
    {{ $watermark ?? 'CONFIDENTIAL' }}
  </div>
@endif
