# Snip

A self-hosted URL shortener written in plain PHP. No framework, no Composer,
no `vendor/` directory — the only things it needs are PHP 8.1 and PDO.

Paste a long link, get a short one, and read the click log for it afterwards
at the same address with a `+` on the end.

```
https://snip.example.com/k7Wd2xq     the link you share
https://snip.example.com/k7Wd2xq+    the clicks it got
```

## What it does

- Random 7-character codes, or your own custom name (`/spring-sale`)
- Optional expiry: 1 day, 7 days, 30 days, a year, or never
- Click log per link: totals, unique visitors, a 14-day chart, top referrers
- JSON API for creating and reading links, with optional bearer-token auth
- SQLite by default (nothing to install) or MySQL by changing one config value
- Rate limiting per visitor, CSRF protection on the form, and a URL validator
  that refuses anything but public `http`/`https` destinations

## Requirements

- PHP 8.1 or newer
- `pdo_sqlite` (default) or `pdo_mysql`
- Apache with `mod_rewrite`, or Nginx, or just PHP's built-in server

## Quick start

```bash
git clone https://github.com/<you>/snip.git
cd snip
php -S localhost:8000 -t public public/index.php
```

Open <http://localhost:8000>. The SQLite file and its tables are created on the
first request, so there is no install step and no migration command to run.

For anything beyond local use, copy the config and edit it:

```bash
cp config.example.php config.php
```

At minimum set `base_url` and change `hash_key` to a long random string.

### Using MySQL instead

```bash
mysql -u root -p -e "CREATE DATABASE snip CHARACTER SET utf8mb4"
mysql -u root -p snip < database/schema.mysql.sql
```

Then set `db.driver` to `mysql` and fill in the credentials in `config.php`.

The `links` table uses the `utf8mb4_bin` collation on purpose: short codes are
case sensitive, so `/aB3` and `/Ab3` have to be two different links.

## Configuration

| Key                   | Default   | What it does                                              |
| --------------------- | --------- | --------------------------------------------------------- |
| `base_url`            | `null`    | Public URL of the installation. Auto-detected when unset.  |
| `db.driver`           | `sqlite`  | `sqlite` or `mysql`.                                       |
| `code_length`         | `7`       | Characters in a generated code.                            |
| `rate_limit_per_hour` | `30`      | Links one visitor may create per hour. `0` disables it.    |
| `block_private_hosts` | `true`    | Refuse destinations on private or loopback addresses.      |
| `hash_key`            | —         | Secret used to hash visitor IPs. Change it.                |
| `api_token`           | `''`      | Set it to require `Authorization: Bearer …` on the API.    |
| `trust_proxy`         | `false`   | Believe `X-Forwarded-For` and `X-Forwarded-Proto`.         |
| `debug`               | `false`   | Show exceptions in the browser. Local use only.            |

## HTTP API

Create a link:

```bash
curl -X POST https://snip.example.com/api/links \
  -H 'Content-Type: application/json' \
  -d '{"url": "https://example.com/a/very/long/path", "expires_in_days": 30}'
```

```json
{
  "code": "k7Wd2xq",
  "short_url": "https://snip.example.com/k7Wd2xq",
  "stats_url": "https://snip.example.com/k7Wd2xq+",
  "target_url": "https://example.com/a/very/long/path",
  "expires_at": "2026-10-12 09:15:00"
}
```

Read one back:

```bash
curl https://snip.example.com/api/links/k7Wd2xq
```

| Method | Path                | Result                                      |
| ------ | ------------------- | ------------------------------------------- |
| `GET`  | `/{code}`           | 302 to the destination, click recorded       |
| `GET`  | `/{code}+`          | HTML stats page                              |
| `POST` | `/api/links`        | 201 with the new link, 422 if it is rejected |
| `GET`  | `/api/links/{code}` | 200 with the link and its click summary      |
| `GET`  | `/health`           | 200 with a link count, for uptime checks     |

## How it works

**Short codes** are drawn at random from a 57-character alphabet rather than
derived from the row id, so nobody can tell how many links exist or walk
through them by incrementing a number. `0`, `O`, `1`, `l` and `I` are left out
so a code can be read over the phone. A unique index on `code` settles any
collision; the generator simply draws again.

**Clicks** are written to a `clicks` row and the counter on `links` is bumped
in the same transaction, so the number on the stats page always matches the
rows behind it. Redirects are `302`, not `301` — a permanent redirect gets
cached by the browser and the second click never reaches the server.

**Visitors** are counted by an HMAC of their IP address, keyed with
`hash_key`. The raw address is never stored, and changing the key resets
unique-visitor counts.

**Destinations** go through `UrlValidator`, which rejects everything that is
not `http` or `https`, strips control characters that could smuggle a second
header into the redirect, refuses URLs with credentials in the host (the
classic `https://paypal.com:x@evil.example` trick), blocks links back to the
shortener itself, and — unless you turn it off — refuses hosts that resolve to
private or loopback addresses.

## Project layout

```
public/          document root: index.php (front controller) and assets
src/             application classes, autoloaded under the App namespace
  Config.php         configuration access
  Database.php       PDO factory, first-run migration
  Router.php         pattern matching for {code} routes
  Request.php        path, method, client IP, base URL
  UrlValidator.php   destination rules
  CodeGenerator.php  short codes and alias rules
  LinkRepository.php every SQL query in the project
  Link.php           the link itself
  View.php           template rendering and escaping
  Csrf.php  Http.php
views/           plain PHP templates
database/        schema for SQLite and MySQL
bin/test.php     the test suite
storage/         SQLite file lives here (git-ignored)
```

## Tests

```bash
php bin/test.php
```

Covers code generation, alias and URL validation, the redirect and stats
routing, click counting, and expiry — against a temporary SQLite database that
is deleted afterwards. CI runs the same suite plus `php -l` on PHP 8.1, 8.2
and 8.3.

## Deploying

Point the document root at `public/`. The bundled `public/.htaccess` handles
Apache. For Nginx:

```nginx
server {
    listen 80;
    server_name snip.example.com;
    root /var/www/snip/public;
    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
}
```

If your host will not let you move the document root, the `.htaccess` in the
project root forwards everything into `public/` instead.

## Things left to build

- A management token per link so the creator can edit or delete it
- QR codes for the short URL
- A cleanup command for expired links
- Bulk import from CSV

## Licence

MIT. See [LICENSE](LICENSE).
