{{-- Sprint 8 — Task 76. Bulk PDF wrapper.

     Loops over $payloads, including the per-doc Blade for each. CSS
     page-break-after enforces a hard break between documents.
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; }
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
