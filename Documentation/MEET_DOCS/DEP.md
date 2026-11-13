# Deployment Manual (DEP)

**Version:** 2.0

**Document Type:** Deployment Manual

**Priority:** Critical

---

# 1. Purpose

This document defines the deployment architecture, hosting constraints, environment strategy, release process, and operational procedures for the Meet platform.

Its objective is to ensure that every deployment - the first install on a brand new hosting account and every routine release afterward - is repeatable, secure, predictable, and recoverable, without requiring shell access on the production server.

---

# 2. Deployment Objectives

The deployment strategy shall provide:

* Reliable, repeatable deployments
* A secure, minimal-access production environment
* Minimal downtime during releases
* Consistent configuration across environments
* Automatic rollback on a failed release
* Operational stability between releases
* Full deployment traceability (version, commit, timing, operator)

Deployment procedures remain standardized across every environment the platform runs in.

---

# 3. Hosting Model & Constraints

Meet runs on shared cPanel hosting with no shell/SSH access and no persistent background processes. Two constraints shape everything else in this document:

* **No `exec()`/`shell_exec()`.** The hosting plan disables both, and there is no Terminal or SSH access. Every deployment script is therefore pure PHP: cURL for network calls, `ZipArchive` for extraction, and `Illuminate\Support\Facades\Artisan::call()` for running Artisan commands in-process. Nothing in the deployment pipeline shells out to `git`, `composer`, or `npm`.
* **No build step on the server.** Because there is no way to run `composer install` or `npm run build` remotely, `vendor/` and `public/build/` are committed directly to the `master` branch instead of being `.gitignore`'d as they would be in a typical Laravel project. The server only ever downloads and extracts already-built files; it never builds anything itself.

These two constraints are the reason the deployment tooling looks different from a conventional CI/CD pipeline, and they apply to both the one-time initial install and every routine update afterward.

There is also no persistent queue worker daemon and no long-running scheduler process - see Section 17.

---

# 4. Deployment Architecture

```text id="deploymentarchitecture"
Developer (local machine)
      ↓
deploy/prepare-release.sh   (tests, composer/npm build, commit artifacts)
      ↓
deploy/publish-release.sh   (bump VERSION, merge to master, tag, push)
      ↓
GitHub - master branch (fully built, tagged release)
      ↓
Nightly cron: deploy/update.php   (download, verify, atomic swap, migrate)
      ↓
Production (single live application folder)
```

Every release is fully built and tested locally before it ever reaches `master`; the server's only job is to safely pull down and activate an already-validated release.

---

# 5. Environment Strategy

The platform maintains the following environments:

* **Local development** - each engineer's own machine, full `composer`/`npm`/`artisan` toolchain, `.env` pointed at a local database.
* **Production** - the single live cPanel-hosted deployment, `APP_ENV=production`, `APP_DEBUG=false`, driven entirely by the `deploy/` scripts described in this document.

Each environment maintains its own isolated `.env` configuration and database. Local development is free to use the full Artisan/Composer/NPM toolchain; production is restricted to whatever `initialize.php` and `update.php` can do through pure PHP, per Section 3.

---

# 6. Infrastructure Components

Production infrastructure consists of:

* A cPanel-managed web server, document root pointed at `public/`
* PHP 8.4 or newer (required by `composer.json`; anything older fatals on every request)
* A MySQL database
* Laravel's file-based storage (`storage/app/`, served through a relative `storage:link` symlink)
* Cron-triggered scheduler and queue drain (there is no persistent queue worker daemon or standalone cache server - see Section 17)
* Outbound email via SMTP for admin notifications (deploy success/failure, calendar reminders, etc.)

There is no separate staging server or container orchestration layer; the deployment model in this document is deliberately built around a single production environment on inexpensive shared hosting.

---

# 7. Environment Configuration

Each deployment's `.env` defines, at minimum:

* Application name, environment, URL, and version (`APP_VERSION`, kept in sync with the deployed release)
* Debug configuration (`APP_DEBUG=false` in production, always)
* Database connection (host, port, name, user, password)
* Mail/SMTP configuration
* Firebase Cloud Messaging credentials for push notifications (`FIREBASE_PROJECT_ID`, `FIREBASE_CLIENT_EMAIL`, `FIREBASE_PRIVATE_KEY`, `FIREBASE_VAPID_KEY`, `FIREBASE_API_KEY`, `FIREBASE_AUTH_DOMAIN`, `FIREBASE_STORAGE_BUCKET`, `FIREBASE_MESSAGING_SENDER_ID`, `FIREBASE_APP_ID`) - a feature reading one of these values before it is filled in simply stays inactive rather than erroring, matching this app's general convention for unconfigured optional features
* Application key (`APP_KEY`), generated once at first install and never regenerated afterward (Section 12)

Environment-specific values are never hardcoded into application code; they live exclusively in `.env`, which is never committed to version control.

---

# 8. First-Time Deployment (Fresh Install)

A brand new cPanel account is bootstrapped with a single self-contained script, `deploy/initialize.php`, run once:

1. Cut a release locally (Section 10) so `master` has a fully built `vendor/`/`public/build/` and a current `VERSION` file.
2. In cPanel, create the subdomain/addon domain, leaving its document root pointed at the domain's own folder rather than `public/` for now.
3. In MultiPHP Manager, set that domain's PHP version to 8.4 or newer.
4. Upload only `deploy/initialize.php` to the domain's folder root - nothing else needs to exist there yet, since the script downloads the rest of the application itself.
5. Visit the script in a browser with a self-invented secret token of at least 8 characters (`?secret=...`). A visitor without the correct secret receives a plain 404, not any indication an installer exists. Submitting the form triggers, in order:
   * Download of the full application as a GitHub zipball (via the GitHub API, using a personal access token) and extraction into the application root.
   * Verification that `vendor/autoload.php` exists post-extraction (confirms the pulled release was actually built with `deploy/prepare-release.sh` before being pushed).
   * Writing `.env` from `.env.example`, overlaid with the submitted database, mail, and application-URL values (`APP_ENV=production`, `APP_DEBUG=false` always set explicitly). Values are always double-quoted and escaped, since an unquoted `#` in, for example, an SMTP password would otherwise be silently treated as a comment marker and truncate the value.
   * Bootstrapping Laravel in-process and running, via `Artisan::call()`: `key:generate --force`, `migrate --force --seed`, `storage:link`, `config:cache`, `route:cache`, `view:cache`.
   * Creating the first Super Admin account from the submitted credentials, together with the same default profile/preference/privacy/presence rows a normal registration receives, and assigning both the `User` and `Super Admin` roles.
   * Persisting deploy configuration (`deploy/.deploy-config.php`, GitHub owner/repo/ref/token, chmod 600) and the current commit SHA (`deploy/.last-sha`) for `update.php`'s later use, and writing a lock file (`deploy/.initialized`) that makes the script refuse to run a second time - protecting a live site from an accidental re-run wiping or re-seeding the database.
6. Once the script reports success, three steps remain to be done by hand, since the script cannot perform them from inside a single web request:
   * In cPanel → Domains, point the document root at `.../public` instead of the application root.
   * Add the three cron lines from Section 9.
   * Delete `initialize.php` from the server (it already refuses to run twice, but there is no reason to leave it reachable).

If anything fails during this process, nothing is locked and the form can simply be resubmitted after the underlying issue is fixed.

---

# 9. Cron Configuration

Production relies entirely on cPanel's cron, not a daemon, for every recurring process:

```text id="croncnfiguration"
* * * * * cd /home/user/meet && php artisan schedule:run
* * * * * cd /home/user/meet && php artisan queue:work --stop-when-empty --max-time=50
0  2 * * * php /home/user/meet/deploy/update.php >> /home/user/meet-deploy.log 2>&1
```

* The first line drives every scheduled job listed in Section 17 (cleanup jobs, presence, reminders, etc.).
* The second line drains any queued jobs once a minute and then exits (`--stop-when-empty`) - there is no long-running worker process to keep alive or restart.
* The third line is the nightly auto-update (Section 12). Its log file lives one level above the application folder (`meet-deploy.log`, a sibling of `meet/`) specifically so it survives the folder-swap `update.php` performs on every successful deploy.

---

# 10. Release Process

A release is prepared locally, never on the server, in two steps run from the branch being released (not from `master` itself):

**`deploy/prepare-release.sh`:**

* Locates a PHP 8.4+ binary explicitly (checking `php8.4`, `php8.5`, then plain `php`) rather than trusting whatever the shell's `PATH` resolves to, since an unqualified `composer` can silently run under an older PHP and fail on `composer.json`'s platform check.
* Restores dev dependencies first if `vendor/bin/phpunit` is missing (a previous stripped-down `vendor/` from an earlier release run).
* Runs the full test suite (`artisan test`) **before** stripping dev dependencies - running it after `composer install --no-dev` would leave nothing for the test runner to execute, and the suite would silently report nothing to run rather than fail.
* Runs `composer install --no-dev --optimize-autoloader` to produce a production-ready `vendor/`.
* Locates a Node.js version satisfying Vite's floor (`^20.19.0 || >=22.12.0`), preferring the newest matching version under `nvm` and pairing it with that same install's `npm`.
* Runs `npm install` and `npm run build`.
* Stages and commits `vendor/` and `public/build/` onto the current branch (`Build release artifacts`), skipping the commit if nothing changed.

**`deploy/publish-release.sh <version>`:**

* Requires plain SemVer (`MAJOR.MINOR.PATCH`) and requires `CHANGELOG.md` to already have a `## [<version>] - Unreleased` heading before touching anything.
* Bumps `VERSION`, stamps today's date onto the `CHANGELOG.md` heading, updates the version line in `README.md`, and commits (`Release v<version>`).
* Pushes the release branch to `origin` immediately, so it never falls behind `master` after the merge below.
* Merges the release branch into `master` inside a disposable scratch worktree (never by checking out `master` in the primary working directory, so a bad merge or rejected push can never strand local work on `master`), tags `v<version>`, and pushes both `master` and the tag.

Once pushed, the nightly cron (Section 9) picks up the new `master` on its own; `deploy/update.php` can also be triggered immediately (via cron's "Run Now" in cPanel) instead of waiting.

---

# 11. Version Control & Artifact Management

Every deployable artifact is `master` itself at a given tagged commit - there is no separate build server or artifact repository. Because the server can never build `vendor/` or frontend assets on its own (Section 3), those built outputs are committed directly alongside source code rather than excluded via `.gitignore`. This makes every tagged release on `master` immediately deployable as-is: whatever `git` (or, on the server, the GitHub zipball API) hands back for that ref is already complete and runnable.

A release is considered final once tagged; `deploy/update.php` refuses to install a release whose `VERSION` file is lower than what is already live (Section 12), so a tag is never silently downgraded onto production.

---

# 12. Nightly Auto-Update Workflow

`deploy/update.php` runs nightly via cron (Section 9) and performs the entire update in a single PHP process, entirely through pure-PHP mechanisms (no `git`, `composer`, or `npm` calls):

1. **Check for a new release.** Reads the configured GitHub owner/repo/ref from `deploy/.deploy-config.php` and compares the latest commit SHA against `deploy/.last-sha`. If unchanged, it logs "already up to date" and exits.
2. **Download.** Downloads the new commit as a zipball and extracts it into a sibling folder (`meet_new`), never touching the live `meet` folder yet.
3. **Sanity check.** Confirms `vendor/autoload.php` exists in the extracted copy (confirming it was built with `deploy/prepare-release.sh`), and refuses to proceed if the new release's `VERSION` is lower than what is currently live.
4. **Carry forward persistent paths.** Copies the live `.env`, `storage/app/` (uploads, archives), `storage/logs/`, and `deploy/.deploy-config.php` from the live folder into the new one - nothing in these paths is ever regenerated or lost across an update.
5. **Sync new environment keys** (Section 13).
6. **Atomic swap.** Performs the cutover as two `rename()` calls: the live `meet` folder is renamed to `meet_old`, then `meet_new` is renamed to `meet`. This is the only moment production traffic is affected, and it is effectively instantaneous.
7. **Bootstrap and migrate - only now, against the final path.** Laravel is bootstrapped for the first time against the new code, but only after the swap, and only at its final, permanent path. Bootstrapping earlier (against the temporary `meet_new` path) would let Laravel's and `nwidart/laravel-modules`'s own bootstrap caches (`bootstrap/cache/packages.php`, `services.php`, per-module `*_module.php`) bake in absolute paths pointing at a folder that stops existing the moment the swap renames it away - a real failure mode this design specifically avoids. After the swap: `migrate --force`, relative `storage:link` symlinks (recreated directly rather than via `--relative`, which would need a package this app doesn't depend on - a relative symlink survives the folder rename with no extra step), then `config:cache`, `route:cache`, `view:cache`.
8. **Run version-specific actions** (Section 14) for every version between the previous and new `VERSION`, in order.
9. **Record success.** Updates `.env`'s `APP_VERSION` and `deploy/.last-sha`, and emails the Super Admin a summary (previous version, new version, commit SHA, and any newly-added blank environment keys from step 5).

**Failure handling:**

* If anything fails **before** the swap, the live `meet` folder was never touched; `meet_new` is discarded and the failure is logged (and, best-effort, emailed).
* If anything fails **after** the swap (migrations or version-actions), the now-broken `meet` folder is renamed aside to `meet_broken_<timestamp>` for later inspection, and `meet_old` is renamed back into `meet` - restoring the previous working release automatically, with no manual intervention required. The previous release is otherwise kept at `meet_old` as a rollback snapshot after every successful deploy.

`key:generate` only ever runs inside `initialize.php`, never inside `update.php` - running it against a live site would invalidate every active session and any already-encrypted database value.

---

# 13. Environment Variable Sync on Upgrade

New environment variables reach production automatically as part of every nightly update. `update.php` copies the server's *existing* `.env` forward unchanged, then adds - with a blank value - any key present in the new release's `.env.example` but missing from that `.env`; a key that already exists is never touched, so a real configured value is never disturbed.

A feature that reads one of these newly-added-but-still-blank keys simply stays inactive, exactly as it would on a fresh install that never configured it - this app's established convention for "not configured yet" rather than an error condition. The success email `update.php` sends to the Super Admin, and the deploy log, both list exactly which keys were added on that run, so it is clear what still needs a real value filled in.

This sync mechanism only ever compares the new release's `.env.example` against the *live* `.env` at update time - it does not retroactively fix a server that was already several releases behind before this mechanism existed. For that situation, `git diff <previous-tag> <new-tag> -- .env.example` shows exactly what changed, and the operator can add any needed keys to production's `.env` by hand. In particular, the full set of Firebase Cloud Messaging keys listed in Section 7 must be added by hand on any server that was upgraded before this sync mechanism existed on it - those replaced the project's earlier, self-managed Web Push setup entirely, and the old `VAPID_SUBJECT`/`VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY` entries can be removed from production's `.env` whenever convenient (leaving them does no harm).

---

# 14. Version-Specific Deploy Actions

Beyond the baseline steps every deploy always runs (migrate, storage link, config/route/view cache - Section 12), a release can register extra one-off actions in `deploy/version-actions.php`, keyed by the exact version being released. `update.php` runs every entry between the previously-deployed version and the new one, in order, exactly once per real deploy.

This file is **append-only** - an existing entry is never edited or removed, since doing so could disrupt an already-successful past deploy's recorded behavior.

Entries here are restricted to cheap, near-instant work only (a schema-adjacent tweak, flipping a Setting flag), because `update.php` runs them synchronously, in the same process, before the site comes back up. Anything scaled to table size - a bulk backfill, a large seed - belongs in a scheduled job instead (Section 17), with a version-actions entry doing no more than flipping the flag that turns that job on. Running a genuinely large, unchunked operation directly inside a version-actions entry has previously caused a production incident (the site left 500ing, with the next cron tick starting an overlapping deploy attempt on top of it) - this restriction exists specifically because of that.

---

# 15. Rollback & Recovery

Because `update.php` never modifies the live `meet` folder until every prior step has already succeeded against a completely separate copy, rollback is built into the ordinary update flow rather than a separate manual procedure:

* **Failure before the swap:** the live folder is untouched; nothing to roll back.
* **Failure after the swap:** the broken release is automatically moved aside to `meet_broken_<timestamp>` and the previous release (`meet_old`) is automatically restored as `meet` - no operator action required, though the broken copy remains on disk for inspection.
* **Manual rollback of an already-successful deploy** (e.g. a release that passed its own migrations but is later found to have a functional regression): restore `meet_old` as `meet` by hand, restore the database from the most recent backup taken before that deploy if the release included data-altering migrations, and confirm the restored `.env`'s `APP_VERSION` matches the restored code.

A previous release's artifact, its point-in-time database backup, and its `.env` snapshot together form the minimum rollback readiness for any given deploy.

---

# 16. Database Migration Strategy

Every deploy - first-time install and every nightly update - runs `migrate --force` as an unconditional baseline step. Migrations are written to be forward-only and additive wherever practical; a destructive schema change is paired with a data migration plan reviewed before release rather than left to `migrate` alone. `initialize.php` additionally runs `--seed` on first install only; `update.php` never re-seeds an existing database.

Any release requiring more than a plain migration (a backfill, a data correction) uses the version-actions/scheduled-job split described in Sections 14 and 17, specifically to keep the migration step itself fast and safe to run synchronously during the swap window.

---

# 17. Scheduler & Queue Operation

There is no persistent queue worker daemon on this hosting plan and no long-running scheduler process - both are driven entirely by the per-minute cron lines in Section 9.

* `php artisan schedule:run`, invoked every minute, drives every job registered in `routes/console.php`, including: OTP cleanup (hourly), stale typing-status cleanup, expired disappearing-message cleanup, expired group-invite cleanup, stale presence cleanup, missed-call marking, calendar reminder delivery, expired guest-session cleanup (all every minute or every five minutes), inactive-meeting auto-end, orphaned media-file cleanup, data-retention cleanup, and scheduled archive generation.
* `php artisan queue:work --stop-when-empty --max-time=50`, also invoked every minute, drains whatever is currently queued and then exits - deliberately not a long-running worker, since no process on this hosting plan can be kept alive indefinitely outside of cron itself.

Any job whose queued work could scale with table size (for example, a load-test data generator or a batched backfill) is written to self-limit its own per-run batch size and to disable itself once its work is done, rather than assuming a worker will keep running continuously.

---

# 18. Storage Configuration

Application storage is prepared for:

* User avatars
* Chat media (images, video, voice messages, documents)
* Generated archives and backups
* Application logs

`storage/app/` and `storage/logs/` are explicitly carried forward, untouched, across every update (Section 12) - they are never regenerated, rebuilt, or reset by any deployment script. Public access to uploaded media goes through a `storage:link` symlink, recreated with a relative target on every update specifically so it continues to resolve correctly after the folder-rename swap.

---

# 19. Security Considerations

Deployment enforces:

* **Secrets never in source control.** `.env` is never committed; `deploy/.deploy-config.php` (holding the GitHub token used for updates) is written with `chmod 600` and excluded from version control.
* **A gated, one-time installer.** `initialize.php` requires an operator-invented secret token of at least 8 characters in the URL just to render the form at all; without it, a request gets a plain 404 rather than any indication an installer exists. Once it succeeds, a lock file (`deploy/.initialized`) makes it permanently refuse to run again, and the operational checklist explicitly calls for deleting the file from the server afterward.
* **HTTPS in production**, with the document root pointed at `public/` only (never the application root, which would otherwise expose `.env`, `storage/`, and application source directly).
* **Restricted administrative access**, enforced at the application layer (role/permission checks) rather than at the infrastructure layer, since this hosting model has no network-level segmentation to rely on.
* **A version-downgrade guard.** `update.php` refuses to install any release whose `VERSION` is lower than what is already live, preventing an accidental or malicious downgrade.

Security verification of a new release (dependency review, no committed secrets, no accidental debug flags) happens locally as part of Section 10, before the release ever reaches `master`.

---

# 20. Admin Environment Editor

A Super-Admin-only Environment tab in the Admin panel complements the deploy pipeline as a direct, in-browser way to manage the live `.env` without needing file access to the server at all. Access is gated behind a dedicated permission granted only to the Super Admin role, never the plain Admin role, since `.env` can hold database, mail, and third-party API credentials - the same sensitivity level as the existing backup-management permission.

The tab offers two editing modes: a field-by-field form (one input per real key, with sensitive-looking keys such as `*_KEY`/`*_SECRET`/`*_PASSWORD`/`*_TOKEN` masked behind a Show/Hide toggle) and a raw full-file textarea for bulk edits. Every save requires the acting admin to re-enter their own account password first - the same step-up confirmation pattern used elsewhere for sensitive account actions - and takes a dated backup of the current file before writing the new one. `config:clear` runs automatically immediately afterward, so a production box running on cached configuration picks up the change right away rather than requiring a separate deploy.

Keys present in `.env.example` but missing or still blank in the live `.env` - the same detection `update.php`'s environment-key sync performs at deploy time (Section 13) - are highlighted directly in this UI, giving an operator a single place to see, and fill in, whatever configuration a recent release added but hasn't yet been given a real value.

This tool is a complement to, not a replacement for, the deploy-time sync in Section 13: the sync guarantees a new key exists (blank) in `.env` the moment it reaches production; this editor is how an operator actually fills in its real value afterward, without needing shell or FTP access.

---

# 21. Monitoring & Post-Deployment Verification

Immediately after any deploy - first-time install or nightly update - verify:

* Application availability (a real page load, not just an HTTP 200 on a static asset)
* Authentication (login succeeds)
* Database connectivity
* Core functionality: contacts, chat, media upload, voice calls, video calls, the meeting room, calendar
* The Administrative Dashboard
* Scheduler and queue-drain cron lines are still present and running (Section 9/17)

The nightly update's own success/failure email (Section 12) is the first signal for a routine deploy; the deploy log (`meet-deploy.log`, sibling to the application folder) is the durable record for anything that email doesn't cover, since it is never affected by the folder-swap.

---

# 22. Incident Handling

Operational incidents follow:

```text id="incidentworkflow"
Detection
      ↓
Classification
      ↓
Containment
      ↓
Investigation
      ↓
Recovery
      ↓
Root Cause Analysis
      ↓
Closure
```

A deploy-caused incident detected by `update.php` itself (a migration or version-action failing after the swap) is contained automatically via the rollback described in Section 15, with the broken release preserved on disk for the investigation step rather than deleted. Incidents detected afterward, by monitoring or user reports, follow the same workflow but require a manual rollback decision.

---

# 23. Deployment Governance

Deployment governance requires:

* Local test-suite success before a release is ever built (Section 10)
* A populated `CHANGELOG.md` entry before a release can be published (`publish-release.sh` refuses to run otherwise)
* Restriction of `deploy/version-actions.php` entries to cheap, instant operations only (Section 14)
* Restricted, password-gated access to both the server's `.env` (Section 19) and the Admin environment editor (Section 20)

No production deployment bypasses these controls; `publish-release.sh` and `prepare-release.sh` enforce the checkable ones directly rather than relying on operator discipline alone.

---

# 24. Deployment Lifecycle

```text id="deploymentlifecycle"
Local development
      ↓
Test suite (prepare-release.sh)
      ↓
Production build (composer --no-dev, npm run build)
      ↓
Commit built artifacts to the release branch
      ↓
Version bump, tag, merge to master (publish-release.sh)
      ↓
Nightly cron pickup (update.php)
      ↓
Download, verify, atomic swap
      ↓
Migrate, cache, version-actions
      ↓
Production verification
```

Each stage must complete successfully before the next begins; a failure at any stage after the swap triggers the automatic rollback described in Section 15.

---

# 25. Deployment Acceptance Criteria

A deployment is complete only when:

* The new code is live at its final path (post-swap) and serving requests.
* Database migrations have completed against the live path.
* Any version-specific actions for the versions being crossed have run.
* The scheduler and queue-drain cron lines are confirmed still active.
* `.env`'s `APP_VERSION` and `deploy/.last-sha` reflect the newly deployed release.
* Any newly-added environment keys have been identified (via the success email, the deploy log, or the Admin environment editor) even if not yet filled in with real values.
* Core functionality (Section 21) has been spot-checked.

A deploy that fails any migration or version-action step after the swap does not reach this state - it is rolled back automatically instead (Section 15), and is not considered complete until re-attempted successfully.

---

# 26. Recovery Readiness

Ongoing recovery readiness depends on:

* A verified database backup taken before any deploy involving a non-trivial schema or data change
* The automatically retained previous-release folder (`meet_old`) after every successful deploy
* A working `.env` snapshot corresponding to that previous release
* A clear record, in the deploy log, of exactly which commit/version is currently live

Recovery capability is exercised, in effect, on every deploy that fails after the swap, since the automatic rollback path is the same mechanism a manual recovery would use.

---

# 27. Documentation Requirements

Every release includes:

* A `CHANGELOG.md` entry, started as soon as work on that version begins (kept under an "Unreleased" heading until the release is actually published)
* Any new or changed environment variables reflected in `.env.example`
* Any new scheduled job or cron-driven behavior reflected in `routes/console.php` and Section 17 of this document
* Any new version-specific deploy action documented directly in `deploy/version-actions.php`'s own entry

Documentation is expected to stay synchronized with what is actually deployed, not with what was originally planned.

---

# 28. Deployment Quality Metrics

Operational deployment quality is tracked using:

* Deployment success rate (nightly auto-updates that complete without triggering the automatic rollback)
* Rollback frequency and root causes
* Time between a tag being pushed and it going live in production (bounded above by the nightly cron interval, or reduced to near-immediate by triggering `update.php` manually)
* Post-release incident rate
* Environment-configuration drift (keys present in `.env.example` but never given a real value on production)

These metrics guide ongoing improvements to the release and update tooling itself.

---

# 29. Files Reference

| File | Runs | Purpose |
|---|---|---|
| `deploy/initialize.php` | Once, via browser | Fresh install: download, `.env`, migrate + seed, Super Admin |
| `deploy/update.php` | Nightly, via cron | Check GitHub, download, verify, atomic swap, migrate |
| `deploy/version-actions.php` | Loaded by `update.php` | Extra per-version actions beyond the baseline migrate/cache |
| `deploy/lib/GithubRelease.php` | Loaded by `update.php` | GitHub zip download + extract (no `git` binary needed) |
| `deploy/lib/DeployEnv.php` | Loaded by `update.php` | Safe `.env` key=value read/write |
| `deploy/prepare-release.sh` | Locally, before a release | Test, build, commit `vendor/`/`public/build/` |
| `deploy/publish-release.sh` | Locally, after `prepare-release.sh` | Bump version, merge to `master`, tag, push |

`deploy/.deploy-config.php`, `deploy/.initialized`, and `deploy/.last-sha` are written by `initialize.php`/`update.php` at runtime on the server - never committed, and never present in a fresh checkout.

---

# 30. Summary

The Deployment Manual defines the complete deployment lifecycle for the Meet platform under a hosting model with no shell access and no server-side build step:

* Every release is built and tested locally, with `vendor/` and `public/build/` committed directly to `master` so the server never has to build anything itself.
* A single self-contained script performs the one-time fresh install; a nightly cron-triggered script performs every routine update after that, always via an atomic folder swap with automatic rollback if anything fails post-swap.
* New environment variables reach production automatically on every update, added blank without disturbing any already-configured value, with both an email/log trail and an in-app Admin environment editor to fill them in.
* Scheduler and queue processing are entirely cron-driven, with no persistent worker daemon anywhere in the stack.
* Recovery and rollback are not a separate manual process bolted on afterward - they are the same mechanism the update script already uses to protect itself against a failed deploy.
