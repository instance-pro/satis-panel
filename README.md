# Satis Panel

A small web UI around [composer/satis](https://github.com/composer/satis) for running a
private Composer repository on [Coolify](https://coolify.io) (or any Docker host).
Composer package `instance-pro/satis-panel`.

It is built for a plain Docker deployment: the build output, the basic auth for the
package files, the admin UI and the webhooks all live in one container, so no reverse
proxy labels are needed.

**What the UI does**

* Login for one admin account (`ADMIN_USER` / `ADMIN_PASSWORD`).
* Repositories: add, edit and remove the entries of `satis.json`.
* Configuration: name, homepage, require options, stability and the full
  **archive** block (dist mirroring).
* Composer access: users (HTTP basic auth) and access tokens (`Authorization: Bearer`)
  for `packages.json`, metadata and dist files. Users are stored as bcrypt hashes in an
  htpasswd file that nginx reads on every request, tokens in `tokens.json`; the plain
  values are kept in the config volume (mode 600) so they can be shown and copied in
  the UI together with the matching `composer config` command.
* Source access: generate or import an SSH deploy key, show the public key to register
  at Bitbucket/GitHub/GitLab, manage `known_hosts` for self-hosted servers, and keep
  Composer credentials for HTTPS sources (GitHub/GitLab tokens, Bitbucket OAuth,
  basic auth) in Composer's `auth.json`.
* Build: run `satis build` (full or per repository) in the background with live log.
* Webhook: `POST /webhook/<secret>` rebuilds only the pushed repository
  (GitHub, GitLab, Gitea, Bitbucket, Azure DevOps payloads).

Everything else is Satis itself: the app writes `satis.json`, validates it against
the Satis JSON schema and calls `vendor/bin/satis build`.

## Stack

* PHP 8.5 (php-fpm) + nginx in one container, Symfony 7.4, Vite + Tailwind CSS 4
* `composer/satis` from `dev-main`
* No database, all state lives in files on volumes

## Deploy on Coolify

1. Create a **Docker Compose** resource (or a Git repository with build pack
   *Docker Compose*) pointing at this repository.
2. Set the domain of the `panel` service. Coolify fills `SERVICE_FQDN_PANEL_80`
   and routes the domain to port 80.
3. Set `ADMIN_PASSWORD` under environment variables.
4. Deploy, open `https://<domain>/admin`, log in. Check the homepage under
   *Configuration* (taken from the Coolify domain on first start).
5. *Source access*: generate an SSH key and register the public key at your git
   hosting, or store a token for HTTPS sources.
6. *Repositories*: add your packages. *Configuration*: enable archives if Composer
   clients should download dist files from Satis instead of from git.
7. *Composer users*: create a user for your CI / developers.
8. *Build*: run the first full build. The dashboard shows the webhook URL to register
   for automatic partial builds on push.

### Deployments without downtime

With the Docker Compose resource Coolify stops the running stack, then starts the new
one; the proxy answers 502 for the few seconds until the new container is healthy
(the compose file uses `start_interval` so this is short). Coolify does rolling
updates only for single-container applications. If even that window matters, deploy
the image without the compose file:

1. Create a **Redis** database resource in Coolify (any version 6+) and note its
   internal URL.
2. Create an application from this repository with build pack **Dockerfile**, port
   `80`, and enable the health check (the image carries a `HEALTHCHECK` on `/login`).
3. Environment variables: `ADMIN_PASSWORD`, `REDIS_URL` (the internal Redis URL,
   e.g. `redis://:<password>@<redis-host>:6379`), optionally `TZ`.
4. Persistent storage: add named volumes for `/data/config`, `/data/output`,
   `/var/www/html/var`, `/var/www/.composer` and `/var/www/.ssh` (see *Volumes* below).
5. Deploy. The homepage is taken from Coolify's `COOLIFY_URL` on first start.

Coolify then starts the new container next to the old one and switches over once
it is healthy. Both containers share the volumes; the build lock and the Redis
queue are designed for that.

Composer clients:

```json
{
    "repositories": [
        { "type": "composer", "url": "https://satis.example.com" }
    ]
}
```

```
composer config http-basic.satis.example.com <user> <password>
```

or, with an access token instead of a user:

```
composer config bearer.satis.example.com <token>
```

Both write `auth.json` next to the project's `composer.json` (keep it out of git).
With `--global` the credentials go to the Composer home of the user instead, which
containers such as ddev lose on restart.

## Environment variables

Set in Coolify (or `.env` for local docker compose):

| Variable | Default | Description |
|---|---|---|
| `ADMIN_USER` | `admin` | Web UI user. |
| `ADMIN_PASSWORD` | required | Web UI password, plain text or bcrypt/argon2 hash. Empty disables the login. |
| `BUILD_TIMEOUT` | `1800` | Abort a satis build after this many seconds, `0` for no limit. |
| `TZ` | `UTC` | Timezone for times shown in the UI, build and webhook logs, e.g. `Europe/Berlin`. |
| `SERVICE_PASSWORD_REDIS` | generated by Coolify | Redis password used by both services. Locally set it in `.env`. |

Generated on first start and kept in the config volume, no need to set them: `APP_SECRET`
(Symfony) and `WEBHOOK_SECRET` (shown on the dashboard). Both can still be given
explicitly as environment variables.

Rarely needed, supported by the image but not listed in `docker-compose.yaml`:

| Variable | Default | Description |
|---|---|---|
| `REDIS_URL` | set in compose | Redis connection, `redis://:<password>@redis:6379`. Empty disables the webhook log and the build queue. |
| `SATIS_HOMEPAGE` | Coolify domain | Homepage written into `satis.json` on first start; afterwards edit it under *Configuration*. |
| `COMPOSER_AUTH` | empty | Composer auth JSON, merged over the credentials managed under *Source access*. |
| `SATIS_AUTH_DISABLED` | `0` | `1` serves the package files without authentication. |
| `TRUSTED_PROXIES` | private networks | Proxies whose `X-Forwarded-*` headers are trusted. |
| `APP_ENV` / `APP_DEBUG` | `prod` / `0` | Symfony environment. |

## Volumes

| Volume | Path | Content |
|---|---|---|
| `satis-panel-config` | `/data/config` | `satis.json`, `htpasswd`, `composer-users.json`, `tokens.json`, `webhooks.json`, optional `auth.json`, generated secrets |
| `satis-panel-output` | `/data/output` | Satis build output (`packages.json`, `p2/`, `include/`, `dist/`, `index.html`) |
| `satis-panel-var` | `/var/www/html/var` | Sessions, logs, build state and log |
| `satis-panel-composer` | `/var/www/.composer` | Composer home and cache |
| `satis-panel-ssh` | `/var/www/.ssh` | Deploy key, `config`, `known_hosts` |
| `satis-panel-redis` | `/data` (redis) | Webhook request log |

The entrypoint pins `output-dir` in `satis.json` to `/data/output`, the
directory nginx serves.

## Package index page

Satis renders `index.html` (the page Composer users see at `/`) from
`satis/index.html.twig`, a self-contained template in the same style as the admin UI
with filter, dark mode and a click-to-copy `composer require` snippet. The entrypoint
sets `twig-template` in `satis.json` to that file when the key is missing. Point it at
your own template, or at `/var/www/html/vendor/composer/satis/views/index.html.twig`
for the Satis default.

## Routing

nginx serves the build output (`/`, `/packages.json`, `/p/`, `/p2/`, `/include/`,
`/dist/`) straight from the output volume. Access is granted with basic auth (Composer
users) or, via `auth_request`, with a logged-in admin session, so the admin can browse
the package index without a Composer user. Everything else goes to the
Symfony app: `/login`, `/admin/...` (session login) and `/webhook/<secret>` (no auth
besides the secret).

## Webhook

```
POST https://satis.example.com/webhook/<WEBHOOK_SECRET>
```

Register it as push webhook with a JSON payload. Every repository URL in the payload is
matched against `satis.json` (scheme, credentials, `.git` and case are ignored) and
`satis build --repository-url=<url>` runs for the matches. Responses:

| Code | Meaning |
|---|---|
| 202 | build started, body lists the repositories |
| 400 | no repository URL in the payload |
| 404 | wrong secret, or no configured repository matches |
| 403 | the repository has a webhook secret and the signature is missing or wrong |
| 409 | a build is already running (only without Redis, see the build queue) |

Manual triggers: `?url=<repository url>` for one repository, `?full=1` for a full build.

### Build queue

With Redis configured (default in `docker-compose.yaml`) builds from webhooks, the
*Build* page and `satis-panel-build --queue` go into a Redis list and a worker process
in the container runs them one after another. Equivalent jobs are merged and a full
build replaces waiting partial builds, so a burst of pushes never triggers more builds
than needed and nothing is refused with 409. The worker is restarted automatically if
it exits, unfinished jobs are moved back to the queue on start, and a build is aborted
after `BUILD_TIMEOUT` seconds (default 1800). The *Build* page shows the queue and the
worker heartbeat.

### Signed requests

Each repository can have its own webhook secret (repository form, *Generate* creates
a random one). Enter the same value as secret in the webhook settings of the provider.
Requests for such a repository are only accepted with a valid signature, otherwise
the endpoint answers 403:

| Provider | Header checked |
|---|---|
| Bitbucket Cloud / Server | `X-Hub-Signature: sha256=<hmac>` |
| GitHub | `X-Hub-Signature-256: sha256=<hmac>` |
| Gitea | `X-Gitea-Signature: <hmac>` |
| GitLab | `X-Gitlab-Token: <secret>` |

The secrets live in `webhooks.json` next to `satis.json`, not inside it. Repositories
without a secret keep working with the URL secret alone.

### Request log

The last webhook requests are kept in Redis (`redis` service in `docker-compose.yaml`,
password protected with `SERVICE_PASSWORD_REDIS`, which Coolify generates automatically)
and shown under *Webhooks* in the UI: time, source, detected and matched repository
URLs, the request payload (up to 64 KB) and the response. How many requests are kept can
be chosen there (default 100, 10 per page). The app reads `REDIS_URL`; without it the
log is simply off and the webhook still works.

## Command line

```
docker compose exec panel satis-panel-build                       # full build now
docker compose exec panel satis-panel-build --queue               # full build via the queue (cron / Coolify scheduled task)
docker compose exec panel satis-panel-build --repository-url=<url>
docker compose exec panel satis-panel-htpasswd <user> <password>  # add/update a Composer user
docker compose exec -u www-data panel php bin/console app:user:remove <user>
```

## Scheduled full rebuilds

Webhooks only rebuild the pushed repository. For a nightly full rebuild add a Coolify
**Scheduled Task** (or a cron job) on the `panel` service with

```
satis-panel-build --queue
```

It puts a full build into the queue; without `--queue` it builds immediately.

## Local development

```
cp .env.example .env                       # compose values, set ADMIN_PASSWORD
printf 'services:\n  panel:\n    ports:\n      - "8000:80"\n' > docker-compose.override.yml
docker compose up --build
```

Then open http://localhost:8000/admin. Both files are git-ignored. Note that `.env` is the
docker compose file here; the Symfony defaults are in `.env.dist`, which Symfony loads
when no `.env` exists (the `.env` is excluded from the image).

Without Docker: PHP 8.5 with `ext-zip`, `composer install`, `npm install && npm run build`
(Node 20+), then `symfony serve` or `php -S localhost:8000 -t public`. In `dev` the
data files live below `var/satis-panel/` and the login is `admin` / `admin` (`.env.dev`).
Note that outside the container nothing protects the build output; the basic auth is
done by nginx.

## Project layout

```
assets/           Vite entry (app.js) and Tailwind CSS
config/           Symfony configuration
docker/           nginx template, php-fpm settings, entrypoint and helper scripts
src/Auth          htpasswd management
src/Satis         satis.json access, form models, build runner
src/Ssh           deploy key and known_hosts management
src/Webhook       payload parsing
templates/        Twig templates
```

## License

MIT, see [LICENSE](LICENSE).
