<x-mail::message>
# {{ $domain->name }}

@if ($domain->customer)
Kunde: **{{ $domain->customer->name }}**
@endif

Gesamtstatus: **{{ $run->status->emoji() }} {{ $run->status->getLabel() }}** · geprüft am {{ $run->finished_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}

@if (count($changeRows))
## Änderungen seit der letzten Prüfung

@foreach ($changeRows as $c)
**{{ $c['label'] }}** – {{ $c['kind'] }}@if ($c['from']) ({{ $c['from']->emoji() }} {{ $c['from']->getLabel() }} → {{ $c['to']->emoji() }} {{ $c['to']->getLabel() }})@endif

@if ($c['from_summary'] && $c['from_summary'] !== $c['to_summary'])
- vorher: {{ $c['from_summary'] }}
@endif
- jetzt: {{ $c['to_summary'] }}

@endforeach
@endif

@if (count($problems))
## Offene Probleme

@foreach ($problems as $p)
- {{ $p['status']->emoji() }} **{{ $p['label'] }}:** {{ $p['summary'] }}
@endforeach
@else
Aktuell keine offenen Probleme.
@endif

<x-mail::button :url="$url">
Details ansehen
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
