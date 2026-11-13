# Test Case & QA Manual (TEST)

**Version:** 2.0

**Document Type:** Quality Assurance & Testing

**Priority:** Critical

---

# 1. Purpose

This document defines the quality assurance strategy, test-suite structure, testing methodology, quality standards, and validation framework for the Meet platform.

The app has a real, executing test suite: PHPUnit Feature tests against a real (in-memory) SQLite database, and Puppeteer browser tests driving a real Chrome instance against a real running server, exercising real WebRTC media, a real Firebase backend, and real push infrastructure. This document describes that suite, scoped appropriately for a single-operator cPanel app with a cron-only, no-persistent-worker architecture - there is no dedicated QA/Staging environment tier or formal UAT sign-off ceremony, since neither fits the project's scale.

---

# 2. Core Testing Discipline: Real Infrastructure, Not Mocks

The single most important thing to know about this project's testing approach is:

**Tests exercise real behavior wherever it is feasible to do so, rather than mocking the thing under test.**

Concretely:

* **Real database** - Feature tests run against a genuine SQLite database (`DB_DATABASE=:memory:` in `phpunit.xml`), migrated fresh per test via `RefreshDatabase`, not a fake query builder or in-memory array store. Model relationships, cascades, and constraints are exercised for real.
* **Real WebRTC** - Browser tests for calls and meetings do not stub `RTCPeerConnection`. They launch Chrome with `--use-fake-device-for-media-stream` / `--use-fake-ui-for-media-stream` so `getUserMedia` returns synthesized (silent/blank) but genuine `MediaStreamTrack`s, then drive two real browser sessions through actual SDP offer/answer/ICE negotiation and assert on the *resulting* remote `MediaStream` (`stream.getAudioTracks().length > 0`, etc.) - i.e., the test proves audio/video tracks actually arrived over a real peer connection, not that a mock was called correctly.
* **Real push/Firebase infrastructure** - Push notification tests do not stub the Firebase SDKs. A live browser test obtains a genuine FCM device token from Firebase's real servers via the Web SDK's `getToken()`; a companion test confirms the server-side Admin SDK authenticates for real and sends through that exact real token, with success confirmed by absence of the `NotFound`-cleanup path (an invalid token would be deleted; the subscription row survives instead). A `tinker`-driven manual check independently confirmed the Admin SDK both authenticates with the real service account and correctly classifies a deliberately fake token as `NotFound`. This is the project's clearest articulation of the philosophy: **verified against real Firebase infrastructure, not just unit-level mocks.**
* **Real browser rendering for layout/UX bugs** - Fixes for things like keyboard-open jank on mobile chat are verified with a real Puppeteer test that shrinks the viewport (simulating what an on-screen keyboard actually does to `window.visualViewport`) and asserts the container tracks the resize and the composer stays visually on-screen - not a CSS unit test or a snapshot diff. Similarly, a narrow-viewport header-overlap fix is verified via real bounding-rect measurements at 390px width rather than an assumption about the CSS.
* **Every browser session auto-catches console/page errors** - `tests/Browser/support/session.js` attaches a listener that accumulates any JS console error or page error for the life of a page, which is deliberately the cheapest way to catch regressions automatically in *every* test, not just the one that happens to assert on it.

Mocking is used only where the alternative is genuinely impossible or destructive - e.g., time is frozen/traveled in job-scheduling tests, and outbound email uses the `array` mailer - but the core business logic, database, real-time signaling, media, and push paths are never faked.

---

# 3. Test Suite Structure

## 3.1 PHPUnit Feature Tests - `tests/Feature/`

Configured via `phpunit.xml`: `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `QUEUE_CONNECTION=sync` (no queue worker exists on the production host either, so tests correctly exercise the same synchronous execution path used in production), `CACHE_STORE=array`, `SESSION_DRIVER=array`, `MAIL_MAILER=array`, `BCRYPT_ROUNDS=4` (fast hashing in tests only). Two suites are declared: `Unit` (`tests/Unit`) and `Feature` (`tests/Feature`); in practice almost all real coverage lives in `Feature`, organized per module:

| Directory | Coverage |
|---|---|
| `tests/Feature/Authentication/` | Login/registration flow, account switcher, passkeys (WebAuthn), SSO, two-factor (TOTP) |
| `tests/Feature/Admin/` | Admin panel, admin-triggered password reset, archive engine, load-test seeder, RBAC, retention/cleanup jobs |
| `tests/Feature/Calls/` | Voice/video call flow (start, accept, reject, end, history, escalation to meeting) |
| `tests/Feature/Chat/` | Core chat flow, disappearing messages, chat export, encryption |
| `tests/Feature/Contacts/` | Contact request/accept/reject/block flow |
| `tests/Feature/Groups/` | Group creation and flow |
| `tests/Feature/Media/` | Media upload/download flow, orphaned-media cleanup |
| `tests/Feature/Meetings/` | Meeting flow, calendar flow, guest-session cleanup, inactive-meeting auto-end |
| `tests/Feature/User/` | Profile flow, profile-name backfill command |
| `tests/Feature/Whiteboard/` | Whiteboard flow (including guest access) |
| `tests/Feature/Core/` | Push subscription and push-notification trigger tests |
| `tests/Feature/Web/` | Server-rendered (Blade) page flows: auth, broadcast lists, communities, contacts, groups, profile |
| `tests/Feature/` (top level) | Error pages, PWA manifest/service-worker behavior, baseline example test |

Every Feature test class uses `RefreshDatabase`, builds real model factories (e.g. `User::factory()->create()`), authenticates via `actingAs($user, 'sanctum')`, and asserts against real JSON responses (`assertStatus`, `assertJsonPath`) - API contract and business logic are validated together in one real HTTP-through-the-stack pass, not layered mocks of controller/service/repository in isolation.

## 3.2 Puppeteer Browser Tests - `tests/Browser/`

A hand-rolled runner (`tests/Browser/run.js`, no external test framework) that:

1. Seeds real browser test users via `php artisan test:seed-browser-users`.
2. Loads every `*.test.js` file in the directory (optionally filtered by a substring argument).
3. Executes each registered test with `support/test.js`'s lightweight `test()`/`assert()` API against a real running server (`BROWSER_TEST_BASE_URL`, default `http://localhost:8000`) using a real headless Chrome (`puppeteer-core`, `CHROME_PATH`).
4. Force-closes all open sessions after every test (`closeAllSessions()`) so a thrown assertion mid-test cannot leak browser contexts into the next test.

Coverage spans navigation, admin, calls, meetings, calendar, chat (multiselect, export, encrypted previews, message status ticks, message actions), broadcast lists, communities, media, mobile layout (chat full-screen, nav overlap), notifications/push, offline support, passkeys/SSO/two-factor, and settings.

Shared infrastructure lives in `tests/Browser/support/`: `session.js` (browser/session lifecycle, fake-user credentials, error-capture), `fixtures.js` (helpers like `ensureConversationUrl`), `test.js` (the minimal test-registration/assertion shim); `tests/Browser/fixtures/` holds real binary test assets (`test-image.png`, `test-document.txt`) actually uploaded during media tests, not synthetic byte arrays.

---

# 4. Testing Levels

| Level | Scope | Approach |
|---|---|---|
| Unit Testing | Isolated business logic | Minimal; most logic is validated at the Feature level against a real DB, which catches integration issues plain unit tests would miss |
| Integration / Feature Testing | Module interactions | The dominant layer - `tests/Feature/*` - full HTTP request through routing, middleware, controller, service, repository, and a real SQLite DB |
| System / End-to-End Testing | Full user journeys | `tests/Browser/*` - real two-user (or more) concurrent browser sessions across real WebRTC, real Firebase push, real DOM interactions |
| Regression Testing | Prevent reintroduction of defects | Both suites run on demand before release; the browser suite specifically re-verifies prior UI fixes |
| UAT / Manual Sign-off | Business-stakeholder approval | Not a formal ceremony - single-operator project; a real Puppeteer run before any change is considered complete is the operative standard instead, with frontend assets rebuilt first if new Tailwind classes were added |
| Performance / Load Testing | Realistic scale and search relevance | A dedicated load-test seeder generates large, organically-varied fake-user populations for realistic load and search-relevance testing, rather than synthetic uniform load generators |

---

# 5. Test Environments

In practice there are two:

* **Local/CI test environment** - in-memory SQLite, array cache/session/mail drivers, sync queue, a real locally-running server for Puppeteer to drive against `localhost:8000`.
* **Production (cPanel)** - the only "real" environment; no queue worker, cron-only background processing, deploy pipeline scripts (`deploy/`) handle release rollout without any server-side `exec()`.

There is no dedicated QA or Staging tier. This is a deliberate consequence of the project's scale and hosting model, not a gap to be filled - new features are verified with real Feature + Browser tests locally, then deployed directly.

---

# 6. Test Data Management

* Feature tests use Laravel model factories to generate realistic per-test data (users, conversations, calls, etc.), created fresh inside each test's transaction/`RefreshDatabase` cycle - nothing persists across test runs.
* Browser tests use a small fixed roster of seeded accounts (`browser.alice@example.test`, `browser.bob@example.test`, `browser.carol@example.test`, `browser.admin@example.test`) with a shared known password, created via `php artisan test:seed-browser-users` at the start of every run.
* Separately, large-scale load-test data uses a distinct seeding path marked by a dedicated email convention so load-test accounts are identifiable and cleanly excluded from "real users" views in Admin, and safely cleanable via a dedicated command.
* No production data is ever used in tests.

---

# 7. Notable Testing Techniques

* **Fake media devices, real negotiation** - Chrome's `--use-fake-device-for-media-stream`/`--use-fake-ui-for-media-stream` flags let call/meeting/voice-note tests exercise `getUserMedia`/`MediaRecorder`/full WebRTC negotiation without real camera/microphone hardware or manual permission prompts, while still proving the actual negotiated media arrives (real track-presence assertions on the remote `MediaStream`).
* **Visual-viewport simulation for mobile keyboard bugs** - rather than trying to trigger a real on-screen keyboard in headless Chrome, keyboard-related layout fixes are verified by directly shrinking the Puppeteer viewport (which is what a real keyboard does to `window.visualViewport`) and asserting the app's own resize listener reacts correctly.
* **Bounding-rect assertions over visual snapshotting** - narrow-viewport layout bugs are verified with real DOM bounding-rect measurements rather than screenshot/pixel diffing, keeping browser tests fast and deterministic.
* **Ambient error capture** - every browser test session installs a console/page-error listener up front (`support/session.js`), so any unrelated JS regression (a missing global, an uncaught exception) surfaces as a failure in whichever test happened to be running, without that test needing to know to look for it.

---

# 8. Acceptance Criteria for a Change to Ship

A feature or fix is considered adequately tested only when:

* Relevant Feature tests pass against a real (migrated) SQLite database - not just green on stale fixtures.
* Where the change touches real-time (calls/meetings), push (Firebase), or DOM/layout behavior, a corresponding Puppeteer browser test exercises the real path end-to-end, not a mocked substitute.
* Newly added Tailwind classes are followed by a Vite asset rebuild before the browser test run - a stale build is the most common cause of a browser test "passing" against old markup.
* No new console/page errors are introduced (caught automatically by the ambient error listener described in §7).
* For anything affecting production data hygiene (e.g. guest sessions, load-test rows), a corresponding cleanup/retention test exists rather than relying on manual DB cleanup.

There is no separate formal QA sign-off, UAT ceremony, or release-gate tooling beyond this - the project's scale (single operator, cron-only cPanel host, no persistent worker) makes a heavier release-governance process unnecessary overhead rather than a missing safeguard.

---

# 9. Summary

The Meet platform's testing strategy rests on two real, executing suites - PHPUnit Feature tests against a real in-memory SQLite database, and Puppeteer browser tests against a real running server - bound together by one consistent discipline: verify against real infrastructure (real DB, real WebRTC negotiation, real Firebase servers, real DOM layout) rather than mocking the behavior under test. This is a lean, real, single-operator-appropriate test suite rather than a multi-environment, formal-QA-team process.
