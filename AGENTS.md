<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

# Project: network-tools (Domain-Monitor)

Self-hosted domain monitor: Laravel 13 + Filament 5 on PHP 8.5, PostgreSQL in production, SQLite for tests. The backend UI is German; all public documents (README, handbuch/, wiki, website, CLAUDE.md) are English.

## Architecture

- Each check is a class in `app/Checks` implementing `App\Checks\Check` (`type()`, `run(CheckContext): CheckOutcome`).
- Checks are registered only in `App\Services\CheckRegistry` (plus a case in `App\Enums\CheckType`). `DomainChecker` runs whatever the registry returns; `ChangeDetector` compares runs; `AlertNotifier` sends mails. New features plug in through the registries, never through direct calls between checks or services.
- `DomainChecker::evaluate(Domain, ?types)` runs the registry without persisting; `run()` adds storage, change detection and alerts. Tools like the DNS check page (`App\Filament\Pages\DnsCheck`, group *Werkzeuge*) use `evaluate()` on an unsaved `Domain`.
- Results several checks need (e.g. the STARTTLS certificate for `TlsMxCheck` and `DaneCheck`) go through `CheckContext::cached()`; the registry order decides who fetches first. Direct queries to authoritative name servers go through `App\Support\Dns\NameserverProbe` so tests can fake them.
- AI copilot: providers are registered only in `App\Services\Ai\AiProviderRegistry` (provider in `App\Enums\AiProvider` + client implementing `AiClient`); Anthropic goes through the official `anthropic-ai/sdk`. `App\Services\Copilot\CopilotPrompt` builds prompt and export from result arrays, `Copilot` caches answers, `App\Livewire\Copilot` is embedded via `ResultSchema::copilot()` / `copilotActions()`. Render model output only through `Str::markdown` with `html_input => escape`.
- `scheduler` runs `domains:check-due` every 5 minutes, `worker` runs `CheckDomainJob` (timeout 300 s, `DB_QUEUE_RETRY_AFTER` must be larger), `model:prune` runs nightly.
- Settings live in the single-row `app_settings` table (`AppSetting::current()`); secrets are encrypted with `APP_KEY`.

## Rules

- No personal data of the maintainer in the repository (code, docs, handbuch/, wiki, commits, issues).
- Spamhaus only via DQS with the user's key; public Spamhaus zones are never queried.
- "Not checkable" (`CheckStatus::Unknown`) must never trigger an alert.

## Documentation

- `handbuch/<Page>.md`: full details, one file per wiki page; the source of the wiki. Link other pages as `Page.md#anchor`, repository files as `../path`.
- `README.md`: short overview; only update for a new check/module or a changed quick start.
- `docs/`: website at https://network-tools.einsle.app (GitHub Pages via `.github/workflows/pages.yml`), English, overview of all features, keep SEO/GEO metadata (`index.html` JSON-LD, `llms.txt`, `sitemap.xml`) in sync. `docs/og.png` (1200×630) is the social preview.
- Wiki: never edit by hand. Regenerate with `python3 scripts/build-wiki.py <dir>`, copy the output into a clone of `network-tools.wiki.git` and push.
