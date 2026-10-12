{{--
    Report empty state: a clean, bordered "no rows" box instead of an empty
    table. $message should name the filters (e.g. "No records match these filters.").
--}}
<div class="empty-state">{{ $message ?? 'No records to display.' }}</div>
