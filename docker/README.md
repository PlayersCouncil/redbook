# Running Red Book with Docker

One image runs everything. `docker-compose.yml` starts the app and its database:

| Service | Container | What it does |
|---|---|---|
| `app` | `<project>` | Apache + PHP serving the site on `127.0.0.1:${APP_PORT}`, plus the scheduled jobs from `crontab` (run by supercronic alongside Apache; if either stops, the container restarts) |
| `db` | `<project>_db` | MariaDB 11.4, data in `data/mariadb/` |
| `init` | (one-off, same image) | First-time setup: schema, default data, cards, restricted lists, admin account |

`<project>` is `COMPOSE_PROJECT_NAME` from `.env` (default `redbook`). All commands below run from this `docker/` folder.

## First run

```bash
cp .env.example .env            # compose and database settings
cp app.env.example app.env      # the app's settings: APP_SECRET, mail, branding
chmod 600 .env app.env
# fill in both files (passwords, APP_SECRET, MAILER_DSN, optional ADMIN_*)

docker compose up -d --build
docker compose --profile init run --rm init
```

`init` fetches the card data ([lotrtcg-card-data](https://github.com/PlayersCouncil/lotrtcg-card-data), `CARD_DATA_REPO` at `CARD_DATA_REF`) into `data/carddata/` and imports it. The default data (which empties the database) is only loaded when there are no cards yet; set `INIT_FORCE=1` to wipe and reload on purpose. The card import runs every time and is safe to repeat, so to update the cards, change `CARD_DATA_REF` and run `init` again.

The site is then at `http://localhost:${APP_PORT}` if `APP_WEBSITE_URL` is `localhost`. In production the app redirects every other hostname to `APP_WEBSITE_URL`, so set it to the public hostname (and note that Spanish is served from `es.<hostname>`). It is only bound to localhost: put a reverse proxy in front for the public hostname (below).

## After a code change

The code is baked into the image, so a change means a rebuild:

```bash
git pull
docker compose up -d --build
docker exec -u www-data <project> php bin/console doctrine:migrations:migrate --no-interaction
```

Migrations do not run automatically in production; run them after any update that adds one. Logins survive a rebuild (sessions are kept in `data/sessions/`).

## Running console commands

From the host, as the web server user (so cache files keep the right owner):

```bash
docker exec -u www-data <project> php bin/console <command>
```

For example, `fos:user:promote <username> ROLE_ADMIN`. Red Book's own:

- `app:import:cards /srv/carddata`: re-import the card data (init does this; it fetches lotrtcg-card-data at `CARD_DATA_REF` first)
- `app:formats:refresh`: copy Gemp's formats now (the crontab does it hourly)
- `app:import:seed-decks`: import archived decks (phase3/06 batches). With no argument it imports every `*.json` in `data/seed-decks/` on the host (mounted read-only at `var/seed-decks`), one file after another: `docker exec -u www-data <project> php bin/console app:import:seed-decks`. It also takes a single file or folder inside the container, or `-` to read one batch from standard input. Re-running is safe: decks update in place and ones a historian has verified are left alone. `--dry-run` checks without saving, `--no-validate` skips Gemp
- `app:import:seed-libraries`: import seeded (official) libraries from manifests. With no argument it imports every `*.json` in `data/seed-libraries/` on the host (mounted read-only at `var/seed-libraries`): `docker exec -u www-data <project> php bin/console app:import:seed-libraries`. It also takes a file or folder inside the container, or `-` for standard input; `--dry-run` checks without saving. Import the decks the manifests name first. The format is in `docs/seed-libraries.md`.
- `app:account:organization <username> ["Display Name"]`: make an organization account (Decipher's, the PC's), which owns things but which nobody logs in as; admins edit its profile from its page. `--convert` turns an existing ordinary account into one (its login stops working). The PC's (`APP_OFFICIAL_ACCOUNT`) is made by itself the first time it's needed. See `docs/seed-libraries.md`.

Archived decks can be claimed by their authors; admins approve the requests at `/admin/claims`.

## Gemp

Deck validation, PC errata conversion and the formats come from Gemp's public JSON endpoints, at `GEMP_URL` (default: the test server, which has them; set it in `app.env` once the live server does). If Gemp can't be reached, decks still save and show as not checked. The browser tests use a stub instead (`tests/e2e/gemp-stub`).

## Mail

Mail goes out through Symfony Mailer, configured by `MAILER_DSN` in `app.env` (for example `smtp://user:password@smtp.example.com:587`). With `null://null` nothing is sent, which also means new accounts can't confirm their email. The bug report form (the boxed bug in the top bar) emails `APP_FEEDBACK_EMAIL` (comma-separated); left empty, it goes to the admins' own addresses, the newest twenty.

## Reverse proxy

The app trusts `X-Forwarded-For` and `X-Forwarded-Proto` from private addresses (`TRUSTED_PROXIES` in `docker-compose.yml`), so the proxy must send them. A minimal nginx site:

```nginx
server {
    server_name decks.example.com;
    # listen 443 ssl; ssl_certificate ...;

    location / {
        proxy_pass http://127.0.0.1:1300;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

## Development

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

(or uncomment `COMPOSE_FILE` in `.env`). This runs Symfony's dev environment with debug on, mounts `src/`, `templates/`, `translations/` and `config/` from the checkout so PHP and Twig edits show up without a rebuild, runs migrations on start, and sends all mail to Mailpit at `http://127.0.0.1:8025`. JavaScript and CSS changes still need `--build`.

## Browser tests

`docker-compose.e2e.yml` is an overlay for the Playwright tests in `tests/e2e/`: production environment, served as `localhost`, all mail caught by Mailpit. See `tests/e2e/README.md`.

## What lives on the host

| Path | Contents |
|---|---|
| `.env`, `app.env` | settings and secrets (never commit) |
| `data/mariadb/` | the database's data directory |
| `data/sessions/` | PHP sessions |
| `data/carddata/` | the card data repository used by `init` |
| `data/oauth/` | the OAuth2 signing key pair, created on first start |

Back up `data/mariadb/` (or a `mariadb-dump` from the `db` container), `data/oauth/` and the two settings files.

## OAuth2 clients

Apps that act on users' decks through `/api/oauth2/` are registered as OAuth2 clients:

```bash
docker exec -u www-data <project> php bin/console league:oauth2-server:create-client "App name" \
    --redirect-uri=https://app.example.com/callback --grant-type=authorization_code --grant-type=refresh_token \
    --scope=read_deck --scope=write_deck
```

`league:oauth2-server:list-clients`, `update-client` and `delete-client` manage them.

## Tuning

| Setting | Where | Default |
|---|---|---|
| `APACHE_MAX_WORKERS` | `app.env` | 10 Apache workers (each holds a PHP interpreter) |
| `APP_MEM_LIMIT`, `DB_MEM_LIMIT` | `.env` | 1g each |
| PHP settings | `php/app.ini` | memory_limit 256M, opcache on |

## Checks

`check.sh` runs everything a CI job would, from the repository root or anywhere else:

```bash
docker/check.sh             # build the images, then PHPUnit, lints, PHPStan, PHP_CodeSniffer
docker/check.sh --no-build  # same checks, reusing the images from the last run
```

`make check` does the same. It uses the Dockerfile's `test` stage (the app plus its dev dependencies). To run only PHPUnit:

```bash
docker build -f docker/Dockerfile --target test -t redbook_check:test ..
docker run --rm redbook_check:test
```
