# About network-tools

## Tech stack

- PHP 8.5, Laravel 13, Filament 5
- PostgreSQL in production, SQLite for local development and tests
- `laravel/passkeys` for passkey login, `anthropic-ai/sdk` for Claude in the AI copilot, `pear/net_dns2` for DNS queries (including DNSSEC and the AD flag)
- Docker image built with GitHub Actions and published to GitHub Container Registry, deployed with Coolify
- Tests with PHPUnit

## Architecture in short

- Each check is a class in `app/Checks` that implements the common `Check` interface and returns a `CheckOutcome` (status, messages, details, fingerprint).
- All checks are registered in `App\Services\CheckRegistry`. A new check is added there and in the `CheckType` enum; nothing else calls a check directly.
- AI providers are registered in `App\Services\Ai\AiProviderRegistry` (one client per provider); `App\Services\Copilot\Copilot` builds the prompt and caches answers.
- `DomainChecker::evaluate()` runs the registered checks without storing anything; `run()` adds storage, change detection and alerts.
- Results several checks need (e.g. the STARTTLS certificate for the MX and DANE checks) are shared through `CheckContext::cached()` within one run.
- The tools in the menu (*Werkzeuge*, *Mail-Sicherheit*, *Reverse-DNS*, *Fehlersuche*) are registered in the `App\Enums\DnsTool` enum, which also builds the menu entries. One page (`App\Filament\Pages\Tool`) serves all of them; `App\Services\Lookup\ToolRunner` runs a tool: mail security tools and SRV through the registered checks (`DomainChecker::evaluate()`), the others through the lookup services in `App\Services\Lookup`. All direct server queries go through `App\Support\Dns\NameserverProbe`.
- The public resolvers of the IP tool are registered in the `App\Enums\PublicResolver` enum, the services the SRV check looks up in `App\Enums\SrvService`.
- DNS providers recognized by their name servers are registered in the `App\Enums\DnsProvider` enum.
- Who gets an alert is decided in `Domain::recipientsFor()`; the customer's level is the `App\Enums\NotifyLevel` enum.
- `DomainChecker` runs the registered checks for a domain, `ChangeDetector` compares the run with the previous one, and `AlertNotifier` sends the mail.

## Documentation

This handbook (`handbuch/` in the repository) is the source of the [wiki](https://github.com/roberteinsle/network-tools/wiki). The [website](https://network-tools.einsle.app) gives an overview.

## Contributing

Ideas, feature requests and bug reports are welcome as [issues](https://github.com/roberteinsle/network-tools/issues).
