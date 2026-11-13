# Developer Handbook (DEV)

**Version:** 2.0

**Document Type:** Developer Handbook

**Priority:** Critical

---

# 1. Purpose

This handbook provides developers with the standards, workflows, engineering principles, and project expectations required to contribute to the Meet platform - the single, current reference for how this codebase is actually built and maintained.

---

# 2. Handbook Objectives

The handbook shall ensure:

* Consistent development practices
* High code quality
* Shared engineering standards
* Maintainable architecture
* Secure development
* Efficient collaboration
* Predictable delivery
* Truthful, living documentation (see § 9)

Every contributor shall follow this handbook.

---

# 3. Project Philosophy

The Meet platform shall be developed with emphasis on:

* Simplicity
* Reliability
* Security
* Performance
* Scalability within the actual hosting constraints (see § 8)
* Maintainability
* Documentation that reflects reality, not aspiration

Engineering decisions shall favor long-term sustainability over short-term convenience - including the discipline to remove a design outright rather than let two competing implementations coexist. When the encryption model was redesigned mid-development (see § 22), the old per-user keypair/password-unlock code, and its supporting tables, were deleted outright rather than deprecated in place. Prefer that kind of clean cut over accumulating parallel paths.

---

# 4. Engineering Principles

Developers shall apply:

* SOLID principles
* DRY (Don't Repeat Yourself) - when the same matching/validation logic is needed from two entry points (e.g. a web form and an API endpoint), extract one shared method rather than duplicating it. The cautionary example: the web scheduling form and the meetings API independently implemented invitee-email matching, and only one of them got it right - fixed by extracting a single `CalendarService::resolveInviteeEmails()` used by both.
* KISS (Keep It Simple) - don't build a second, parallel subsystem when an existing one can be bridged instead. Escalating a 1:1 call into a full Meeting reuses the Meetings module rather than building group-calling logic inside the pair-only Calls module a second time.
* Separation of Concerns
* Dependency Injection
* Composition over Inheritance
* Convention over Configuration - an established pattern in this codebase (client-side tab filters, guest-parallel controllers, icon-first UI - see § 15) should be reused for the next similar feature rather than reinvented.

---

# 5. Project Structure

The application is organized by module (`Modules/<Name>/...`) with the classic Laravel layers inside each:

* Controllers
* Services
* Repositories
* Models
* Policies
* Requests
* Resources
* Events
* Listeners
* Jobs (cron-triggered - see § 8; **never** queued)
* Commands
* Middleware

Responsibilities remain clearly separated. Two structural conventions are expected for any new feature in the same shape:

* **Guest-parallel controllers.** A feature usable by both authenticated users and unauthenticated meeting guests gets a second, dedicated controller (e.g. `GuestWhiteboardController` alongside `WhiteboardController`, `GuestMeetingController` alongside the authenticated meeting flow) reached through its own `{guestSession}`-scoped routes, rather than branching one controller's logic on "is this a guest." The service layer underneath takes a `MeetingParticipant` (which already models "whoever is in this meeting, guest or not") rather than a `User`, and resolves which side of a nullable-column pair to populate based on `$participant->isGuest()`.
* **Shared, once-implemented cross-cutting logic.** Anything that must behave identically from two entry points (web + API, registered + guest) belongs in one service method both call, not two independent implementations.

---

# 6. Developer Responsibilities

Developers are expected to:

* Follow project architecture and its established conventions (§ 15).
* Write maintainable code.
* Add appropriate tests - including a real browser check for anything user-facing (§ 17).
* Keep documentation current, including `CHANGELOG.md`.
* Participate in code reviews.
* Follow security standards, including this app's specific data-handling model (§ 18).

Every change shall be traceable and reviewed.

---

# 7. Development Workflow

```text id="developerworkflow"
Select Task
      ↓
Design Review
      ↓
Implementation
      ↓
Local Testing (incl. real browser verification)
      ↓
Code Review
      ↓
Merge
      ↓
Deployment Pipeline (see § 20)
```

No code shall bypass the review process.

---

# 8. Local Development

Developers shall prepare:

* Local Laravel environment
* Database
* Scheduler (`php artisan schedule:run`, or `schedule:work` for local iteration)
* Mail testing
* Storage
* Debug tools

**There is no queue worker in this application, in any environment, including production.** The production host is cPanel-hosted shared/managed hosting with no persistent background process - only cron. Consequently:

* **Never use `ShouldQueue`.** Every job class in this app runs synchronously or via a scheduled cron tick, never `dispatch()`'d onto a queue nobody is consuming. Real push notifications, for example, are sent synchronously inline from the request that triggers them, not queued.
* Anything that needs periodic execution is a real scheduled job registered in the console kernel/scheduler, ticking every few minutes (meeting-inactivity cleanup every 5 minutes, orphaned-media cleanup every 15 minutes, the disappearing-messages sweep every 5 minutes, backfill jobs every minute while backlog remains), not a `ShouldQueue` job assumed to run "eventually."
* Local environments should simulate this by running the scheduler, not a queue worker, so local behavior matches production.
* **Rebuild frontend assets after any Tailwind class change.** `public/build/` is committed to the repository (see § 20) - a page using a new utility class that was never compiled in will render correctly locally (via Vite dev) but break in production until `npm run build` is run and the build output committed. Always rebuild before considering a UI change complete.

---

# 9. Documentation Responsibilities

Every implementation shall update:

* Technical documentation relevant to the change
* API documentation
* Database documentation
* `CHANGELOG.md` - proactively, not only when asked. Pick the version bump size yourself (patch/minor/major) based on the actual change; a new "Unreleased" heading is used until a release number is finalized, and the final number is the user's call.
* `BACKLOG.md` - anything deliberately deferred or explicitly out of scope for a given pass is recorded there rather than silently left unaddressed. Temporary scaffolding introduced as a stopgap should also list its own planned removal there.

Documentation shall evolve alongside the codebase and reflect what actually ships, not what was originally planned.

---

# 10. Communication Standards

Engineering communication shall emphasize:

* Clarity
* Respect
* Traceability
* Technical accuracy

Design discussions shall be documented when decisions affect architecture. When a feature's design changes mid-implementation before reaching real users, record the "what we tried and why we replaced it" reasoning rather than leaving only the final state.

---

# 11. Coding Standards

## 11.1 PHP Standards

Developers shall follow:

* PSR-1, PSR-4, PSR-12
* Constructor dependency injection preferred over global state
* Descriptive class and method names

## 11.2 Laravel Standards

Application code shall use:

* Form Requests for validation
* Policies for authorization
* Eloquent relationships appropriately
* Service classes for business logic
* Resource classes for API responses
* Events/listeners for in-process, synchronous side effects (not as a substitute for a queue that doesn't exist)
* **Jobs, but never `ShouldQueue`** - see § 8. A "job" in this codebase means "class invoked by the scheduler," not "class dispatched to a worker."

Controllers shall remain lightweight; guest-facing variants mirror their authenticated counterpart 1:1 rather than branching internally (§ 5).

## 11.3 Frontend Standards

* Tailwind CSS v4 utility classes, mobile-first: a control's default state should be usable on a touch device, with `sm:`-and-up variants adding hover-only affordances where a mouse makes them discoverable (not the reverse) - real bug classes have come from getting this backwards (message actions and the archive button once being `:hover`-only and unreachable on mobile; a global 14px input font size causing iOS Safari to auto-zoom on focus, now `text-base` on mobile and `sm:` and up `text-sm`).
* Vanilla JS, no frontend framework. Prefer Pointer Events over mouse-only handlers for anything that needs touch support.
* Every user-facing confirmation, alert, or prompt uses the app's own themed modal component - never the browser's native `alert`/`confirm`/`prompt`, which cannot be styled and breaks the product's visual consistency.
* `overscroll-behavior` should be scoped deliberately: contain bounce/rubber-band at the document root (`html, body { overscroll-behavior-y: none; }`) and allow it only on the specific panes that need their own natural scroll.

---

# 12. Naming Conventions

Use consistent naming for Controllers, Services, Repositories, Models, Jobs, Events, Policies, Requests, Resources, database tables, and migrations. Names shall clearly communicate purpose - including guest-parallel classes, which are named by direct analogy to their authenticated counterpart (`GuestWhiteboardController` next to `WhiteboardController`, not e.g. `WhiteboardControllerV2`).

---

# 13. Git Workflow

```text id="gitworkflow"
Issue
      ↓
Feature Branch
      ↓
Implementation
      ↓
Commit
      ↓
Pull Request
      ↓
Code Review
      ↓
Merge
      ↓
Deployment
```

Direct commits to protected branches shall be restricted.

## 13.1 Branch Strategy

Recommended branches: `main`/`master`, `develop`, `feature/*`, `bugfix/*`, `hotfix/*`, `release/*`.

## 13.2 Commit Message Guidelines

Commit messages shall be concise, clearly describe the change, and reference related issue identifiers where applicable. Prefixes: `feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`.

**Commits in this project do not carry AI attribution lines** (no `Co-Authored-By: Claude` or equivalent), regardless of what tooling assisted in writing the change.

---

# 14. Pull Requests & Code Review

Every pull request shall include a change summary, related issue, testing performed (including real browser verification for user-facing changes - § 17), screenshots for UI changes, and documentation updates.

Reviewers shall verify architecture compliance, coding standards, security, performance, testing, and documentation. Merge only after successful review, passing tests, security validation, documentation updates, and required approvals.

---

# 15. Architecture Practices

Each layer keeps a single responsibility (Controllers → request handling, Requests → validation, Services → business logic, Repositories → data access, Models → domain entities, Resources → API formatting, Policies → authorization, Jobs → scheduled/cron processing, Events → in-process communication). Three additional conventions are established and expected for new work in the same shape:

* **Client-side tab filtering over one already-loaded dataset**, instead of a separate query per tab. Chat's All/Chats/Groups tabs, Meetings/History/Calendar's merged tabs, and the Admin panel's pill-tab nav all filter one already-fetched list client-side rather than hitting the backend once per tab. Use this pattern for the next segmented-view feature.
* **Icon-first UI, calibrated to the surface's stakes.** User-facing high-traffic surfaces (Settings, the meeting room toolbar, Chat, Contacts) go icon-only with tooltips. Lower-traffic, higher-stakes surfaces (Admin) keep the label alongside the icon, since an unlabeled icon is more likely to be misclicked somewhere consequential like Delete or Force-logout. Choose the treatment based on which category the new surface falls into, not by default.
* **Nullable-pair columns for optional actor identity.** Where a row can be created by either a registered user or a guest, model it as two nullable FK columns (e.g. `created_by` → `users`, `created_by_participant_id` → `meeting_participants`) with exactly one populated per row, rather than a single polymorphic-ish column or forcing a `User` row to exist for guests.

---

# 16. Database Practices

Developers shall use migrations for schema changes, maintain foreign key integrity, optimize queries, avoid unnecessary duplication, and keep changes backward compatible where practical.

**Bounded, scheduled batch processing - not deploy-time, unchunked scripts - for anything scaled to table size.** This is a hard-learned rule, not a stylistic preference: a data-backfill fix once ran as a single unchunked query directly at deploy time. Against a database holding a large number of seeded accounts, that single query either exhausted memory or ran long enough for the next cron tick to trigger an overlapping second deploy attempt - a real production incident, fixed by:

* Making any seeder/creation path set required fields correctly at creation time, so the backlog can't grow.
* Moving the actual catch-up to a scheduled job processing one bounded batch (1,000 rows) per tick, disabling itself via a Settings flag once nothing is left to do.
* Adding a same-request, single-row fallback (visiting the affected page fixes that one record immediately) as a safety net that doesn't wait for the batch job.
* Restricting deploy-time, one-off version actions to cheap, near-instant actions only (e.g. flipping a Setting flag) going forward - anything whose cost scales with table size belongs in a scheduled job, never a deploy-time action.

Temporary migration scaffolding (a batch job plus a per-page fallback, in this example) is itself temporary - track its planned removal in `BACKLOG.md` rather than letting it become permanent by default.

---

# 17. Testing Responsibilities

Every implementation shall include appropriate unit, feature, integration, and API tests, with comprehensive coverage for critical business logic. Beyond that baseline, this codebase treats **real, tool-driven verification as mandatory, not optional**, before a change is considered complete:

* **Verify with a real browser (Puppeteer), not just unit-level assertions**, especially for anything involving layout, viewport behavior, or timing. The keyboard-aware chat container is verified by a real Puppeteer test that shrinks the viewport to simulate an on-screen keyboard and confirms the composer stays visible; the no-scroll month calendar grid is verified against real 375×667 and 6-row-month viewports; the mobile bottom-nav overlap fix is confirmed via a real `scrollIntoView` repro before and after.
* **Verify against real external infrastructure when a feature depends on it, not only mocks.** Push notification delivery is confirmed with a live browser test obtaining a genuine token from Firebase's real servers, a second live test confirming the Admin SDK sends through that exact token, and a `tinker` check confirming real service-account auth and correct classification of an invalid token. Stale-subscription recovery is verified the same way - a genuinely mismatched subscription created first, then confirmed recoverable.
* Rebuild frontend assets (`npm run build`) before testing any change that added new CSS classes - a dev-server-only render can look correct while the committed build output is stale (§ 8, § 20).
* Physics-based browser automation does not perfectly reproduce every real device quirk (iOS Safari's overscroll bounce chaining is one such gap - a difference discovered only via real-device reports after automated tests had already passed). Treat automated test passes as necessary, not sufficient, for anything with strong platform-specific rendering behavior.

A change is not "complete" until it has been exercised this way - this is a standing project expectation, not a per-task reminder.

---

# 18. Security Responsibilities

Developers shall validate all external input, enforce authorization, protect sensitive data, avoid exposing internal details, and follow `Documentation/MEET_DOCS/SEC.md`.

Specific, established conventions in this codebase:

* **Message encryption is server-managed, not zero-knowledge, by deliberate design - and there is never a password-unlock UI.** The app itself generates and holds every conversation's AES-256-GCM key server-side (`conversation_keys.key_material`, encrypted at rest via Laravel's `Crypt` facade); access is checked live at fetch time against current conversation membership, not baked in at issuance. An earlier, genuinely zero-knowledge design (per-user RSA keypair, password-wrapped private key, an "unlock" prompt) was built and then removed outright before reaching real users, replaced by the current model, by direct instruction. Do not reintroduce a client-side unlock step for message encryption.
* **Step-up re-authentication for sensitive, one-way actions.** Disabling 2FA, removing a passkey, deactivating an account, and editing live `.env` values all require re-entering the actor's own account password immediately before the action, regardless of an existing session's age.
* **Meeting passwords and comparable secrets are hashed**, never stored or compared in plaintext.
* **Privacy settings must be enforced at every surface that exposes the data, not just the first one built.** `last_seen_visibility` was correctly enforced in the Contacts API from the start but was missed entirely in the Chat conversation API - both the polled endpoint and an unguarded initial Blade render - until that gap was closed. When adding a privacy-gated field to a new surface, check every existing surface that already renders the same underlying data.
* Sensitive-looking `.env` keys (`*_KEY`/`*_SECRET`/`*_PASSWORD`/`*_TOKEN`) are masked by default in the Admin environment editor; follow the same masking convention for any future admin-facing secret display.

---

# 19. Performance Guidelines

Developers shall optimize database queries, API response times, background/cron processing, file operations, and memory usage - with particular attention to unchunked operations against large tables (§ 16) and to the fact that scheduled jobs run on a shared-hosting cron tick, not a dedicated worker, so each tick must complete quickly and idempotently. Performance optimizations shall not compromise maintainability.

---

# 20. Deployment & Release Practices

This app deploys to cPanel-hosted shared/managed hosting with **no ability to `exec()` arbitrary commands on the server** and no persistent process beyond cron (see § 8). Consequences for how code is written and shipped:

* `vendor/` and the compiled `public/build/` frontend assets are committed to the repository - there is no server-side `composer install` / `npm run build` step. Always run and commit a fresh build after any frontend change, and commit `vendor/` updates after any dependency change.
* One-off, deploy-time version actions are reserved for cheap, near-instant actions only (flipping a Setting flag, etc.) - never anything whose cost scales with table size (§ 16).
* New environment variables required by a release are synced automatically into the live `.env` by the deploy pipeline, which adds any key present in the new release's `.env.example` but missing from the live file, blank, never overwriting an already-configured value.
* See `deploy/README.md` for the full pipeline; do not bypass it with manual server-side commands.

---

# 21. Long-Term Maintenance & Technical Debt

Technical debt shall be identified, documented, prioritized, reviewed, and scheduled for resolution; critical technical debt shall not accumulate across releases. Two concrete practices support this:

* **Track deliberately-deferred scope in `BACKLOG.md`**, not silence - what a given pass explicitly didn't cover is named there, distinguishing "not done yet, on purpose" from "forgotten."
* **Track temporary scaffolding for removal.** A stopgap introduced to solve an urgent problem should record its own planned removal once its job is done, rather than becoming permanent by default.

---

# 22. Engineering Ethics

Developers shall write honest, maintainable code; respect security and privacy; avoid shortcuts that reduce quality; communicate technical risks clearly, including in writing when a design choice is a deliberate reversal of an original plan; and support collaborative engineering practices.

---

# 23. Developer Growth

Developers are encouraged to strengthen expertise in Laravel, PHP, database design, security, performance optimization, testing (including real-browser and real-infrastructure verification, § 17), and the specific constraints of shared/managed hosting without a queue worker (§ 8, § 20).

---

# 24. Engineering Checklist

Before requesting review, verify:

* Code builds successfully; frontend assets are rebuilt if any Tailwind class changed.
* Tests pass, including a real-browser check for any user-facing/layout-affecting change.
* No `ShouldQueue` job was introduced.
* No debugging code remains.
* `CHANGELOG.md` is updated with an appropriate version bump.
* Documentation is updated.
* Static analysis passes.
* Security considerations are addressed, including step-up re-auth for any new sensitive action and privacy-setting enforcement across every surface exposing the affected data.
