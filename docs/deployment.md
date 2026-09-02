# Deployment

Phase 1. One Laravel app serves the public site, the donor portal, the delegate
PWA and three Filament panels.

> Deploying to Cloudways? Use [`deployment-cloudways.md`](deployment-cloudways.md),
> which matches that panel step by step. This page is the platform-neutral version.

## Requirements

- PHP 8.3+ with `redis`, `gd`, `intl`, `zip`, `sodium`, and `pdo_mysql` or `pdo_pgsql`
- **MySQL 8.4+, MariaDB 10.11+, or PostgreSQL 14+** — the suite runs green on
  MySQL/MariaDB and PostgreSQL, and `scripts/basket-race-check.php` passes on both
- Redis 6+ (queues and cache)
- Node 20+ (to build assets; can be done off the server)
- Optional: an S3-compatible bucket, **private**, for media. Without one, media
  falls back to a private local disk.

## Deploy

```bash
git clone <repo> && cd sanabel
composer install --no-dev --optimize-autoloader
npm ci && npm run build

cp .env.example .env
php artisan key:generate
# set APP_ENV=production, APP_DEBUG=false, APP_URL, DB_*, REDIS_*, AWS_*, MAIL_*

php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\DatabaseSeeder --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Point the web root at `public/`. Nothing outside `public/` is web-reachable.

### Long-running processes

```
php artisan queue:work redis --tries=3 --max-time=3600
php artisan schedule:work
```

Run both under a supervisor. The schedule releases expired basket reservations
every five minutes — without it, a family stays held after its hold ends.

### Storage

`SANABEL_MEDIA_DISK` selects where media lives:

- `media_local` (default) — a private directory under `storage/app/private/media`,
  outside the web root, served only through signed expiring URLs. Back it up with
  the database; `scripts/backup.sh` does this automatically.
- `media` — an S3-compatible private bucket. The bucket must **not** allow public
  reads.

Either way media is served only through short-lived signed URLs, and never to a
donor.

### A note on MySQL isolation

InnoDB defaults to REPEATABLE READ; PostgreSQL defaults to READ COMMITTED. The
basket reservation, which is the one check-and-write that has to hold across
concurrent transactions, sets READ COMMITTED for its own transaction on MySQL —
otherwise a second donor keeps a pre-lock snapshot and both reserve the same
last amount. This needs `binlog_format` to be `MIXED` or `ROW`, which is the
default on MySQL 8 and MariaDB 10.11. Check with:

```sql
SELECT @@binlog_format;
```

## First admin

`AdminUserSeeder` creates `admin@sanabel.local` with `SEED_ADMIN_PASSWORD`.
Change the password immediately, then enrol a second factor — `admin` and
`council` cannot reach a panel without one.

## Backups and the restore test

```bash
BACKUP_PASSPHRASE='<from the password manager>' scripts/backup.sh /var/backups/sanabel
```

Backups are gzipped, AES-256 encrypted with PBKDF2, and written with a SHA-256
sum. `scripts/backup.sh` refuses to run without a passphrase, so an unencrypted
backup cannot be produced by accident.

A backup nobody has restored is not a backup. Run the restore test on a
schedule — it backs up, restores into a throwaway database and compares row
counts on the tables that carry the record:

```bash
BACKUP_PASSPHRASE='…' scripts/verify-restore.sh
```

Restore a specific backup:

```bash
BACKUP_PASSPHRASE='…' scripts/restore.sh /var/backups/sanabel/sanabel-<stamp>.sql.gz.enc sanabel
```

Keep the passphrase somewhere other than the server holding the backups.

## Rollback

1. Put the app in maintenance mode: `php artisan down`
2. Check out the previous tag, `composer install --no-dev`, `npm ci && npm run build`
3. If the release ran a migration: restore the pre-release backup (above) rather
   than rolling a migration back. Money and audit rows must not be replayed.
4. `php artisan optimize:clear && php artisan config:cache`
5. `php artisan up`

## Configuration after deploy

Everything operational is a row, not a deploy:

- **System → settings**: hold hours, grace days, reassessment windows, badge
  thresholds, the verification target
- **Reference data**: regions, living reference values, rent references,
  adjustments, scoring weights — all versioned, with a CSV import

Editing any of these never changes an assessment already stored: each one keeps
the snapshot of the values it was computed with.

## Monitoring

- `GET /up` — Laravel's health endpoint
- The admin dashboard shows the verification queue against the 48-hour target
- `audit_log` grows with every write and is append-only; watch its size
- Application logs never contain a name, ID, phone or wallet (rule 10)
