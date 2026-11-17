# Login and security

## Login without passwords

network-tools has no passwords.

- **Passkey** (recommended): register one or more passkeys under *Settings → Passkeys* (fingerprint, face ID, security key or password manager). Afterwards, log in with a single click.
- **Magic link** (fallback): enter your email on the login page and you receive a link. It is valid for **15 minutes** and works only once.
- The first user is created on the console with `php artisan app:user`, which prints a login link directly, so no SMTP is needed for the first login.

## Data protection

- network-tools runs on your own server. It only contacts the DNS resolvers, RDAP servers, blacklists and hosts that the checks need. There is no telemetry.
- The SMTP password and the Spamhaus DQS key are stored encrypted with `APP_KEY` and never shown again in the backend.
- Spamhaus is only used through DQS with your own key, in line with the Spamhaus terms of use.

## Reporting vulnerabilities

Please report security issues privately through [GitHub security advisories](https://github.com/roberteinsle/network-tools/security/advisories/new) instead of a public issue.
