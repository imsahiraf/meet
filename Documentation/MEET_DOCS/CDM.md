# Claude Development Manual (CDM)

**Version:** 2.0

**Document Type:** AI-Assisted Development Governance

**Priority:** Critical

---

# 1. Purpose

This document defines the master instructions and decision framework for AI-assisted development of the Meet platform. It governs how an AI assistant (or any contributor following the same discipline) should generate, modify, test, and document code for this codebase.

Its objective is to ensure that every generated file, module, feature, and change follows the project's actual architecture, coding standards, documentation practices, and quality bar - not an idealized or aspirational version of them. Project documentation, and the codebase itself, are treated as authoritative; where they disagree, the actual shipped behavior wins and the documentation is corrected to match.

---

# 2. Primary Objective

AI-assisted work on the Meet platform shall produce a system that is:

* Modular
* Secure
* Scalable within its actual hosting constraints
* Maintainable
* Testable
* Responsive
* Fully documented

Generated code is expected to be production-ready after validation and testing - not a draft requiring a separate "hardening" pass. Given the project's single-operator, cPanel-hosted reality, "scalable" means scaling cleanly within a shared-hosting, cron-only environment, not assuming infrastructure (dedicated workers, container orchestration, managed queues) that doesn't exist here.

---

# 3. AI Development Principles

When multiple valid approaches exist, priority order is:

* Correctness
* Security
* Maintainability
* Readability
* Performance
* Consistency with established project conventions
* Simplicity

Architectural consistency takes precedence over the shortest possible diff. Reusing an established pattern (a guest-parallel controller, a client-side tab filter, a nullable-actor-pair column - see §7 and §11) is preferred over inventing a new one for a problem the codebase has already solved once.

---

# 4. Project Context

The AI shall assume the platform's real, current technical foundation:

**Backend**

* Laravel 12, organized as first-party modules (`Modules/<Name>/...`)
* MySQL 8+ in production; SQLite in-memory for the automated test suite

**Frontend**

* Blade templates
* Tailwind CSS v4 (utility classes via an `@theme` block in `resources/css/app.css`), mobile-first
* Vanilla JavaScript - no frontend framework (no Vue/React runtime is in production use)

**Authentication**

* Laravel Sanctum for the API, session-based web auth, plus passkeys (WebAuthn), TOTP two-factor, and Google SSO as additional first-class login paths

**Deployment**

* Apache, cPanel, Linux - shared/managed hosting with no ability to `exec()` arbitrary commands on the server and no persistent background process beyond cron (see §17, §20)

Any generated or modified code that assumes a queue worker, a different frontend framework, or server-side build tooling that doesn't exist on this host is non-compliant regardless of how idiomatic it looks in isolation.

---

# 5. Documentation Hierarchy

When generating or changing code, documentation is consulted in this order:

1. Business/functional requirements for the affected area (SRS)
2. Database design (DDS)
3. REST API contract (RAS)
4. UI/UX design system (UDS)
5. Developer handbook and Laravel conventions (DEV/LDG)
6. This manual (CDM)

If documentation appears inconsistent, or is silent on a real, already-shipped behavior, the AI shall identify the conflict explicitly rather than silently picking an interpretation, and shall prefer updating the documentation to match the real, working implementation over leaving a stale description in place.

---

# 6. AI Responsibilities

The AI shall:

* Generate production-quality code that compiles and runs against the real stack described in §4.
* Follow Laravel conventions and this project's own established conventions ahead of generic "best practice" defaults where the two differ.
* Reuse existing modules, services, and patterns rather than duplicating logic across entry points. When the same matching or validation logic is needed from two places (for example, a web form and the equivalent API endpoint), it is extracted into one shared method both call - not implemented twice with a risk of the two drifting apart.
* Generate or update tests for anything it changes, including a real browser check for user-facing behavior (§14).
* Keep documentation, including the changelog, synchronized with what actually shipped.

The AI shall not bypass project standards for convenience, and shall not silently narrow a request's scope without saying so.

---

# 7. Architectural Rules

Generated backend code uses the standard layered structure inside each module:

* Controllers (thin - receive validated requests, invoke services, return standardized responses)
* Services (business logic, transactions, coordination)
* Repositories (persistence, pagination, filtering, searching)
* Policies (authorization)
* Form Requests (validation)
* Resources (API response shaping)
* Events / Listeners (in-process, synchronous side effects)
* Jobs (scheduler/cron-triggered - see §13; **never** `ShouldQueue`)

Business logic resides in the Service layer, not in controllers or models.

Two additional structural conventions are established in this codebase and are expected for new work of the same shape:

* **Guest-parallel controllers.** A feature usable by both authenticated users and unauthenticated meeting guests gets its own dedicated controller (e.g. a guest-scoped controller reached through `{guestSession}`-scoped routes) alongside the authenticated one, rather than branching a single controller's logic on "is this a guest." The service layer underneath is written to accept whichever domain object already models "whoever is present, guest or not" (a meeting participant, not a `User`), and resolves which side of a nullable-actor-pair column (§9) to populate based on whether that participant is a guest.
* **Nullable-pair columns for optional actor identity.** Where a row can be created by either a registered user or a guest, it is modeled as two nullable foreign-key columns - one to `users`, one to the guest-tracking entity - with exactly one populated per row, rather than forcing a `User` row to exist for guests or introducing a polymorphic column.

---

# 8. Coding Standards

Generated code complies with:

* PSR-1 / PSR-4 / PSR-12
* Laravel conventions and idioms
* SOLID principles
* DRY - shared logic used from more than one entry point lives in exactly one place
* KISS - a new capability is bridged onto an existing, tested subsystem rather than reimplemented as a parallel one wherever the domains genuinely overlap (for example, escalating a paired call into a full multi-party meeting reuses the existing meeting engine instead of building a second group-calling stack)

Readable, conventional code is preferred over clever code. Constructor dependency injection is preferred over global state; class and method names are descriptive.

Engineering decisions favor long-term sustainability over short-term convenience - including the discipline to remove a design outright once it's replaced, rather than let two competing implementations coexist. When a design is reworked mid-development before it reaches real users, the superseded code and its supporting schema are deleted, not left in place "just in case."

---

# 9. Database Rules

Before creating a migration, the AI verifies the table doesn't already exist, that required relationships and indexes are defined, and that naming follows project convention. Existing migrations and models are reused; schema duplication is avoided.

Two rules specific to this project's operating environment:

* **Bounded, scheduled batch processing - never a deploy-time, unchunked script - for anything scaled to table size.** A single unchunked backfill query run directly at deploy time, against a database holding a very large number of accounts, is exactly the kind of change that can exhaust memory or run long enough that the next cron tick starts a second, overlapping deploy attempt. Any data migration whose cost scales with table size is written as a scheduled job that processes one bounded batch per tick and disables itself via a flag once nothing is left to do, with a same-request fallback (the affected page fixing its own record on the spot) as an immediate safety net. Deploy-time, one-off version actions are reserved for cheap, near-instant work only (flipping a setting flag, and similar).
* **Nullable-pair columns for optional actor identity** (see §7), used consistently rather than reinvented per feature.

---

# 10. API Rules

Generated API code follows REST conventions, uses API Resources to shape every response, returns standardized success/error envelopes, and respects authentication and authorization on every route. The REST API contract is the authoritative interface description; a change to response shape or status codes is a contract change, not an implementation detail.

---

# 11. UI Generation Rules

Generated frontend code:

* Uses Tailwind CSS v4 utility classes, mobile-first - a control's default state must be usable on a touch device, with `sm:`-and-up variants adding hover-only affordances only where a mouse makes them discoverable, never the reverse. Getting this backwards has been a real, recurring bug class (hover-only message actions being unreachable on mobile; a global 14px input font size triggering iOS Safari's auto-zoom-on-focus).
* Uses vanilla JavaScript, preferring Pointer Events over mouse-only handlers for anything that needs to work on touch.
* Never uses the browser's native `alert`/`confirm`/`prompt` for a user-facing confirmation, alert, or prompt - the app's own themed modal component is used instead, so visual consistency and styling control are preserved.
* Applies an **icon-first visual language calibrated to the surface's stakes**: high-traffic, user-facing surfaces (Settings, the meeting room toolbar, Chat, Contacts) go icon-only with tooltips; lower-traffic, higher-stakes surfaces (the Admin panel) keep the text label alongside the icon, since an unlabeled icon is more likely to be misclicked somewhere consequential like Delete or Force-logout. New UI chooses its treatment based on which category it falls into, not by default.
* Reuses **client-side tab filtering over one already-loaded dataset** for any segmented view (e.g. an All/Category-A/Category-B tab set), rather than issuing a separate backend query per tab, matching the pattern already established across the chat list, the meetings/calendar view, and the Admin panel's own tab nav.
* Scopes `overscroll-behavior` deliberately - contained at the document root, allowed only on the specific panes that need their own natural scroll - rather than leaving default browser overscroll/rubber-band chaining to propagate through nested scrollable containers.

---

# 12. Security Rules

The AI shall always:

* Validate all input via Form Requests.
* Authorize every protected action via Policies.
* Hash passwords, meeting passwords, and any comparable secret - never store or compare them in plaintext.
* Avoid exposing internal implementation details or sensitive data in responses, logs, or error pages.

Two conventions specific to this codebase's actual security model:

* **Message encryption is server-managed, not zero-knowledge, by deliberate design - and there is never a password-unlock UI.** The application itself generates and holds every conversation's encryption key server-side, encrypted at rest; access is checked live, at fetch time, against current conversation membership, rather than being fixed permanently at the moment a key was issued. Generated code must not introduce a client-side "unlock" step, a per-user keypair, or any mechanism implying the server cannot read message content - that would misrepresent the actual trust model to users.
* **Step-up re-authentication for sensitive, one-way actions.** Disabling two-factor authentication, removing a passkey, deactivating an account, and editing live environment configuration all require re-entering the actor's own account password immediately before the action proceeds, regardless of how recently they last authenticated. Any new sensitive, hard-to-reverse action follows the same pattern.
* **A privacy setting must be enforced at every surface that exposes the underlying data, not just the first one built.** When a privacy-gated field (such as last-seen visibility) is added to a new surface, every existing surface that already renders the same underlying data is checked for the same enforcement - a gap in only one of several equivalent surfaces is a real, shipped vulnerability class in this codebase's history, not a hypothetical one.

---

# 13. Performance Rules

Generated code:

* Uses eager loading to avoid N+1 queries.
* Paginates large datasets rather than returning unbounded collections.
* Runs long or periodic work via the scheduler, never a queue worker (see §4, §20) - there is no persistent worker process on the production host, in any environment, so `ShouldQueue` is never used. A "job" in this codebase means a class invoked by cron on a schedule, not a class dispatched to a consumer that doesn't exist.
* Keeps each scheduled tick quick and idempotent, since it runs on shared-hosting cron rather than a dedicated worker.

Performance optimizations never reduce correctness or bypass validation/authorization.

---

# 14. Testing Requirements

Every generated or modified feature includes appropriate automated tests, and - for anything user-facing - real, tool-driven verification is treated as mandatory, not optional, before the work is considered complete:

* **PHPUnit Feature tests** against a real (in-memory SQLite) database, run through the full HTTP stack (routing, middleware, controller, service, repository), not layered mocks of each layer in isolation.
* **Puppeteer browser tests** driving a real Chrome instance against a real running server for anything involving layout, viewport behavior, timing, real-time media, or push delivery. Verification against real infrastructure (a genuine WebRTC negotiation, a genuine push-service round trip) is preferred over mocking the thing under test wherever that's actually feasible.
* **Rebuild frontend assets after any Tailwind class change, before considering the change complete.** The compiled `public/build/` output is committed to the repository (§20) rather than built on the server; a new utility class renders correctly against a local dev server but will not render at all in production, or in a browser test run against stale build output, until `npm run build` has been run and the result committed.
* Automated test passes are necessary but not sufficient for anything with strong platform-specific rendering behavior - a fix should also be sanity-checked against the real device quirk it targets where automation is known to fall short of reproducing it faithfully.

A change is not "done" until it has been exercised this way; this is a standing expectation, not a per-task reminder.

---

# 15. Documentation Requirements

For every implemented feature or fix, the AI keeps the following current:

* Technical/implementation notes relevant to the change.
* API documentation, where the contract changed.
* `CHANGELOG.md` - updated proactively, not only when asked. The AI picks the version bump size itself (patch/minor/major) based on the actual nature of the change; a new "Unreleased" heading is used until a release number is finalized, and the final number is the user's call, not the AI's.
* `BACKLOG.md` - anything deliberately deferred, or explicitly out of scope for the current pass, is recorded there rather than silently left unaddressed. Temporary scaffolding introduced as a stopgap also gets its own planned-removal note there.

Documentation reflects the implemented behavior, not aspirational or planned behavior. Legacy specification documents are not edited in place as the app evolves past their original baseline; real, current behavior is recorded going forward instead, and consolidated into this documentation suite as it's kept current.

---

# 16. Error Handling

Generated code throws meaningful, domain-specific exceptions, returns standardized error responses, and logs operationally significant failures. Internal implementation details (stack traces, query text, file paths) are never exposed to end users.

---

# 17. Code Generation Workflow

Each generated feature follows the same deterministic sequence, skipping only steps that are demonstrably unnecessary for that specific feature - and stating explicitly when a step is skipped, rather than silently omitting it:

```text id="cdmgenerationworkflow"
Review relevant documentation & existing modules
      ↓
Migration (if required)
      ↓
Model (relationships, fillable, casts, scopes)
      ↓
Repository (persistence, pagination, filtering)
      ↓
Service (business rules, transactions, events)
      ↓
Policy (authorization)
      ↓
Form Request (validation)
      ↓
Controller (thin - and its guest-parallel counterpart, if applicable)
      ↓
API Resource
      ↓
Routes
      ↓
Events / Listeners (in-process only - never a queue)
      ↓
Scheduled Job (only if genuinely periodic work is needed)
      ↓
Tests (Feature +, where user-facing, a real browser test)
      ↓
Documentation (CHANGELOG.md, BACKLOG.md where applicable)
```

No step is skipped merely for convenience. A module is complete only when all applicable files exist, tests pass, routes function, validation and authorization are enforced, and documentation is updated - a partial implementation is never represented as production-ready.

---

# 18. Frontend Generation Workflow

Frontend work for a feature follows the equivalent sequence: review the UI/UX design system and any existing equivalent screen, build on the established Blade layout hierarchy and reusable components, implement with Tailwind v4 utilities mobile-first, wire up vanilla JS behavior (module-scoped, not global), verify responsive behavior across real breakpoints, verify accessibility (semantic HTML, keyboard navigation, visible focus, screen-reader compatibility), and verify with a real browser test before considering the screen complete (§14).

No screen is considered complete without responsive validation on a real narrow viewport, since automated layout assumptions have repeatedly missed real overlap/visibility bugs that only show up at a genuine mobile width.

---

# 19. Quality Checklist

Before considering a generated module or change complete, verify:

* Architecture compliance (§7, §17)
* Security (§12)
* Validation and authorization
* Test coverage, including real-browser verification where applicable (§14)
* Documentation, including `CHANGELOG.md` and `BACKLOG.md` (§15)
* Performance (no unbounded queries, no accidental `ShouldQueue`)
* Frontend build freshness (`npm run build` re-run and committed if any Tailwind class changed)

Outstanding items are disclosed explicitly rather than represented as complete.

---

# 20. Deployment Constraints

The production host is cPanel-hosted shared/managed hosting with no ability to `exec()` arbitrary commands on the server and no persistent process beyond cron. This shapes how code is written and shipped, not just how it's operated:

* `vendor/` and the compiled `public/build/` frontend assets are committed to the repository - there is no server-side `composer install` or `npm run build` step. A dependency or frontend change is not complete until the corresponding build/vendor artifacts are committed alongside it.
* New environment variables required by a release are synced automatically into the live `.env` by the deploy pipeline, adding any key present in the new release's `.env.example` but missing from the live file - blank, never overwriting an already-configured value.
* One-off, deploy-time version actions are reserved for cheap, near-instant work only (§9); anything whose cost scales with table size is a scheduled job instead.
* The deploy pipeline (`deploy/`) is not bypassed with manual server-side commands.

---

# 21. Git & Review Conventions

* Commit messages are concise, describe the change clearly, and use prefixes (`feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`) consistent with project history.
* **Commits in this project never carry AI attribution lines** (no `Co-Authored-By: Claude` or equivalent), regardless of what tooling assisted in writing the change.
* Every pull request includes a change summary, testing performed (including real browser verification for user-facing changes), and documentation updates. Reviewers verify architecture compliance, security, performance, testing, and documentation before merge.
* Direct commits to protected branches are restricted; changes flow through feature branches and review.

---

# 22. AI Decision Rules

The AI shall:

* Prefer reuse over duplication, and framework/project convention over a bespoke alternative.
* Prefer maintainability over brevity, and explicit behavior over hidden complexity.
* Prefer documented requirements and real, already-shipped behavior over unsupported assumptions.
* State assumptions clearly wherever documentation is genuinely incomplete, rather than guessing silently.

If a request conflicts with the established architecture, the AI explains the conflict and recommends a compliant alternative, preserving the existing architecture unless explicitly instructed to redesign it. If two sources of truth (documentation and implementation, or two documents) disagree, the AI names both and recommends the least disruptive resolution rather than silently picking one.

---

# 23. Refactoring & Regeneration Rules

When improving or regenerating existing code, the AI:

* Preserves external behavior and compatible interfaces unless a breaking change is explicitly intended and explained up front.
* Reduces duplication rather than introducing more of it.
* Updates the tests and documentation affected by the change in the same pass, not as a follow-up.
* Minimizes disruption to unrelated, already-working code.

When a design is reworked before it reaches real users (see §8), the superseded implementation is removed outright rather than kept alongside its replacement "for safety" - two competing implementations of the same concern are a liability, not a hedge.

---

# 24. AI Limitations & Transparency

The AI acknowledges, rather than papers over, situations where:

* Requirements are incomplete or ambiguous.
* A design decision genuinely requires human input (a product trade-off, not a technical one).
* Documentation and real behavior disagree and neither is obviously the correct one to prefer.
* Something was deliberately left out of scope for the current pass - recorded in `BACKLOG.md` (§15), not left unstated.

Transparency about outstanding work and genuine limitations is preferred over unsupported certainty that a feature is "done."

---

# 25. Future Compatibility

Generated code is structured to accommodate future evolution - additional modules, expanded API surface, and future Laravel upgrades - without assuming infrastructure this project doesn't have (a queue worker, a different frontend framework, a multi-environment QA tier). Any future architectural shift of that scale is a deliberate, documented decision, not an incidental side effect of a single feature's implementation.

---

# 26. Summary

This manual defines the complete AI-assisted development discipline for the Meet platform: project context and priorities, architectural and coding standards, the guest-parallel-controller and nullable-actor-pair conventions specific to this codebase, database and API rules, an icon-first UI standard calibrated by surface stakes, a security model built around server-managed encryption and step-up re-authentication, a testing discipline that verifies real infrastructure over mocks, documentation practices that keep the changelog and backlog current as a matter of course, the deployment constraints imposed by a cron-only, no-queue-worker cPanel host, and the decision-making, regeneration, and transparency rules that govern how the AI resolves ambiguity and incomplete information. Together these establish a single, current reference for how AI-assisted work on this codebase is actually expected to be done.
