# UPI Gateway on Vercel (free tier) — notes

Source: fork of https://github.com/mynkdev-21/upi-gateway (Laravel 12, commit
`66fddd3`, tested locally — see `../RUN_REPORT.md`). Only the files in this
folder were changed for Vercel; the app code itself is untouched.

## What was added / changed for Vercel

- `api/index.php` — serverless entry point. Forwards every request to
  `public/index.php`, after pointing Laravel's writable paths at `/tmp`
  (the only writable directory on Vercel) and dropping stale
  package-discovery caches.
- `vercel.json` — `vercel-php@0.7.4` (PHP 8.3, matches Laravel's `^8.2`
  requirement), catch-all route to `api/index.php`, Vite assets served
  from `/public/build`. Env vars set here are NON-SECRET only and tuned
  for serverless: `CACHE_STORE=array`, `SESSION_DRIVER=cookie`,
  `QUEUE_CONNECTION=sync`, `LOG_CHANNEL=stderr`,
  `DB_CONNECTION=pgsql` with `DB_SSLMODE=require` (database host/user/
  password come from Vercel dashboard env vars — see Supabase section).
  `vercel-php` is a community runtime, now ARCHIVED by its maintainer,
  who recommends Vercel's container runtime for new PHP projects
  instead. It may stop working on Vercel at any time.
- `package.json` — added `"vercel-build": "npm run build"` so Vercel
  compiles the Vite/Tailwind assets.
- `.vercelignore` — excludes `.env`, `vendor`, `node_modules`, tests,
  logs and the stale shipped `database.sqlite`.
- `README.md` — short "Deploy on Vercel" section appended.
- `.env.example` — Vercel env-var checklist appended as comments only.

No secrets are in this repo. `.env` is excluded and `APP_KEY` is
deliberately NOT in `vercel.json` — it must be set as a Vercel
environment variable (see below).

## 2026-11-06 fix — Vercel strips the "/api" prefix (verified live)

Requests to `/api/v1/accounts` returned Laravel 404
`{"message": "The route v1/accounts could not be found."}`, while
`/api/login` returned the login page and `/api/docs` the docs page —
Vercel routes `/api/*` to the `api/index.php` function with the
leading `/api` segment stripped. `api/index.php` now restores the
prefix for paths starting `/v1/` before Laravel boots (verified
locally: `/v1/accounts` → 401 `Invalid API key or secret`, i.e. the
API route now matches). Non-`/api` paths are untouched.

## Database — Supabase Postgres (free tier, chosen 2026-11-06)

Neon dropped per user instruction; use Supabase, NOT Turso (Laravel
has no native Turso/libSQL driver). Free tier: 500 MB database,
2 active projects, 5 GB egress/month, 60 direct connections.
Project pauses after 7 days of inactivity — restore from the
Supabase dashboard (takes ~30 s to wake). No automatic backups.

Connection (Supabase dashboard → Project Settings → Database):
use the **Supavisor Session pooler** host `<ref>.pooler.supabase.com`,
port **5432**, user `postgres.<ref>`, database `postgres`,
`sslmode=require`. Do NOT use the direct host `db.<ref>.supabase.co`
(it is IPv6-only on free tier) and do NOT use transaction pooler
port 6543 (it breaks Laravel's prepared statements).

Vercel env vars (Project → Settings → Environment Variables,
Production + Preview): `DB_CONNECTION=pgsql`, `DB_HOST`,
`DB_PORT=5432`, `DB_DATABASE=postgres`, `DB_USERNAME`,
`DB_PASSWORD`, `DB_SSLMODE=require`. `vercel.json` already defaults
`DB_CONNECTION=pgsql` / `DB_SSLMODE=require`; dashboard values win
over `vercel.json`. Never put the password or connection string in
the repo, chat, or any report.

Then, from a machine with PHP + pdo_pgsql: point the local clone's
`.env` at the same Supabase DB, run `php artisan migrate --force`,
`php artisan app:create-admin <test email>`, seed a `test@upi`
manual payment account, redeploy (push to `main` auto-deploys),
and verify login → dashboard → API order → pay page → QR → UTR →
approve live.

## Env vars you MUST set in Vercel (Project → Settings → Environment Variables)

Generate a key locally first (never reuse the local test key):

```bash
php artisan key:generate --show   # paste the base64:... value as APP_KEY
```

| Variable | Value |
|---|---|
| `APP_KEY` | output of `key:generate --show` (required — without it even the homepage 500s, because Laravel encrypts cookies) |
| `APP_URL` | `https://<your-project>.vercel.app` |
| `DB_CONNECTION` / `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` / `DB_SSLMODE` | Supabase Postgres — see Supabase section above (Session pooler, port 5432, `sslmode=require`). No DB works without these; the old `/tmp` SQLite setup is gone. |

## What Vercel CANNOT do for this app (honest limits)

1. **No persistent database on the free tier.** SQLite lives in `/tmp`
   and is wiped between invocations/instances. Tables are never
   migrated automatically, so every DB-backed page (`/login`,
   `/dashboard`, `/pay/...`, API orders) will error until an external
   database is connected. Only the static views (`/` homepage and
   `/docs`, which run no DB queries) can load without one.
   Real fix: point `DB_CONNECTION=mysql` (or `pgsql`) at a hosted
   database (e.g. a free-tier hosted MySQL/Postgres — needs its own
   signup) and run migrations against it once.
2. **No queue worker.** `QUEUE_CONNECTION=sync` means webhooks are
   sent inline during the request instead of by a worker. If the
   webhook receiver is slow/down, the request can fail or time out
   (10 s function limit on Hobby).
3. **No per-minute scheduler.** `orders:check-pending` (expiry +
   re-verification of pending orders) never runs. Orders will not
   expire on their own. Vercel Cron Jobs exist but need a Pro plan
   for sub-daily schedules on some setups — check current pricing.
4. **10-second function timeout on Hobby.** Slow requests (cold
   start + Composer autoload + Blade compile) can hit this.
5. **Manual-UTR flow only.** Nothing about verification changes:
   free generic-UPI verification is manual (customer types UTR →
   merchant checks bank app → merchant approves). No SMS/Gmail/bank
   API exists in this code. Paytm/PhonePe auto-verify needs their
   merchant credentials (see `../RUN_REPORT.md` §4).
6. **Deployment protection / SSO.** New Vercel projects default to
   deployment protection; if the live URL asks for a login, turn it
   off in Project → Settings → Deployment Protection.

## If the live URL only shows the homepage

That is the expected free-tier outcome without an external DB.
To get the full flow (login → dashboard → ₹1 test order → QR →
UTR review → paid), connect an external database, set its
`DB_*` env vars in Vercel, run migrations once against it, and
redeploy. The proper long-term home for this app is a VPS
(see `../SETUP_GUIDE.md` Part B), not Vercel.
