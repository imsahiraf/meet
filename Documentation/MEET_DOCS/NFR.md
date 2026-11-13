# Non-Functional Requirements (NFR)

**Version:** 2.0

**Document Type:** Non-Functional Requirements

**Priority:** Critical

---

# 1. Purpose

This document defines the non-functional requirements governing the performance, scalability, security, privacy, reliability, availability, maintainability, and usability of the Meet platform.

These requirements apply across all modules unless explicitly stated otherwise.

---

# 2. Architectural Baseline That Shapes Every NFR Below

Two facts about the deployment environment are load-bearing enough to state once, up front, rather than repeat in every section:

* **No persistent queue worker exists.** The app runs on shared cPanel hosting with cron as the only scheduling primitive - there is no `queue:work` daemon. Nothing in the codebase uses Laravel's `ShouldQueue`; anything that looks like "background processing" in this document is either a scheduled cron job (`everyMinute`/`everyFiveMinutes`/`everyFifteenMinutes`, etc., all idempotent and self-limiting) or synchronous, inline work performed during the request that triggers it (e.g. push notifications).
* **Deploys are artifact-based, not build-on-server.** `vendor/` and the compiled frontend build are committed and shipped; the deploy pipeline does not execute arbitrary code on the server (see `deploy/README.md`). Any operational change (env vars, one-off migrations) must fit that constraint - this is why deploy-time actions are restricted to cheap, near-instant operations only (§8.2).

Every performance, scalability, and reliability requirement below assumes this architecture, not a conventional worker-pool deployment.

---

# 3. Performance Objectives

The platform provides fast response times, low latency, stable performance, predictable resource usage, and efficient scheduled/inline processing, under both normal and peak conditions.

---

# 4. Response Time Requirements

Target response times:

| Operation                | Target                                  |
| ------------------------ | ---------------------------------------- |
| Page Load                | ≤ 2 seconds                             |
| API Response              | ≤ 300 ms (typical)                      |
| Authentication            | ≤ 1 second                              |
| Message Send              | ≤ 500 ms (excluding network conditions)  |
| Contact Search             | ≤ 500 ms                                |
| File Metadata Retrieval  | ≤ 500 ms                                |

Long-running operations execute asynchronously where "asynchronous" is achievable within the no-queue-worker constraint (§2) - i.e. deferred to the next cron tick, not a background job queue.

---

# 5. Real-Time Communication

The platform does not use WebSockets; "real-time" behavior is polling-based, a deliberate, long-standing architectural choice rather than a gap:

* Message delivery, typing indicators, and presence are near-real-time via client polling.
* Nav-wide unread badges (Chat, Contacts) poll a shared aggregate endpoint from the app layout itself, so they stay current on every page, not only while viewing that section.
* Message status ticks (sent/delivered/read) advance at concrete trigger points - opening a thread, syncing the chat list, or a push arriving to a backgrounded/closed client - rather than a continuous stream.
* **Real OS-level push notifications** (new message, incoming call, contact request, missed call, meeting reminder) supplement polling for the closed/backgrounded-app case - see §22.

Actual latency depends on network conditions and deployment environment; stable voice/video call setup and responsive call controls are a target, backed by a redesigned call UI and a liveness/heartbeat model (§10) that prevents stale state from masquerading as "real-time."

---

# 6. Database Performance

Queries are indexed, full-table scans and N+1 patterns are minimized, and hot queries are optimized. List endpoints (Admin → Users, the chat list, the contacts list) use real `paginate()` with either page controls (Admin) or infinite-scroll-on-reaching-bottom (chat/contacts) - every list endpoint is expected to follow this pattern.

---

# 7. Storage, Media & Data Lifecycle Performance

Storage operations support efficient uploads/downloads, background thumbnail generation, and streaming of large files. Temporary and orphaned data are cleaned up by a concrete set of scheduled jobs (none of them `ShouldQueue` - see §2):

* **Delete-for-everyone** deletes the underlying `Media` row and disk file, not just the message row, once nothing else (e.g. a forward) still references that exact media id, so deleted-message storage does not grow unbounded.
* **`CleanupOrphanedMediaJob`** (every 15 minutes) deletes View Once files stuck "viewed" past a grace window (a safety net - the primary deletion path is synchronous, inside the serving response) and any `Media` row older than a day referenced by nothing at all - covering both delete-for-everyone residue and abandoned uploads that were never attached to a sent message.
* **Guest-created meetings** that never reach a terminal state (no authenticated organizer to call the normal `end()` action) are ended by the existing expired-guest-session cleanup job once no guest session remains active.
* **`EndInactiveMeetingsJob`** (every 5 minutes) ends any `active`/`locked` meeting with nobody genuinely present for 5 minutes straight, using the same participant-heartbeat/staleness definition the live roster uses (§10), not a raw `left_at` check - closing a gap where an abandoned lobby or a fully-vacated meeting could otherwise sit "active" indefinitely.
* **Disappearing messages** (per-conversation Off/24h/7d/90d) are expired by a cron job running every 5 minutes, soft-deleting the same way "delete for everyone" already does.

Net effect: storage and row growth from ordinary usage (deleted media, abandoned meetings/uploads, expiring messages) is self-bounding via cron, rather than accumulating until a manual cleanup.

---

# 8. Scheduled/Background Processing (No Queue Workers)

## 8.1 Current Model

Queue-shaped work - notification delivery, media/data cleanup, reminders - is implemented either as synchronous inline work during the triggering request (real push sends) or as small, frequent, idempotent cron jobs (5-15 minute cadence) that each process a bounded batch and exit. This is the permanent architecture for this deployment target, and it constrains every future feature that might otherwise reach for `ShouldQueue`.

## 8.2 Case Study: The Backfill Incident

A one-off deploy-time action (`deploy/version-actions.php`) that ran an unchunked, unbounded backfill query directly against a database already holding 100,000+ rows caused a real production incident - the query exhausted memory or ran long enough for the next cron tick to start an overlapping second deploy attempt, leaving the site 500ing and the deploy loop re-triggering itself. This is a governing operational constraint, not just a historical bug:

* `deploy/version-actions.php` is restricted (by its own docblock) to cheap, near-instant actions only (e.g. flipping a config flag) - anything whose cost scales with table size must be a scheduled job instead.
* The corrected pattern is `BackfillProfileNamesBatchJob`: runs every minute, processes one bounded batch (1,000 rows) per tick, and disables itself via a settings flag once nothing is left to do.
* A per-page fallback (fixing one row on-demand when that user's own Settings page is visited) provides an immediate safety net without waiting on the batch.

This case study is the operative precedent for how *any* future large one-time data migration on this platform must be shaped.

---

# 9. Scalability

Cron-tick frequency and per-tick batch size are the actual levers for background-work throughput, since no queue-worker lever exists in this deployment (§2). Horizontal scaling, external storage providers, load balancing, and database optimization remain valid and available levers. Scalability enhancements continue to avoid requiring significant architectural redesign.

---

# 10. Concurrent Users & Meeting Capacity

Concurrent-user support scales through the levers in §9. For real-time meetings specifically, capacity and correctness depend on an explicit **liveness model**, not just raw participant counts:

* A `last_seen_at` heartbeat, refreshed on every roster poll, filters stale (crashed/closed-tab/network-lost) participants out of what the live call UI shows, with a configurable staleness window (`MEETINGS_STALE_PARTICIPANT_SECONDS`, default 30s) - the underlying `activeParticipants()` capacity-counting query is deliberately left unfiltered, since staleness-filtering would double-count or under-count for billing/capacity purposes.
* A `pagehide` handler fires a best-effort `navigator.sendBeacon()` leave call on tab close/navigation, making the common case (closing the tab) instant instead of waiting out the staleness window.

This prevents "phantom" participants from distorting concurrent-capacity assumptions or leaving other participants staring at a video tile for someone who is genuinely gone.

---

# 11. Availability & Reliability

## 11.1 Offline Behavior

Offline support covers the login screen, navigation shell, static assets, and - deliberately, by disclosed design decision - chat and contacts:

* IndexedDB caches the conversation list, the last ~200 messages per conversation (post-decryption for encrypted conversations), and the contacts list; the chat list, an open conversation, and the contacts list all fall back to this cache when a live fetch fails while offline.
* An outbox queue lets a plain-text message composed offline render optimistically ("Waiting to send…") and queues for delivery once connectivity returns; the queue drains per-conversation, when that conversation's page is next opened online (a disclosed, bounded scope decision).
* The service worker runtime-caches the HTML shells for `/chat`, `/chat/{id}`, `/contacts`, and `/groups` (network-first with offline fallback), the same pattern used for `/login`.
* **Genuinely impossible offline, by nature rather than scope choice:** file uploads, calls, and meetings (real-time WebRTC has no offline equivalent) - `/calls` and `/meetings` are deliberately excluded from the service worker's offline shell caching.

## 11.2 PWA Update Safety

An installed PWA can sit on a stale service worker indefinitely if the browser is never fully closed. The app checks for a waiting update on every page load and activates it immediately **unless** a call or meeting is actively in progress on that page, in which case it retries every 30 seconds until safe - preserving the guarantee that updates never interrupt active meetings/calls while actually getting stale clients updated onto current features, including push notifications.

## 11.3 Fault Tolerance & Error Handling

The platform recovers from transient failures where practical, isolates failures to affected modules, avoids cascading failures, displays user-friendly errors while logging technical detail for administrators, and returns standardized API error responses. Concrete mechanisms reinforcing this include the meeting liveness model (§10), the cron-based cleanup jobs (§7), and defensive handling of previously-silent failure modes such as the stale push-subscription case (§22).

---

# 12. Security Objectives

Confidentiality, integrity, availability, accountability, and traceability are the platform's security objectives, considered at design, implementation, deployment, and maintenance time. Multi-factor authentication, Passkeys, and SSO are all shipped, first-class authentication mechanisms - not aspirational future support.

---

# 13. Authentication & Session Requirements

Authentication supports secure login/logout, password reset, email verification, session management, and device management, plus:

* **Two-Factor Authentication (TOTP)** - QR-code enrollment, confirmed with a real code before activation (never enabled from bare secret generation), 8 one-time recovery codes shown exactly once; login stops at a code-entry step after password verification, before any session/token issues. A "remember this device" option (30 days, hashed token, `trusted_devices` table with a real expiry) lets a recognized browser skip the challenge; the raw token lives only in the browser.
* **Passkeys (WebAuthn)** - web login only; a passkey ceremony deliberately skips the separate 2FA challenge, since possession + biometric/PIN verification is already a second factor by construction.
* **SSO with Google** - matches or creates an already-verified account by email; unlike Passkeys, this does **not** skip 2FA, since a linked external identity is not itself a second factor for this app's own account. `users.phone`/`users.password` are nullable at the DB level to support password-less, phone-less accounts (application-level registration validation is unchanged).
* **Multi-account login/switching** - built entirely on the existing "remember me" mechanism (`remember_token` + `loginUsingId()`), not a new session/token layer; a held account is never more trustworthy than its own remember-me login already was, and a real password change or admin force-logout invalidates it the same way everywhere. "Logout of all accounts" clears every linked session in one action.
* **Admin-triggered password reset with forced change** - an admin-set temporary password forces the target through a dedicated change-password page before reaching anywhere else on next login (`must_change_password`), and forces that user's other sessions out immediately, matching other admin account-access actions.

2FA-remember-device, Passkeys, SSO, and multi-account switching are all web-login-only for this pass - a bearer-token API client has no cookie jar for any of them.

---

# 14. Authorization Requirements

Role-Based Access Control, policy-based authorization, resource-ownership validation, and least-privilege are enforced before business logic executes on every protected request. Guest-participant authorization for registered-user-only meeting features (whiteboard, lock, screen share) is provided via a parallel guest-scoped controller/route convention rather than relaxing the authenticated path.

---

# 15. Password & Credential Handling

Passwords are securely hashed (never plaintext, never returned by APIs), reset only through verified workflows. Meeting passwords are hashed with `Hash::make`/`Hash::check` like any other credential, rather than stored or compared in plaintext.

---

# 16. Session Security

Sessions use secure cookies, configurable expiration, revocation, device logout, idle timeout, and admin-configurable behavior. 2FA challenge state, trusted-device tokens, and multi-account session sets (§13) are all built on the same underlying session/remember-token primitives rather than a parallel mechanism.

---

# 17. Data Protection & Encryption

Data protection and encryption are among the platform's most substantial security surfaces.

## 17.1 Message Encryption (Server-Managed, Not Zero-Knowledge)

Text message bodies are encrypted, on a deliberately simpler trust model than end-to-end encryption, per direct product decision: **the application itself generates and holds every conversation's key** (`conversation_keys.key_material`, encrypted at rest via Laravel's `Crypt` facade) - there is no per-user keypair and no password-derived unlock step. This is consistent with the platform's honest chat-privacy banner, which has never claimed encryption the server itself cannot read; message *bodies* are encrypted at rest and in the database, while the operator-controlled server remains able to decrypt, which is a materially different (and disclosed) guarantee from true end-to-end encryption.

Key mechanics:

* Each conversation gets one AES-256-GCM key per **epoch**; a new epoch issues automatically whenever needed (none exists, or membership changed since the last one) - no separate client-initiated "issue a key" step.
* Access control is checked live at key-fetch time (current conversation membership), not baked in at issuance - a removed member's client cannot decrypt anything sent after a subsequent membership-triggered rotation.
* Groups above `chat.e2e_max_group_members` (default 256) stay plaintext server-side with a visible "not end-to-end encrypted" indicator, matching how real E2E messengers cap group encryption rather than per-recipient key-wrapping at broadcast scale.
* Existing plaintext history is encrypted in place, in bounded batches (500 messages/call), the next time any current member opens a conversation.
* Push notification previews fall back to a generic "New message" for encrypted conversations, since the server building the push payload cannot produce a real preview.
* **Out of scope, disclosed:** regular media attachments (photos/videos/documents) are not encrypted by this scheme - only text bodies. View Once media is encrypted separately, at rest, under the server's own key (`media.is_encrypted_at_rest`) - deliberately still server-decryptable for disclosed operational reasons out of scope for this document.
* The chat list's last-message preview decrypts client-side like every other surface that shows message content, rather than rendering raw ciphertext.

## 17.2 Other Encryption/Protection Requirements

Application secrets, API credentials, TURN credentials, and backup archives require encryption/protection at rest and in transit; production requires HTTPS. Sensitive-looking `.env` values (`*_KEY`/`*_SECRET`/`*_PASSWORD`/`*_TOKEN`) are masked by default wherever an admin surface can view them (§20).

---

# 18. Privacy Controls

Configurable privacy settings (profile visibility, presence, last-seen, read receipts, profile photo) are a user-facing requirement, enforced consistently: `last_seen_visibility` is enforced identically across both the Contacts API and the Chat conversation API, so a user set to "nobody"/"contacts" for last-seen does not leak live presence to a chat partner via polling or via the initial page render.

---

# 19. File & Media Security

Uploaded files require authentication, authorization, MIME/size validation, and ownership verification before being served. Media lifecycle is actively managed, not merely access-controlled - see §7 for the cleanup jobs that bound orphaned/deleted-but-undeleted media growth, and §17.1 for View Once's separate at-rest encryption.

---

# 20. Audit Logging & Administrative Security

Administrative functionality requires authentication, authorization, audit logging, and secure configuration management, granted only to authorized personnel. This surface includes:

* Step-up re-authentication (re-entering the actor's own password) before sensitive actions - Deactivate Account, disabling 2FA, removing a passkey, and environment-variable edits all share this pattern.
* A Super-Admin-only Environment tab (`manage_environment` permission, never granted to a plain Admin) for editing the live `.env`, gated behind step-up auth, taking a dated backup before every write, and auto-running `config:clear` afterward.
* Admin per-row actions (suspend, force logout, reset password, grant/revoke elevated access, delete/restore) are icon+label rather than icon-only, since Admin is treated as a lower-traffic/higher-stakes surface where an unlabeled icon risks a misclick.

---

# 21. Security Monitoring & Incident Handling

Failed logins, rate limiting, authorization failures, application errors, and security alerts are all monitored, supporting timely investigation and documented incident response. §8.2's backfill incident is the platform's concrete precedent for how an operational incident gets diagnosed, fixed at the architectural level (not just patched), and guarded against recurring.

---

# 22. Push Notification Reliability

Push notifications are a real, load-bearing NFR surface:

* Delivered via Firebase Cloud Messaging, as data-only messages so the service worker's own `push` handler retains full control of what's displayed - including Android Chrome's inline quick-reply, which sends an encrypted message directly from the service worker with no page open.
* Sent **synchronously, inline**, from three trigger points (new message, incoming call, contact request) plus meeting reminders and missed-call notices - never via a queue, per §2's architectural constraint.
* "Delivered" status genuinely reflects arrival at a backgrounded/closed client, not just an open client's own poll - the service worker calls a dedicated endpoint on receiving a `message`-type push.
* A silent, indefinite failure mode - a stale pre-FCM subscription throwing `InvalidStateError` inside `getToken()`, swallowed by a bare `catch` - is detected and repaired by comparing the existing subscription's key against the current VAPID key before attempting to re-obtain a token.
* Users can view and revoke their own subscribed devices; disabling every notification toggle at once unsubscribes for real, at both the DB and browser (`PushManager.unsubscribe()`) level.

---

# 23. Reliability, Maintainability & Usability

Reliability, maintainability, and usability rest on modular design, low coupling, meaningful naming, automated testing, readable structure, and current documentation. Concrete usability/reliability points worth noting:

* **Mobile-specific reliability fixes**, each found via real device/viewport testing rather than assumption: iOS zoom-on-focus from sub-16px input font size, document-level overscroll/rubber-band chaining fought with the fixed composer, a visual-viewport-vs-layout-viewport mismatch hiding the composer behind the on-screen keyboard, and touch-incompatible mouse-only whiteboard/message-action handlers converted to Pointer Events / tap targets.
* **Full-bleed mobile chat** (no persistent app chrome while a conversation is open) is an explicit, opt-in exception to the standard navigation shell, matching reference messaging apps rather than a regression.
* Custom-built media playback (audio/video) and an image lightbox replace native browser controls everywhere a message can contain media, for UI consistency.

---

# 24. Accessibility, Browser & Mobile Compatibility

Accessibility and compatibility requirements cover keyboard navigation, screen-reader compatibility, semantic HTML, focus indicators, and contrast; current Chrome/Edge/Firefox/Safari; Android/iOS/tablet/responsive desktop. Compatibility validation includes real-device/viewport Puppeteer testing as part of the shipped fix record, not just a stated intention.

---

# 25. Localization, Configuration & Extensibility

Multi-language/timezone support remains an architectural allowance rather than a shipped requirement; configuration is environment-specific and centrally managed, including the Admin-facing environment-variable editor (§20), which is additive to, not a replacement for, `.env`-file-based configuration. Extensibility (new modules, APIs, storage providers, notification channels) is evidenced in practice by the Firebase Cloud Messaging migration being a clean swap of the push transport layer without touching client-side notification-handling code.

---

# 26. Capacity Planning & Operational Quality

Capacity planning considers active users, storage growth, database size, media volume, and network utilization, and explicitly accounts for:

* **Load-test-seeded accounts** as a distinct population - Admin → Users segments Real/Load-test/All (`account_type`, matched on the `@mail.meet` marker), so operational metrics and capacity reviews are not distorted by synthetic accounts.
* **Cron-tick throughput**, not queue-worker count, as the operative background-processing capacity lever (§8, §9).
* **The unchunked-query failure mode** (§8.2) as a standing review item whenever a new one-off data operation is proposed.

---

# 27. Performance & Security Testing

Load, stress, endurance, and recovery testing; authentication, authorization, file-access, input-validation, API-security, and dependency-review testing are all in scope. Verification practice extends to real-infrastructure testing (a live Puppeteer session obtaining a genuine FCM token and confirming genuine Admin SDK delivery against it) rather than mock-only testing for infrastructure-dependent features, and real-viewport/real-device testing for mobile-specific reliability fixes (§23).

---

# 28. Quality Attribute Matrix

| Quality Attribute | Current Objective                                                                                     |
| ------------------ | ------------------------------------------------------------------------------------------------------ |
| Performance         | Fast, predictable response times; real pagination on every list surface (§4, §6)                       |
| Scalability         | Growth via horizontal scaling, storage, DB optimization, and cron-tick throughput - **not** queue workers (§9) |
| Security            | MFA/Passkeys/SSO, hashed credentials everywhere including meeting passwords, step-up auth on sensitive actions (§13-§16, §20) |
| Privacy             | Configurable visibility, consistently enforced across every surface that shows presence/content (§18)   |
| Data Protection     | Server-managed message encryption at rest, disclosed non-E2E trust model (§17)                          |
| Reliability         | Heartbeat-based liveness, cron-bounded cleanup, offline-first chat/contacts, safe PWA updates (§7, §10, §11) |
| Availability        | High uptime; genuine offline fallback for chat/contacts; graceful degradation elsewhere (§11)           |
| Maintainability     | Modular, documented, incident-informed operational constraints (§8.2, §20)                              |
| Usability           | Reference-app-parity mobile UX, verified on real devices/viewports (§23)                                |
| Accessibility       | Keyboard/screen-reader/contrast support (§24)                                                           |
| Compatibility       | Current major browsers/devices, validated via real-device testing (§24)                                 |
| Extensibility       | Demonstrated via clean push-transport swap (FCM) and guest-parallel-controller pattern (§25)            |
| Operability         | Env-var management, load-test/real segmentation, cron-based background-work visibility (§20, §26)       |

---

# 29. Validation & Acceptance

The platform satisfies this NFR when: response-time targets are met; MFA/Passkeys/SSO and message encryption function as specified; cleanup/inactivity cron jobs run and self-report; offline fallback genuinely works with connectivity cut; PWA updates never interrupt a live call/meeting; and no unchunked, unbounded query runs outside a scheduled, batched job. Validation proceeds through code review, automated testing, manual QA, performance/security/accessibility testing, and operational review, including real-infrastructure and real-device verification where a feature's correctness cannot be established through mocks alone (§27).

---

# 30. Governance & Change Management

NFR-affecting changes require impact assessment, documentation updates, testing updates, and operational review before approval, reviewed at architecture reviews, before releases, and during major upgrades/security reviews. `Documentation/IMPLEMENTATION_CHANGES.md` is the ongoing record of that review process - every change referenced above went through it at ship time.

---

# 31. Summary

This document establishes the performance, scalability, security, privacy, reliability, availability, and quality requirements for the Meet platform: a cron-only, no-queue-worker architecture (§2, §8, §9), server-managed (non-zero-knowledge) message encryption (§17), a materially expanded MFA/Passkeys/SSO/multi-account authentication surface (§13), genuine offline-first chat/contacts (§11.1), heartbeat-based meeting liveness (§10), FCM-based push reliability (§22), and a set of self-bounding cleanup cron jobs that keep storage and data growth in check (§7).
