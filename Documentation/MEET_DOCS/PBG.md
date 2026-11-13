# Project Bootstrap Guide (PBG)

**Version:** 2.0

**Document Type:** Project Bootstrap Guide

**Priority:** High

---

# 1. Purpose

This document defines the exact, current steps required to bootstrap a Meet development environment, and the pipeline that takes a change from a local machine to production. It reflects the real repository layout, tooling, environment configuration, and deployment mechanics as they exist today - not an aspirational baseline.

Every developer, and every automated process (release scripts, the production update cron), follows the standards defined here.

---

# 2. Bootstrap Objectives

The bootstrap process shall:

* Provision the Laravel 12 application together with its module structure
* Configure a working local development environment (PHP, Node, MySQL)
* Establish and seed the database (roles, permissions, default settings)
* Configure version control and branch strategy
* Install the required composer and npm packages
* Prepare the project for feature development and for its no-exec, cron-driven production deployment model

---

# 3. Repository Structure

```text
meet/
├── app/
├── bootstrap/
├── config/
├── database/
│   ├── migrations/
│   └── seeders/
├── Modules/
│   ├── Admin/
│   ├── Authentication/
│   ├── Calls/
│   ├── Chat/
│   ├── Contacts/
│   ├── Core/
│   ├── Groups/
│   ├── Media/
│   ├── Meetings/
│   ├── User/
│   └── Whiteboard/
├── public/
│   └── build/          (committed - see Section 11)
├── resources/
├── routes/
├── storage/
├── tests/
│   ├── Feature/
│   ├── Unit/
│   └── Browser/         (Puppeteer, see Section 13)
├── deploy/               (see Section 11)
├── Documentation/
│   ├── MEET_DOCS/        (current, consolidated documentation - this file lives here)
│   └── <legacy per-category folders>
├── VERSION
└── CHANGELOG.md
```

The application is built on `nwidart/laravel-modules`. Feature code is organized as self-contained modules under `Modules/` (each with its own `Http/Controllers`, `Models`, `Services`, `routes/`, `resources/views`, and `database/seeders`), rather than living entirely under the root `app/` directory. `database/seeders/DatabaseSeeder.php` at the project root simply calls into each module's own seeder (currently `Modules\Admin\Database\Seeders\AdminDatabaseSeeder`, which seeds roles, permissions, and default settings for every module - see Section 9).

---

# 4. Branch Strategy

The Git repository uses:

* `master` - the production branch; the deployment pipeline (Section 11) only ever pulls from `master`
* `develop` - the integration branch that day-to-day feature work targets
* `feature/*`, `release/*`, `hotfix/*` - as needed

No direct commits are made to `master`. A release reaches `master` only through `deploy/publish-release.sh` (Section 12), which merges the release branch in via a scratch git worktree - the developer's own checked-out branch is never switched during a release.

---

# 5. Required Development Tools

* **PHP 8.4 or later.** `composer.json` itself declares a broad `^8.2` constraint, but in practice the resolved dependency tree (Laravel 12 and its ecosystem) needs 8.4+, and both the production cPanel target and `deploy/prepare-release.sh` enforce an 8.4 floor explicitly - an older PHP fatals immediately, including on `deploy/initialize.php` itself.
* **Composer**
* **Node.js 20.19+ or 22.12+** (Vite's own floor) and **npm**
* **MySQL 8+**
* **Git**

Optional locally:

* Redis (an alternative cache/session backend; the shipped default is file cache and database-backed sessions - see Section 6)
* Mailpit (or any local SMTP catcher, for testing mail without `MAIL_MAILER=log`)
* Laravel Pint (code style)

---

# 6. Environment Configuration

Every environment (local, staging, production) configures its own `.env`, copied from `.env.example` and never committed. Key groups:

* **Core:** `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, `APP_LOCALE`/`APP_FALLBACK_LOCALE`, `BCRYPT_ROUNDS`.
* **Database:** `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
* **Session/Cache/Queue:** `SESSION_DRIVER=database`, `CACHE_STORE=file`, `QUEUE_CONNECTION=database` - see Section 10 for why a database queue connection does not imply a running queue worker.
* **Mail:** `MAIL_MAILER` defaults to `log` (a safe zero-config default - messages just land in `storage/logs` instead of being sent). Once real SMTP credentials are filled in below it, `MAIL_MAILER` must be switched to `smtp` by hand, or registration OTPs, meeting invites, and archive emails will keep silently only logging - a mismatch that has actually happened during this project's own setup.
* **Firebase Cloud Messaging (real push notifications):** `FIREBASE_PROJECT_ID`, `FIREBASE_CLIENT_EMAIL`, `FIREBASE_PRIVATE_KEY` (all three from a downloaded Firebase service-account key, used server-side by the Firebase Admin SDK to send), plus the separate `FIREBASE_VAPID_KEY` (Web Push certificate) and the plain, non-secret client config `FIREBASE_API_KEY`/`FIREBASE_AUTH_DOMAIN`/`FIREBASE_STORAGE_BUCKET`/`FIREBASE_MESSAGING_SENDER_ID`/`FIREBASE_APP_ID`. Push notifications silently no-op - never crash - when these are left blank, exactly like a fresh install that hasn't configured them yet.
* **Google SSO:** `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` (must exactly equal `{APP_URL}/auth/google/callback`, registered as an authorized redirect URI on that OAuth client). "Continue with Google" simply doesn't appear on the login page when these are unset.
* **Object storage (optional):** `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`/`AWS_DEFAULT_REGION`/`AWS_BUCKET` - only needed if `FILESYSTEM_DISK` is switched away from the default `local`.
* **Frontend:** `VITE_APP_NAME`, mirrored from `APP_NAME` for use in built frontend assets.

All sensitive values stay outside version control. A production `.env` is never hand-maintained blindly - see Sections 9 and 11 for how new keys reach an already-deployed server automatically.

---

# 7. Core Package Stack

Backend (`composer.json`), beyond the Laravel 12 framework itself:

* `nwidart/laravel-modules` - the module system underpinning Section 3's structure
* `laravel/sanctum` - API authentication
* `laravel/passkeys` - WebAuthn passkey login
* `laravel/socialite` - Google SSO
* `pragmarx/google2fa-qrcode` - TOTP two-factor authentication
* `kreait/firebase-php` - Firebase Admin SDK, for sending push via FCM
* `chillerlan/php-qrcode` - QR code generation (2FA enrollment)
* `guzzlehttp/guzzle` - HTTP client

Dev-only: `laravel/pint` (style), `laravel/pail` (log tailing), `phpunit/phpunit`, `mockery/mockery`, `fakerphp/faker`, `laravel/sail`, `nunomaduro/collision`.

Frontend (`package.json`):

* `vite` + `laravel-vite-plugin` - the build pipeline
* `tailwindcss` v4 with `@tailwindcss/vite` - utility CSS, configured via the `@theme` block in `resources/css/app.css`
* `firebase` (the Firebase Web SDK) - client-side FCM token acquisition
* `puppeteer-core` - drives the real-browser tests under `tests/Browser` (Section 13)
* `axios`, `concurrently`

---

# 8. Local Development Bootstrap

A fresh clone is brought up with the standard Composer lifecycle script (`composer run setup` - `composer.json`'s own `scripts.setup` entry):

```text
composer install
copy .env.example -> .env (if .env doesn't already exist)
php artisan key:generate
php artisan migrate --force
npm install
npm run build
```

Day-to-day local development runs everything concurrently via `composer run dev`, which starts, in parallel: `php artisan serve`, `php artisan queue:listen --tries=1 --timeout=0` (drains any job that ends up on the queue table - see Section 10), `php artisan pail` (live log tailing), and `npm run dev` (Vite).

`php artisan key:generate` is only ever expected to run once, at genuine first setup. Running it again against a database already holding sessions or encrypted values invalidates them - the production deploy pipeline (Section 11) never calls it after the very first install for exactly this reason.

---

# 9. Database Setup & Seeding

`php artisan migrate` creates the schema; `php artisan db:seed` (or the `--seed` flag on `migrate:fresh`) runs `database/seeders/DatabaseSeeder`, which delegates to `Modules\Admin\Database\Seeders\AdminDatabaseSeeder`. That seeder is idempotent (`updateOrCreate`/`firstOrCreate` throughout) and establishes:

* **Roles:** Super Admin, Admin, Moderator, User, Guest (all `is_system = true`).
* **Permissions:** `manage_users`, `manage_roles`, `manage_meetings`, `manage_groups`, `manage_files`, `view_analytics`, `manage_settings`, `manage_backups`, `view_audit_logs`, `manage_environment`.
* **Role/permission matrix:** Super Admin holds every permission. Admin holds every permission except `manage_backups` and `manage_environment` - both withheld deliberately, since archive creation/download and live `.env` editing are gated to Super Admin specifically by an explicit check in their controllers, not by role membership alone. Moderator holds `manage_groups` and `manage_files`. User and Guest hold none.
* **Default settings:** session timeout, login lockout thresholds, password complexity rules, maintenance mode, file retention/upload limits.
* **Branding, SMTP, and archive settings:** materialized via each setting model's own `::current()` accessor.
* **Feature flags:** `guest_mode`, `one_view_media`, `whiteboard`, `screen_share`, `voice_calls`, `video_calls`.
* **Retention policies:** default expiry windows for media, activity logs, audit logs, and archives.

There is no separate "create an administrator account" seeding step for local development - the first Super Admin account is created interactively as part of the production installer (Section 11); locally, a developer registers a normal account and promotes it to Super Admin directly via the database or `tinker`.

---

# 10. No Persistent Queue Worker

`QUEUE_CONNECTION=database` is configured, but this project deliberately never uses `ShouldQueue` for real background work. Production hosting (a shared cPanel plan) has no long-running process and no queue worker daemon - only cron. Anything that looks like it should be "queued" instead runs synchronously inline (e.g. push notification delivery), or as a scheduled artisan command invoked every minute by cron (`php artisan schedule:run`) and every five minutes for lighter periodic cleanup jobs. The `queue:work --stop-when-empty` cron entry (Section 11) exists purely as a safety net to drain anything that ends up on the queue table by accident - it is not the primary execution path for any feature.

---

# 11. Production Deployment Pipeline

Production hosting is a shared cPanel account with `exec()`/`shell_exec()` disabled and no Terminal/SSH access. Every deploy script is therefore pure PHP (cURL + `ZipArchive`, with Artisan invoked in-process via `Artisan::call()`) - nothing shells out to `git`, `composer`, or `npm` on the server. This is also why **`vendor/` and `public/build/` are committed to `master`** rather than `.gitignore`'d: the server can never build them itself, so a release has to arrive as already-built plain files.

**First-time setup on a new cPanel account:**

1. Cut a release locally (Section 12) so `master` carries a fully built `vendor/`/`public/build/` and a bumped `VERSION`.
2. Create the subdomain/addon domain in cPanel; leave the document root pointed at the domain folder itself for now, not `public/`.
3. Set that domain's PHP version to 8.4+ in MultiPHP Manager.
4. Upload only `deploy/initialize.php` to the domain folder root.
5. Visit it in a browser with a self-chosen secret token (`?secret=...`, minimum 8 characters - anything shorter or missing gets a plain 404, not a hint that an installer exists). The form collects a GitHub token, DB credentials, SMTP settings, and the first Super Admin's details. Submitting it downloads the app from GitHub, writes `.env`, runs migrations and seeders, and creates that Super Admin account.
6. Once it reports success: point the domain's document root at `public/`, add the cron lines below, and delete `initialize.php` (it already refuses to run a second time via a `deploy/.initialized` lock file, but there is no reason to leave it reachable).

**Cron lines:**

```text
* * * * * php artisan schedule:run
* * * * * php artisan queue:work --stop-when-empty --max-time=50
0 2 * * * php deploy/update.php
```

The first two implement the scheduler and queue-drain from Section 10. The third is the nightly auto-update, logging to a file one level above the app folder so the log survives the folder-swap described below.

**Nightly auto-update (`deploy/update.php`):** checks GitHub for a newer tagged release than the one currently deployed. If found, it downloads the new release into a sibling folder (`meet_new`), copies forward the live `.env`, `storage/app/`, and `storage/logs/` untouched, runs migrations, cache rebuilds, and any matching entries in `deploy/version-actions.php` (Section 12) against that new copy - and only swaps it into place via two atomic `rename()` calls once everything has already succeeded. The previous release is retained as one rollback snapshot (`meet_old`). If anything fails first, the live folder is never touched. `key:generate` is never run by `update.php` - only ever by `initialize.php`, once, matching Section 8's caution.

**New environment variables reach a live server automatically:** on every deploy, `update.php` adds (blank) any key present in the new release's `.env.example` but missing from the server's existing `.env`, without ever touching a key that already has a real value. A feature reading a newly-added-but-still-blank key just silently no-ops, the same as a fresh install that never configured it. The email `update.php` sends the Super Admin on completion lists exactly which keys were added, so it's clear what still needs a real value filled in (via Settings → Environment, Section 6, or by hand).

---

# 12. Release Process

A release is cut locally in two steps, run from the branch being released (never from `master` itself):

**`deploy/prepare-release.sh`** - pins an explicit PHP 8.4+ binary and a Node 20.19+/22.12+ binary (rather than trusting whatever `php`/`node` resolve to first in `PATH`, since both have drifted before), runs the full test suite while dev dependencies are still installed, then runs `composer install --no-dev --optimize-autoloader` and `npm run build`, and commits the resulting `vendor/` and `public/build/` onto the current branch.

**`deploy/publish-release.sh <version>`** - requires `CHANGELOG.md` to already have a `## [<version>] - Unreleased` heading (see the changelog-maintenance conventions this project follows), then bumps `VERSION`, stamps that heading with today's date, updates the version line in `README.md`, pushes the branch, and merges it into `master` inside a disposable scratch git worktree (so the developer's own checked-out branch is never touched, even transiently) before tagging `v<version>` and pushing both `master` and the tag. The server's nightly cron (Section 11) picks the new tag up on its own, or `deploy/update.php` can be triggered manually to deploy immediately.

If a release needs more than a plain migration (a one-off backfill, flipping a setting flag), it gets an entry in `deploy/version-actions.php`, keyed by the exact version being tagged. That file is append-only - an existing entry is never edited or removed - and every entry between the previously-deployed version and the new one runs, in order, exactly once per real deploy. Entries there are restricted to cheap, near-instant work; anything scaled to table size belongs in a scheduled job instead (see Section 10), with a `version-actions.php` entry only ever flipping the flag that turns such a job on.

---

# 13. Testing

* **PHPUnit** - `tests/Feature` and `tests/Unit`, run via `php artisan test` (or `composer test`, which clears config cache first).
* **Real-browser tests** - `tests/Browser`, driven by `puppeteer-core` (`npm run test:browser`), covering interactive surfaces that unit/feature tests can't (calendar drag-to-resize, calls, chat encryption preview, Admin environment editing, and more).

The release script (Section 12) runs the full PHPUnit suite before stripping dev dependencies, specifically to avoid a real failure mode: if `vendor/bin/phpunit` were ever missing (e.g. dev dependencies already stripped from a previous run), `php artisan test` falls back to listing the `test` namespace and exits `0` - a silent no-op that `set -e` would never catch. The script now checks for `vendor/bin/phpunit` up front and restores dev dependencies first if needed.

---

# 14. Bootstrap Completion Checklist

Before feature development begins in a new environment, confirm:

* Repository cloned and on the correct branch (Section 4)
* `.env` configured with at least the core, database, and mail keys (Section 6); Firebase/Google keys filled in only if those features are needed locally
* `composer run setup` completed without error
* Database migrated and seeded (roles, permissions, and default settings present - Section 9)
* Frontend assets build cleanly (`npm run build` or `npm run dev`)
* `php artisan test` passes
* Local queue/scheduler behavior understood - no `ShouldQueue` job is ever added without reading Section 10 first

---

# 15. Summary

The Meet platform bootstraps as a modular Laravel 12 application (`nwidart/laravel-modules`, with feature code living under `Modules/` rather than a flat `app/`), configured through a single `.env` covering core Laravel settings, Firebase Cloud Messaging, and Google SSO. Local setup is a single `composer run setup` plus `npm` build; the database is seeded idempotently with a fixed role/permission matrix (Super Admin, Admin, Moderator, User, Guest) plus default settings, feature flags, and retention policies.

Production runs on a no-exec, no-SSH cPanel host with no persistent process beyond cron - reflected end to end in the architecture: no queue worker daemon, only scheduled commands; `vendor/` and `public/build/` committed as plain files since the server can never build them; and a from-scratch, pure-PHP deploy pipeline (`initialize.php` for the one-time install, `update.php` for nightly atomic-swap auto-updates, `version-actions.php` for append-only per-release extras) built specifically around that constraint. New environment variables introduced by a release reach an already-deployed server automatically, and every release is cut through the same two-script, test-gated process before it ever reaches `master`.
