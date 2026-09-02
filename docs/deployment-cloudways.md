# Deploying to Cloudways

Step by step, matching the Cloudways panel. For the platform-neutral notes see
[`deployment.md`](deployment.md).

## What you need first

| | |
|---|---|
| Cloudways server | any provider; 2 GB RAM is comfortable for one application |
| Database | **MySQL 8.4 or MariaDB 10.11** — Cloudways offers no PostgreSQL, and the app is tested on both engines |
| PHP | 8.3 or newer, set per application |
| Redis | queues and cache |
| Supervisord | keeps the queue worker running |
| Domain + SSL | Let's Encrypt, built into Cloudways |
| SMTP | for notifications |
| Object storage | **optional** — media defaults to a private local disk |

You do not get root on Cloudways, so anything not in its package list cannot be
installed. That is why the database choice is settled for you.

## 1. Create the application

Server → **Add Application** → PHP Stack (Laravel). Note the application's
folder name; the path is:

```
/home/master/applications/<APP>/public_html
```

## 2. Install Redis and Supervisord

Server → **Settings & Packages** → **Packages** tab → install **Redis** and
**Supervisord**. Both are one click and need no configuration here.

## 3. Set the PHP version

Application → **Settings** → PHP version → **8.3** or newer.

Confirm the extensions over SSH:

```bash
php -m | grep -E 'pdo_mysql|redis|gd|intl|zip|sodium|mbstring|openssl'
```

All of these ship with the Cloudways PHP stack. If `redis` is missing, the
package in step 2 has not finished installing.

## 4. Deploy the code

SSH in as the application user, then:

```bash
cd applications/<APP>/public_html
git clone -b main https://github.com/malhaji95/Sanabel-Al-Rahma.git .
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

**Point the web root at `public/`.** Application → Settings →
**Webroot** → `public_html/public`. Without this the whole application
directory is web-reachable, including `.env`.

### Assets

`public/build` is not in the repository, and Cloudways servers do not reliably
have Node. Build on your own machine and upload:

```bash
npm ci && npm run build
rsync -avz public/build/ <user>@<server-ip>:applications/<APP>/public_html/public/build/
```

`scripts/deploy.sh` refuses to finish if the build is missing, rather than
serving the site unstyled.

## 5. Configure `.env`

Database credentials are in Application → **Access Details**.

```dotenv
APP_NAME="سنابل الرحمة"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.org
APP_LOCALE=ar
APP_TIMEZONE=Asia/Damascus

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<from Access Details>
DB_USERNAME=<from Access Details>
DB_PASSWORD=<from Access Details>

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=database
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

SANABEL_MEDIA_DISK=media_local

MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=no-reply@your-domain.org
```

`APP_DEBUG=false` is not optional: a stack trace on this application would show
beneficiary data.

Then:

```bash
php artisan migrate --force
php artisan db:seed --force          # roles, permissions, funds, settings, regions
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Do **not** run `SyntheticDataSeeder` in production — it creates 40 fake families.

## 6. The queue worker

Application → **Application Settings** → **Supervisord Jobs** → **Add New Job**:

| Field | Value |
|---|---|
| Command | `php artisan queue:work redis --tries=3 --max-time=3600 --sleep=3` |
| Directory | `applications/<APP>/public_html` |
| Processes | `1` |

## 7. The scheduler

Application → **Cron Job Management** → advanced editor:

```cron
* * * * * cd /home/master/applications/<APP>/public_html && php artisan schedule:run >> /dev/null 2>&1
```

**This one is not optional.** The scheduler releases expired basket
reservations every five minutes. Without it a family stays held after its 24-hour
hold ends, and no other donor can reach it. It also sends sponsorship reminders,
flags overdue reassessments, expires referral cards and delivers notifications.

Confirm it is wired up:

```bash
php artisan schedule:list
```

## 8. Domain and SSL

Application → **Domain Management** → add your domain, set it as primary. Then
Application → **SSL Certificate** → Let's Encrypt → install, and turn on
**Force HTTPS**.

## 9. Lock the first account down

```bash
php artisan tinker
>>> $u = App\Models\User::where('email', 'admin@sanabel.local')->first();
>>> $u->update(['email' => 'you@your-domain.org', 'password' => Hash::make('<a long passphrase>')]);
```

Then sign in at `/admin` and enrol a second factor — `admin` and `council`
cannot reach a panel without one.

## 10. Backups

```bash
mkdir -p ~/backups
```

Add a second cron job:

```cron
0 2 * * * cd /home/master/applications/<APP>/public_html && BACKUP_PASSPHRASE='<passphrase>' scripts/backup.sh ~/backups >> ~/backups/backup.log 2>&1
```

Backups are gzipped and AES-256 encrypted with a SHA-256 sum. While
`SANABEL_MEDIA_DISK=media_local`, the media directory is archived alongside the
database — receipts and delivery proofs are what a closed case rests on.

**Keep the passphrase somewhere other than the server, and copy the backups off
the server.** A backup that only exists on the machine it protects is not a backup.

### The restore test

Creating a database needs more rights than the application user has on
Cloudways, so pass the master credentials (Server → **Master Credentials**):

```bash
BACKUP_PASSPHRASE='<passphrase>' \
RESTORE_DB_USERNAME='<master user>' \
RESTORE_DB_PASSWORD='<master password>' \
scripts/verify-restore.sh
```

Run it monthly. It backs up, restores into a throwaway database, compares row
counts on the tables that carry the record, and drops it again.

## 11. Later releases

```bash
cd applications/<APP>/public_html
BACKUP_PASSPHRASE='<passphrase>' scripts/deploy.sh
```

It backs up, pulls, installs, migrates behind maintenance mode, rebuilds the
caches and restarts the workers. Upload `public/build` first if the front end
changed.

## Cloudways-specific notes

**Varnish.** Cloudways enables Varnish by default. The donor pages are
personalised and the panels are authenticated, so either turn Varnish off
(Application → Settings → Varnish) or exclude `/admin`, `/association`,
`/provider`, `/portal`, `/field` and `/livewire`. Left on and unexcluded, one
donor can be served another donor's cached basket.

**PHP-FPM and worker memory.** Application → Settings → PHP-FPM Settings. The
default is fine for one application on 2 GB; watch it if you add more.

**Log rotation.** `storage/logs` grows. Add:

```cron
0 3 * * 0 find /home/master/applications/<APP>/public_html/storage/logs -name '*.log' -mtime +30 -delete
```

Application logs never contain a name, national ID, phone or wallet — that is
rule 10 — but they still take up disk.

## Verifying the deployment

```bash
php artisan about
php artisan schedule:list
curl -sI https://your-domain.org/up          # health check
php scripts/basket-race-check.php 5          # against a staging database only
```

The race check writes and clears basket rows, so point it at staging, never at
production.

## Moving to object storage later

`media_local` keeps files on the application server. Once you have a bucket
(DigitalOcean Spaces, AWS S3, Wasabi, Backblaze B2 — anything S3-compatible):

1. Create it **private**. No public read.
2. Fill in `AWS_*` in `.env`.
3. Set `SANABEL_MEDIA_DISK=media`.
4. Copy `storage/app/private/media` into the bucket, keeping the paths.
5. `php artisan config:cache`.

No code changes. Media is served through short-lived signed URLs either way,
and is never shown to a donor.

## Before go-live

`docs/07-decisions.md` item 1 blocks correctness: the **real living and rent
reference values per region** are still with the client. Until they are loaded,
`ReferenceValueSeeder` writes placeholders and every computed need is wrong.
Load them through **Reference data → living reference values → import** before
publishing a single case.
