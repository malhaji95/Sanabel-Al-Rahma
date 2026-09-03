# Free demo deployment (Render + Neon)

A working demo you can send someone a link to, at no cost.

> **This is not a production setup.** Read [What this demo cannot
> do](#what-this-demo-cannot-do) before putting anything real in it.
> For production see [`deployment-cloudways.md`](deployment-cloudways.md).

## What you get

One free Render web service running the whole application — nginx, PHP-FPM,
the queue worker and the scheduler, all in one container — against a free Neon
Postgres database, seeded with 40 synthetic families.

No credit card, no Redis, no object storage.

## Before you start

You need a [Render](https://render.com) account, a [Neon](https://neon.com)
account, and this repository on GitHub.

Generate the application key **now** and keep it:

```bash
php artisan key:generate --show
# base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx=
```

This key encrypts national IDs, phones and wallets, and it keys the HMAC behind
`national_id_hash` — the unique index that stops a second file being opened for
the same person. If it changes after data exists, that data becomes unreadable
and duplicate detection silently stops working. The container refuses to start
without it, on purpose.

## 1. The database (Neon)

1. **New Project** → name it, pick the region closest to you.
2. Open **Connection Details** and read off the host, database, user and
   password. The host looks like `ep-something-123456.eu-central-1.aws.neon.tech`.

The free plan gives 0.5 GB, which is far more than a demo needs — the synthetic
seed is well under 10 MB.

## 2. The web service (Render)

1. **New** → **Web Service** → connect the GitHub repository.
2. Confirm:
   - Language: **Docker**
   - Plan: **Free**
   - Health check path: `/up`
3. Under **Environment**, set the six secrets:

| Key | Value |
|---|---|
| `APP_KEY` | the key you generated above |
| `APP_URL` | `https://<your-service>.onrender.com` |
| `DB_HOST` | the Neon host |
| `DB_DATABASE` | the Neon database name |
| `DB_USERNAME` | the Neon user |
| `DB_PASSWORD` | the Neon password |

Those six are the only variables you have to set. Everything else —
`DB_CONNECTION=pgsql`, `DB_PORT`, `DB_SSLMODE=require`, the database queue,
cache and session drivers, `APP_LOCALE=ar`, `SANABEL_MEDIA_DISK=media_local`,
`DEMO_SEED=true` — is baked into the image as `ENV` in the `Dockerfile`.

> **Why they are in the image and not in `render.yaml`.** Creating a service
> from the dashboard (**New → Web Service**) does **not** read `render.yaml`;
> only **New → Blueprint** does. A value defined only in the blueprint is
> silently absent on a dashboard-created service — that is how the first deploy
> came up with no `DB_CONNECTION`, fell back to SQLite, and died trying to open
> a file named after the database. The image now boots correctly either way, and
> `render.yaml` carries nothing but the six secrets above.

`APP_URL` is a chicken-and-egg: Render only tells you the hostname once the
service exists. Deploy, copy the URL, set `APP_URL`, and let it redeploy.

4. **Create Web Service.**

The first build takes 5–10 minutes: it installs the PHP extensions, runs
`composer install`, and builds the front-end assets in the image, so there is
nothing to upload by hand.

On boot the container migrates, seeds the reference data, seeds the synthetic
families, caches config and routes, and starts all four processes. Watch the
**Logs** tab — it prints each step and ends with `ready`.

## 3. Sign in

| | |
|---|---|
| Public site | `https://<your-service>.onrender.com` |
| Admin panel | `/admin` |
| Association panel | `/association` |
| Provider panel | `/provider` |
| Delegate field app | `/field` |

Demo accounts, all with the password `password`:

| Email | Role |
|---|---|
| `admin@sanabel.local` | مدير النظام |
| `officer@sanabel.local` | مسؤول الحالات |
| `supervisor@sanabel.local` | مشرف منطقة |
| `delegate@sanabel.local` | مندوب ميداني |
| `association@sanabel.local` | جمعية |
| `council@sanabel.local` | مجلس الإدارة (اطلاع فقط) |
| `provider@sanabel.local` | مزود خدمة |
| `donor@sanabel.local` | متبرع |

`admin` and `council` are asked to enrol a second factor on first sign-in. Any
authenticator app works; the screen shows the key to add.

### A walkthrough worth showing

1. **Public** → الحالات. Note that a donor sees a file number, an area and
   bands — no name, no ID, no phone, no exact rent, no score.
2. Sign in as `donor@sanabel.local`, add two families to the basket, reserve it,
   and enter any reference as the transfer.
3. Sign in as `admin@sanabel.local` → التبرعات. Verify the donation and watch
   coverage move on the public list. Try entering the same reference twice.
4. `/admin` → ملفات المستفيدين → إضافة. The six-step form derives the
   dependency flags rather than asking for them, and accepts no score.
5. `/field` as `delegate@sanabel.local`. Turn off the network in devtools, save
   a visit, turn it back on, and watch the queue sync.

## What this demo cannot do

Four limits, all consequences of the free plan.

**It falls asleep.** Render's free tier spins the service down after about 15
minutes of inactivity, and the first request afterwards takes 30–60 seconds.
The scheduler sleeps with it, so `sanabel:release-expired-baskets` does not run
— a basket hold can outlive its 24 hours and keep a family locked. On a paid
plan the container stays up and this stops being a problem.

**Uploads do not survive a redeploy.** The free plan has no persistent volume,
so `storage/app/private/media` is wiped on every deploy. Receipts and delivery
proofs are what a closed case rests on, so this alone rules the setup out for
real data. Fix by attaching a disk (paid) or pointing
`SANABEL_MEDIA_DISK=media` at an S3-compatible bucket — Cloudflare R2's free
10 GB works and needs no code change.

**Email goes nowhere.** `MAIL_MAILER=log` — notifications are created and
marked sent, and you can see them in the panel, but nothing leaves the server.
Set real SMTP credentials if you need delivery.

**The reference values are placeholders.** `docs/07-decisions.md` item 1: the
real living and rent figures per region are still with the client, so every
computed need in this demo is wrong by construction. It is fine for showing how
the system behaves and useless as a number anyone should act on.

**Do not put real family data in this.** Everything here is synthetic, and it
should stay that way.

## Resetting the demo

From Render's **Shell** tab:

```bash
php artisan migrate:fresh --force
php artisan db:seed --force
php artisan db:seed --class=SyntheticDataSeeder --force
```

## Running the same image locally

```bash
docker build -t sanabel-demo .

docker run --rm -p 8080:10000 \
  -e APP_KEY="$(php artisan key:generate --show)" \
  -e APP_URL=http://localhost:8080 \
  -e DB_CONNECTION=pgsql -e DB_HOST=host.docker.internal -e DB_PORT=5432 \
  -e DB_DATABASE=sanabel -e DB_USERNAME=sanabel -e DB_PASSWORD=sanabel \
  -e SESSION_DRIVER=database -e CACHE_STORE=database -e QUEUE_CONNECTION=database \
  -e DEMO_SEED=true \
  sanabel-demo
```

The image is the same one Render builds, so anything that works here works
there.

## Moving the demo towards production

In rough order of what matters:

1. A plan that does not sleep, so the scheduler actually runs.
2. Persistent media — a disk, or an S3-compatible bucket.
3. Real SMTP.
4. The real reference values per region.
5. Backups with a tested restore (`scripts/verify-restore.sh`).

At which point you are better off on the Cloudways server you already have.
