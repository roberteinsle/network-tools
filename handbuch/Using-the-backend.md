# Using the backend

The backend is built with Filament and is called **Network-Tools**. Its interface is in German; this page uses English names with the German label in brackets where helpful.

Below the title, in smaller type, the backend shows the deployed build (only after login): the latest release number, the short commit hash and the commit date, for example `v1.0.0 · 19333b3 · 17.11.2026`. The image gets these values at build time (see [Operations](Operations.md#build-information)).

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
| Expected SRV services | SRV services that must exist, as `_service._protocol` (e.g. `_imaps._tcp`). A missing one is a warning; see [Checks](Checks.md#srv-services). |
| Check MX STARTTLS certificates | Needs outgoing port 25. Also enables the DANE certificate comparison. |
| Notify on IP changes | Off: IP changes of A/AAAA and PTR are only logged. |
| Disabled checks | Checks to skip for this domain. |
| Additional recipients | Alert recipients for this domain only. |
| Notes | Free text. |

The domain list shows status, number of problems, customer and the time of the last check, with filters for status, customer and active. By default it is sorted by customer, the customer with the most domains first, and within each customer by domain name A–Z; domains without a customer come last. Clicking a column header sorts by that column instead. **Check now** runs a check immediately, for one domain or as a bulk action.

The domain page shows the current result of every check and the history of its check runs. Results are sorted by severity: errors first, then warnings, not checkable, info and OK; within the same severity they keep the check order. The details of every check that is not OK are expanded, and their messages are sorted the same way. OK results stay collapsed. Check runs show results the same way. Every result that is not OK has a **KI-Empfehlung** button, and the header offers **KI-Copilot** and **Export für KI-Chat** (see [AI copilot](AI-copilot.md)).

## Tools

Four menu groups contain tools that query any name on demand. They store nothing and send no mail. Every tool has its own page at `/admin/tools/<tool>`; append `?q=<name>` to open it with the name filled in. Internationalized names are converted to Punycode. Answers come from the resolvers configured in the settings unless noted otherwise.

### Werkzeuge (DNS records)

| Tool | Input | Shows |
| --- | --- | --- |
| **MX-Lookup** | Domain | Mail servers sorted by priority (lower number is preferred) with their IPv4 and IPv6 addresses and the TTL. A null MX (`0 .`) is marked; without MX records, mail goes to the domain's A/AAAA record (implicit MX). |
| **IP** | Host name | IPv4 (A) and IPv6 (AAAA) addresses at every authoritative name server of the zone (asked directly, without recursion) and at the ten most widely used public resolvers: Google Public DNS, Cloudflare, Quad9, Cisco OpenDNS, AdGuard DNS, CleanBrowsing, Control D, Comodo Secure DNS, Lumen/Level 3 and Yandex DNS. Public resolvers may answer from their cache, so a recent change can show up there later. Notes show a `CNAME`, `NXDOMAIN` or a timeout. |
| **Nameserver** | Domain | The name servers responsible for the zone with their IPv4 and IPv6 addresses, and the SOA serial each server returns when asked directly. The status is an error if a server does not answer authoritatively. |
| **SOA** | Domain | Zone information: primary name server (MNAME), admin address (RNAME, shown as an email address), serial, refresh, retry, expire, minimum (negative TTL) and the TTL of the SOA, each with its meaning. For a subdomain, the SOA of its zone is shown. |
| **CNAME** | Host name | The alias target and the full CNAME chain (up to ten steps, loops are detected), then the IPv4 and IPv6 addresses of the final target. |
| **TXT** | Host name | All TXT records with their type (SPF, DMARC, DKIM, MTA-STS, TLS-RPT, BIMI, Google, Microsoft 365, Meta and Apple verification, other) and TTL. |
| **CAA** | Host name | Which certificate authorities may issue certificates (`issue`), wildcard certificates (`issuewild`) and where violations are reported (`iodef`). CAA is inherited: if the name itself has no CAA, the nearest parent name with CAA applies, and the page says so. Without any CAA, every CA may issue. |
| **SRV** | Domain, or a full SRV name such as `_sip._tls.example.com` | Runs the same SRV check as the monitoring (see [Checks](Checks.md#srv-services)): all known services (submission, IMAP, POP3, Autodiscover, SIP, CalDAV/CardDAV, XMPP) with priority, weight, port, target and its addresses, plus problems and **suggestions** for fixing them. Additional expected services can be entered; a full SRV name checks that service as expected. The result looks like a domain page, including the **KI-Empfehlung** button. |

### Mail-Sicherheit (mail security)

**SPF**, **DMARC**, **DKIM**, **MTA-STS**, **TLS-RPT** and **DANE** run exactly the same check as the monitoring (see [Checks](Checks.md)) for any domain, through `DomainChecker::evaluate()`. The result looks like the current state on a domain page, including the **KI-Empfehlung** button.

- **DKIM:** selectors cannot be read from DNS, so at least one provider (with its known selectors, e.g. Microsoft 365: `selector1`/`selector2`) or a custom selector is required.
- **DANE:** optionally compares the TLSA records with the STARTTLS certificates of the MX hosts (needs outgoing port 25).

### Reverse-DNS

**PTR** takes an IP address (IPv4 or IPv6) or a host name; for a host name, every address it resolves to is checked. For each IP it shows the reverse name (`…in-addr.arpa` / `…ip6.arpa`), the PTR records, the addresses the PTR names resolve to, and whether forward-confirmed reverse DNS (FCrDNS) holds. A missing PTR is an error, a PTR whose name does not resolve back to the IP a warning.

### Fehlersuche (troubleshooting)

| Tool | Input | Shows |
| --- | --- | --- |
| **Trace** | Host name | Resolution from the root servers downwards, like `dig +trace`: each level is asked without recursion for the A record and the referral is followed (glue from the answer, otherwise the configured resolver resolves the name server). One row per level with the server asked, the answer (referral with name servers, final records, NXDOMAIN or NODATA) and the response time. Up to three servers per level are tried. The row where it stops is marked as an error: no server of a level answers, a referral does not lead deeper, or the name servers' addresses cannot be resolved. An answer without the AA flag is flagged as lame delegation. |
| **DNSSEC** | Host name and record type (A, AAAA, MX, TXT, NS, SOA, DNSKEY, CAA) | The records and their signatures (RRSIG) from a query with the DO bit: covered type, algorithm, key tag, signer, valid from and until. Expired or not yet valid signatures are errors, signatures expiring within 72 hours warnings. The AD flag shows whether the resolver validated the answer. |
| **DS** | Domain | The DS records of the zone at the parent (key tag, algorithm, digest type, digest) and the DNSKEYs of the zone (key tag computed per RFC 4034, KSK or ZSK, flags, algorithm). Each DS must match a DNSKEY; a KSK without DS is a warning, a DS without matching DNSKEY an error. |

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
