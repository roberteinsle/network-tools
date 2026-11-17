# Installation

network-tools (the app is called **Domain-Monitor**) ships as a ready-made Docker image, `ghcr.io/roberteinsle/network-tools`, and needs a PostgreSQL database. The recommended way to run it is [Coolify](https://coolify.io), but any Docker host works.

## Requirements

- A small Linux server (1–2 CPU cores and 2 GB RAM are plenty for a few hundred domains).
- A domain or subdomain for the backend, with HTTPS (Coolify handles the certificate).
- PostgreSQL (in Coolify: a separate database resource with backups enabled).
- An SMTP mailbox for sending alerts. It is configured later in the backend, not via environment variables.
- **Outgoing port 25** if you want the optional STARTTLS check of MX hosts and the DANE certificate comparison. Many cloud providers block it by default.
- Ideally your own recursive resolver (e.g. Unbound) for the blacklist check, because many RBLs refuse queries from public resolvers.

## Deploying with Coolify

1. Every push to `main` builds `ghcr.io/roberteinsle/network-tools:latest` via GitHub Actions. Tagged versions are published as well.
2. In Coolify, create a **PostgreSQL** database as its own resource and enable backups there.
3. Create a **Docker Compose (Empty)** resource and paste [`deploy/docker-compose.coolify.yml`](../deploy/docker-compose.coolify.yml).
4. Set the variables (see [Configuration](Configuration.md)): `APP_KEY`, `APP_URL`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
5. On the `app` service, enter your domain with port `8080` and deploy.
6. Open the terminal of the `app` container in Coolify and create the first user (see [First steps](#first-steps)).

The compose file starts three services from the same image:

| Service | Purpose |
| --- | --- |
| `app` | Web backend on port 8080. Runs the database migrations on start. |
| `worker` | Runs the domain checks from the queue (`queue:work`). |
| `scheduler` | Queues due domains every 5 minutes and prunes old logs at night (`schedule:work`). |

`worker` and `scheduler` only start once `app` is healthy, so migrations are always finished first.

## First steps

There are no passwords. Create the first user on the console; the command prints a one-time login link right away, so it works even before SMTP is configured:

```bash
php artisan app:user you@example.com --name="Your Name"
```

Then, in the backend:

1. **Settings → Passkeys:** register a passkey for future logins.
2. **Settings → Email delivery:** enter the SMTP server and the sender address, then use **Send test mail**.
3. **Settings → Email delivery → Notifications:** add the global alert recipients.
4. **Customers:** optionally create customers and pick their DKIM providers.
5. **Domains:** add your domains. The first check runs within five minutes, or immediately via **Check now**.

## Updates

Pull a new image tag (or `latest`) in Coolify and redeploy. Database migrations run automatically when `app` starts. Take a database backup before every update.

## Local development

The repository includes a dev container (PHP 8.5, Node 22, Docker-in-Docker) that also works in GitHub Codespaces.

```bash
docker compose up -d --build
docker compose exec app php artisan app:user you@example.com
php artisan test
```

Without SMTP, mails are written to the log (`MAIL_MAILER=log`).
