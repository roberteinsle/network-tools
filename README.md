# Domain-Monitor

Überwacht eigene Domains automatisch, protokolliert jede Prüfung und meldet Probleme und Änderungen per E-Mail.
Laravel 13 + Filament 5 auf PHP 8.5. Gebaut und ausgerollt nach dem Standard GitHub → GHCR → Coolify.

## Prüfungen

| Prüfung | Was geprüft wird |
| --- | --- |
| MX | Records, Null-MX, A/AAAA der MX-Hosts, CNAME-/IP-Fehler |
| A / AAAA | Domain und `www.` (oder eigene Web-Hosts), private Adressen |
| PTR | Reverse DNS der MX-IPs und gepflegten ausgehenden IPs inkl. FCrDNS |
| SPF | Syntax, mehrere Records, `all`-Qualifier, **Zähler für das 10-Lookup-Limit** (rekursiv inkl. redirect) und Void-Lookups |
| DKIM | Bekannte Selektoren je Anbieter (M365, Google, Mailchimp, SendGrid …) am Kunden oder an der Domain, eigene Selektoren, Schlüssellänge |
| DMARC | Richtlinie, pct, sp, rua inkl. Autorisierung externer Report-Empfänger |
| MTA-STS | TXT, Policy per HTTPS, Modus, Abgleich mit den MX-Hosts |
| TLS-RPT | Record und Report-Ziele |
| BIMI | Record, SVG-Logo (Tiny PS), DMARC-Voraussetzung, VMC |
| DNSSEC | DS beim Registrar, DNSKEY, Validierung über das AD-Flag |
| TLS-Zertifikat | HTTPS (Domain, www, mta-sts) und optional MX-STARTTLS (Port 25): Vertrauen, Hostname, Ablauf |
| Domain-Ablauf | RDAP (IANA-Bootstrap). Registries ohne RDAP-Ablaufdatum (z. B. DENIC/.de) werden als Hinweis gemeldet |
| Blacklists | Kleine Auswahl kostenloser RBLs (im Backend pflegbar). **Spamhaus nur über DQS** mit eigenem Schlüssel; die öffentlichen Spamhaus-Zonen lassen sich gar nicht erst eintragen |

## Benachrichtigungen

- Eine E-Mail geht raus, wenn sich der Status einer Prüfung ändert (schlechter oder behoben) oder sich die Konfiguration ändert (z. B. neuer SPF-Record oder ein anderes Zertifikat).
- Die erste Prüfung einer Domain meldet nur vorhandene Probleme.
- **Nicht prüfbar** (Timeout, Resolver-Problem) löst nie eine Mail aus, damit Netzprobleme keine Fehlalarme erzeugen.
- IP-Wechsel bei A/AAAA und PTR (CDN, M365) werden nur protokolliert. Pro Domain lässt sich das umschalten.
- Empfänger sind die globalen Adressen aus den Einstellungen plus Adressen je Kunde und je Domain.
- SMTP-Zugang, Empfänger, Schwellwerte, Resolver und der Spamhaus-DQS-Schlüssel werden im Backend gepflegt. Passwort und Schlüssel liegen mit `APP_KEY` verschlüsselt in der Datenbank.

## Betrieb

- `scheduler` führt alle 5 Minuten `domains:check-due` aus. Fällige Domains (Intervall je Domain) landen in der Queue.
- `worker` führt die Prüfungen aus (`CheckDomainJob`, Timeout 300 s). `DB_QUEUE_RETRY_AFTER` muss größer als 300 sein.
- `model:prune` löscht nachts Protokolle, die älter als die eingestellte Aufbewahrung sind. Der letzte Lauf jeder Domain bleibt erhalten.
- `php artisan domains:check [domain]` prüft sofort und gibt das Ergebnis als Tabelle aus.

## Anmeldung

Anmeldung per Passkey (laravel/passkeys) oder als Ausweichweg per Magic Link (15 Minuten gültig, einmalig nutzbar). Passwörter gibt es nicht.
Den ersten Benutzer legst du per Konsole an. Der Befehl gibt direkt einen Anmeldelink aus, auch ohne SMTP:

```bash
php artisan app:user du@example.com --name="Dein Name"
```

Danach unter *Einstellungen → Passkeys* einen Passkey einrichten und unter *Einstellungen* den SMTP-Server hinterlegen.

## Entwicklung

```bash
docker compose up -d --build
docker compose exec app php artisan app:user du@example.com
php artisan test
```

## Deployment (Coolify)

1. Push auf `main` → GitHub Action baut `ghcr.io/roberteinsle/network-tools:latest`.
2. In Coolify eine PostgreSQL-Datenbank als eigene Ressource anlegen (Backups dort aktivieren).
3. *Docker Compose (Empty)* anlegen und `deploy/docker-compose.coolify.yml` einfügen.
4. Variablen setzen: `APP_KEY` (`php artisan key:generate --show`), `APP_URL`, `DB_HOST` (interner Hostname der Datenbank-Ressource), `DB_PORT` (Standard 5432), `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
5. Am Service `app` die Domain mit Port `8080` eintragen und deployen. Danach `php artisan app:user …` im Container-Terminal ausführen.

Hinweise:
- Für die MX-STARTTLS-Prüfung muss ausgehender Port 25 erlaubt sein.
- Viele RBLs sperren öffentliche Resolver. Am besten einen eigenen Resolver (z. B. Unbound) unter *Einstellungen → Resolver für Blacklists* eintragen.
