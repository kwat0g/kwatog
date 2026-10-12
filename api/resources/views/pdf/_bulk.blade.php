{{-- Sprint 8 — Task 76. Bulk PDF wrapper.

     Loops over $payloads, including the per-doc Blade for each. CSS
     page-break-after enforces a hard break between documents.
     Company/generator context is inherited by every @include automatically.
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'Helvetica Neue', 'Helvetica', Arial, 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1f1b16; }
        .doc-wrapper { page-break-after: always; }
        .doc-wrapper:last-child { page-break-after: auto; }
    </style>
</head>
<body>
    @foreach ($payloads as $payload)
        <div class="doc-wrapper">
            @include($view, $payload)
        </div>
    @endforeach
</body>
</html>
