# E-Book Library — PHP + SQLite

Plain PHP (no framework) + SQLite (PDO `pdo_sqlite`). Dev server: PHP built-in
server with `router.php`. Production: nginx + PHP-FPM (see `deploy/`).

## Requirements

- PHP >= 8.1 with `pdo_sqlite`, `sqlite3`, `fileinfo`, `mbstring`
  (`gd` optional — cover resizing is skipped gracefully without it)
- Windows: enable `extension=pdo_sqlite`, `extension=sqlite3` in `php.ini`
- Linux: `sudo apt install php-sqlite3 php-mbstring` (or `dnf`/`yum` equivalent)

## Run (Windows PowerShell / cmd)

```bat
serve.bat
REM or:  php serve.php --port=8080
REM then open http://127.0.0.1:8080
```

## Run (Linux)

```sh
./serve.sh
# or:  php serve.php --host=0.0.0.0 --port=8080
# then open http://127.0.0.1:8080
```

`serve.php` is the canonical launcher on both OSes (checks `pdo_sqlite`,
creates `uploads/` + `uploads/covers/`, seeds the DB on first run, then
starts `php -S` with `router.php`). `HOST`/`PORT` env vars are also honored.
Optional: `composer serve` (needs Composer).

> HTTPS: the PHP built-in server is HTTP-only. For TLS, terminate HTTPS at
> nginx with `deploy/nginx-ssl.conf` in front of PHP-FPM (production path).
> `serve-tls.sh` is a deprecated wrapper that prints this notice and starts
> the plain dev server.

## Demo accounts

| Email                         | Password     | Role      |
|-------------------------------|--------------|-----------|
| 403lzeus@gmail.com            | Admin@2026   | admin     |
| ookamivillavert@gmail.com     | Lib@2026!    | librarian |
| keanriedagumanpan@gmail.com   | View@er26    | viewer    |

## Maintenance

```sh
php seed.php                 # (re-)create schema + demo users, idempotent
php cleanup.php              # dry run: list orphaned files under uploads/
php cleanup.php --delete     # actually delete orphans (SQLite-based)
php maintenance.php          # archive old logs + VACUUM (see --help flags in file)
```

Schedule `maintenance.php` weekly — Linux: cron
(`0 3 * * 0 php /path/to/maintenance.php`); Windows: Task Scheduler → weekly
task running `php.exe maintenance.php` with “Start in” set to this folder.

## Production (Linux, nginx + PHP-FPM)

`deploy/nginx.conf` (HTTP) and `deploy/nginx-ssl.conf` (HTTPS, Let's Encrypt
paths) serve the app under `/ebook`, block `/config`, `/database`,
`/partials`, route `/api/*` and clean URLs, and pass PHP to PHP-FPM.
Uploads live in `uploads/` next to the code; back up `database/ebook.db`.

## Notes for contributors

- SQLite only — no MySQL dependency anywhere (the old `cleanup-uploads.sh`
  MySQL code is gone; `cleanup.php` is canonical).
- Paths use `__DIR__` + `DIRECTORY_SEPARATOR` (or `/`, which PHP accepts on
  Windows too); comparisons that must work on case-insensitive Windows FS
  normalize `\` → `/` and lowercase. Keep LF or CRLF consistent per file;
  PHP accepts both.
- Frontend `fetch` automatically sends the `X-CSRF-Token` header for
  non-GET requests (see `partials/head.php`); API clients must do the same.
