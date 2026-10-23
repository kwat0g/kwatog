<x-mail::message :preheader="$preheader" :title="$title">
# {{ $title }}

Hello,

The file for your saved export schedule is attached. It contains the columns and filters selected for that schedule.

| Export detail | Value |
|---|---|
| Report | {{ $exportName }} |
| Area | {{ $moduleLabel }} |
| Generated | {{ $generatedAt ?? 'See the timestamp in the attached filename' }} |
| File | {{ $filename }} |
| Format | {{ $formatLabel }} |

<x-mail::button :url="$scheduleUrl">
View export schedule
</x-mail::button>

If you did not create this schedule, contact your system administrator.
</x-mail::message>
