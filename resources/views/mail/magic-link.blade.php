<x-mail::message>
# Anmelden

Klicke auf den Button, um dich bei {{ config('app.name') }} anzumelden.

<x-mail::button :url="$url">
Jetzt anmelden
</x-mail::button>

Der Link ist {{ $minutes }} Minuten gültig und funktioniert genau einmal.
Wenn du keinen Link angefordert hast, kannst du diese E-Mail ignorieren.
</x-mail::message>
