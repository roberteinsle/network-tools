# Using the backend

The backend is built with Filament. Its interface is in German; this page uses English names with the German label in brackets where helpful.

## Dashboard

- **Status overview:** number of domains per status.
- **Problem domains:** all domains with warnings or errors.
- **Recent changes:** the latest detected changes across all domains.
- A hint is shown until you have registered a passkey.

## Customers (Kunden)

Customers group domains, which is useful for agencies and IT service providers.

- **Name**, **contact email** and **notes**.
- **Alert recipients** (*Benachrichtigungen an*): get mails for all domains of the customer, in addition to the global recipients.
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

The domain page shows the current result of every check with all details, and the history of its check runs.

## DNS check (Werkzeuge → DNS-Check)

Checks any domain right away, without adding it. It uses exactly the same checks as the monitoring, but **nothing is stored and no mail is sent**.

- **Domain name:** any domain, written like on the domain form (no `http://`, no path).
- **Checks:** the DNS checks are preselected: name servers, DNSSEC, CAA, MX, A/AAAA, PTR, SPF and DMARC. Any other check can be added; certificates, HTTP and blacklists take a few seconds longer.
- **Options:** DKIM providers and custom selectors, web hosts, and whether port 25 may be used (STARTTLS certificates and the DANE comparison).
- The result looks like the current state on a domain page: one row per check with status, summary and details.
- **Monitor** opens the form for a new domain with the name filled in. It is hidden if the domain is already monitored.
- The page can be opened with a domain filled in: `/admin/dns-check?domain=example.com`.

## Check runs (Prüfläufe)

The log of all runs: time, domain, overall status, number of detected changes, whether a mail was sent, trigger (scheduled or manual) and duration. Filters show only runs with changes or with a mail. Each run can be opened to see the full results as they were at that time. Runs older than the retention period are deleted at night; the latest run of each domain is always kept.

## Blacklists

The RBLs used by the blacklist check. Each entry has a name, zone, type (IP or domain), an info link, a description and can be enabled or disabled. Public Spamhaus zones are rejected; use the DQS key in the settings instead. See [Checks](Checks.md#blacklists).

## Users (Benutzer)

All users have the same rights. Add users with name and email; they log in with a magic link and then register a passkey. See [Login and security](Login-and-security.md).

## Settings (Einstellungen)

SMTP, recipients, thresholds, interval, retention, resolvers and the Spamhaus DQS key. See [Configuration](Configuration.md).
