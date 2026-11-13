# Laravel Development Guide (LDG)

**Version:** 2.0

**Document Type:** Laravel Engineering Conventions

**Priority:** Critical

---

# 1. Purpose

This document defines the Laravel-framework-level conventions, architectural patterns, and implementation standards used throughout the Meet platform: how modules, controllers, services, repositories, models, migrations, jobs, and the scheduler are actually structured and expected to behave. It is the Laravel-specific companion to `Documentation/MEET_DOCS/DEV.md`, which covers general engineering practice (workflow, git, review, testing discipline, deployment pipeline); this document goes one layer deeper into how those practices map onto Laravel's own primitives, and does not restate DEV.md's general material except where a Laravel-specific detail depends on it.

---

# 2. Technology Stack

* **Framework:** Laravel 12, on the modern slim application skeleton - there is no `app/Http/Kernel.php` or `app/Console/Kernel.php`. `bootstrap/app.php` is the single configuration point: `Application::configure()` registers routing (`web.php`, `api.php`, `console.php`, plus the `/up` health endpoint), middleware, and exception rendering in one place.
* **PHP:** 8.3+.
* **Database:** MySQL 8+ in production; SQLite (`:memory:`) in the automated test environment (see § 24). Migrations that use raw, driver-specific SQL must account for both, and defensively for PostgreSQL as a third supported driver (see § 10).
* **Modules:** `nwidart/laravel-modules`, one module per bounded domain (Authentication, User, Contacts, Chat, Groups, Meetings, Calls, Whiteboard, Media, Admin, Core), each a self-contained Laravel application slice with its own `module.json`, service provider, and full set of the layers below.
* **Frontend:** Blade + Tailwind CSS v4 + vanilla JavaScript, built via Vite. `public/build/` is committed to the repository (see § 26); there is no server-side asset build step.
* **Authentication:** Laravel Sanctum, used in **stateful SPA mode** - the Blade UI's own JavaScript calls the same `/api/v1/*` JSON endpoints as any other API client, authenticated via the browser's session cookie rather than a bearer token. This is a deliberate choice: it lets the web UI reuse the exact same, already-tested API controllers instead of maintaining a parallel web-specific controller layer for the same data.
* **Queues:** none, anywhere, in any environment. See § 15 - this is the single most load-bearing constraint on this codebase's Laravel conventions and it recurs throughout this document.
* **Scheduler:** the Laravel Scheduler, driven by a single cPanel cron entry running `php artisan schedule:run`.
* **Cache:** file cache by default; array driver in tests.

---

# 3. High-Level Architecture & Request Flow

Every request follows the same layered flow, regardless of whether it arrives via `web.php` or a module's `api.php`:

```text
Client
  ↓
Route (module-owned)
  ↓
Middleware (global → group → route)
  ↓
Controller
  ↓
Form Request Validation
  ↓
Service (business logic)
  ↓
Repository (data access) - where the module has one
  ↓
Model / Database
```

Cross-cutting concerns - Policies, Events, Jobs (scheduler-driven, never queued), Resources, logging - sit alongside this flow rather than inside it. Controllers never write SQL, never embed business logic, and never call a repository or model directly for anything beyond the simplest read; Services own business rules and coordinate repositories, dispatch events, and manage transactions; Repositories isolate persistence and query concerns from the Service layer above them. Not every module needs a Repository - a module whose data access is a handful of straightforward Eloquent queries may keep them in the Service directly - but any module with non-trivial query composition, filtering, or pagination logic extracts it into one.

---

# 4. Module & Project Structure

The application is organized by module (`Modules/<Name>/...`), each carrying the classic Laravel layers internally:

```text
Modules/<Name>/
    config/
    database/
        migrations/
        factories/
        seeders/
    DTO/
    Exceptions/
    Http/
        Controllers/
        Requests/
        Resources/
    Jobs/
    Models/
    Policies/
    Providers/
    Repositories/
    resources/
        views/
        js/
    routes/
        api.php
        web.php
    Services/
    Tests/
```

Two structural conventions recur across modules and are expected for any new feature built in the same shape:

* **Guest-parallel controllers and services.** A feature usable both by authenticated users and by unauthenticated meeting guests gets a second, dedicated controller (`GuestWhiteboardController` alongside `WhiteboardController`, `GuestMeetingController` alongside the authenticated meeting flow), reached through its own `{guestSession}`-scoped routes, rather than branching one controller's logic on "is this a guest." See § 19 for the full pattern.
* **Shared, once-implemented cross-cutting logic.** Anything that must behave identically from two entry points - a web form and an API endpoint, a registered user's flow and a guest's - belongs in one Service method both call, never two independent implementations. See § 20.

API routes are never defined in a single monolithic `routes/api.php`; that file exists only as a pointer comment ("routes live per-module") - each module owns and loads its own `routes/api.php`/`routes/web.php` from its service provider.

---

# 5. Routing Conventions

* Routes stay thin: they map an HTTP verb and path to a controller action and declare their middleware. No business logic, no closures containing real work.
* API endpoints are versioned under `/api/v1/...`. A breaking change is introduced as a new version path, not a mutation of the existing one.
* Implicit route-model binding is preferred (`/meetings/{meeting}`); guest-facing routes bind on `{guestSession}` instead of a `User`-keyed parameter, resolved against the guest session table.
* Route names are descriptive and namespaced by module/resource (`chat.index`, `meetings.guest-landing`, `calendar.reschedule`).
* `bootstrap/app.php` is also where request-level routing redirects are declared, not scattered across middleware classes - for example, where an unauthenticated request should be sent (`redirectGuestsTo`) is conditioned on whether the request is an API call (`null`, so it falls through to the JSON 401 handler in § 18) or a Blade page (the login route), and where an already-authenticated visitor to a guest-only page should land (`redirectUsersTo`) points at this app's actual landing page - not necessarily whatever Laravel's own default convention assumes (Laravel's built-in `RedirectIfAuthenticated` looks for a route literally named `dashboard` first, which is not this app's actual post-login destination; that default needs an explicit override wherever the real landing route differs from the framework's assumption).
* Route caching is enabled in production; closures in production route definitions are avoided in favor of controller actions so caching remains effective.

---

# 6. Controllers

Controllers are responsible only for: receiving the request, invoking Form Request validation, calling exactly one Service method (or a small, obvious sequence of them), and returning an API Resource or a Blade view/HTTP response. They never write SQL, never contain business rules, never send email, and never touch a queue (there isn't one).

Every guest-facing controller mirrors its authenticated counterpart action-for-action rather than branching internally on `is_guest`. This keeps both variants simple, testable independently, and easy to compare side by side when one changes - see § 19 for why this convention exists and how the underlying Service layer supports it.

---

# 7. Services

The Service layer owns all business logic: registering a user, creating a meeting, sending a message, escalating a call, resolving invitees, rotating an encryption epoch. Services coordinate one or more Repositories, dispatch Events for in-process side effects, wrap multi-step writes in database transactions, and throw domain-specific exceptions - they never return a view response themselves.

Two conventions specific to this codebase govern when and how a Service should be extended versus a new one created:

* **Bridge, don't duplicate a module.** When a feature in one module would require re-implementing something an adjacent module already does well, reuse that module's Service instead of building a second, parallel implementation. Escalating an active 1:1 call to a group call does not build N-way mesh calling logic inside the pair-only Calls module a second time - it calls the Meetings module's own instant-meeting creation, admits both original call participants directly (bypassing the lobby, since they are already mutually engaged in a live call), ends the original call row, and records the resulting meeting id back on it. The receiving party is redirected automatically the next time their own call-status poll notices the escalation, rather than needing a bespoke second signaling path.
* **Extract one shared method the moment two entry points need the same rule.** If a piece of matching/validation/resolution logic is needed from both a web form and an API endpoint, it is extracted into a single Service method both controllers call - see § 20 for the canonical example and the bug class it prevents.

---

# 8. Repositories

Repositories abstract data access - reads, writes, pagination, filtering, search, and any query composition non-trivial enough to clutter a Service. They expose only the operations their Service actually needs, never raw query-builder internals. Complex query logic (list-with-filters-and-pagination, aggregate unread counts, roster queries with staleness filtering) belongs here, not duplicated inline in the Service that calls it.

A Repository takes the actor its module actually deals with - most commonly a `User`, but see § 19 for modules that operate over guests as well, where the Repository/Service pair is written against `MeetingParticipant` instead so the same code path serves both kinds of actor without an `if ($isGuest)` branch threaded through it.

---

# 9. Models & Eloquent Conventions

* Every model declares its mass-assignment protection explicitly via `$fillable`; nothing is left unguarded without a documented reason.
* Sensitive fields (password, remember token, any internal security field, raw key material) are declared `$hidden` and never exposed through an API Resource - Resources are the enforcement boundary for API output either way (see § 17), but hiding at the model level is the first line of defense.
* Native Laravel casting is used for booleans, dates, arrays/JSON, and decimals rather than manual encode/decode in accessors.
* Accessors/mutators stay lightweight and deterministic; they never issue their own database queries.
* Soft deletes are used for anything that may need administrative restoration (users, groups, files, meetings, messages) - a message "deleted for everyone" is a soft delete, not a hard delete, specifically so a later cleanup step (orphaned-media sweep, § 21) can still reason about whether its attached file is safe to remove.
* Eager loading is used deliberately to avoid N+1 queries, but only for relationships actually needed on that response - eager-loading everything "just in case" is avoided for its own memory cost.
* Query scopes are used for durable filters (active, archived, scheduled); a scope is not a substitute for a Repository method that composes several conditions dynamically.
* Every model with any non-trivial creation logic has a Factory; seeders are kept clearly separated between "real bootstrap data" (roles, an admin account) and synthetic/load-test data, since the latter needs its own identifying marker so it can be filtered, reported on, and cleaned up separately from genuine user accounts (see `Documentation/LOAD_TESTING.md`).
* **Nullable-pair columns model optional actor identity.** Where a row can be created or acted on by either a registered user or an unauthenticated guest, it is modeled as two nullable FK columns - one to `users`, one to a guest/participant table - with exactly one populated per row, rather than a single polymorphic-ish column or forcing a throwaway `User` row to exist for guests. `MeetingParticipant` is the canonical "whoever is in this meeting, guest or not" entity these pairs resolve against; see § 19.

---

# 10. Migrations & Database Conventions

Migrations are written to run correctly against MySQL in production and SQLite in the automated test suite, and defensively against PostgreSQL as a third supported driver - never assuming only one database engine will ever execute them.

**Cross-driver-safe enum widening.** Adding a new allowed value to a MySQL `enum` column (a new signal type, a new status) cannot be expressed as a portable `Schema::table()` call, because the three supported drivers represent enum-like constraints completely differently:

* **MySQL** - a raw `ALTER TABLE ... MODIFY column ENUM(...)` statement.
* **PostgreSQL** - the existing `CHECK` constraint must be dropped (`DROP CONSTRAINT IF EXISTS`) and re-added with the widened value list.
* **SQLite** - there is no in-place `ALTER COLUMN` for this at all. The migration creates a distinctly-named temporary table with the new enum definition, copies every row across with a raw `INSERT INTO ... SELECT * FROM ...`, drops the original table, and renames the temporary one into its place. Reused temp-table names are a real hazard here: SQLite does not rename a table's indexes when the table itself is renamed, so a **later** migration reusing an earlier migration's temp-table name can collide with an index left behind under that older name. Each such migration picks its own distinct temp-table name rather than reusing one from a previous rebuild.

The same three-way branch (MySQL `MODIFY`, Postgres constraint drop/re-add, SQLite full rebuild) applies to widening a nullable/foreign-key constraint on an existing non-nullable column - e.g. making an "organizer" or "invited user" column nullable to support a guest- or email-only actor. On MySQL/Postgres this is a `dropForeign()` + raw `MODIFY`/constraint change + `foreign()` re-add; on SQLite it is the same create-temp/copy/drop/rename sequence, selecting an explicit column list in the `INSERT ... SELECT` (not `SELECT *`) whenever the new and old table shapes differ (e.g. a new nullable sibling column was added at the same time).

**Bounded, scheduled batch processing - never a deploy-time, unchunked script - for anything whose cost scales with table size.** A one-off backfill (populating a newly-added column for every existing row) must never run as a single unchunked query at deploy time: against a large table, that single query can exhaust memory or simply run long enough for the next cron tick to trigger an overlapping second deploy attempt, corrupting the deploy state and 500ing the site. The correct shape is:

1. Fix the creation path first, so the backlog cannot grow further (a registration listener or seeder that was bypassing the normal column-population logic gets it added at creation time).
2. Move the actual catch-up into a small scheduled job (`Schedule::job(...)->everyMinute()`) that processes one bounded batch (on the order of 1,000 rows) per tick and disables itself via a settings flag the moment nothing is left to process.
3. Add a same-request, single-row fallback as a safety net - visiting the affected record's own page fixes just that row immediately, without waiting for the batch job to reach it.
4. Restrict deploy-time, one-off version actions to cheap, near-instant work only (flipping a settings flag, clearing a cache) - anything whose cost scales with table size belongs in a scheduled job, full stop.

Temporary migration/backfill scaffolding built this way (the batch job, the per-page fallback) is itself temporary; its planned removal is tracked in `BACKLOG.md` once the backlog it exists to drain is confirmed empty, rather than left to become permanent by accident.

---

# 11. Form Requests & Validation

Validation lives in Form Request classes, one per operation, organized under each module's `Http/Requests/`. Form Requests validate input, normalize it where appropriate (trimming, case normalization), and authorize the request - they never contain business logic. Validation failures return HTTP 422 with the app's standard field-level error envelope (see § 18) before any Service method runs. Custom, reusable validation rules are used for recurring domain constraints (a valid meeting time window, an available room, a maximum-participants check) rather than re-deriving the same rule inline in multiple requests.

---

# 12. Policies, Gates & Authorization

Authorization uses Laravel Policies as the default mechanism for any check scoped to a specific model instance (can this user view/update/delete/restore this meeting, this file, this group). Gates are reserved for genuinely global permissions (an administrative capability, a feature flag) that are not tied to one entity. Authorization runs before the Service method that would perform the action, either via the Form Request's `authorize()` method, a policy check in the controller, or route middleware - never after the fact. Every protected controller action verifies permission before calling into its Service, and reviewers explicitly check for this during code review (see DEV.md § 14).

A step-up re-authentication step is layered on top of ordinary policy checks for a specific class of sensitive, one-way actions: disabling two-factor authentication, removing a passkey, deactivating one's own account, and editing live environment configuration all additionally require re-entering the actor's own account password immediately before the action executes, regardless of how recently they last authenticated. This is enforced in the Service/controller for that specific action, not as a general-purpose middleware, since it applies to a small, deliberate set of actions rather than every write.

---

# 13. Middleware

Global middleware is registered in `bootstrap/app.php`'s `withMiddleware()` closure rather than a `Kernel.php` middleware array. A recurring, real ordering hazard is worth calling out explicitly: middleware that depends on `request()->user()` being resolvable (an admin bypass check, a maintenance-mode exception for admins) must run **after** the session/authentication middleware has actually run - for the `web` group that means after `StartSession`, for `api` it means after Sanctum's stateful middleware. Registering such middleware as a bare global `append()` runs it before either of those in the pipeline, so `request()->user()` is silently `null` every time and the check never actually does anything - a real, previously-shipped bug (an admin bypass for maintenance mode that never worked for any admin, ever, because of exactly this ordering mistake). The fix is to append it specifically to the `web`/`api` middleware groups (`$middleware->web(append: [...])`, `$middleware->api(append: [...])`), which are guaranteed to run after those groups' own session/auth middleware.

Route-level middleware is layered per concern: authentication (`auth:sanctum`), role/permission checks (an `admin` alias resolving to a dedicated `EnsureUserIsAdmin` middleware), a forced-password-change gate for accounts an administrator has reset with `must_change_password` set, and rate limiting on sensitive or high-volume endpoints (login attempts, OTP verification, message sending, media uploads, administrative configuration changes).

---

# 14. Events & Listeners

Services dispatch Events for completed business actions (a user registered, a message was sent, a meeting ended) so that in-process, synchronous side effects - writing an audit log entry, updating an aggregate counter, clearing a cache - can be decoupled from the Service method that triggered them. Because there is no queue (§ 15), Listeners in this codebase are always synchronous; an Event/Listener pair here buys separation of concerns, not asynchrony. A Listener that would need real background execution time is not a fit for this pattern - that work instead runs synchronously inline at the point it is triggered (see § 16 for how this plays out for notification delivery specifically), or as a scheduled job if it can tolerate running on the next cron tick instead of immediately.

---

# 15. Jobs & the Scheduler

**There is no queue worker in this application, in any environment, including production.** The production host is cPanel-managed shared hosting with no persistent background process of any kind - only cron. This is not a temporary limitation to design around; it is a permanent hosting constraint that shapes every "Job" class in the codebase:

* **`ShouldQueue` is never implemented, anywhere.** A class that looks like a queue job (uses `Dispatchable`/`Queueable`/`SerializesModels`) but is invoked exclusively through the Scheduler, never `dispatch()`'d onto a queue nobody is consuming, is the only shape a "Job" takes here. Class docblocks say so explicitly (`Deliberately NOT ShouldQueue`) precisely because the pattern looks queueable at a glance and a future contributor might otherwise "fix" it by adding the interface.
* **Recurring work is registered in `routes/console.php`** via `Schedule::job(new SomeJob)->everyMinute()` / `->everyFiveMinutes()` / `->hourly()` / `->daily()`, etc. - there is no `app/Console/Kernel.php` to hold this in a Laravel 12 slim-skeleton app; the scheduler definition lives directly alongside Artisan command registration in `routes/console.php`.
* **Every scheduled job must be idempotent and cheap enough to finish comfortably within its own tick**, since the next cron invocation of `schedule:run` arrives on a fixed interval regardless of whether the previous logical unit of work is "done" in a larger sense. Jobs that process a backlog (§ 10) self-limit to a bounded batch per run and self-disable via a settings flag rather than assuming they get to run to completion in one shot.
* **A job that must self-disable once its purpose is served should do so cheaply** - checking one settings flag and returning immediately - so it can be left registered in `routes/console.php` permanently rather than requiring a follow-up commit to remove the schedule entry once it stops being needed.
* Real, shipped scheduled jobs in this codebase include: expired-OTP cleanup (hourly), expired-guest-session cleanup and stale-presence/missed-call/meeting-reminder sweeps (every minute), disappearing-message and stale-typing-status cleanup (every five minutes), inactive-meeting auto-end (every five minutes), orphaned-media cleanup (every fifteen minutes), and daily archive/retention jobs - each a single, narrowly-scoped responsibility, never a general-purpose "do maintenance" job.
* Local development should run the scheduler (`php artisan schedule:work` for fast local iteration), not a queue worker, so local behavior matches what production actually does.

---

# 16. Notifications, Push & Synchronous Delivery

Because there is no queue, notification delivery - database, email, and real OS-level push via Firebase Cloud Messaging - is sent **synchronously, inline, from the request that triggers it**, gated per-recipient on their own notification-preference toggles. This is a deliberate consequence of § 15, not an oversight: a "fire and forget onto a queue" pattern has nothing to fire onto here. Delivery failures (a stale or revoked push subscription, a bounced email) are caught and logged without interrupting the triggering request's own response.

Push notification delivery specifically is verified against real infrastructure, not mocked - see § 24. Sent payloads are data-only (no platform-rendered notification body), so the service worker's own push handler stays in full control of exactly what is displayed and how a notification action (call-back, quick-reply) behaves, matching the same contract regardless of which specific push transport sits underneath it.

---

# 17. API Resources & Response Standardization

Every API response is transformed through a Laravel API Resource - never a raw model `toArray()` - so hidden/sensitive fields, internal-only columns, and encryption metadata are shaped deliberately rather than by omission. A Resource that exposes an encrypted field's ciphertext must also expose whatever the client needs to decrypt it (encryption mode, IV, key version) as sibling attributes, since the API layer is the only place that can package those consistently; any server-rendered Blade view that also displays the same underlying data must independently ensure it never renders raw ciphertext as if it were plaintext - a gap that is easy to miss on a newly-added rendering path (an initial page render, an export, a starred-items list) even after the primary API surface handles it correctly, so each new server-rendered surface exposing message-like content is checked against this specifically, not assumed safe by association with the API Resource that already handles it.

API responses use one consistent success/error envelope across every endpoint (`success`, `message`, `data`, `errors`), enforced centrally rather than per-controller - see § 18.

---

# 18. Exception Handling

Exception-to-response mapping is centralized in `bootstrap/app.php`'s `withExceptions()` closure, conditioned on whether the request is an API call (`$request->is('api/*')`) or a Blade page load:

* API requests get a standard JSON envelope for every framework-level exception class: `AuthenticationException` → 401, `AuthorizationException` → 403, `ModelNotFoundException`/`NotFoundHttpException` → 404, `ValidationException` → 422 (with field-level errors under `errors`), each shaped as `{success: false, message, data: null, errors}`.
* Web (Blade) requests fall through to Laravel's ordinary error handling, rendering the app's own `resources/views/errors/*.blade.php` templates instead of JSON.

Domain-specific exceptions (an invalid meeting state, a permission-denied case with a more specific message than the generic 403, a group/member limit exceeded) are defined per module where a generic HTTP status alone would lose useful information, and are caught and translated to the same standard envelope rather than leaking a raw stack trace or internal message to the client.

---

# 19. Guest-Parallel Architecture

Several features in this app must work identically for a fully authenticated user and for a visitor with no account at all (an instant meeting's guest participants, the meeting whiteboard). The established pattern for this, expected for any new feature in the same shape, is:

1. **A second, dedicated controller**, not a branch inside the authenticated one - `GuestWhiteboardController` next to `WhiteboardController`, `GuestMeetingController` next to the authenticated meeting controller - reached through its own routes scoped by `{guestSession}` rather than a `User`-keyed route parameter.
2. **The Service layer underneath takes a `MeetingParticipant`, not a `User`.** `MeetingParticipant` already models "whoever is in this meeting, guest or not" (it wraps either a real `user_id` or a `guest_session_id`, and exposes `isGuest()`/`displayName()`), so a single Service method serves both callers without an `if ($isGuest)` branch - it resolves which side of a nullable-pair column (§ 9) to populate based on `$participant->isGuest()`.
3. **A dedicated resolver method guards against cross-resource access** - a guest session belongs to exactly one meeting, and any guest-scoped Service method that accepts a resource id belonging to a *different* meeting must reject it explicitly (a `participantForGuestSessionAndMeeting()`-style guard), since a guest has no account-level ownership check to fall back on the way an authenticated user's policy would provide.
4. **Guest-facing views are copied from the authenticated view with only the endpoint base changed**, not rewritten independently - the guest room's script targets the same guest-scoped API base every other guest action on that page already uses, keeping the two surfaces from drifting into near-duplicate, subtly-inconsistent implementations over time.
5. **API Resources report the actor's display identity generically** (e.g. "Alice" vs. "Bob (Guest)") so a mixed roster of registered and guest participants renders correctly without the consuming code needing to know which kind of actor produced each row.

This pattern is preferred over branching a single controller/service on "is this a guest" because it keeps each variant's logic simple and independently testable, and because a guest-parallel class is trivially discoverable by naming convention (never named e.g. `WhiteboardControllerV2` - always the direct guest-prefixed analogue of the class it mirrors).

---

# 20. Cross-Cutting Logic & Shared Services

Anything that must produce identical results from two different entry points - a web form and an API endpoint, a registered-user path and a guest path - is implemented exactly once, as a Service method both entry points call. The canonical example: a meeting-scheduling web form and the meetings API both need to match an invited email address against an existing registered account (so a matched invitee gets a full invitation record rather than a bare ad-hoc one) - these were once implemented independently, and only one of the two got the matching logic right; the API path skipped it entirely. The fix was to extract the matching logic into one shared method the Service exposes (e.g. `resolveInviteeEmails()`) and have both the web controller and the API controller call it, eliminating the possibility of the two paths silently diverging again.

When adding a second entry point for existing business logic, the default assumption should be "there is already a Service method for this - call it," not "write the equivalent logic again for this new caller."

---

# 21. Storage, Media & File Handling

* File storage is abstracted through Laravel's Filesystem; local disk today, with the storage layer written so a future S3-compatible driver requires only configuration, not application code changes.
* Stored filenames never reuse user-supplied names directly (a UUID or equivalent generated identifier is used instead); the original filename is retained as metadata only.
* Large uploads use chunked transfer with a defined merge/cleanup workflow; interrupted uploads are resumable rather than requiring a full restart.
* **A soft-deleted message's underlying media file is only removed once nothing else still references it.** "Delete for everyone" soft-deletes the message row and then checks whether any other row (most commonly a forward, which intentionally reuses the original upload's media id rather than duplicating the file) still points at that same media id before deleting the actual file from disk - the check must correctly account for the just-soft-deleted row itself via the model's own `SoftDeletes` scope, or a forward's shared file gets deleted out from under it.
* **Orphaned and stale media are swept by a scheduled job, not left to accumulate.** A view-once file that was marked consumed but never actually deleted (normally removed synchronously by the controller that streams it, with the scheduled sweep acting only as a safety net for an interrupted stream), and any media row past a short grace period that nothing at all references any more (an abandoned upload whose message was never actually sent, since upload and send are two separate API calls) are both cleaned up on a recurring schedule (§ 15), never accumulated indefinitely.
* Media the server itself must remain able to decrypt (as opposed to conversation-scoped end-to-end content, § 25) uses server-held encryption at rest via the `Crypt` facade, a materially different trust model from per-conversation message encryption - the two are not interchangeable and a new media feature should be deliberate about which one it needs.

---

# 22. Frontend Integration Points (Laravel Side)

This section covers only the Laravel-side conventions that touch the frontend; general frontend engineering standards (Tailwind conventions, vanilla-JS-only, Pointer Events, themed modals) live in DEV.md § 11.3 and are not repeated here.

* Blade views that render message-like content must never render ciphertext directly for an end-to-end-encrypted conversation (§ 17) - a server-rendered page showing such content renders a placeholder instead, exactly like the treatment already applied to a CSV export of the same data.
* A page opting into the full-bleed, chrome-free mobile layout (an open chat thread) does so via an explicit, opt-in Blade component prop (default `false`), never a global layout change - so exactly one view sets it and every other page's layout is provably unaffected.
* Any page-level JavaScript that depends on the visual viewport (keyboard-aware layout) is scoped behind a `matchMedia` guard so it only activates where it is actually needed, rather than attaching unconditionally on every page render.

---

# 23. Logging

Log categories are kept meaningfully separated: application, security, audit, and scheduler/job execution. Business-critical operations (authentication, administrative changes, security-sensitive actions, scheduled-job start/completion/failure) generate log entries; sensitive information (raw passwords, key material, full message bodies) is never written to a log, including in an exception's own logged context. Failures inside a scheduled job are logged with enough detail to diagnose without a debugger attached, since there is no persistent worker process to attach one to in production.

---

# 24. Testing Conventions

Automated tests run against a genuine, migrated-fresh SQLite in-memory database (`DB_DATABASE=:memory:`, `RefreshDatabase` per test) - not a fake query builder or an array-backed store - so model relationships, cascades, and constraints are exercised for real on every test run. `QUEUE_CONNECTION=sync` in the test environment reflects the same "no real queue, ever" reality as production, just via a different mechanism (synchronous execution instead of a scheduler tick).

Beyond PHPUnit Unit/Feature coverage (services, repositories, custom validation rules, controller request/response shape, authorization, migrations), this codebase treats real, tool-driven verification as mandatory for anything with layout, timing, or external-infrastructure dependence:

* Real-browser (Puppeteer) verification for viewport- and timing-sensitive behavior - a keyboard-aware layout is tested by actually shrinking the simulated viewport, not asserting on CSS classes.
* Verification against real external infrastructure where a feature depends on it (a real push-notification round trip against real Firebase servers, confirmed by a token surviving rather than being cleaned up as invalid) rather than mocking the SDK being tested.
* Migration tests exercise the actual driver-specific paths described in § 10 - a migration that behaves correctly on MySQL but was never run against the test suite's SQLite driver is not considered tested.

See DEV.md § 17 for the full testing-discipline expectations that apply project-wide; this section covers only what is specific to how Laravel's own test tooling is configured and used here.

---

# 25. Security Conventions (Laravel-Specific)

* **Sensitive data at rest uses Laravel's own `Crypt` facade**, keyed off the application key - this is how per-conversation message-encryption key material, and view-once media requiring continued server access, are both encrypted at rest. This is a server-managed trust model, not a zero-knowledge one: the application itself generates and holds the encryption keys, and access to a given key is checked live against current membership at fetch time, not fixed permanently at the moment a key was first issued. There is never a client-side "unlock" step gating access to this kind of data.
* **Secrets are hashed, never compared in plaintext** - `Hash::make()`/`Hash::check()`, exactly as used for account passwords, is the same mechanism applied to any other secret-like value a user sets (a meeting password, for instance) rather than a bespoke plaintext-equality check.
* **Step-up re-authentication** for a defined set of sensitive, one-way actions (§ 12) is implemented at the Service/controller layer for that specific action, requiring the current password regardless of session age.
* **Admin-facing raw configuration/secret editing** (an environment-variable editor, for example) masks any key that looks sensitive (`*_KEY`/`*_SECRET`/`*_PASSWORD`/`*_TOKEN` naming patterns) behind a show/hide toggle by default, takes a timestamped backup of the file being edited before writing it, requires the step-up re-authentication above, and runs `config:clear` immediately afterward so a config-cached production process picks up the change without a separate deploy step.
* CSP and other security headers are maintained centrally (a dedicated `SecurityHeaders` middleware, appended globally in `bootstrap/app.php`) and updated deliberately whenever a new legitimate external call is introduced (a new third-party SDK's own token/config endpoints), rather than loosened broadly.

General, non-Laravel-specific security practice (OWASP categories, input validation discipline, privacy-setting enforcement across every surface exposing a field) lives in `Documentation/MEET_DOCS/SEC.md` and DEV.md § 18.

---

# 26. Deployment & Environment Conventions (Laravel-Specific)

* `vendor/` and the compiled `public/build/` frontend assets are committed to the repository - the production host has no server-side `composer install`/`npm run build` step, so a fresh build/vendor snapshot must be committed alongside any dependency or frontend change.
* New environment variables a release depends on are synced into the live `.env` automatically by the deploy pipeline: any key present in the new release's `.env.example` but missing from the live file is added blank, and an already-configured value is never overwritten.
* One-off, deploy-time "version action" scripts are restricted to cheap, near-instant work only (flipping a settings flag, clearing a cache) - never anything whose cost scales with table size (§ 10); that always belongs in a scheduled job instead.
* Config caching in production means any runtime change to configuration-backed values (including a live `.env` edit) must be followed by `config:clear` to actually take effect - this is not automatic just because the underlying file changed.

See `deploy/README.md` for the full pipeline and DEV.md § 20 for the broader operational picture; this section covers only the Laravel-framework mechanics involved.

---

# 27. Coding Standards & Naming

* PSR-1, PSR-4, PSR-12 throughout; constructor dependency injection is preferred over facades/global state inside Services and Repositories (Facades remain idiomatic at the edges - controllers, config - where Laravel itself expects them).
* Classes: PascalCase. Methods and variables: camelCase. Constants: UPPER_SNAKE_CASE. Database tables: snake_case, plural. Columns: snake_case.
* A guest-parallel class is always named by direct analogy to its authenticated counterpart (`GuestMeetingController`, never `MeetingControllerV2` or similar) - see § 19.
* Every module contains the same recognizable shape (Controllers, Requests, Resources, Services, Repositories where warranted, Policies, Jobs, Tests) so a contributor moving between modules already knows where to look.

---

# 28. Engineering Checklist (Laravel-Specific)

Before requesting review on a Laravel-layer change, verify:

* No new class implements `ShouldQueue` (§ 15); any new recurring work is registered in `routes/console.php` via `Schedule::job()`.
* Any new migration touching an enum, a nullable/foreign-key constraint change, or any raw SQL has been checked against all three supported drivers, not just the one used locally (§ 10).
* Any new backfill/catch-up logic runs as a bounded, scheduled batch - never a single unchunked deploy-time script (§ 10).
* A new guest-facing feature follows the guest-parallel controller/service pattern rather than branching an existing authenticated controller (§ 19).
* Logic needed from two entry points was extracted into one shared Service method, not implemented twice (§ 20).
* New middleware that depends on `request()->user()` is attached after session/auth middleware, not as a bare global `append()` (§ 13).
* API responses use the standard Resource/envelope conventions (§ 17, § 18); no raw model array or ad-hoc response shape.
* Any admin-facing secret/config editor masks sensitive-looking keys and requires step-up re-authentication (§ 25).
