<x-mail::message>
# Weekly Operations Digest

**Period**: {{ $stats['period_start'] }} → {{ $stats['period_end'] }}

## Activity this week
- New documents catalogued: **{{ $stats['documents_added_this_week'] }}**
- Total documents in archive: **{{ $stats['documents_total'] }}**

## Pending disinfestation
@if ($stats['pending_disinfestation_over_30d'] > 0)
**{{ $stats['pending_disinfestation_over_30d'] }} documents** are waiting more than 30 days for disinfestation.

<x-mail::button :url="config('app.url') . '/admin/reports/pending-disinfestation'">
Review pending disinfestation
</x-mail::button>
@else
No documents older than 30 days are waiting for disinfestation. 👌
@endif

## Open document flags
@if (! empty($stats['flags_open_by_severity']))
<x-mail::table>
| Severity | Open count |
| -------- | ---------: |
@foreach ($stats['flags_open_by_severity'] as $severity => $count)
| {{ ucfirst($severity) }} | {{ $count }} |
@endforeach
</x-mail::table>

<x-mail::button :url="config('app.url') . '/admin/document-flags'">
Open the flags board
</x-mail::button>
@else
No open flags this week. ✅
@endif

---

This digest is generated automatically every Monday at 08:00 by the
`nra:send-weekly-digest` artisan command. To stop receiving it, ask
an admin to demote your account from the `admin` role.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
