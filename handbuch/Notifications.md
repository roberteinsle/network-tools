# Notifications

network-tools sends an email when something actually changes, not on every check run.

## When a mail is sent

After each run, the results are compared with the previous run of the same domain, check by check:

| Change | Mail |
| --- | --- |
| **Worse:** status got more severe (e.g. OK → warning) | yes |
| **Fixed:** a warning or error is gone | yes, unless *Also report when a problem has been fixed* is off in the settings |
| **Status changed:** other status changes (e.g. info → OK) | yes |
| **Configuration changed:** same status, but different records (e.g. a new SPF record or another certificate) | yes |

Special cases:

- The **first run** of a new domain only reports existing warnings and errors.
- **Not checkable** (timeout, resolver problem) never triggers a mail, neither when it appears nor when it goes away. Network hiccups do not create false alarms.
- **IP changes** of A/AAAA and PTR (CDNs, Microsoft 365) are only logged. Enable *Notify on IP changes* on a domain to get mails for them.

One mail per domain and run lists all changes, with old and new status and the summaries.

## Recipients

Recipients are combined and de-duplicated from three levels:

1. **Settings → Email delivery → Notifications:** recipients for all domains.
2. **Customer:** addresses that get alerts for all domains of this customer, depending on the customer's mail level (see below).
3. **Domain:** additional recipients for this domain only.

Global and domain recipients get every alert mail.

### Customer mail level

On each customer, *E-Mail an diese Adressen bei* decides whether the customer's addresses are included in an alert mail:

| Level | German label | The customer's addresses get the mail when … |
| --- | --- | --- |
| All changes | *Alle Änderungen* | any change is reported, including changed records without a problem. Default, also for existing customers. |
| From info | *Ab Hinweis* | a check changes to info, warning or error, or returns from one of them. |
| From warning | *Ab Warnung* | a check changes to warning or error, or returns from one of them. |
| Errors only | *Nur Fehler* | a check changes to error, or an error is fixed. |
| No mails | *Keine E-Mails* | never. The addresses stay stored on the customer. |

For the first check of a domain, the level is compared with the current results. *Not checkable* never counts, in line with the rule that it never triggers an alert. The mail itself is the same for all recipients.

If no recipient is configured for a domain, the mail is skipped and the reason is logged. If sending fails, the error is logged and visible on the check run.

## Delivery

Mails are sent through the SMTP server from the settings (see [Configuration](Configuration.md)). Until it is configured, mails are written to the application log.
