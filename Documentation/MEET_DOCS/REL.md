# Release Notes & Versioning Guide (REL)

**Version:** 2.0

**Document Type:** Release Notes & Versioning Guide

**Priority:** High

---

# 1. Purpose

This document defines the official versioning strategy, release management process, release lifecycle, compatibility policy, and documentation standards for the Meet platform, and records the platform's release history to date.

Its objective is to ensure that every release is predictable, traceable, well-documented, and easy to maintain throughout the product lifecycle.

---

# 2. Release Objectives

The release management framework provides:

* Predictable Versioning
* Traceable Changes
* Stable Releases
* Controlled Rollouts
* Documentation Consistency
* Rollback Readiness
* Long-Term Maintainability

Every production release follows this framework.

---

# 3. Versioning Strategy

The Meet platform uses **Semantic Versioning (SemVer)**:

```text
MAJOR.MINOR.PATCH
```

Version numbers accurately reflect the scope of changes: every minor bump ships genuinely new user-facing features, and every patch bump is fixes/hardening only, with no breaking change ever shipped without a major-version bump.

The single source of truth for "what version is this install running" is the root `VERSION` file - `config('app.version')` falls back to reading it, so a local dev checkout and a live deployed server can never disagree about what's running.

---

# 4. Version Definitions

### Major Release

Increment when:

* Breaking API changes
* Major architecture redesign
* Significant feature changes

Not yet used in this platform's history - every release to date has been additive or corrective.

### Minor Release

Increment when:

* New features
* Backward-compatible improvements
* New modules

Real examples include a navigation/calendar/identity batch (nav redesign, calendar, 2FA/Passkeys/SSO, push notifications, communities, broadcast lists, disappearing messages) and a multi-select/meeting-reliability batch (multi-select, Account & Security sub-hub, working meeting reminders, mobile meeting-room redesign).

### Patch Release

Increment when:

* Bug fixes
* Security fixes
* Performance improvements
* Documentation corrections

Real examples include an early patch series stabilizing the deploy pipeline and mail delivery, a multi-account-login patch, and a chat encryption/offline-support/FCM-migration/message-status-ticks patch series.

### Hotfix / Security Release

The framework also names Hotfix and Security release categories, following the same patch-numbering convention. In practice, real hotfixes have been the next patch release rather than an out-of-band tag - see Section 12 for same-day/next-day patch turnarounds on real production issues.

---

# 5. Release Lifecycle

```text id="releaselifecycle"
Planning
      ↓
Development
      ↓
Testing
      ↓
Approval
      ↓
Deployment
      ↓
Monitoring
      ↓
Maintenance
```

"Deployment" is executed via the automated cPanel deploy pipeline (`deploy/initialize.php` for first install, `deploy/update.php` as a nightly auto-update cron, `deploy/prepare-release.sh` + `deploy/publish-release.sh` to cut a release locally) - see `deploy/README.md`. "Testing" is enforced mechanically: `prepare-release.sh` refuses to build a release if the test suite isn't actually runnable.

---

# 6. Release Types

Supported release categories:

* Major Release
* Minor Release
* Patch Release
* Hotfix Release
* Security Release

Each release follows the documented approval workflow (Section 9) and is recorded in `CHANGELOG.md` at the repo root, headed by an `[Unreleased]` section that is renamed and dated at cut time.

---

# 7. Changelog & Release Notes Standards

Every release documents, where applicable:

* New Features
* Improvements
* Bug Fixes
* Security Updates
* Breaking Changes
* Known Issues (tracked in `BACKLOG.md`)
* Migration / Upgrade Notes

Changelog entries are concise and user-focused. `deploy/publish-release.sh` auto-stamps the release date into `CHANGELOG.md`'s "Unreleased" heading and updates `README.md`'s "Current version" line at cut time.

Release notes favor plain-language descriptions of user-visible behavior over changelist-style entries - see `CHANGELOG.md` itself.

---

# 8. Compatibility Policy

The platform maintains:

* API Compatibility
* Database Migration Compatibility
* Configuration Compatibility (see below)
* Client Compatibility (PWA installs, via a service-worker self-update mechanism)

Breaking changes are documented before release. `deploy/update.php` automatically syncs any new key present in a release's `.env.example` but missing from the live `.env` (blank-filled, never overwriting an already-configured value) on every deploy, and reports what it added in the deploy's success email.

---

# 9. Release Documentation & Governance

Every release package includes, in principle:

* Version Number
* Release Date
* Release Summary
* Changelog
* Deployment Notes
* Rollback Notes

Release approval calls for Development, QA, Security, and Operations sign-off before any production release. In practice, for a small-team project of this size, this is enforced mechanically rather than through separate human sign-off gates: the deploy pipeline itself refuses to proceed past a failed test suite, and `deploy/update.php` automatically rolls back to the previous release (keeping the broken one aside for inspection) if migrations or any post-deploy version-action fail after the atomic swap.

Rollback readiness is concrete, not aspirational: the update mechanism keeps the prior release folder and swaps via atomic rename, so a bad deploy can be reverted without data loss.

---

# 10. Change Management

Every change:

* Is traceable to an approved requirement or a documented bug.
* Is reviewed.
* Is tested (enforced by the pipeline, see Section 5).
* Is documented.
* Is versioned.

Change history remains available for future reference in `CHANGELOG.md`.

---

# 11. Upgrade Strategy & Deprecation Policy

Upgrade documentation defines prerequisites, backup requirements, deployment steps, database migration steps, configuration updates, verification steps, and rollback instructions - see `deploy/README.md` for the concrete, current version of this.

The deprecation policy calls for advance documentation, migration guidance, affected versions named, and replacement recommendations before any feature is removed from the live product.

---

# 12. Release History

### v1.0.0 - Initial Production Baseline

Authentication/profile, contacts, chat (1:1 + group, AJAX polling, no websockets), groups, media (incl. One View), calls (WebRTC, polling signaling), meetings (instant/scheduled/guest mode, whiteboard, calendar/ICS), an admin panel, an archive/backup-mail engine, PWA installability, and baseline security hardening.

### Early Patch Series

A fast run of patch releases stabilizing the deploy pipeline and fixing real production issues surfaced immediately after the initial baseline went live:

* The automated cPanel deploy pipeline itself shipped (`deploy/initialize.php`, `deploy/update.php`, `prepare-release.sh`/`publish-release.sh`), the root `VERSION` file was introduced, and a critical fix: every queued job (OTP/reset/invite emails, and all scheduled maintenance jobs) was silently dead on arrival because no queue worker runs on this hosting - everything now runs synchronously.
* Release-cut scripts moved to a disposable scratch worktree instead of mutating the developer's own working directory; a `#` in an SMTP password was fixed from being silently truncated by unquoted `.env` writes; the Admin "Test Email" crash on a blank SMTP row was fixed.
* `publish-release.sh` now pushes `develop` before merging/tagging `master`; `prepare-release.sh` now hard-fails if the test suite can't actually run, instead of silently "passing" with no tests found.
* A serious incident fix: `deploy/update.php` bootstrapped Laravel against a *temporary* folder path before the atomic swap, baking absolute paths (logs, sessions, compiled views, module discovery) into cache against a name that was about to disappear, which could take the whole live site down. Bootstrapping now happens only after the swap to the final path; automatic rollback on post-swap failure was added.
* One View media is now genuinely deleted from disk after first view (previously only a DB flag flipped, files sat on disk indefinitely); added a synthesized call ring tone, a client-side 30-second ring timeout, auto-dismissing call-error banners, and a `h-dvh`-based layout fix for mobile chat/meeting headers.

### Navigation, Calendar, and the Security/Identity Batch

The largest single minor release to date. Headline changes:

* Full navigation redesign - icon-only desktop sidebar rail, fixed mobile bottom bar, Chat became the post-login landing page (replacing Dashboard).
* Calendar became a real first-class feature (Month/Week/Day views, drag-to-reschedule) across several internal redesign passes.
* Guest-mode meetings (start/join with no account), ad-hoc email invitees, and a clearer waiting-room-vs-direct-join choice.
* Settings rebuilt into a multi-section hub (Profile, Preferences, Notifications, Chat defaults, Account & Security).
* Identity and security features: Two-Factor Authentication (TOTP), Passkeys (WebAuthn), and SSO with Google.
* Real push notifications, disappearing messages, broadcast lists, Communities, and chat export.
* A long tail of bug fixes: chat-header mobile layout, last-seen-privacy leaks in the chat API, guest-meeting auto-end, ICS invite links, plaintext meeting passwords, timezone-drift on scheduling, and nav-highlighting collisions.

### Multi-Account Login and a Settings/Presence Batch

New: password-free multi-account switching, admin-triggered forced password resets, inline call-log entries in chat, the WhatsApp-style Settings hub split into its own pages, "last seen X ago" replacing the bare word "Offline," and a safe self-updating PWA mechanism that never interrupts an active call/meeting. Fixes: meeting-room chats no longer leaking into the chat list as "Unknown," a mobile in-call toolbar overlap, and a first pass at backfilling first/last name for pre-existing accounts.

### Production-Incident Hotfix

A single-purpose patch release. A prior name-backfill fix caused a real production incident: an unchunked query against a database already holding a large number of seeded accounts either exhausted memory or overlapped with the next cron tick, leaving the site erroring out. Fixed on three fronts - the seeder now sets names at creation time, the real backfill runs as a small bounded batch job instead of one giant deploy-time action, and visiting your own Settings page fixes your own account immediately. This incident produced a standing rule, still in force: deploy-time, one-off version actions must be cheap and near-instant only.

### Multi-Select and Meeting Reliability

New: chat/message multi-select with bulk actions (archive/mute/delete, star/forward/delete), multi-select-aware Forward, an Account & Security sub-hub with a one-click Add-account entry, meeting reminders that actually deliver (push + in-chat), and a Google-Meet-style mobile meeting-room redesign. Fixes: mobile-UI/production-readiness items (input styling, a phantom video tile for a participant who never truly left, invisible touch-only action buttons), an Unarchive button that was hardcoded to always archive, and an API-scheduled email invite that failed to match an existing account the web form already matched correctly.

### Encryption, Offline Support, FCM

New: server-managed, zero-setup message encryption for 1:1 and small-group conversations (server-held keys, deliberately no password-unlock UI); full offline-first support for chat and contacts; guest whiteboard access (previously logged-in-only); migration of all push notifications to Firebase Cloud Messaging; and full-screen, keyboard-aware mobile chat matching mainstream chat-app conventions.

### Chat Polish Batch

A large bundled release: a fix for a real ciphertext leak (the chat list's last-message preview was the one surface that never decrypted client-side); message status ticks (sent/delivered/read, with a distinct read-state tick color and a per-recipient Info view); a redesigned Camera/Gallery/Document attachment picker with real multi-file previews; a genuine in-app camera (tap for photo, hold for video); message actions moved from an inline row to a floating card (adding Copy and un-react); a redesigned voice-note recorder with a live waveform; a composer/footer scroll-pin fix on mobile; and closing the configuration-compatibility gap described in Section 8 (automatic `.env.example` key sync on deploy).

### Meeting Reliability, Call Escalation, and Platform Hardening

Fixed the instant-meeting lobby flow so a creator is never dropped directly into a room without confirming camera/mic access; added a call-to-meeting escalation path so a 1:1 call can grow into a full meeting; redesigned the voice/video call UI with in-call screen share; added shareable meeting join links everywhere a meeting appears; automatic cleanup of inactive meetings and orphaned media via scheduled jobs; delivered push notifications while the app is backgrounded or closed with call-back/reply actions on missed calls; custom-built media viewers replacing native browser controls; and an Admin-facing environment-configuration manager.

---

# 13. Long-Term Version Strategy & Future Enhancements

The platform supports, and has followed in practice:

* Regular Patch Releases, several same-week turnarounds on real production incidents.
* Feature (Minor) Releases, roughly monthly cadence.
* No Major Release yet - no breaking change has been necessary since the initial baseline.

Future enhancements under consideration include automated version generation, automated changelog creation beyond the current date-stamping, continuous delivery, progressive rollouts, and AI-assisted release analysis.

---

# 14. REL Summary

This document establishes the versioning strategy, release lifecycle, changelog/release-note standards, compatibility policy, change-management and governance framework for the Meet platform, together with its release history to date.
