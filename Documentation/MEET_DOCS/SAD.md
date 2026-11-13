# System/Software Architecture Document (SAD)

**Version:** 2.0

**Document Type:** System/Software Architecture Document

**Priority:** Critical

---

# 1. Purpose

This document defines the complete technical architecture of the Meet platform: the system's overall shape, its codebase organization, its database design, its real-time and communication mechanisms, its background-processing model, and the significant architectural decisions that shaped it. It is the single, current reference for how Meet is actually built - covering what earlier separate architecture, database, and entity-relationship documents addressed individually, now as one coherent picture.

---

# 2. Architecture Vision

Meet is a modular communication platform supporting private chat, group chat, communities, broadcast lists, voice calls, video calls, full-featured meetings, a collaborative whiteboard, a calendar, file sharing, guest access with no account required, push notifications, and administration - deployable on ordinary shared/managed hosting without sacrificing a real feature set.

The architecture prioritizes:

* Modularity - each capability is an independently organized Laravel module.
* Security and privacy, calibrated honestly to what the system actually guarantees rather than overstated.
* Performance within the real constraints of shared hosting.
* Maintainability by multiple developers over time.
* Configurability - operational values live in configuration or the database, not hardcoded.
* Deployability without server-side build tooling or a persistent worker process.

---

# 3. Core Design Principles

* **Modular architecture.** Each feature is a self-contained Laravel module sharing a common core layer.
* **Single Responsibility.** Controllers handle requests; Services hold business logic; Repositories own data access.
* **Configuration over hardcoding.** Branding, limits, intervals, and feature toggles are configurable, not baked into code.
* **Service-oriented business logic**, reused across entry points rather than duplicated per entry point.
* **Event-driven, in-process side effects** - used for decoupling, not as a substitute for a queue that doesn't exist (see § 21).
* **Polling-based synchronization**, deliberately, not WebSockets (see § 12).
* **API-first development**, with a documented JSON contract independent of the web (Blade) frontend that also consumes it.
* **Bridge instead of duplicate.** When an existing subsystem can be extended to cover a new need, it is bridged into rather than re-built in parallel (see § 18, § 28).
* **Clean replacement over dual maintenance.** When a design is replaced mid-development, the old implementation is removed outright, not kept alongside the new one "just in case" (see § 28).

---

# 4. Technology Stack

* **Backend:** Laravel 12, PHP 8.3+
* **Database:** MySQL 8+, accessed through Laravel Eloquent
* **Frontend:** Server-rendered Blade views, Tailwind CSS v4, vanilla JavaScript (no frontend framework)
* **Delivery model:** Progressive Web App (installable, offline-capable for select surfaces - § 15)
* **Real-time transport:** WebRTC (direct peer media) for calls/meetings; AJAX polling for chat, presence, and status
* **Push:** Firebase Cloud Messaging (Firebase Web SDK client-side, Firebase Admin SDK server-side)
* **Scheduling:** Laravel's scheduler, invoked exclusively via cron - there is no queue worker anywhere in this application, in any environment (see § 21)
* **Hosting target:** cPanel-compatible shared/managed hosting, artifact-based deployment (see § 27)
* **STUN:** Google's public STUN servers by default; TURN remains a future addition (see § 29)

---

# 5. High-Level Architecture

```text id="highlevelarchitecture"
                    Users
                       │
      ┌──────────────────────────────────┐
      │      PWA (Mobile/Desktop)        │
      │ Blade • Tailwind • Vanilla JS     │
      │ Service Worker (offline + push)   │
      └──────────────────────────────────┘
                       │
                 HTTPS / REST API
                       │
      ┌──────────────────────────────────┐
      │        Laravel Application       │
      │      (Modular Monolith, § 7)     │
      └──────────────────────────────────┘
                       │
 ┌──────────────────────────────────────────────┐
 │ Authentication (password, 2FA, passkeys, SSO) │
 │ User / Contacts                               │
 │ Chat (encryption, offline, disappearing msgs) │
 │ Groups / Communities / Broadcast Lists        │
 │ Calls (signaling, escalation bridge)          │
 │ Meetings (WebRTC mesh, whiteboard, guests)    │
 │ Calendar                                      │
 │ Notification (Firebase Cloud Messaging)       │
 │ Archive / Analytics                           │
 │ Configuration Engine                          │
 │ Admin                                         │
 └──────────────────────────────────────────────┘
                       │
     ┌──────────────────────────────────────┐
     │ MySQL │ Cron Scheduler │ File Storage │
     └──────────────────────────────────────┘
                       │
      Google STUN servers ── Firebase (push)
                       │
            Direct WebRTC Peer Connection
```

There is deliberately no application server process between the PWA and Laravel other than the standard HTTP request/response cycle, and no message broker or queue daemon anywhere in the stack.

---

# 6. Core System Modules

**Core / platform modules:** Authentication (password, TOTP two-factor, passkeys/WebAuthn, Google SSO, multi-account switching), User Management, Configuration, Security, Storage, Notification, Scheduler, Analytics.

**Communication modules:** Chat (1:1, groups, broadcast lists, communities), Calls (1:1 voice/video with screen share), Meetings (N-way WebRTC mesh with whiteboard, waiting room, guest access), Calendar (scheduling, reminders, standalone events), File Sharing, Guest Mode (account-free meeting creation and participation).

**Administration modules:** Dashboard, Users, Roles & Permissions, Reports, Archives, Monitoring, Settings, Environment management.

Every module retains its own Controllers, Services, Repositories, Models, Policies, Requests, Resources, Events, and cron-triggered Jobs (see § 7-§ 9), and modules interact through services and contracts rather than reaching into one another's internals.

---

# 7. Modular Monolith: Codebase Organization

Meet is implemented as a **modular monolith** - a single deployable Laravel application internally organized as independent modules, rather than either a single undifferentiated codebase or a distributed set of separately-deployed services.

```text id="rootfolderstructure"
app/
bootstrap/
config/
database/
Documentation/
lang/
public/
resources/
routes/
storage/
tests/
vendor/

Modules/
    Core/
    Authentication/
    User/
    Contacts/
    Chat/
    Groups/
    Media/
    Calls/
    Meetings/
    Whiteboard/
    Calendar/
    Notification/
    Guest/
    Archive/
    Analytics/
    Admin/
    Configuration/
    Shared/
```

Modules communicate through services, contracts (interfaces), events, and well-defined APIs - never through direct cross-module database manipulation.

---

# 8. Standard Module Structure

```text id="standardmodulestructure"
ModuleName/
    Config/
    Console/
    Contracts/
    DTO/
    Events/
    Exceptions/
    Helpers/
    Http/
        Controllers/
        Middleware/
        Requests/
        Resources/
    Jobs/            (cron-invoked, never queued - see § 21)
    Listeners/
    Mail/
    Models/
    Notifications/
    Observers/
    Policies/
    Providers/
    Repositories/
    Routes/
    Rules/
    Services/
    Traits/
    Views/ (only where the module renders Blade)
    Tests/
```

Every module follows this same shape, so a developer already familiar with one module can navigate any other.

---

# 9. Layered Responsibilities

* **Controllers** receive requests, apply authorization, delegate to Services, and return Resources. They never contain business logic, execute complex queries directly, or send mail themselves.
* **Services** hold business logic and are the reusable, testable unit shared across entry points - for example, a single service method resolves invitee-email matching for both the web scheduling form and the meetings API, rather than each implementing its own version.
* **Repositories** encapsulate all database access via Eloquent and the query builder; raw SQL is reserved for justified performance cases.
* **DTOs** carry validated data between layers and stay immutable where practical.
* **Form Requests** own all input validation, so validation logic is defined once and reused.
* **API Resources** control JSON output shape, keeping the API contract consistent and decoupled from internal model structure.
* **Policies** own authorization decisions, so permission checks are never duplicated ad hoc inside controllers.
* **Events/Listeners** handle decoupled, in-process side effects - since there is no queue, a listener either does its work synchronously or hands off to a scheduled job, never a `dispatch()` into a worker that does not exist.

A structural convention used throughout the codebase: any feature usable by both an authenticated user and an unauthenticated meeting guest gets a second, dedicated controller reached through its own guest-scoped routes (for example, a guest-facing whiteboard controller mirroring the authenticated one 1:1), rather than branching a single controller's logic on "is this a guest." The service layer underneath such a feature is written against `MeetingParticipant` - an abstraction that already models "whoever is in this meeting, whether registered or guest" - rather than against `User` directly, and resolves which half of a nullable-column pair to populate based on whether the participant is a guest.

---

# 10. Dependency Rules

Allowed:

```text id="dependencyrules"
Controller → Service
Service → Repository
Repository → Database
Service → Event
Event → Listener
Listener → Scheduled Job (never a queue)
```

Not allowed:

```text id="dependencyrulesforbidden"
Controller → Repository
Controller → Database
Controller → Mail
View → Database
Module → Another Module's Internal Classes
```

Cross-module interaction happens through contracts or public service methods only.

---

# 11. Request Lifecycle

1. A user performs an action in the PWA (or a guest, in an unauthenticated meeting flow).
2. The request reaches a Laravel route.
3. Middleware validates the request (authentication, rate limiting, guest-session validation as applicable).
4. A Form Request validates input.
5. The Controller delegates to a Service.
6. The Service executes business logic and coordinates Repositories.
7. Events are dispatched for decoupled side effects.
8. Anything requiring asynchronous-feeling execution is either handled inline within the same request (e.g. sending a push notification) or picked up by the next scheduled cron tick - never queued.
9. An API Resource shapes the JSON response.
10. Connected clients pick up resulting state changes via polling (chat, presence, status) or, where subscribed, a Firebase push notification.

---

# 12. Real-Time & Synchronization Architecture

Meet does not use WebSockets. Real-time-feeling behavior is achieved through optimized polling, a deliberate and long-standing architectural choice suited to shared-hosting deployment rather than a stopgap:

* Active chat, notification badges, conversation lists, meeting rosters, and presence each poll at intervals tuned to their own freshness needs, all configurable through the Admin panel.
* Navigation-wide unread badges (Chat, Contacts) poll a shared aggregate endpoint from the common app layout itself, so they stay current on every page rather than only while viewing that specific section.
* Message status ticks (sent → delivered → read) advance at concrete trigger points - opening a thread, syncing the chat list, or a push notification reaching a backgrounded or fully closed client - rather than a continuous stream.
* A meeting roster is filtered by a recency heartbeat (a participant's client refreshes `last_seen_at` on every poll), so a participant whose tab closed, crashed, or lost network without a chance to signal "leaving" stops appearing as still-present once their heartbeat goes stale, instead of leaving a phantom tile indefinitely. A best-effort beacon sent on tab close/navigation-away makes the common case (the tab simply closing) resolve immediately rather than waiting out the staleness window.

The architecture is written to allow a future migration to WebSockets or a dedicated signaling server for the modules that would benefit most (§ 29), without polling being treated as a permanent ceiling on the product's capability today.

---

# 13. Chat Architecture

Chat supports 1:1 conversations, groups, communities (a group-of-groups container with a shared announcements channel), and broadcast lists (one message fanned out as individual 1:1 messages, so replies stay private between sender and each recipient rather than becoming a group thread).

Key structural facts:

* The conversation list and an open conversation both support real pagination and infinite-scroll, not an unbounded in-memory collection.
* A conversation supports per-conversation wallpaper, mute, archive, starring, pinning, disappearing messages (off / 24h / 7d / 90d, computed at send time into a per-message expiry and swept by a scheduled cleanup job), and export (a CSV transcript plus attached media, packaged as a zip).
* Attachments use a dedicated Camera / Gallery / Document picker, an in-app camera (`getUserMedia` + `MediaRecorder`, shutter for photo, press-and-hold for video), and a voice recorder rendered as its own composer-replacing bar with a waveform sampled from the live microphone stream, pause/resume, and one-tap send.
* Message actions (react, reply, forward, star, pin, delete, copy) render as a floating card positioned near the tapped bubble, opened by long-press or double-click rather than a single tap, so a single tap remains free for bubble-specific behavior (View Once tap-to-view, image lightbox tap-to-expand).
* On mobile, an open conversation is a deliberate, documented exception to the app's shared navigation chrome: the header and bottom nav are hidden and the thread renders full-bleed edge-to-edge, with its own in-thread header (back arrow, name, call buttons) as the sole navigation - matching how mainstream chat apps treat an open thread. The container tracks the on-screen keyboard via the visual viewport (not the layout viewport), so the composer lands correctly above the keyboard rather than being obscured by it.
* Every message-content-bearing surface - the thread itself, the starred-messages view, chat export, and the conversation list's own last-message preview - decrypts client-side before display; none of them render raw ciphertext.

---

# 14. Message Encryption Architecture

Text message bodies are encrypted, under a deliberately simpler trust model than a zero-knowledge scheme: the application itself generates and holds every conversation's key, rather than a scheme where only end-user devices ever hold key material. There is no per-user keypair and no password-derived unlock step; encryption is fully automatic from the user's perspective, and the chat-privacy banner shown at the top of every conversation is worded honestly around this model rather than overstating it as end-to-end encryption (see § 25).

* **Keys.** Each conversation is assigned one random AES-256-GCM key per epoch, generated server-side and stored encrypted at rest using the application's own encryption key. A new epoch is issued transparently whenever one is needed - none exists yet, or conversation membership has changed since the last one - with no separate client-initiated "issue a key" step.
* **Access control is enforced live.** Any current, authenticated member of a conversation can fetch a given epoch's key material; this is checked at fetch time against present-tense conversation membership, not fixed at the moment a key happened to be issued. A membership change rotates to a new epoch, so a removed member's client cannot decrypt anything sent after they left.
* **Client-side caching.** Fetched key material is cached in IndexedDB, persisting across reloads on both desktop and mobile browsers, through a single shared client-side entry point used by every page that needs it.
* **Group size cap.** Beyond a configured member-count threshold, a group stays plaintext server-side with a visible "not end-to-end encrypted" indicator, matching how mainstream encrypted messengers handle very large groups rather than attempting per-recipient key-wrapping at broadcast scale.
* **Historical migration.** Once every current member of an existing plaintext conversation can obtain a key, its message history is encrypted in place automatically, in bounded batches, the next time any member opens it.
* **Reply-from-push exception.** On the one browser platform that supports inline-reply notification actions, a typed reply can be sent directly from the service worker with no page open - it fetches the conversation's current key epoch and encrypts exactly as a normal in-app send would, authenticated via the same-origin session cookie. Every other browser degrades gracefully to a plain "open the conversation" action.
* **Disclosed scope boundary.** Only text message bodies are encrypted this way; regular media attachments are not, a materially larger undertaking (client-side file encryption, streaming, thumbnails) tracked as future work rather than silently left unaddressed. View Once media remains a deliberate exception: it is encrypted at rest using the server's own key rather than the per-conversation scheme, since it must remain server-decryptable for administrative purposes.

---

# 15. Offline-First Architecture

Chat and Contacts are deliberately offline-capable, a considered scope decision rather than an oversight - calls, meetings, authentication, and file uploads remain genuinely network-only, since real-time media negotiation and binary uploads cannot be meaningfully queued.

* **Client-side caching.** An IndexedDB-backed cache stores the conversation list, the most recent messages per conversation (already decrypted, for encrypted conversations), and the contacts list. The chat list, an open conversation, and the contacts page each fall back to rendering from this cache when a live fetch fails while offline.
* **Outbox queue.** Composing a plain text message or reply while offline renders an optimistic "waiting to send" state immediately and queues the send instead of failing outright. Once connectivity returns, a shared online-event listener drains the queue, and each conversation's own page supplies the logic to actually resend its own queued items (running the same encryption-aware send path used online) - a queued action for a conversation not currently open stays queued until that conversation is next opened while online, a disclosed and bounded scope decision.
* **Service worker shell caching.** The chat, conversation, contacts, and groups page shells are runtime-cached network-first, falling back to the last successfully rendered response when offline - the same pattern used for the login shell. Calls and meetings pages are deliberately excluded, since neither can function without a live connection regardless of shell availability.

---

# 16. Voice & Video Call Architecture

Calls are pair-based (`calls` has no group data model by design - see § 18) and use WebRTC for direct peer media, with Laravel handling only signaling (SDP offer/answer exchange, ICE candidates) via authenticated REST endpoints, polled by each party rather than pushed over a persistent socket.

* Controls render as an icon+label grid (speaker, camera, mute, then more/share/end), full-screen for video with an unmasked self-view.
* Screen share is supported for 1:1 calls: the local video sender's transceiver is pre-provisioned at call setup (present with no track for a voice call) specifically so starting or stopping a share never requires SDP renegotiation, and a dedicated signal type tells the other party explicitly when a share starts or stops.
* Output routing (speaker/earpiece) is supported where the browser exposes the underlying sink-selection API.
* A completed or missed call logs its own system-style row into the 1:1 chat it was started from, when the call is linked to a conversation.
* Call history entries are interactive: tapping one opens a detail popup with a call-back action reusing the same call-start entry point the in-thread call buttons use. A missed call also triggers its own push notification with call-back and quick-reply actions.

---

# 17. Meetings Architecture

Meetings support N-way participants over a WebRTC mesh, with a shared whiteboard, calendar integration, waiting room / direct-join access control, and full guest participation with no account required.

* **Guest access.** A visitor can create an instant, optionally password-protected meeting directly from the login page with no account, receiving a shareable join link immediately. The creating guest becomes the meeting's host-role participant but, being unauthenticated, cannot use authenticated-only host-control endpoints (mute/remove/lock/transfer); such a meeting winds down through scheduled cleanup instead of an explicit host action (see § 21).
* **Guest-parallel controllers** mirror every authenticated meeting action (roster, signaling, whiteboard, lock, screen share) through guest-session-scoped routes, keeping guest and registered code paths structurally identical rather than branched (§ 9).
* **Whiteboard** creator/actor columns are nullable-pair (a `users` FK and a `meeting_participants` FK, exactly one populated per row), so a guest can create and act on whiteboard objects using the same `MeetingParticipant` abstraction the rest of the guest-parallel system relies on. Drawing input uses Pointer Events with capture, not mouse-only handlers, so it works on touch devices.
* **Lifecycle and hygiene.** A liveness/heartbeat model (§ 12) drives both the live roster and a scheduled job that ends any meeting with nobody genuinely present for a sustained window, whether that's a lobby nobody entered or a room everyone has since left - closing a gap that would otherwise leave meetings active indefinitely once their creating guest sessions or organizers stopped being present.
* **Join links** are surfaced as a copyable `/j/{code}` URL everywhere a meeting appears - the host's own lobby/room view, the meetings list, and the calendar event-detail popup - not just inside the room itself.
* **Mobile layout** matches the reference full-screen video-call convention: the video grid takes the full content width with no permanent side panel, the toolbar is a floating semi-transparent bottom bar, and People/Chat opens as a full-screen slide-up overlay rather than a persistent panel; the desktop layout is unaffected by this.

---

# 18. Calls-to-Meetings Escalation Bridge

Rather than duplicating the Meetings module's already-built, tested N-way WebRTC mesh (lock, screen share, reactions, add-via-link) a second time inside the pair-only Calls module, adding a member to an active 1:1 call escalates it directly into a full Meeting: an instant meeting is created, both original call parties are admitted immediately (bypassing the lobby, since they are already mutually engaged in a live call and there is no real "waiting to be let in" moment to model), the original call is ended, and both parties are redirected into the full-featured meeting room. The call retains a reference to the meeting it escalated into, and the party who did not initiate the escalation is redirected automatically once their own call-status check notices it. This bridge is the deliberate alternative to building a second, parallel group-calling data model and UI inside Calls.

---

# 19. Push Notification Architecture

Push delivery uses Firebase Cloud Messaging end to end, chosen over a self-managed Web Push implementation for its more complete device/token lifecycle management.

* **Client.** The Firebase Web SDK obtains an FCM device token, explicitly passed the application's own existing service worker registration rather than letting Firebase register a separate service worker of its own - a page can only have one active service worker, and the application's service worker already owns push receipt and notification-click handling (including the inline quick-reply behavior described below). Firebase's token flow still rests on the same underlying browser Push API/service worker subscription mechanism, so that ownership required no change.
* **Server.** Notifications are built as Cloud Messaging data-only messages (no FCM-level notification payload), so the service worker's own push handler stays in full control of exactly what is displayed and how - matching how notification display already worked before this migration. The Firebase Admin SDK handles delivery server-side, authenticated via a service account.
* **Stale-subscription recovery.** A leftover subscription registered under a previous key (a different Firebase project, or an earlier non-Firebase Web Push setup) surfaces as a specific browser error when requesting a new token; that condition is now detected explicitly and the stale subscription is unsubscribed first, rather than the failure being swallowed silently with no way to recover.
* **Background delivery marking.** A message pushed to a fully closed or backgrounded client is marked delivered directly from the service worker's own push handler, rather than only ever advancing once the recipient's client next happens to poll - closing a gap where a genuinely-arrived message could sit on "sent" indefinitely.
* **Reply from a notification.** On the one browser/platform combination that supports inline-reply notification actions, a typed reply is sent directly from the service worker with no page open, using the same conversation-key-fetch-and-encrypt path a normal in-app send uses; every other platform falls back to a plain action that opens the conversation instead, per the graceful-degradation behavior the underlying notification action API already defines for an unsupported action type.
* **Notification content boundary.** A push for an encrypted conversation falls back to a generic "new message" preview, since the server cannot decrypt message content to build a real one.
* **Coverage.** New messages, incoming and missed calls, contact requests, and meeting reminders each trigger a push, gated per-type on the recipient's own notification preferences.

---

# 20. Custom Media Playback Architecture

Every audio, video, and image a message can contain uses the application's own playback UI rather than the browser's built-in media controls or native image viewer, kept visually consistent with the rest of the chat surface: a real play/pause control, seek bar, and time label built around a controls-less underlying media element (audio matching a compact voice-note bar with no volume control; video additionally offering mute and fullscreen), and a dedicated full-screen image viewer opened by tapping a bubble. The View Once full-screen viewer shares the same custom players rather than maintaining its own separate implementation.

---

# 21. Scheduler & Background Processing Architecture

**There is no queue worker in this application, in any environment, including production.** The production host is shared/managed cPanel hosting with no persistent background process - only cron. This is a hard architectural constraint, not a temporary limitation, and it shapes how every "background" behavior in the system is implemented:

* Nothing in the codebase is dispatched onto a queue. A "job" in this codebase means a class invoked by the scheduler on a cron tick, never a class handed to a worker that does not exist.
* Time-sensitive actions that would elsewhere be queued (push notification delivery, for example) run synchronously, inline, within the request that triggers them instead.
* Periodic work is modeled as scheduled jobs ticking every few minutes: cleanup of expired disappearing messages, cleanup of expired guest sessions (and the guest-created meetings that depend on them), auto-ending inactive meetings, cleanup of orphaned media files, marking missed calls, and any bounded catch-up/backfill processing.
* Any batch operation whose cost scales with table size runs as a scheduled job processing bounded chunks per tick and disabling itself once no backlog remains - never as a single unchunked deploy-time action. This distinction is enforced deliberately: an earlier deploy-time backfill that ran as one unchunked query against a large table caused a real production incident (exhausting memory or running long enough to overlap with the next deploy attempt), which is why deploy-time version actions are now restricted to cheap, near-instant work only, with anything scaled to table size required to be a scheduled job from the outset.

---

# 22. Database Architecture - Design Standards

* **Engine:** MySQL 8+, accessed exclusively through Laravel Eloquent and the query builder; schema changes are made only through Laravel migrations, never applied by hand.
* **Naming:** tables are plural snake_case (`users`, `meetings`, `conversation_participants`); columns are snake_case; foreign keys follow `{table_singular}_id`.
* **Primary keys:** every table uses an auto-incrementing unsigned bigint `id`.
* **Timestamps:** every transactional table carries `created_at`/`updated_at`; `deleted_at` is added wherever soft-delete semantics apply.
* **Nullable-pair actor columns.** Where a row can be created or acted on by either a registered user or an unauthenticated guest, this is modeled as two nullable foreign keys (one to `users`, one to `meeting_participants`) with exactly one populated per row, rather than a single polymorphic-ish column or forcing a `User` row to exist for guests. This pattern is used throughout the Meetings/Whiteboard schema and is the expected shape for any future guest-accessible feature.
* **Foreign keys** maintain referential integrity and use cascading rules only where appropriate; critical historical records (audit logs, analytics, completed meetings) are not cascade-deleted.
* **Indexing** exists on email, phone, username, and every foreign key, plus `created_at`/`status` columns and composite indexes where query patterns justify them.
* **Soft deletes** are used for users, groups, meetings, messages (per feature rule), communities, and files, with permanent deletion following retention/cleanup jobs rather than being immediate.
* **Transactions** wrap multi-step writes: registration, meeting creation, group/community creation, archive generation, and permission updates.
* **Audit data** (user, action, module, timestamp, IP, device, result) is append-only through the application layer.
* **Configuration data** (branding, SMTP, upload limits, polling intervals, guest settings, STUN/TURN, feature flags) is database-backed where an administrator needs to change it without a code deployment.
* **Storage references.** Media rows store file name, original name, MIME type, size, storage path, and owner - never binary content inline.

---

# 23. Database Architecture - Entity Groups

The schema is organized into the following functional groups, each mapped to its owning module:

* **Authentication:** `users`, password reset tokens, email verification/OTP records, `user_sessions`, `trusted_devices` (with an expiry, backing 2FA device-remembering and multi-account session handling), passkey credentials, SSO-linked identity data. `users.phone` and `users.password` are nullable to support password-less, phone-less SSO-created accounts.
* **Profile & Preferences:** `user_profiles`, `user_preferences` (timezone, media auto-download per type), `user_privacy_settings` (including last-seen visibility), notification-preference toggles.
* **Contacts:** relationships/contacts, `blocked_users`, `favorite_contacts`, contact requests.
* **Chat:** `conversations` (including `disappearing_messages_seconds`), `conversation_participants`, `messages` (including computed `expires_at`), `message_status` (per-recipient delivered/read), `message_reactions`, `pinned_messages`, `starred_messages`, `chat_settings`/`chat_wallpapers`, broadcast list tables, `conversation_keys` (per-conversation, per-epoch encrypted key material for the message encryption architecture in § 14).
* **Groups & Communities:** `groups`, `group_members`, `group_roles`/permissions, `group_polls`, `group_announcements`, community tables (a community's link to its member groups and its auto-created announcements group).
* **Meetings:** `meetings` (`organizer_id` nullable, for guest-created meetings), `meeting_participants` (including `guest_session_id`, `last_seen_at` heartbeat), `meeting_invitations` (`invited_email` nullable alongside `invited_user_id`, for ad-hoc email invitees), `meeting_history`, `meeting_chat`, guest session records.
* **Calls:** `calls` (including `conversation_id` linking a call to the chat it logs into, and `escalated_to_meeting_id` for the escalation bridge in § 18), call history, signaling message records.
* **Whiteboard:** `whiteboards`, `whiteboard_pages`, `whiteboard_objects`, `whiteboard_history`, `whiteboard_exports` - creator/actor columns are the nullable-pair shape described in § 22.
* **Calendar:** `calendars`, `calendar_events` (a variety of `event_type` values including meeting-linked, reminder, personal, holiday, and task entries), `meeting_reminders`, `calendar_rsvp`.
* **Files/Media:** `uploads`, `upload_chunks`, `media_files` (including a flag marking server-side-encrypted-at-rest media such as View Once), one-view file records.
* **Notifications:** `notifications`, `push_subscriptions` (storing an FCM device token rather than a raw Web Push credential triple), notification templates/logs.
* **Administration:** `roles`, `permissions`, `role_permissions`, `settings`, `feature_flags`, environment-management audit trail.
* **Analytics & Archive:** analytics event/report tables, `archives`, `archive_history`, retention policy records.

---

# 24. Storage Architecture

Storage categories include avatars, images, videos, audio, documents, meeting assets, whiteboard exports, View Once files, temporary uploads, and archive files, with the storage provider itself configurable rather than hardcoded to local disk. View Once media is encrypted at rest using the application's own key even though it is not part of the per-conversation message-encryption scheme (§ 14), since it must remain server-decryptable. Orphaned and expired files - an interrupted View Once stream, an uploaded-but-never-sent attachment, or residue from "delete for everyone" - are reclaimed by scheduled cleanup jobs (§ 21) rather than accumulating indefinitely.

---

# 25. Security Architecture

* **Transport and framework-level protections:** HTTPS, Laravel's authentication and policy layers, CSRF protection, XSS protection, parameterized queries throughout, rate limiting.
* **Step-up re-authentication** is required immediately before sensitive, one-way actions - disabling two-factor authentication, removing a passkey, deactivating an account, and editing live environment values all require re-entering the actor's current account password at the moment of the action, regardless of session age.
* **Secrets are hashed, never stored in plaintext** - meeting passwords and comparable secrets included.
* **Privacy settings are enforced at every surface that exposes the underlying data, not only the first one built.** Last-seen visibility, for example, is checked consistently across every polled endpoint and initial server-rendered view that could otherwise leak presence.
* **Honest privacy framing.** The chat-privacy banner shown in every conversation describes messages as private to the conversation's participants without claiming end-to-end encryption, matching the actual server-managed encryption model in § 14 rather than overstating it.
* **Sensitive configuration values** (API keys, secrets, passwords, tokens) are masked by default wherever an administrator views them, with an explicit reveal action.
* **Audit logging** covers account-access actions (forced logout, password reset, role/permission grants) and environment changes.

---

# 26. Configuration & White-Label Architecture

Operational values - branding, SMTP, storage, STUN/TURN, upload limits, polling intervals, security policy, meeting defaults, guest-mode behavior, feature flags, and notification templates - are configurable without a code change, primarily through the Admin panel's Settings and Environment tabs. White-label items (application name, company name, logo, favicon, theme colors, PWA manifest, email branding, login screen, footer) are administrator-configurable so no module hardcodes application identity; every PWA manifest icon size, the Apple touch icon, and the favicon reference the same real, versioned logo assets, including a maskable variant sized for safe-zone cropping on Android home screens.

---

# 27. Deployment Architecture

Meet deploys to standard cPanel-compatible shared/managed hosting with two hard constraints that shape the deployment pipeline itself:

* **No ability to execute arbitrary commands on the server.** There is no server-side `composer install` or `npm run build` step - `vendor/` and the compiled `public/build/` frontend assets are committed to the repository and shipped as-is.
* **No persistent process beyond cron.** This is the same constraint driving § 21; the deployment pipeline itself must also work within it, meaning deploy-time "version actions" are restricted to cheap, near-instant operations, with anything whose cost scales with table size implemented as a scheduled job instead.

The pipeline additionally keeps a live server's `.env` synchronized with each release: any environment key present in a new release's example file but missing from the live file is added automatically (left blank, never overwriting an already-configured value), so a newly required integration credential is never silently absent after a deploy.

---

# 28. Key Architectural Decisions

This section records the significant architectural decisions behind the system, with the reasoning that drove each one, not just the outcome.

**Calls-to-Meetings escalation instead of a second group-calling mesh.** The Calls module's data model is fundamentally pair-based. Rather than building a parallel N-way WebRTC mesh inside Calls to support adding a third participant, adding a member to an active call escalates the whole interaction into a full Meeting - reusing the Meetings module's already-built, tested mesh, lock, screen share, and reactions instead of duplicating them. The two original call parties are admitted directly into the resulting meeting room, bypassing the lobby, since they are already mutually engaged in a live call and there is no meaningful "waiting to be let in" moment to model for them. This is a direct application of preferring a bridge between existing subsystems over a second, competing implementation of the same capability.

**Push notifications on Firebase Cloud Messaging, not a self-managed Web Push stack.** An initial self-managed VAPID/Web Push implementation was replaced outright with Firebase Cloud Messaging for more complete device-token lifecycle handling (stale-subscription detection, delivery classification) than maintaining that machinery independently would provide. The migration deliberately preserved ownership of push *display* and *click handling* in the application's own service worker - Firebase supplies the device token and transport, but never registers a competing service worker of its own - since a page can only run one, and the existing worker already implements notification behavior (including inline quick-reply) that needed to keep working unchanged.

**Offline-first support for chat and contacts, explicitly not for calls, meetings, or uploads.** Chat and contacts were made to work meaningfully offline - cached data, an outbox for queued sends - as a deliberate, scoped reversal of an earlier, narrower offline design that treated chat synchronization as inherently requiring a live connection. Calls, meetings, authentication, and file uploads remain genuinely network-only: this is not an inconsistency in how "offline-first" was applied, but a recognition that real-time media negotiation and binary transfer cannot be meaningfully queued the way a text message can.

**Server-managed message encryption, not a zero-knowledge scheme - including a design that was built and then deliberately discarded.** An earlier design implemented genuine zero-knowledge encryption: a per-user keypair with the private key password-wrapped client-side, requiring an "unlock encrypted messages" step on a new device. That design was replaced entirely - the keypair code and its supporting tables removed outright, not deprecated alongside the replacement - before it reached real users, in favor of the current model where the application itself generates, holds, and rotates every conversation's key server-side. The reasoning: a password-unlock gate is real friction for an ordinary chat product, and the simpler, honestly-disclosed trust model (the server can technically read message content, and the product says so plainly rather than claiming otherwise) was judged the better fit for this application's actual threat model and user expectations, rather than offering a false sense of end-to-end privacy with a worse experience attached.

**Cron-only background processing, with no queue worker, as a permanent architectural constraint rather than a temporary gap.** The hosting target is shared/managed cPanel hosting with no persistent worker process available at all - only cron. Every mechanism elsewhere described as "asynchronous" (push delivery, cleanup, reminders) is built to either run synchronously inline or as a scheduled, self-limiting job, never assuming a queue daemon that this deployment target cannot provide. This constraint was learned the hard way once: an early data-backfill was implemented as a single unchunked deploy-time script, which - run against a database already holding a very large number of accounts - either exhausted memory or ran long enough that the next cron tick triggered an overlapping second deploy attempt, a real production incident. The fix was architectural, not a one-off patch: deploy-time actions are now restricted to cheap, near-instant work, and anything scaled to table size must be a bounded, scheduled, self-disabling batch job.

**Custom-built media players instead of native browser controls.** Every audio, video, and image attachment renders through the application's own playback UI rather than the browser's native `<audio>`/`<video>` controls or image viewer. The reasoning is visual and behavioral consistency: native controls vary by browser and cannot be styled to match the rest of the product, and a bespoke player (mirroring a familiar voice-note bar for audio, a lightbox for images) keeps the chat surface feeling like one coherent product rather than a patchwork of default browser widgets.

**Guest-parallel controllers and nullable-pair actor columns, as the standard shape for any account-free feature.** Rather than branching a single controller and a single schema column on "is this user a guest," any feature reachable by an unauthenticated meeting guest is implemented as a dedicated, guest-scoped controller mirroring its authenticated counterpart 1:1, backed by a schema shape with two nullable foreign keys (one to `users`, one to `meeting_participants`) where exactly one is populated per row. This keeps guest support additive and structurally obvious rather than threading conditionals through already-complex authenticated code paths, and is the expected pattern for extending any further capability to guests in the future.

---

# 29. Future Scalability

The architecture is written to accommodate, without requiring a ground-up redesign:

* Migration from polling to WebSockets or a dedicated signaling server for the modules that would benefit most.
* Dedicated TURN infrastructure alongside the current default reliance on public STUN.
* Cloud object storage as an alternative to local disk storage.
* Native mobile applications consuming the same versioned API.
* AI-assisted features (e.g. summaries), enterprise/organization support, and plugin-style extension points.
* Selective decomposition of specific modules into separately deployed services, where a module's own scaling needs would eventually justify it - without that being a requirement for the architecture as it stands today.

---

# 30. Success Criteria

The architecture is judged against:

* Supporting the full functional scope described above on ordinary shared/managed hosting, with no queue worker and no server-side build step.
* Avoiding hardcoded operational values wherever an administrator might reasonably need to change them without a deployment.
* Remaining navigable and maintainable by multiple developers, through consistent module structure and layered responsibility.
* Allowing future infrastructure upgrades (WebSockets, TURN, cloud storage, native clients) with minimal structural change.
* Treating architectural decisions - including reversals of earlier designs - as documented reasoning, not silent history.

---

# Document Summary

This System/Software Architecture Document defines Meet's complete technical foundation: a modular-monolith Laravel application organized into independent, consistently structured modules; a MySQL schema built around plain, well-indexed relational tables with a deliberate nullable-pair convention for guest-accessible entities; a polling-based real-time model layered with genuine WebRTC peer media for calls and meetings; a bridge from pair-based calls into full meetings rather than a duplicated group-calling mesh; server-managed, honestly-disclosed message encryption; offline-first chat and contacts scoped deliberately around what can and cannot be meaningfully queued; Firebase Cloud Messaging push delivery still owned end-to-end by the application's own service worker; custom-built media playback; and a cron-only background-processing model with no queue worker, reflected consistently from the codebase's job classes through to the deployment pipeline itself. All other technical documentation for Meet is expected to remain consistent with the principles and decisions recorded here.
