# Operations

## How checks are scheduled

1. The `scheduler` service runs `domains:check-due` **every 5 minutes**. It queues every active domain whose next check time has passed.
2. The `worker` service picks up the jobs (`CheckDomainJob`) and runs all enabled checks. A job may take up to **300 seconds**; `DB_QUEUE_RETRY_AFTER` must be larger (360 in the compose file).
3. After the run, the next check time is set from the domain's interval, changes are detected and alerts are sent.

At **03:30** every night, `model:prune` deletes check runs older than the retention period (default 180 days). The latest run of each domain is kept.

## Console commands

| Command | Purpose |
| --- | --- |
| `php artisan domains:check [domain]` | Checks one domain (or all active ones) immediately and prints the results as a table. |
| `php artisan domains:check-due` | Queues all due domains (normally run by the scheduler). |
| `php artisan app:user <email> --name="…"` | Creates a user, or reuses an existing one, and prints a login link. |

In Coolify, run them in the terminal of the `app` container.

## Health checks

- `app`: HTTP `GET /up` on port 8080.
- `worker` and `scheduler`: the image's built-in queue and schedule health checks.

## Network notes

- The STARTTLS check of MX hosts and the DANE certificate comparison need **outgoing port 25**.
- The name server check queries each authoritative server directly over UDP/TCP port 53.
- Many RBLs block public resolvers. Run your own resolver (e.g. Unbound) and enter it under *Settings → DNS → Resolvers for blacklists*.
- The resolvers for the other checks must validate DNSSEC.

## Backups

All data is in PostgreSQL. Enable backups on the database resource in Coolify and keep `APP_KEY` safe: without it, the encrypted SMTP password and DQS key cannot be read.
