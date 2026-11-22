# Using the backend

The backend is built with Filament and is called **Network-Tools**. Its interface is in German; this page uses English names with the German label in brackets where helpful.

Next to the title, in smaller type, the backend shows the deployed build: the latest release number, the short commit hash and the commit date, for example `v1.0.0 · 19333b3 · 17.11.2026`. The image gets these values at build time (see [Operations](Operations.md#build-information)).

## Dashboard

- **Status overview:** number of domains per status.
- **Problem domains:** all domains with warnings or errors.
- **Recent changes:** the latest detected changes across all domains.
- A hint is shown until you have registered a passkey.

## Customers (Kunden)

Customers group domains, which is useful for agencies and IT service providers.

- **Name**, **contact email** and **notes**.
- **Alert recipients** (*Benachrichtigungen an*): get mails for all domains of the customer, in addition to the global recipients.
- **Mail level** (*E-Mail an diese Adressen bei*): from which status the customer's addresses get a mail: all changes, from info, from warning, errors only, or no mails. See [Notifications](Notifications.md#customer-mail-level).
- **DKIM providers:** the mail services the customer uses. Domains inherit them by default.
- The customer page lists its domains.

## Domains

| Field | Description |
| --- | --- |
| Domain name | Normalized automatically: lower case, no trailing dot, internationalized names as Punycode. |
| Customer | Optional. A new customer can be created right from the field. |
| Active | Only active domains are checked automatically. |
| Check interval | Hourly, every 3, 6 or 12 hours, or daily. New domains use the default from the settings. |
| DKIM | Inherit providers from the customer, or pick providers for this domain; plus custom selectors. |
| Web hosts | Hosts for the A/AAAA and HTTPS certificate checks. Empty means the domain and `www.`. |
| Outgoing mail server IPs | Checked for PTR and blacklists in addition to the MX IPs. |
| Check MX STARTTLS certificates | Needs outgoing port 25. Also enables the DANE certificate comparison. |
| Notify on IP changes | Off: IP changes of A/AAAA and PTR are only logged. |
| Disabled checks | Checks to skip for this domain. |
| Additional recipients | Alert recipients for this domain only. |
| Notes | Free text. |

The domain list shows status, number of problems, customer and the time of the last check, with filters for status, customer and active. **Check now** runs a check immediately, for one domain or as a bulk action.

The domain page shows the current result of every check and the history of its check runs. Results are sorted by severity: errors first, then warnings, not checkable, info and OK; within the same severity they keep the check order. The details of every check that is not OK are expanded, and their messages are sorted the same way. OK results stay collapsed. Check runs show results the same way. Every result that is not OK has a **KI-Empfehlung** button, and the header offers **KI-Copilot** and **Export für KI-Chat** (see [AI copilot](AI-copilot.md)).

## DNS lookup (Werkzeuge → DNS-Lookup)

Shows which IP addresses a host name resolves to, and nothing else. Nothing is stored and no mail is sent.

- **Host name:** any host name, e.g. `www.example.com` (no `http://`, no path). Internationalized names are converted to Punycode.
- **Authoritative name servers:** the zone of the host name is found by walking up its labels until a name with NS records is reached. Every name server of that zone is asked directly (without recursion). The table shows each name server with the address that was asked, the IPv4 (A) and IPv6 (AAAA) addresses it returns, and a note such as `CNAME → target`, `NXDOMAIN` or a timeout.
- **Public DNS resolvers:** the same question goes to the ten most widely used public resolvers (Google Public DNS, Cloudflare, Quad9, Cisco OpenDNS, AdGuard DNS, CleanBrowsing, Control D, Comodo Secure DNS, Lumen/Level 3, Yandex DNS). Their answers may come from their cache, so a recent change can show up here later than at the name servers.
- The page can be opened with a host name filled in: `/admin/dns-lookup?host=www.example.com`.

## Check runs (Prüfläufe)

The log of all runs: time, domain, overall status, number of detected changes, whether a mail was sent, trigger (scheduled or manual) and duration. Filters show only runs with changes or with a mail. Each run can be opened to see the full results as they were at that time. Runs older than the retention period are deleted at night; the latest run of each domain is always kept.

## Blacklists

The RBLs used by the blacklist check. Each entry has a name, zone, type (IP or domain), an info link, a description and can be enabled or disabled. Public Spamhaus zones are rejected; use the DQS key in the settings instead. See [Checks](Checks.md#blacklists).

## Users (Benutzer)

All users have the same rights. Add users with name and email; they log in with a magic link and then register a passkey. See [Login and security](Login-and-security.md).

## AI models (Einstellungen → KI-Modelle)

AI models for the copilot: provider, model ID and API key. See [AI copilot](AI-copilot.md).

## Settings (Einstellungen)

SMTP, recipients, thresholds, interval, retention, resolvers and the Spamhaus DQS key. See [Configuration](Configuration.md).
