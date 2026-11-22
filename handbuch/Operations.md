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

## Build information

The backend shows the deployed build next to its title: release number, commit and commit date. The GitHub Action fills them in at build time and passes them to the image as build arguments:

| Build argument / env | Value |
| --- | --- |
| `APP_VERSION` | Latest Git tag reachable from the commit (`git describe --tags --abbrev=0`), e.g. `v1.0.0`. Empty if there is no tag yet. |
| `APP_COMMIT` | Short commit hash (7 characters). |
| `APP_COMMIT_DATE` | Commit date in ISO 8601, shown as `dd.mm.yyyy` in the app's time zone. |

The checkout in the workflow uses `fetch-depth: 0` so the tags are available. In local development without these variables, the values are read from the Git repository if `git` is installed.

## Network notes

- The STARTTLS check of MX hosts and the DANE certificate comparison need **outgoing port 25**.
- The name server check and the DNS lookup query each authoritative server directly over UDP/TCP port 53. The DNS lookup also asks ten public resolvers directly; outgoing port 53 to them must be allowed.
- Many RBLs block public resolvers. Run your own resolver (e.g. Unbound) and enter it under *Settings → DNS → Resolvers for blacklists*.
- The resolvers for the other checks must validate DNSSEC.

## Backups

All data is in PostgreSQL. Enable backups on the database resource in Coolify and keep `APP_KEY` safe: without it, the encrypted SMTP password and DQS key cannot be read.
