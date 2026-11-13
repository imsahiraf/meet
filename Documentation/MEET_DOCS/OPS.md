# Operations & Monitoring Manual (OPS)

**Version:** 2.0

**Document Type:** Operations & Monitoring Manual

**Priority:** Critical

---

# 1. Purpose

This document defines the operational strategy, monitoring approach, scheduled-job inventory, incident handling, backup/archive operations, and deployment operations for the Meet platform as it actually runs in production today.

Meet runs on shared cPanel hosting with `exec()`/`shell_exec()` disabled and no SSH/Terminal access, and with no persistent queue-worker daemon. Every operational procedure in this document is written against that real constraint, not against a generic cloud-hosting assumption. Its objective is to give an operator a single, accurate reference for how the platform is monitored, how its background work runs, how incidents are recovered from, and how releases reach production.

---

# 2. Operational Architecture

```text id="operationsarchitecture"
Users
      ↓
Laravel Application (public/ document root)
      ↓
cPanel Cron (per-minute trigger)
      ↓
php artisan schedule:run  →  scheduled jobs (Section 5)
php artisan queue:work --stop-when-empty --max-time=50  →  drains the database queue
      ↓
Application Logs (storage/logs) + Admin Panel (Logs / Archives / Settings / Environment)
      ↓
Operator Review & Recovery
```

There is no separate application server, load balancer, or container orchestration layer - the entire stack is a single PHP-FPM/Apache document root (`public/`) backed by MySQL, driven entirely by cron. Two cron lines carry all of production's background execution:

```
* * * * * cd /home/youruser/meet && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/youruser/meet && php artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1
```

The first line drives every scheduled job in Section 5. The second drains the `database` queue connection (`QUEUE_CONNECTION=database` in `.env`) once a minute and then exits (`--stop-when-empty --max-time=50`) rather than running as a long-lived daemon - there is no supervisor, no `queue:work` running continuously, and nothing in this app should ever be built assuming one exists.

---

# 3. No Persistent Queue Worker - A Deliberate Architectural Constraint

This is the single most important operational fact about the platform and governs almost every other section of this document.

cPanel shared hosting on this plan provides no way to keep a background process (a `queue:work` daemon, a Node process, anything long-running) alive continuously - there is no Supervisor, no systemd unit the app can install, and no persistent worker. The only reliable execution primitive is cron, which cPanel triggers once a minute at best.

Consequently:

* **`ShouldQueue` is never used** anywhere in this codebase for anything that must reliably happen. Every recurring cleanup, reconciliation, or notification job in Section 5 is registered directly on the Laravel scheduler (`Schedule::job(...)`) and runs synchronously, in-process, inside the same `schedule:run` cron tick - not dispatched to a queue that might never be drained.
* The `database` queue connection and its once-a-minute `queue:work --stop-when-empty` cron line still exist and still drain real queued jobs, but nothing operationally critical depends on that queue being drained promptly. A queue backlog is a lower-severity condition than it would be on an architecture that assumed a live worker.
* Manual "do it now" admin actions that might otherwise be a natural fit for `dispatch()` (for example, on-demand archive creation - Section 8) run **synchronously** (`dispatchSync()`) inside the web request instead, specifically so they cannot silently get stuck behind a worker that may not be running at that moment.
* Mail sending that must be reliable (OTP codes, password resets) is likewise sent inline rather than queued, for the same reason.

Any future engineering work on this app must preserve this constraint: do not introduce `ShouldQueue` background jobs whose correctness depends on a worker being continuously alive. If genuinely long-running async work is ever required, it must be built as its own scheduler-driven, chunked, self-limiting job (the pattern used throughout Section 5) rather than assuming a queue daemon.

---

# 4. Monitoring Scope

Production monitoring in practice covers:

* **Application availability** - reachability of the `public/` document root; no dedicated `/health` or `/up` endpoint exists in the codebase, so availability is verified by hitting a real page (e.g. the login screen) or via cPanel/hosting-provider uptime tooling.
* **Database health** - MySQL connectivity and table growth, reviewed via cPanel's phpMyAdmin/database tools; there is no separate database-monitoring service.
* **Scheduler execution** - whether cron is actually invoking `schedule:run` every minute. Laravel writes scheduled-task output to `storage/logs/laravel.log` on failure; a missed cron tick has no dedicated alert and must be caught by reviewing logs or observing a symptom (e.g. missed calls not being marked, guest sessions not expiring).
* **Queue drain** - whether the once-a-minute `queue:work --stop-when-empty` line is running; a backlog shows up as rows sitting in the `jobs` table.
* **Storage capacity** - disk usage under `storage/app/` (uploads, archives, media) and the cPanel account's overall quota, both checked through the hosting control panel, not an in-app metric.
* **Deployment health** - whether the nightly auto-update (Section 9) is succeeding, verified via `deploy/*-deploy.log` and the success/failure email it sends to the Super Admin.
* **Application-level activity** - user, call, chat, and meeting activity are visible to admins through the Admin Dashboard's own analytics (`view_analytics` permission) rather than a separate operations dashboard product.

There is no third-party APM, uptime-monitoring SaaS, or centralized log-aggregation service wired into this application. All monitoring is either manual (log files, Admin panel, cPanel) or driven by the self-healing behavior built into the scheduled jobs themselves (Section 5).

---

# 5. Scheduled Jobs - Complete Current Inventory

All scheduled work is registered in `routes/console.php` and executed by cron-triggered `php artisan schedule:run`. None of these are `ShouldQueue` - every one runs synchronously inside the scheduler process itself (Section 3). This table is the authoritative, current list; it must be kept in sync with `routes/console.php` whenever a job is added, removed, or its frequency changes.

| Job | Frequency | Purpose |
| --- | --- | --- |
| `CleanupExpiredOtpsJob` | Hourly | Expires pending email OTPs past their `expires_at`, then deletes expired/verified/blocked OTP rows older than 7 days. |
| `CleanupStaleTypingStatusJob` | Every 5 minutes | Deletes stale chat typing-indicator rows so the table doesn't grow forever (reads already ignore stale rows independently). |
| `CleanupExpiredDisappearingMessagesJob` | Every 5 minutes | Soft-deletes (delete-for-everyone) any message whose per-conversation disappearing-messages timer has expired. |
| `CleanupExpiredInvitesJob` | Hourly | Marks pending group invites whose `expires_at` has passed as expired. |
| `MarkStalePresenceOfflineJob` | Every minute | Flips a user's presence from online/away/busy to offline once their heartbeat ping (sent every 20s by the browser) has stopped arriving for 2 minutes. |
| `MarkMissedCallsJob` | Every minute | Marks a call still `ringing` past its timeout as missed and records call history, self-healing stale rows regardless of exact cron cadence. |
| `SendCalendarRemindersJob` | Every minute | Sends any calendar/meeting reminders that have come due. |
| `CleanupExpiredGuestSessionsJob` | Every minute | Expires guest meeting sessions past their `expires_at` and marks their participants as left, even if the guest never polls their own session status again. |
| `EndInactiveMeetingsJob` | Every 5 minutes | Ends any `active`/`locked` meeting with nobody genuinely present for a full 5 minutes - covers a lobby nobody ever entered and a meeting everyone has since left. |
| `CleanupRetentionJob` | Daily | Applies each admin-configured retention policy (media, activity logs, audit logs, archives) by deleting rows - and, for media, their underlying files - past that category's cutoff; a category set to "Never" is skipped. |
| `CleanupOrphanedMediaJob` | Every 15 minutes | Deletes View Once files stuck "viewed" past a grace window (a safety net for an interrupted stream) and any media row older than a day referenced by no message, group media library, or whiteboard export. |
| `ScheduledArchiveJob` | Daily | Checks the admin-configured archive frequency (daily/weekly/monthly/manual-only) and only actually builds a new archive once enough time has genuinely passed for that setting. |
| `SeedDailyLoadTestUsersJob` | Daily | Adds 10,000 more load-test users per day for 7 days after the initial seed, then self-disables via a Setting counter; independently capped by the admin-configurable `load_test_max_users` (default 150,000). Left permanently scheduled - it no-ops once its own stop conditions are met. |
| `BackfillProfileNamesBatchJob` | Every minute | Processes one bounded batch (1,000 rows) of the first/last-name backfill for pre-existing profiles, disabling itself via a Setting flag once nothing is left to do. Left permanently scheduled - it no-ops instantly once disabled. |

Two of these jobs (`SeedDailyLoadTestUsersJob`, `BackfillProfileNamesBatchJob`) are intentionally left registered indefinitely rather than removed after their one-time purpose completes, because each one's own internal stop condition makes it a no-op the moment it has nothing left to do - this is a deliberate simplification, not stale scheduling that needs cleanup.

Every job in this table follows the same operational pattern: a fast, bounded, synchronous database operation with no external dependency, safe to run every tick of a per-minute cron with no risk of overlap or pile-up.

---

# 6. Health Checks

There is no dedicated health-check endpoint (no `/up`, `/health`, or similar route) built into the application. Operational health is instead verified through:

* **Application startup** - any successful page load (e.g. the login screen) confirms PHP-FPM, Apache, and Laravel's bootstrap are functioning.
* **Database connectivity** - any authenticated page load that touches the database (dashboard, chat list) confirms MySQL connectivity; cPanel's own database tools provide a lower-level check.
* **Scheduler execution** - confirmed indirectly: symptoms like calls never transitioning to "missed," guest sessions never expiring, or typing indicators never clearing all indicate `schedule:run` is not firing, since every one of those behaviors depends on a Section 5 job.
* **Queue availability** - confirmed by inspecting the `jobs` and `failed_jobs` tables for a growing, undrained backlog.
* **Storage accessibility** - confirmed by any successful file upload/download (chat attachments, archive downloads) or directly via cPanel's File Manager.

Because none of these are automated, active alerting, they should be reviewed as part of the Daily/Weekly checklist in Section 10 rather than relied upon to surface problems proactively.

---

# 7. Logging

The application's log configuration (`config/logging.php`, driven by `.env`) defaults to:

* `LOG_CHANNEL=stack`, which fans out to the `LOG_STACK=single` channel - a single `storage/logs/laravel.log` file.
* `LOG_LEVEL=debug` by default (should be tightened to `warning` or `error` on a production box carrying real user traffic, to avoid the log file growing unnecessarily large).
* A `daily` channel is also available (`LOG_DAILY_DAYS=14` by default) for rotating log files if `LOG_CHANNEL`/`LOG_STACK` are reconfigured to use it - this is not the default and must be opted into.

Beyond the raw Laravel log file, two Admin-panel surfaces provide structured, queryable operational visibility without needing shell/file access (relevant given cPanel has no Terminal):

* **Admin → Logs** (`view_audit_logs` permission) - the audit-log/activity-log viewer covering administrative and security-relevant actions (see Section 8's audit-logging note on the Environment editor).
* **Admin → Archives** (`manage_backups` permission) - see Section 8.

Deployment-specific logging is separate again: `deploy/*-deploy.log` (living one level above the app folder so it survives the folder-swap `update.php` performs on every deploy) captures the nightly auto-update's own output, independent of the application's own `storage/logs/`.

Log retention for `activity_logs` and `audit_logs` is itself admin-configurable - see `RetentionPolicy` in Section 8 - with a `null` retention value meaning "Never" (exempt from cleanup) for a given category.

---

# 8. Backup / Archive Operations

Meet's backup mechanism is the **Archive** system (`manage_backups` permission, Admin → Archives), not a traditional `mysqldump`/filesystem-snapshot pipeline - a deliberate choice because shared cPanel hosting frequently disables `exec()`/`shell_exec()`, so the archive builder is pure PHP (`ZipArchive` plus a curated JSON export of key tables: conversations, messages, meetings, meeting participants, whiteboards, whiteboard objects, activity logs, audit logs) rather than a native database dump.

Key operational facts:

* **Scopes**: `entire_system`, `database_only`, or `files_only`, selectable per archive.
* **Two trigger paths**: `ScheduledArchiveJob` (Section 5, admin-configurable daily/weekly/monthly/manual-only frequency) and manual on-demand creation from the Admin panel.
* **Manual creation runs synchronously** (`CreateArchiveJob::dispatchSync()`), not queued - consistent with Section 3's no-persistent-worker constraint, so an admin clicking "Create archive now" can never get stuck behind a queue worker that isn't running.
* **Delivery**: archives at or below 20 MB are emailed as a direct attachment; larger archives are delivered as a signed download link instead.
* **Access control**: creating or downloading an archive is Super-Admin-only, even though viewing the archive list only requires the `manage_backups` permission.
* **Retention**: the `archives` category in `RetentionPolicy` governs how long completed archive files are kept before cleanup (via `CleanupRetentionJob`), same as `media`, `activity_logs`, and `audit_logs`.

There is no separate, independent database-backup product or off-site backup rotation configured outside of this Archive system - operators relying on a stronger disaster-recovery guarantee (e.g. off-site copies, point-in-time MySQL recovery) should treat that as a gap to close at the hosting-provider level (cPanel backup tools), not something this application layer currently provides.

---

# 9. Deployment Operations

Deployment is a fully automated, cron-driven pipeline (`deploy/`), built specifically around this hosting target's constraints: no `exec()`/`shell_exec()`, no `git`/`composer`/`npm` binaries available server-side, and no SSH. All server-side deploy logic is pure PHP (cURL + `ZipArchive`, in-process `Artisan::call()`), and `vendor/` and `public/build/` are committed directly to `master` since the server can never build them itself.

* **Nightly auto-update**: a cron line (`0 2 * * * php /home/youruser/meet/deploy/update.php`) checks GitHub for a new release and, if found, downloads it into a sibling folder (`meet_new`), runs migrations/cache rebuilds/any `version-actions.php` entries against that new copy, and only swaps it into place via two atomic `rename()` calls once everything has succeeded. If anything fails first, the live app folder is untouched and `meet_new` is discarded. The previous release is kept as a `meet_old` rollback snapshot after every successful swap.
* **What's preserved across every swap**: `.env`, `storage/app/` (uploads, archives), and `storage/logs/` are always carried forward from the live folder into the new one - never regenerated or lost.
* **`.env` key sync**: `update.php` copies the server's existing `.env` forward and adds (blank) any key present in the new release's `.env.example` but missing from the live `.env`, without ever touching an already-configured value. The success email sent to the Super Admin lists exactly which keys were added on that run; `deploy/*-deploy.log` records the same.
* **`key:generate`** only ever runs during first-time `initialize.php` setup - `update.php` never runs it, since doing so on a live site would invalidate every session and any already-encrypted database value.
* **Version-specific actions**: `deploy/version-actions.php` is an append-only, per-version list of extra one-off actions (e.g. flipping a Setting flag) that run in order exactly once per real deploy between the previously-deployed version and the new one. Anything scaled to table size belongs in a scheduled job (Section 5) instead of a version action, per that file's own convention.
* **Manual release cutting**: `deploy/prepare-release.sh` builds and tests locally and commits `vendor/`/`public/build/`; `deploy/publish-release.sh <version>` bumps `VERSION`, merges to `master`, tags, and pushes. The server's 2 AM cron then picks up the new `master` on its own, or `update.php` can be triggered manually.
* **Fresh-install path**: `deploy/initialize.php`, run once via browser with a one-time secret token, clones the app, writes `.env`, runs migrations/seeders, and creates the initial Super Admin account; it refuses to run a second time and should be deleted from the server afterward.

---

# 10. Environment Configuration Management

Two entirely separate mechanisms exist for configuration, and they must not be conflated:

1. **DB-backed application settings registry** - `Modules/Admin/Models/Setting.php` and `Modules/Admin/Services/SettingsService.php`. Plain category/key/value rows (with an `is_editable` guardrail on each), cached via `Cache::rememberForever` and invalidated on write. Accessed through `AdminSettingsController` and the `admin::settings` view (Admin → Settings, `manage_settings` permission). This governs application-level configurable behavior (retention policy values, archive schedule frequency, load-test caps, feature toggles, etc.) - it has nothing to do with the real `.env` file and never writes to it. Branding (`BrandingSetting`) and SMTP (`SmtpSetting`) configuration live in their own dedicated tables, following the same general pattern but kept separate from the generic `Setting` registry.

2. **Environment (`.env`) file editor** - `Modules/Admin/Http/Controllers/AdminEnvironmentController.php` and `Modules/Admin/Services/EnvFileService.php`, view `admin::environment` (Admin → Environment). This is a genuinely different, newer feature that edits the real, live `.env` file on disk. It offers two modes: a field-by-field form (one input per real key, with sensitive-looking keys - `*_KEY`/`*_SECRET`/`*_PASSWORD`/`*_TOKEN` - masked behind a Show/Hide toggle) and a raw full-file textarea for wholesale replacement. It is gated behind the `manage_environment` permission, granted to the Super Admin role only (never the plain Admin role, matching the same restriction already applied to `manage_backups`), and every save requires the acting admin to re-enter their own account password first - the same step-up pattern used elsewhere for deactivating an account, disabling 2FA, or removing a passkey. A dated backup of the current `.env` (`storage/app/env-backups/env-<timestamp>.bak`) is taken before every write, and `config:clear` runs automatically afterward so a cached-config production box picks up the change immediately. Keys present in `.env.example` but missing or blank in the live `.env` - the same detection the nightly deploy pipeline's `syncNewEnvKeys()` performs - are surfaced directly in this UI. Audit logging for this feature deliberately records only which keys changed, never the before/after values themselves, since `.env` routinely holds real database, mail, and API credentials that have no business sitting in the `audit_logs` table in plaintext.

Both mechanisms are legitimate, current, and complementary - the Settings registry for ordinary application behavior, the Environment editor for the small set of genuinely infrastructural values that live in `.env`. Neither is a stand-in for the other.

---

# 11. Incident Management

Incident handling on this platform is intentionally lightweight, matching its single-environment, single-operator-team scale:

* **Detection** is manual/log-driven (Section 6), not alert-driven - there is no paging system or automated alerting integration.
* **Classification**: informally, by user impact - a failed nightly deploy or a stuck scheduler is high severity (silently degrades the whole app); a single failed background job tick is low severity given the self-healing nature of every Section 5 job (each one re-evaluates the same condition on its next run regardless of whether the previous tick succeeded).
* **Recovery** for the most common real incident classes:
  * **Failed deploy**: `update.php`'s atomic-swap design (Section 9) means a failed deploy leaves the live app untouched; recovery is simply investigating and fixing the cause of the failure in `deploy/*-deploy.log`, then re-triggering `update.php`.
  * **Stuck scheduler/queue**: confirm the two cron lines (Section 2) are still registered and firing in cPanel's Cron Jobs UI; a missing or misconfigured cron line is the single most common root cause given there is no daemon to restart.
  * **Bad `.env` change**: restore the relevant dated backup from `storage/app/env-backups/` (Section 10) and run `php artisan config:clear`.
  * **Rollback**: the previous release is retained as a `meet_old` sibling folder after every successful deploy swap, giving a one-step manual rollback path if a newly deployed release proves broken.
* **Documentation**: incidents worth remembering are captured in `Documentation/IMPLEMENTATION_CHANGES.md` alongside the fix that resolved them, rather than in a separate incident-tracking system.

---

# 12. Capacity Planning

Given the single shared-hosting deployment target, capacity planning is necessarily coarse-grained rather than metrics-driven:

* **Storage growth** is the primary capacity concern - chat media, call recordings (if any), whiteboard exports, and archive files all accumulate under `storage/app/`, bounded only by whatever `RetentionPolicy` values are configured (Section 8) and the cPanel account's disk quota.
* **Database growth** is bounded the same way - `activity_logs`, `audit_logs`, and expired/stale operational rows (OTPs, typing status, guest sessions, disappearing messages) are all kept in check by the Section 5 cleanup jobs; without those retention policies configured, tables would grow unbounded.
* **Load-test data** (`SeedDailyLoadTestUsersJob`, capped by the admin-configurable `load_test_max_users`, default 150,000) is a deliberate, disclosed source of database growth for realistic organic-search/perf testing, not organic user growth, and should be excluded when reasoning about real capacity trends - see `LOAD_TESTING.md` for how to distinguish real users from load-test users.
* **Concurrency**: since there is no persistent queue worker and no autoscaling, the practical ceiling on the platform is whatever a single shared-hosting PHP-FPM/MySQL instance can sustain - meaningfully higher load would require moving off this hosting model rather than tuning within it.

---

# 13. Operational Checklists

**Daily**

* Confirm the nightly `update.php` deploy run succeeded (check its success/failure email and `deploy/*-deploy.log`).
* Skim `storage/logs/laravel.log` for new errors.
* Spot-check that time-sensitive behaviors are working (a ringing call resolves to missed after timeout, a guest session actually expires) as an indirect scheduler health check.

**Weekly**

* Review Admin → Logs for unexpected administrative/security activity.
* Review Admin → Archives to confirm scheduled archives are completing, not stuck in `processing`/`queued`.
* Check `storage/app/` disk usage and the cPanel account quota.

**Monthly**

* Review configured `RetentionPolicy` values across media/activity logs/audit logs/archives - confirm they still reflect actual operational and compliance needs.
* Confirm `deploy/version-actions.php` has no stale, unreviewed entries and that the rollback (`meet_old`) folder is present after the most recent deploy.
* Review `.env` via Admin → Environment for any keys flagged as missing/blank against the current `.env.example`.

---

# 14. Summary

Meet's operations model is built around the real constraints of its cPanel hosting target: no persistent queue worker, no `exec()`, no SSH, and a single per-minute cron as the only reliable execution primitive. Every recurring background task is a scheduler-driven, synchronous, self-healing job (Section 5) rather than a queued job dependent on a daemon that does not exist. Deployment is a fully automated, atomic-swap nightly pipeline with an automatic rollback snapshot and automatic `.env` key sync. Backups are handled by the pure-PHP Archive system rather than a native database dump tool. Two distinct, non-overlapping configuration surfaces exist in the Admin panel - the DB-backed application Settings registry for ordinary configurable behavior, and the Super-Admin-only, password-gated Environment editor for the real `.env` file - and neither should be mistaken for the other. Monitoring, health verification, and incident detection are deliberately manual and log-driven rather than automated-alert-driven, matching the platform's current operational scale.
