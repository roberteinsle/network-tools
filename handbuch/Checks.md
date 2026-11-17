# Checks

Every check run executes all enabled checks for one domain and stores one result per check. Each result has a **status**, a short summary, the details behind it and a fingerprint of the relevant records, so configuration changes can be detected even when the status stays the same.

| Status | Meaning |
| --- | --- |
| OK | Everything as expected. |
| Info | Worth knowing, not a problem (e.g. no BIMI set up). |
| Warning | Should be fixed. |
| Error | Broken or insecure. |
| Not checkable | Technically impossible right now (timeout, resolver problem). Never triggers an alert. |

The status of a domain is the worst status of its checks. Checks can be switched off per domain (*Domains → Edit → Advanced → Disabled checks*).

## MX

- Reads the MX records. A null MX (RFC 7505) is reported as info: the domain deliberately receives no mail.
- Error if an MX points to an IP address instead of a host name, or if a MX host has no A/AAAA record.
- Warning if an MX host is a CNAME (forbidden by RFC 2181).
- Info if there is only one MX host (normal for hosted services such as Microsoft 365).

## A / AAAA

- Checks the domain and `www.` by default, or the web hosts entered on the domain.
- Info if a host has no address or no IPv6.
- Warning if a host points to a private or reserved address.
- IP changes are only logged by default (CDNs and Microsoft 365 rotate addresses); see [Notifications](Notifications.md).

## PTR (reverse DNS)

- Checks the IPs of all MX hosts plus the outgoing mail server IPs entered on the domain.
- Error if an outgoing IP has no PTR record (many receivers reject such mail); warning for MX IPs without PTR.
- Verifies forward-confirmed reverse DNS (FCrDNS): the PTR name must resolve back to the IP. Warning if not, or if there are several PTR records.

## SPF

- Error for several SPF records (PermError), for `+all`, and when the **10 DNS lookup limit** is exceeded.
- Lookups are counted recursively through `include`, `a`, `mx`, `ptr`, `exists` and `redirect`. Warning when the count gets close to the limit.
- Void lookups (queries without a result) are counted too: warning for any, error above the limit of 2.
- Warning for `?all` or a missing `all` without `redirect`. `~all` and `-all` are OK.
- Info for very long records that may exceed 512 bytes in a DNS answer.

## DKIM

- DKIM selectors cannot be listed from DNS, so the check uses the known selectors of the providers picked on the customer or the domain, plus custom selectors:

| Provider | Selectors |
| --- | --- |
| Microsoft 365 | `selector1`, `selector2` |
| Google Workspace | `google` |
| Mailchimp / Mandrill | `k1`, `k2`, `k3` |
| SendGrid | `s1`, `s2` |
| Mailjet | `mailjet` |
| Brevo | `brevo1`, `brevo2`, `mail` |
| HubSpot | `hs1`, `hs2` |
| Zoho Mail | `zmail`, `zoho` |
| Mailgun | `mx`, `smtp`, `mailo` |
| IONOS | `s1-ionos`, `s2-ionos` |
| Generic | `default`, `dkim`, `mail` |

- Providers with random selectors (Amazon SES, Postmark, your own mail server) are covered by **custom selectors** on the domain.
- Error if no valid key is found for a provider. Key length: error below 1024 bit RSA, warning below 2048 bit.
- Info for Microsoft 365 if only one of the two rotation selectors is active.
- Info if no provider and no selector is configured.

## DMARC

- Error if there is no record at `_dmarc.<domain>`, several records, a missing or invalid `p=`, or invalid alignment values.
- `p=reject` and `p=quarantine` are OK; `p=none` is a warning (monitoring only).
- Warning for `sp=none`, for `pct` below 100 and for missing aggregate reports (`rua=`).
- For report receivers on another domain, the check verifies the authorization record (`<domain>._report._dmarc.<receiver>`); warning if it is missing, because reports would never arrive.

## MTA-STS

- Optional: info if not set up.
- Checks the `_mta-sts` TXT record (exactly one, with `id=`) and fetches the policy from `https://mta-sts.<domain>/.well-known/mta-sts.txt` (no redirects allowed, `version: STSv1`, served as `text/plain`).
- Mode `enforce` is OK, `testing` is info, `none` a warning.
- Warning for a very short `max_age` (below one week).
- Error if the policy has no `mx` entries or if an actual MX host is not covered by the policy (mail to that host would be rejected).

## TLS-RPT

- Optional, but a warning if MTA-STS is active without TLS-RPT.
- Error for several records, missing `rua=` or invalid report targets (`mailto:` or `https:` expected).

## BIMI

- Optional: info if not set up.
- Error if DMARC is not `p=quarantine` or `p=reject` with `pct=100`.
- Fetches the logo: must be HTTPS and SVG. Warning if it is not SVG Tiny PS or larger than 32 KB.
- Info without a VMC/CMC certificate (`a=`), because Gmail and Apple then do not show the logo.

## DNSSEC

- Checks the DS record at the parent, the DNSKEYs in the zone and validation via the AD flag of the configured resolvers.
- Warning if the domain is unsigned, or signed without a DS at the registrar (no chain of trust).
- Error for SERVFAIL (likely a bogus signature: the domain is unreachable for validating resolvers) or a DS without DNSKEYs.
- Warning for deprecated algorithms.

## Nameservers (NS / SOA)

- **Delegation at the parent:** a server of the parent zone (for `example.com` the `.com` servers) is asked directly which name servers the registry has on file, including glue records.
  - Error if the registry does not know the domain (NXDOMAIN: not registered, deleted or suspended) and if a name server inside the domain (e.g. `ns1.example.com`) has no glue.
  - Warning if the delegation differs from the NS records in the zone, or if a glue address differs from the name server's address in DNS.
  - *Not checkable* if no server of the parent zone answers.
- **Response times** of every name server are shown in the details. They fluctuate, so they never change the status or trigger a mail.
- Error if the domain has no NS records, a name server has no address, or a name server does not answer authoritatively for the zone (lame delegation, including REFUSED and SERVFAIL).
- Warning for a single name server (RFC 2182 asks for at least two), and for a server that does not answer while the others do. If no server answers at all, the result is *not checkable*.
- Each name server is queried directly, preferring IPv4. The SOA serial must be the same everywhere: a warning if it differs, but only info when the servers have different primaries (deliberate multi-provider setups such as NS1 plus Route 53).
- Warning if a server lists other name servers in the zone than the delegation.
- Info if all name servers are in the same IPv4 /24 network.
- SOA values: warning if `retry` is not smaller than `refresh` or `expire` is not larger than `refresh + retry`; info if `expire` is below one week or the negative TTL (`minimum`) above one day.
- The serial itself is not part of the change detection, because it changes with every zone edit.

## CAA

- Optional: info if there is no CAA record (every certificate authority may issue).
- Looked up like a CA does: at the domain first, then at the parent names (without the TLD). An inherited record is shown in the details.
- OK lists the allowed CAs (`issue`); the `issuewild` rule is shown in the details.
- Warning if `issue ";"` forbids all CAs (certificates can no longer be renewed) and for invalid `iodef` targets.
- Error for an unknown tag with the critical flag (128): CAs must then refuse to issue.

## TLS certificate (HTTPS)

- Connects to the web hosts (domain and `www.` by default) and to `mta-sts.<domain>` if MTA-STS is published.
- Error if HTTPS is unreachable, the certificate is not trusted or does not match the host name, or it expires within the error threshold; warning within the warning threshold (see [Configuration](Configuration.md)).
- Warning for outdated TLS protocol versions.

## HTTP → HTTPS / HSTS

- For each web host with an address (domain and `www.` by default): requests `http://<host>/` and follows up to six redirects, including relative ones.
- OK if the chain ends on `https://`; warning if HTTP is served without a redirect to HTTPS. Info if port 80 is closed (HTTPS-only is fine).
- Reads the `Strict-Transport-Security` header of `https://<host>/` itself (not of a redirect target):
  - warning if it is missing or `max-age=0`,
  - info if `max-age` is below one year,
  - info if `preload` is set without `includeSubDomains` or a one-year `max-age`, which the preload list requires.
- Certificate errors are ignored here, because the HTTPS certificate check reports them.

## TLS certificate (MX / STARTTLS)

- Optional per domain, because it needs outgoing port 25.
- Connects to every MX host on port 25, runs STARTTLS and checks trust, host name and expiry like the HTTPS check.
- Error if a host does not offer STARTTLS.

## DANE (TLSA)

- Looks up `_25._tcp.<mx>` TLSA records for every MX host (RFC 7672). Optional: info if no MX host has TLSA.
- Warning if the TLSA records or the domain's MX records are not DNSSEC-validated (senders then ignore DANE), and if some MX hosts have TLSA and others do not.
- Warning for usage 0 or 1 (PKIX), which RFC 7672 does not intend for SMTP; `3 1 1` with `2 1 1` as a fallback is recommended. Error for invalid parameters.
- If *Check MX STARTTLS certificates* is enabled on the domain (needs outgoing port 25), the certificate of each MX is compared with its TLSA records: usage 3 against the server certificate, usage 2 against the chain. Error if no record matches or the host offers no STARTTLS, because DANE-validating senders will not deliver mail then. The STARTTLS connection is shared with the MX certificate check.
- If the TLSA query fails for a host (Exchange Online answers with SERVFAIL), it is reported as *not checkable* only if other hosts use DANE.

## Domain expiry

- Queries the registry via RDAP (IANA bootstrap).
- Error if the domain has expired, has an inactive status, or expires within the error threshold; warning within the warning threshold.
- Info if the TLD has no RDAP service or the registry does not publish an expiry date (e.g. DENIC for `.de`), and if no transfer lock is set.

## Blacklists

- Checks the MX IPs and outgoing IPs against IP lists, and the domain against domain lists.
- Only a small set of free RBLs, maintainable under **Blacklists**:

| List | Type | Enabled by default |
| --- | --- | --- |
| SpamCop | IP | yes |
| PSBL | IP | yes |
| Mailspike | IP | yes |
| UCEPROTECT Level 1 | IP | yes |
| Barracuda BRBL | IP | no (the querying resolver must be registered with Barracuda) |
| SURBL multi | domain | yes |
| URIBL multi | domain | yes |

- Spamhaus ZEN and DBL are added automatically when a DQS key is saved (see [Configuration](Configuration.md)).
- A listing is an error. Answers that mean "query blocked" (e.g. `127.0.0.1` from domain lists, `127.255.255.x` from Spamhaus) are reported as *not checkable*, not as listed.
