<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Anmelden – {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; --bg:#f4f4f5; --card:#fff; --fg:#18181b; --muted:#52525b; --btn:#d97706; }
        @media (prefers-color-scheme: dark) { :root { --bg:#09090b; --card:#18181b; --fg:#fafafa; --muted:#a1a1aa; } }
        body { margin:0; min-height:100vh; display:grid; place-items:center; background:var(--bg); color:var(--fg); font:16px/1.5 system-ui, sans-serif; padding:16px; box-sizing:border-box; }
        .card { background:var(--card); border-radius:12px; padding:32px; max-width:400px; width:100%; box-shadow:0 1px 3px rgba(0,0,0,.1); text-align:center; }
        h1 { font-size:1.25rem; margin:0 0 8px; }
        p { color:var(--muted); margin:0 0 24px; }
        button, a.btn { display:inline-block; background:var(--btn); color:#fff; border:0; border-radius:8px; padding:10px 20px; font-size:1rem; cursor:pointer; text-decoration:none; }
    </style>
</head>
<body>
<main class="card">
    @if ($valid)
        <h1>Anmelden bei {{ config('app.name') }}</h1>
        <p>Bestätige die Anmeldung mit einem Klick.</p>
        <form method="POST" action="{{ $action }}">
            @csrf
            <button type="submit" autofocus>Jetzt anmelden</button>
        </form>
    @else
        <h1>Link ungültig</h1>
        <p>Der Anmeldelink ist abgelaufen oder wurde bereits verwendet.</p>
        <a class="btn" href="{{ \Filament\Facades\Filament::getLoginUrl() }}">Neuen Link anfordern</a>
    @endif
</main>
</body>
</html>
