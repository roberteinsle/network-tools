# Configuration

Configuration is split into two places: a few **environment variables** that the container needs to start, and everything else in the backend under **Settings**, so it can be changed without a redeploy.

## Environment variables

| Variable | Required | Description |
| --- | --- | --- |
| `APP_KEY` | yes | Encryption key. Generate with `php artisan key:generate --show`. It also encrypts the SMTP password and the Spamhaus DQS key in the database, so keep it safe and never change it on a running installation. |
| `APP_URL` | yes | Public URL of the backend, e.g. `https://monitor.example.com`. Used in login links and alert mails. |
| `DB_HOST` | yes | Internal hostname of the PostgreSQL resource (in Coolify: *Internal URL* of the database). |
| `DB_PORT` | no | Defaults to `5432`. |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | yes | Database credentials. |
| `APP_TAG` | no | Image tag to deploy, defaults to `latest`. |

The Coolify compose file sets the remaining values: German UI (`APP_LOCALE=de`), time zone `Europe/Berlin`, logging to stderr, database-backed sessions, cache and queue, and `DB_QUEUE_RETRY_AFTER=360`. The retry value **must stay above the job timeout of 300 seconds**, otherwise long checks run twice.

## Settings in the backend

### Email delivery

- **SMTP server:** host, port, encryption (STARTTLS, SSL/TLS on 465, or none), user name and password. The password is stored encrypted and is never shown again; leave the field empty to keep it.
- **Sender:** address and name of outgoing mails.
- **Send test mail:** sends a test message with the saved settings and shows the SMTP error if it fails.
- **Notifications:** recipients for all domains, and whether to also report when a problem has been fixed (on by default). More recipients can be added per customer and per domain.

Until SMTP is saved, mails go to the application log.

### Checks

| Setting | Default | Description |
| --- | --- | --- |
| Certificate: warning from (days) | 21 | TLS certificate expiry turns into a warning. |
| Certificate: error from (days) | 7 | TLS certificate expiry turns into an error. |
| Domain: warning from (days) | 30 | Domain registration expiry turns into a warning. |
| Domain: error from (days) | 7 | Domain registration expiry turns into an error. |
| Default interval for new domains | every 6 hours | Options: hourly, every 3, 6 or 12 hours, daily. |
| Keep logs (days) | 180 | Older check runs are deleted at night; the latest run of each domain is always kept. |

### DNS

- **Resolvers for checks:** defaults to `1.1.1.1`, `8.8.8.8` and `9.9.9.9`. They must validate DNSSEC (set the AD flag), otherwise the DNSSEC check is inaccurate.
- **Resolvers for blacklists:** empty means the system resolver from `/etc/resolv.conf`. Many RBLs block public resolvers such as 8.8.8.8, so use your own resolver (e.g. Unbound) here.

### Spamhaus Data Query Service (DQS)

Spamhaus is only queried through DQS with your own (free for low volume) key. With a key, Spamhaus ZEN (IPs) and DBL (domains) are added to the blacklist check automatically. The key is stored encrypted and can be removed with a toggle. The public Spamhaus zones are never queried and cannot be added as blacklists.
