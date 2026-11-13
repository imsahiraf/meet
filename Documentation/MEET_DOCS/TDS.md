# Technical Design Specification (TDS)

**Version:** 2.0

**Document Type:** Technical Design Specification

**Priority:** Critical

---

# 1. Purpose

This document defines the technical design standards and engineering framework for the Meet platform, and the concrete implementation design of every major subsystem.

While the SRS defines **what** the system must do and the SAD defines **how the system is organized** at an architectural level, this Technical Design Specification defines **how each module is engineered and implemented internally** - data flow, algorithms, protocol-level choices, and the concrete mechanics behind each feature. It is the primary reference for developers during implementation and maintenance.

---

# 2. Objectives

The technical design ensures:

* High Maintainability
* High Scalability within the constraints of the deployment platform
* Security by Design
* Performance by Design
* Modular Development
* Reusability
* Testability

Every implementation decision supports these objectives, including decisions that deliberately trade a theoretically more scalable approach for one that fits the platform actually operated on (see Section 24, Background Processing & Scheduled Jobs).

---

# 3. Design Principles

The platform follows:

* SOLID Principles
* DRY (Don't Repeat Yourself)
* KISS (Keep It Simple)
* Separation of Concerns
* Dependency Injection
* Composition over Inheritance
* Convention over Configuration

A related, actively-applied principle: where two features solve structurally similar problems, the platform prefers **bridging** one into the other over building a parallel implementation. The clearest example is Section 18 (Call-to-Meeting Escalation), where a 1:1 call gaining a third participant hands off into the existing N-way meeting engine rather than a second group-calling stack being built inside the pair-only Calls module.

---

# 4. Technical Architecture Layers

```text id="techlayers"
Presentation Layer
        ↓
Routing Layer
        ↓
Controller Layer
        ↓
Validation Layer
        ↓
Service Layer
        ↓
Repository Layer
        ↓
Model Layer
        ↓
Database
```

Each layer has clearly defined responsibilities. Business rules live in the Service Layer; controllers stay thin; repositories encapsulate query construction only.

---

# 5. Module Design Standard

Every major module includes:

* Migration
* Model
* Repository
* Service
* Form Requests
* Policy
* Controller
* API Resource
* Events
* Listeners
* Scheduled Commands (where background processing is required - see Section 24; the platform does not build modules around queued `ShouldQueue` jobs)
* Tests

No required architectural layer is omitted without documented justification.

---

# 6. Naming Conventions

The project uses consistent naming for classes, interfaces, traits, controllers, services, repositories, events, scheduled commands, policies, database tables, and API routes, following Laravel conventions throughout. A recurring convention worth calling out explicitly: wherever a feature must serve both authenticated users and unauthenticated guests, the guest path is implemented as a parallel, guest-scoped controller/route group rather than branching inside the authenticated one - see `GuestMeetingController`, `GuestWhiteboardController`, and the guest signaling/roster endpoints in Section 19.

---

# 7. Dependency Management

Dependencies are explicitly defined, injected where appropriate, and reviewed before adoption. Third-party packages are preferred over custom implementations for well-solved problems - TOTP (`pragmarx/google2fa-qrcode`), WebAuthn (`laravel/passkeys`), OAuth (`laravel/socialite`), and push delivery (`kreait/firebase-php`) are all adopted rather than hand-rolled (Section 10, Section 21).

---

# 8. Error Handling Strategy

The application uses structured exceptions, returns standardized API errors, logs operational failures, and avoids exposing internal implementation details in responses. Domain-specific exceptions are preferred over generic ones where the distinction matters to the caller - for example, a sender attempting to re-open their own unconsumed One View media raises a dedicated exception rather than a generic authorization failure, so the frontend can render the correct "One View" label instead of a raw error (Section 16).

---

# 9. Configuration Management

Configuration is environment-specific, avoids hardcoded values, and supports feature flags through a database-backed Settings table (read by the Admin feature-flag UI - Section 21) rather than config-file-only toggles, so an operator can flip a feature without a deployment.

The live `.env` file itself is also a managed, editable artifact rather than a file only ever touched by hand over FTP/SSH: Admin → Environment (Section 21) provides a controlled write path with backup-before-write, step-up re-authentication, and an automatic config-cache clear, and the deploy pipeline (Section 25) has its own automatic mechanism for carrying new configuration keys into an already-running production `.env`.

---

# 10. Authentication & Session Management Technical Design

## 10.1 Module Responsibilities

The Authentication module provides registration, credential authentication, email verification, password management, session management, multi-factor verification, passkey and SSO login, multi-account switching, and device/session tracking.

## 10.2 High-Level Architecture

```text id="authdesign"
Client
      ↓
Routes
      ↓
Auth Controller
      ↓
Form Request Validation
      ↓
Authentication Service
      ↓
User Repository
      ↓
User Model
      ↓
Database
```

## 10.3 Primary Authentication Flow

```text id="loginflow"
Login Request
      ↓
Input Validation
      ↓
Credential Verification
      ↓
Account Status Check
      ↓
Second-Factor Challenge (if enabled and device not remembered)
      ↓
Session Creation
      ↓
Audit Log Entry
      ↓
Successful Login
```

A password check succeeding is not sufficient by itself to issue a session or token when a second factor is configured for the account - the second-factor challenge sits strictly between credential verification and session issuance, never after.

## 10.4 Two-Factor Authentication (TOTP)

Users enable TOTP from Settings → Account & Security by scanning a QR code (`pragmarx/google2fa-qrcode`) and confirming with one real generated code - a secret is never activated purely on generation, only once the user demonstrates possession of a working authenticator. Eight one-time recovery codes are displayed exactly once at enrollment. Once enabled, both web and API login stop at a dedicated code-entry step after password verification and before a session or token is actually issued. A "Remember this device for 30 days" option on the code-entry screen writes an entry to a `trusted_devices` table, keyed by an `expires_at` deadline; only a hash of the device's own random token is stored server-side, with the raw token living solely in the browser's cookie, matching how every other bearer-style secret in the app is treated. Users can review and revoke trusted devices from Settings.

## 10.5 Passkeys (WebAuthn)

Passkey registration and a "Sign in with a passkey" login entry point are implemented via the official `laravel/passkeys` package. A passkey login deliberately skips the separate TOTP challenge: a passkey ceremony is itself possession plus biometric/PIN verification and is already a second factor by construction, matching how major identity providers treat it. This is web-login only; there is no Sanctum/API equivalent.

## 10.6 SSO with Google

"Continue with Google" (`laravel/socialite`) signs a user in or registers them in a single step, matching an existing account by email automatically or creating a new, already-verified account. Unlike a passkey, Google SSO does **not** skip the TOTP challenge: a linked Google account is a separate identity from the app's own account, so any TOTP configured on the app account still protects it regardless of which method was used to authenticate. `users.phone` and `users.password` are both nullable at the schema level to support password-less, phone-less SSO accounts; the ordinary registration form's own validation still requires both as before - only the database-level constraint is relaxed.

## 10.7 Multi-Account Login and Switching

A user can hold a second account's active login on the same browser without disturbing the first (Settings → Account & Security → Switch account, or "Continue as…" tiles on the login page for an account already held but not currently active). This is built entirely on the existing "remember me" mechanism (`users.remember_token` plus `Auth::loginUsingId()`) rather than a new session or token layer - a held account is never more trustworthy than its own remember-me login already was, and revoking that token elsewhere (a real password change, an admin-forced logout) invalidates it here too, automatically, with no separate revocation path to maintain. Logging out of an account explicitly forgets it from the switcher, since the framework's own logout already rotates that account's remember token. This flow, like Passkeys and SSO, is web-login only - a bearer-token API client has no cookie jar for the switcher to use.

## 10.8 Admin-Triggered Password Reset

An administrator can set a user's password directly, optionally marking it temporary. A temporary reset emails the new password and forces the recipient to a dedicated change-password page before they can reach anywhere else in the app on their next login, enforced by a global middleware checking a `users.must_change_password` column. The target's other active sessions are force-logged-out the same way any other admin account-access action already is.

## 10.9 Session Management

Sessions support login, logout (including "logout of all accounts" for a multi-account device), expiration, revocation, multiple devices, and active-session listing. Session policies remain configurable per deployment.

---

# 11. Role-Based Access Control & Permission Resolution

Default roles include User, Admin, and Super Admin. Authorization workflow:

```text id="permissionflow"
Request
      ↓
Authentication
      ↓
Role Resolution
      ↓
Permission Evaluation
      ↓
Policy Validation
      ↓
Controller Action
```

Policies remain the authoritative authorization mechanism. Sensitive Admin capabilities are gated by named permissions rather than role membership alone - for example `manage_environment` (Section 21.4) and `manage_backups` are granted to the Super Admin role specifically, never the plain Admin role, since both control access to material that can include credentials.

---

# 12. Profile Management

User profiles support name, avatar, bio, contact information, date of birth, gender, timezone, and privacy/notification preferences. Profile updates validate ownership and input. A profile row is populated automatically at account creation (including splitting a single collected full name into first/last name fields) so Settings never shows a blank name field for a freshly-registered user; a scheduled backfill command handles population for any pre-existing profile still missing this, processing bounded batches per tick rather than one unchunked pass (Section 24), and visiting the Profile settings page itself also fixes that one account's data immediately as a safety net.

---

# 13. Contacts & Chat Technical Design

## 13.1 Module Responsibilities

Contacts & Chat provides user discovery, contact requests, contact management, private conversations, real-time messaging, message synchronization, read receipts, typing indicators, presence management, message search, and conversation management. It is the core module every other real-time feature builds on.

## 13.2 High-Level Architecture

```text id="chatarchitecture"
Client
      ↓
Routes
      ↓
Chat Controller
      ↓
Validation Layer
      ↓
Chat Service
      ↓
Conversation Repository / Message Repository
      ↓
Database
      ↓
Real-Time Delivery (polling + push, see Section 20)
      ↓
Connected Clients
```

## 13.3 Contact & Conversation Lifecycle

```text id="contactflow"
Search User
      ↓
Send Request
      ↓
Pending
      ↓
Accept / Reject
      ↓
Contact Created
      ↓
Chat Available
```

Leaving a conversation removes it from the leaving user's own list (`left_at` on membership); restarting a conversation with the same person clears that flag rather than creating a duplicate conversation. Blocked users cannot bypass contact restrictions.

## 13.4 Message Lifecycle & Status Ticks

```text id="messageflow"
Compose Message
      ↓
Client Validation (incl. client-side encryption, Section 14)
      ↓
API Request
      ↓
Server Validation
      ↓
Database Save
      ↓
Delivery Tracking
      ↓
Read Tracking
```

Per-recipient delivery state (`message_status`) advances `sent → delivered` at multiple independent trigger points - opening a specific thread, merely syncing the chat list, and a push arriving to a backgrounded or fully closed app (the service worker's own `push` handler calls a dedicated delivered-marking endpoint with no page open at all) - so "delivered" can genuinely precede "read." A message's tick state for the sender is the aggregate across every recipient: a group message shows "read" only once every recipient has read it. A sender-only detail view lists exact per-recipient delivered/read timestamps. The read tick is rendered in an amber/gold color, a deliberate choice to keep it visually distinct from both the blue commonly used elsewhere and the app's own primary brand color.

## 13.5 Presence, Typing, and Read-Receipt Privacy

Presence states are Online/Offline, shown as a relative "last seen X ago" rather than a bare "Offline" label wherever presence displays. A user's `last_seen_visibility` preference (everyone/contacts/nobody) is enforced identically across every surface that can leak it - the polled conversation API, the initial server-rendered page load, and the Contacts API - so no code path bypasses the privacy setting. Typing-started/typing-stopped events are transient and are not persisted as permanent records.

## 13.6 Conversation Features

Each conversation supports pin, archive, mute, per-user delete, search, unread counter, per-conversation wallpaper, per-conversation disappearing-messages timer, and full CSV+media export. Chat list and message-thread multi-select provide bulk archive/mute/delete and bulk star/forward/delete respectively, each implemented by looping the existing single-item endpoints from the client rather than introducing new bulk endpoints.

## 13.7 Message Types

Supported types: Text, Emoji, Image, Document, Audio, Voice Message, Video, System (call-log and reminder rows render as centered system-style messages inline in the conversation they belong to). The architecture remains extensible for additional types.

## 13.8 Database Relationships

Primary entities: Contacts, Contact Requests, Conversations, Conversation Members, Messages, Message Reads/Statuses, Message Reactions, Blocked Users, Conversation Keys (Section 14). Relationships comply with the DDS.

## 13.9 Security & Performance

The module enforces authentication, authorization, contact-relationship validation, ownership validation, block-list enforcement, rate limiting, and input validation. Message history is paginated; conversation queries are indexed; unread-count calculation is optimized to avoid duplicate work across the nav-badge poll and the chat list itself.

---

# 14. Message Encryption Architecture

## 14.1 Trust Model

Text message bodies are encrypted at rest and in the database, under a deliberately server-managed trust model rather than a zero-knowledge one: the application itself generates and holds every conversation's key material. There is no per-user keypair and no password-derived unlock step anywhere in the product - encryption is fully transparent to the user. This is a considered design choice, not an oversight: it keeps the product's honest privacy claim (Section 13.5's companion chat-privacy banner) aligned with what the system actually guarantees, without asking users to manage key material or risk permanent data loss from a forgotten passphrase.

## 14.2 Key Material and Epochs

Each conversation is assigned one random AES-256-GCM key per **epoch**, generated server-side (`random_bytes`) and stored encrypted at rest (`conversation_keys.key_material`, via the framework's own `Crypt` facade - the application's own key, not derived from anything a user knows or holds). A dedicated encryption service transparently issues a new epoch whenever one is needed - none exists yet, or conversation membership has changed since the last one - so a client never has to make a separate "issue a key" request; requesting the conversation's current status is enough to guarantee a usable key exists.

## 14.3 Access Control

Any current, authenticated member of a conversation can fetch a given epoch's raw key material through a dedicated endpoint. Access is checked **live, at fetch time** - current conversation membership - rather than being baked in once at the moment a key was issued. A membership change still rotates to a new epoch, so a removed member's cached key material can never decrypt anything sent after their removal.

## 14.4 Client Caching

A client-side crypto module fetches key material on a cache miss and persists it in IndexedDB, surviving reloads on both desktop and mobile browsers. A single entry point resolves a conversation's key for a given epoch version; every page that needs to encrypt or decrypt a message body goes through it, so there is exactly one client-side code path for key resolution.

## 14.5 Group Size Cap

A configurable group-size threshold (default 256 members) caps per-conversation encryption: a group beyond that size stays plaintext server-side with a visible "Not end-to-end encrypted (too many members)" indicator, rather than attempting per-recipient key-wrapping at broadcast-list scale - the same trade-off mainstream encrypted messengers make for very large groups.

## 14.6 Historical Migration

Once every current member of a previously-plaintext conversation has key material available, the next time any of them opens it, its plaintext history is migrated to ciphertext automatically, processed in bounded batches capped per call rather than as a single unbounded operation.

## 14.7 Server-Rendered Surfaces and Notifications

Any server-rendered page that displays message content - the starred-messages view is the one such page; chat itself is entirely client/API-driven - shows a placeholder for an encrypted message's body instead of raw ciphertext, the same treatment already applied to exported chat transcripts. Push notification previews for encrypted conversations fall back to a generic "New message" text, since the server has no plaintext to build a real preview from. Where the platform supports it (Chromium-based browsers with inline notification reply actions), a typed reply sent directly from a push notification is encrypted inside the service worker itself before it ever reaches the network, using the same key-resolution and AES-GCM scheme as an in-app send; browsers without that capability fall back to a plain "Reply" action that opens the conversation.

## 14.8 Reply, Forward, and Scope Boundaries

A quoted reply's preview is itself independently encrypted client-side with its own IV; forwarding a message to a different conversation requires the client to decrypt under the source conversation's key and re-encrypt under the destination's. View Once media (Section 16) is encrypted at rest using the server's own key via the `Crypt` facade rather than this per-conversation scheme, since its lifecycle (single-view consumption, expiration, cleanup) is managed independently of ordinary conversation history. Regular (non-View-Once) media attachments - images, videos, documents, voice messages - are not covered by this scheme; only text message bodies are encrypted. Client-side file encryption/decryption/streaming for attachments is a materially larger, distinct undertaking than text encryption and remains a disclosed scope boundary rather than a silent gap.

---

# 15. Offline-First Chat & Contacts Architecture

Chat and Contacts are designed to remain usable without an active network connection, a deliberate design choice for exactly these two surfaces - calls, meetings, authentication, and file uploads remain genuinely network-only by nature and are not part of this design.

* **Caching**: an IndexedDB-backed cache stores the conversation list, the last roughly 200 messages per conversation (already decrypted, for encrypted conversations), and the contacts list. The chat list, an open conversation, and the contacts list all fall back to rendering from this cache when a live fetch fails while offline.
* **Outbox queue**: composing a plain text message or reply while offline renders an optimistic "Waiting to send…" bubble immediately and queues the send instead of failing. A shared connectivity listener drains the queue once the browser reports it is back online; each conversation's own page registers the handler responsible for actually re-sending its own queued actions (re-running the same encryption-aware send path used online). A queued action for a conversation not currently open stays queued until that conversation is next opened while online - a disclosed, bounded scope decision.
* **Service worker shell caching**: the chat list, an individual conversation, contacts, and groups HTML shells are runtime-cached network-first, falling back to the last successful visit's cached response when offline - the same pattern used for the login shell. Calls and meetings are deliberately excluded from this caching, since a real-time session cannot be meaningfully served from a cache.

---

# 16. Media Sharing, One View & Custom Playback Architecture

## 16.1 Module Responsibilities

The Media module provides image, document, audio, voice-message, and video sharing, media preview, upload progress, configurable One View media, thumbnail generation, secure downloads, and storage management.

## 16.2 Upload Lifecycle

```text id="uploadlifecycle"
Select / Capture File
      ↓
Client Validation
      ↓
Upload Request
      ↓
Server Validation
      ↓
Temporary Storage
      ↓
Media Processing (thumbnailing, etc.)
      ↓
Permanent Storage
      ↓
Message Created
      ↓
Receiver Notification
```

## 16.3 Attachment Picker and In-App Camera

Attachment selection is a Camera/Gallery/Document menu rather than a single generic file input; Gallery and Document both support multi-select, and both images and videos get real thumbnail previews before sending. The in-app camera drives `getUserMedia`/`MediaRecorder` directly - a tap captures a photo, a press-and-hold captures video, front/back cameras can be flipped when more than one exists, and a review screen (retake/use) precedes handing the result to the normal attachment-preview flow. The platform deliberately never falls back to the operating system's native camera capture UI.

## 16.4 Voice Messages

Recording replaces the composer row with a dedicated bar showing a live waveform sampled from the actual microphone stream (Web Audio's `AnalyserNode`, not a canned animation), an elapsed-time counter, pause/resume, a discard action, and a one-tap send with no separate preview/confirm step - the only attachment type sent without a confirmation step.

## 16.5 One View Media

Supported policies: single view, configurable view count, time-based expiration, and administrative expiration. Once the configured viewing policy is satisfied, the media is no longer available through normal access. Both sender and recipient reflect the "Opened" state once consumed, updating live on an already-open conversation rather than requiring a refresh. A sender cannot re-preview their own unconsumed upload - attempting to do so raises a dedicated exception (Section 8) rather than rendering a clickable placeholder.

## 16.6 Custom Media Viewers

Every audio, video, and image attachment a message can contain uses the application's own playback UI rather than the browser's native `<video controls>`/`<audio controls>` or native image viewer. A shared player builder constructs a real play/pause button, seek bar, and time label around a controls-less `<audio>`/`<video>` element - the audio variant matches a compact voice-note bar with no volume control, the video variant adds mute and fullscreen. A lightbox opens on tapping any regular image bubble. The One View full-screen viewer uses the same custom players, kept visually and behaviorally consistent with the rest of the chat surface.

## 16.7 Storage Architecture

```text id="mediastorage"
avatars/
chat/
images/
documents/
audio/
video/
temporary/
one-view/
thumbnails/
```

Storage is abstracted through the framework's Filesystem layer with configurable drivers. Thumbnail generation happens asynchronously via a scheduled command (Section 24), never inline with the upload request.

## 16.8 Cleanup

A scheduled command deletes: a View Once file stuck "viewed" but never actually removed, past a short grace window (the primary deletion path is synchronous, inside the stream response that serves the file - this is a safety net for an interrupted stream, not the main mechanism); and any media row older than a day referenced by nothing at all (no message, no group media library entry, no whiteboard export) - covering both delete-for-everyone residue and an uploaded-but-never-sent attachment, since upload and message-send are two separate API calls and an abandoned upload is a real, reachable orphan. "Delete for everyone" itself removes the underlying file from disk once nothing else (e.g. a forward reusing the same underlying media row) still references it.

---

# 17. Voice & Video Calling Technical Design

## 17.1 Module Responsibilities

The Calling module provides one-to-one voice and video calls, secure signaling, device management, screen sharing, call history, connection recovery, and call notifications.

## 17.2 High-Level Architecture

```text id="callingarchitecture"
Client A
      │
      ▼
Signaling Service
      │
      ▼
Authentication
      │
      ▼
Call Service
      │
      ▼
Session Management
      │
      ▼
WebRTC Connection
      │
      ▼
Client B
```

The signaling service coordinates session establishment and state transitions; it never carries the media stream itself.

## 17.3 Call Lifecycle and States

Supported states: Idle, Calling, Ringing, Connecting, Connected, Reconnecting, Ended, Busy, Rejected, Failed, Missed. Both participants remain synchronized through every transition. A completed or missed call logs a system-style row directly into the conversation it originated from, using the same inline system-message pattern as meeting reminders.

## 17.4 Screen Sharing Without Renegotiation

The local video sender's transceiver is pre-provisioned at call setup time - present, but with no track attached, for a voice-only call - specifically so that starting or stopping a screen share never requires WebRTC SDP renegotiation partway through an already-connected call. A dedicated `screen-share` signal type, carried over the same signaling channel already used for offer/answer/reaction messages, tells the other party explicitly when a share starts or stops, since a video track simply appearing on the connection has no other reliable meaning (it could otherwise be mistaken for the caller turning their camera on). Starting a screen share during a voice call never turns the local camera on as a side effect.

## 17.5 Call UI and Device Management

Controls are an icon+label two-row grid (Speaker/Camera/Mute, then More/Share/End); a video call is full-screen with an unmasked, uncropped self-view. Supported device controls: camera selection, microphone selection, and - where the browser exposes `HTMLMediaElement.setSinkId()` - speaker/earpiece output routing. Call history rows open a detail popup (participants, direction, duration, timestamp) with a call-back action reusing the same call-start entry point the in-thread call buttons use.

## 17.6 Call History

Each completed call stores participants, call type, start/end time, duration, and final status, available only to authorized participants and administrators with appropriate permissions.

## 17.7 Security & Performance

The module enforces authentication, authorization, contact verification, secure signaling, rate limiting, TURN credential protection, and audit logging. STUN/TURN support includes public STUN servers, self-hosted TURN, credential management, and failover configuration, with TURN credentials never exposed unnecessarily.

---

# 18. Call-to-Meeting Escalation Bridge

The `calls` table is fundamentally caller/receiver pair-based, with no group data model of its own. Rather than duplicating the Meetings module's already-built N-way WebRTC mesh (lock, screen share, reactions, add-via-link) inside the pair-only Calls module, an "Add member" action during an active 1:1 call creates an instant Meeting, directly admits both original call parties - bypassing the lobby, since they are already mutually engaged in a live call and there is no genuine "waiting to be let in" moment to model for either of them - ends the original call, and redirects both parties into the full-featured meeting room. The originating call record keeps a reference to the resulting meeting; the party who did not tap "Add member" is redirected automatically once their own call-status poll observes the escalation. This bridge is the concrete application of the reuse-over-duplication principle in Section 3.

---

# 19. Meetings & Calendar Technical Design

## 19.1 Guest-Created and Guest-Joined Meetings

A visitor with no account can create an instant meeting directly from the login page and receive a shareable join link immediately, optionally password-protected, in addition to joining an existing meeting as a guest. `meetings.organizer_id` is nullable to support this; the creating guest becomes the meeting's `role=host` participant but, being unauthenticated, cannot use the authenticated-only host-control endpoints (mute/remove/lock/transfer). A guest-created meeting winds down through the same guest-session-expiry cleanup that already tears down other guest state, plus a general inactive-meeting sweep (Section 19.5) - it is never left "active" indefinitely just because it has no authenticated organizer to end it explicitly. Meeting passwords are hashed, never stored or compared in plaintext. Guests can also use the meeting whiteboard (Section 19.4) and, when their own role is host, lock/unlock the meeting and screen-share, through a parallel guest-scoped controller mirroring the authenticated one action-for-action.

## 19.2 Join Access Model

Scheduling a meeting makes an explicit "who can join" choice - direct join or host-approval waiting room - with an admin-configurable default. A meeting created via the instant-meeting path routes its organizer through the same lobby (device preview, permission prompt, explicit "Join meeting") as any other participant; the organizer is never auto-admitted, so their own first page load after creating a meeting is not silently indistinguishable from "rejoining mid-call."

## 19.3 Invitations and Calendar Sync

Meeting scheduling can invite both existing accounts and raw email addresses that do not match one (`meeting_invitations.invited_email`, nullable alongside `invited_user_id`); a single shared invitee-resolution routine is used by both the web scheduling form and the API scheduling endpoint so the two paths cannot drift. The generated ICS invite carries a real join URL, a per-recipient `ATTENDEE;RSVP=TRUE` line, and a `SEQUENCE`, so calendar clients render an actionable invite rather than a bare event. A join link (`/j/{code}`) is shown, with a copy action, everywhere a meeting appears - the host's own lobby/room view, the meetings list, and the calendar event-detail popup - not only inside the guest room. Rescheduling or resizing an event (drag-to-reschedule, drag-to-resize in the week/day calendar views) updates the calendar event and the underlying meeting atomically in one operation, so the two records can never drift apart. Meeting reminders are delivered for real, fifteen minutes ahead of the scheduled time: a push notification gated on the recipient's notification preference, plus - for a meeting-linked reminder specifically - a system message posted into the organizer/invitee's own 1:1 conversation, the same inline system-message convention used for call history (Section 17.3). A standalone personal reminder with no organizer/invitee pair receives only the push.

## 19.4 Whiteboard

Whiteboard creator/actor columns (`created_by`, `actor_id`, `exported_by`) are nullable and each paired with a sibling foreign key into the meeting-participant record, so exactly one of the pair is populated per row depending on whether the acting party is a registered user or a guest - the same participant abstraction already used to model "whoever is in this meeting" elsewhere. The whiteboard service resolves which column to populate from the participant's own guest/registered status. Drawing input is implemented with Pointer Events (not mouse-only handlers) with pointer capture and `touch-action: none` on the canvas, so it works identically on touch devices, in both the registered and guest rooms.

## 19.5 Participant Liveness and Auto-End

A `last_seen_at` heartbeat column, refreshed on every roster poll from a participant's own client, lets the live roster filter out anyone not heartbeated within a short staleness window (a configurable number of seconds, default 30) - the capacity-counting and end-of-meeting "mark everyone left" logic deliberately continue to use the raw participant record rather than this staleness filter, since filtering there would be incorrect. A `pagehide` handler in the room script best-effort sends a leave beacon on tab close or navigation away, so the common case of simply closing the tab is handled instantly rather than waiting out the staleness window. A scheduled command separately ends any active or locked meeting that has had nobody genuinely present, by the same staleness definition, for a full grace period (five minutes) - covering both a lobby nobody ever entered and a meeting everyone has since left, with the grace window sized to avoid killing a meeting that is about to be rejoined.

## 19.6 Calendar Rendering

The calendar renders Month, Week, and Day views; Week and Day render a real hour-by-hour grid. The month grid computes its row height from the actual remaining viewport space (accounting for fixed chrome and any empty-state note) rather than using a fixed per-cell minimum height, so a six-week month never forces the page itself to scroll - matching the reference calendar product's own behavior. Clicking any day cell with events, any event chip, or any timed block opens an event-detail popup (title, time range, description, delete/join actions) before navigating or joining, rather than acting immediately on the click.

---

# 20. Push Notification Architecture

Push delivery uses Firebase Cloud Messaging end to end. The client obtains an FCM device token via the Firebase Web SDK's `getToken()`, explicitly passed the application's own existing service worker registration rather than letting the SDK register a separate service worker of its own - a page can only have one active service worker, and the application's own already owns push and notification-click handling (including encrypted quick-reply, Section 14.7). The server builds and sends a **data-only** Cloud Message (no FCM notification payload), so the service worker's own `push` handler stays in full control of exactly what is shown to the user, matching how a raw Web Push payload would have been handled. The client-safe Firebase Web configuration is shipped in client JS like any Firebase web application; the service-account credentials used server-side are not. A stale-subscription case is explicitly handled: when a leftover subscription registered under a different applicationServerKey (e.g. from an earlier notification setup) is still present, it is detected and unsubscribed before a fresh token is requested, rather than silently failing indefinitely. Turning off every notification preference at once unsubscribes the underlying browser-level push subscription for real, not just the stored preference row; turning off a single notification type alone leaves the shared subscription in place, since one subscription serves every notification type indiscriminately. A dedicated device-management page lists and allows revocation of a user's own subscribed devices.

---

# 21. Administration & Platform Management Technical Design

## 21.1 Module Responsibilities

Administration provides the administrator dashboard, role and permission management, feature flag management, audit logging, system configuration (including the live `.env`, Section 21.4), monitoring, and backup management.

## 21.2 High-Level Architecture

```text id="adminarchitecture"
Administrator
        ↓
Admin Routes
        ↓
Admin Controllers
        ↓
Admin Services
        ↓
Repositories
        ↓
Database
        ↓
Monitoring Services
        ↓
System Components
```

Administrative functionality remains logically separated from user-facing application services.

## 21.3 Feature Flags and Audit Logging

Administrators can enable or disable major features (registration, contacts, chat, media sharing, voice/video calls, screen sharing, meetings, groups, communities) through a database-backed Settings table, with every state change recorded for auditing. Audit records cover authentication events, administrative actions, permission changes, configuration updates, feature flag changes, and security events, retained per configured operational policy.

## 21.4 Environment Variable Management

A dedicated, Super-Admin-only Environment tab offers two ways to edit the live `.env`: a field-by-field form (one input per real key, with keys that look sensitive - `*_KEY`/`*_SECRET`/`*_PASSWORD`/`*_TOKEN` - masked behind a Show/Hide toggle) and a raw full-file textarea. This is deliberately kept as its own, more sensitive surface rather than folded into the general key/value Settings table, matching the precedent already set for backup management. Every save requires the acting administrator to re-enter their own account password first - the same step-up re-authentication pattern already used for deactivating an account, disabling 2FA, or removing a passkey - and takes a dated backup of the current file before writing anything. `config:clear` runs automatically after a successful save so a config-cached production deployment picks up the change immediately. Keys present in the shipped configuration template but missing or blank in the live file are highlighted directly in the UI, using the same detection the deploy pipeline's own environment-sync step (Section 25) already performs.

## 21.5 Monitoring Dashboard

Operational monitoring displays active users, online users, scheduler status, storage capacity, error rates, API performance, and call activity, updated with minimal delay where practical.

## 21.6 Backup Management

The system supports database backups, file backups, configuration backups, backup verification, and restore operations, with scheduling remaining configurable.

## 21.7 Security & Performance

Administrative endpoints require elevated, permission-gated authorization (Section 11), strong authentication, audit logging, rate limiting, and configuration validation. Dashboard aggregation minimizes expensive queries and caches frequently requested operational summaries so administrative operations never degrade user-facing performance.

---

# 22. Cross-Module Integration & Event Architecture

## 22.1 Design Objectives

The integration architecture provides loose coupling, high cohesion, event-driven communication, reliable background processing, modular growth, transaction integrity, and fault isolation. Direct module dependencies are minimized wherever practical.

## 22.2 Event-Driven Communication

The platform dispatches domain events for significant, already-completed business actions - user registration, contact-request lifecycle, conversation/message creation, media upload, call and meeting lifecycle transitions, and administrative actions - never for raw UI interactions. Events are dispatched only after the underlying database transaction succeeds.

```text id="eventflow"
Business Action
        ↓
Service Layer
        ↓
Database Transaction
        ↓
Event Dispatch
        ↓
Listeners
        ↓
Scheduled/Inline Processing (Section 24)
        ↓
Notifications
```

## 22.3 Module Communication

Modules communicate through service interfaces, domain events, repositories, and API contracts, and avoid direct access to another module's internal implementation. Where two modules' responsibilities genuinely overlap (Section 18), the platform prefers an explicit bridging service over either duplicating logic or reaching across module boundaries.

## 22.4 Transaction Management

Database transactions protect operations requiring atomicity: contact acceptance, message creation with related metadata, permission updates, calendar-event/meeting synchronization, and administrative changes. Long-running work executes outside active transactions.

---

# 23. Database, Storage & Caching Technical Design

## 23.1 Data Architecture

```text id="dataarchitecture"
Application
        ↓
Service Layer
        ↓
Repository Layer
        ↓
Database
        ↓
Cache
        ↓
Storage
        ↓
Backup
```

## 23.2 Database Access Strategy

Database access occurs only through repositories, Eloquent models, and explicit transactions where required. Controllers never execute direct database queries. Repositories encapsulate query construction, pagination, filtering, and search; business logic stays out of the repository layer.

## 23.3 Database Optimization

The database uses primary keys, foreign keys, indexes and composite indexes where beneficial, query optimization, and soft deletes where appropriate (soft-deleted rows back both "delete for me/everyone" chat semantics and account deactivation).

## 23.4 Storage Architecture

Storage is abstracted through the framework's Filesystem layer with configurable drivers, organized into logical categories: avatars, chat media, documents, voice messages, video files, thumbnails, temporary uploads, and backups (Section 16.7 gives the concrete directory layout).

## 23.5 Cache Architecture

The platform caches user preferences, frequently accessed configuration, permission resolution, feature flags, and frequently requested metadata, with configurable expiration policies.

## 23.6 Session and Background-Job Storage

Session storage supports configurable drivers (database, Redis, or file storage) appropriate to the deployment environment. Background-job storage and processing are covered in full in Section 24 - the platform's actual design does not route routine background work through a persistent-worker queue backend at all.

## 23.7 Backup and Restore

Backups cover the database, uploaded files, and configuration/environment metadata, with restore procedures supporting database restoration, file restoration, configuration restoration, and post-recovery verification. Backup scheduling remains configurable by administrators.

---

# 24. Background Processing & Scheduled Jobs

The deployment environment this platform runs on provides no persistent, always-running queue worker process - only cron-invoked, time-boxed PHP execution. The technical design accounts for this directly rather than assuming a queue daemon is always available:

* **The scheduler is the backbone of background processing.** A single cron entry invokes the framework's task scheduler once a minute; every recurring background responsibility is implemented as a scheduled Artisan command triggered by that tick, not as a `ShouldQueue` job waiting on a worker that may not be running.
* **Scheduled commands are bounded and idempotent.** Each command is written to process a bounded amount of work per tick and to be safe to run again if a previous tick's run was interrupted. A profile-name backfill for pre-existing accounts processes a fixed-size batch (on the order of one thousand rows) per tick and disables itself via a settings flag once nothing remains - deliberately not a single unchunked pass, since an unchunked operation against a large table can exhaust available memory or run long enough to overlap with the next tick.
* **Representative scheduled commands**: ending inactive meetings (every five minutes, Section 19.5), deleting orphaned media (every fifteen minutes, Section 16.8), expiring disappearing messages (every five minutes), marking missed calls, and expired guest-session/guest-meeting cleanup.
* **Time-sensitive delivery runs inline, not queued.** Push notifications (Section 20) are sent synchronously, inline, from the request that triggers them (a new message, an incoming call, a contact request) rather than deferred to a queue - inline dispatch is the design's actual delivery guarantee, since nothing is otherwise guaranteed to drain a queue promptly on this hosting platform.
* **Deploy-time one-off actions are restricted to cheap, near-instant operations only** (e.g. flipping a settings flag); anything whose cost scales with table size is implemented as a scheduled command with bounded batches instead, following the same principle as the profile-backfill example above.

---

# 25. Deployment & Release Architecture

The hosting platform this application targets has `exec()`/`shell_exec()` disabled and no shell access, so every deploy-side script is pure PHP - no shelling out to `git`, `composer`, or `npm`. This one constraint shapes the entire release design:

* **Built artifacts are committed, not generated on the server.** `vendor/` and the compiled frontend build output are committed to the release branch, since the server itself can never run a build step - they arrive as plain files inside the release archive.
* **Fresh install** is a single browser-driven script that downloads the application, writes the initial `.env`, runs migrations and seeders, and creates the initial Super Admin account.
* **Nightly updates** are pulled by a scheduled script that checks for a new release, downloads it into a sibling folder, runs migrations/cache rebuilds/any version-specific one-off actions against that new copy, and only then atomically swaps it into place with two `rename()` calls. If any step fails first, the live folder is never touched and the sibling copy is discarded; the previous release is retained as a single rollback snapshot after every successful swap. `.env`, persistent uploads, and logs are always carried forward from the live folder into the new one before the swap.
* **New configuration keys reach production automatically.** On every update, any key present in the new release's shipped environment template but missing from the live `.env` is added, blank, without ever touching a key that already has a real value - so an already-configured deployment is never disturbed, and a feature reading a newly-added-but-still-blank key simply no-ops until an operator supplies a real value, the same convention a fresh install already follows for anything left unconfigured.
* **Encryption-key generation never runs on an existing deployment.** It runs only during fresh install; the update path never regenerates it, since doing so against a live site would invalidate every session and any already-encrypted database value.

---

# 26. Testing, Quality Assurance & Deployment Technical Design

## 26.1 Testing Architecture

```text id="testingarchitecture"
Developer
      ↓
Local Testing
      ↓
Pull Request
      ↓
Continuous Integration
      ↓
Automated Tests
      ↓
Quality Validation
      ↓
Staging
      ↓
User Acceptance Testing
      ↓
Production
```

## 26.2 Automated and Manual Testing

Automated testing covers unit, feature, integration, API, authorization, and validation tests, executed during continuous integration. Manual validation covers UI, responsive design, browser compatibility, device compatibility, and accessibility. A category of behavior in this platform is specifically verified through real browser automation rather than unit-level mocking alone, because the underlying bugs only reproduce under a real engine: on-screen-keyboard visual-viewport tracking, iOS-specific overscroll/zoom behavior, touch/pointer-event handling on the whiteboard, and real Firebase Cloud Messaging token issuance and delivery against live infrastructure rather than a mocked SDK.

## 26.3 Continuous Integration & Deployment

The CI pipeline executes dependency installation, style/static analysis, automated tests, and build verification; failures block merges into protected branches. Deployment follows Section 25's release architecture, promoted through development, staging, and production.

## 26.4 Release Validation and Rollback

Every release verifies database migrations, asset compilation, scheduler configuration, environment variables, and health checks. Rollback is supported at the application, database, configuration, and asset level, backed concretely by the one-rollback-snapshot mechanism in Section 25.

## 26.5 Monitoring and Incident Response

Monitoring covers application availability, scheduler status, error rates, API performance, storage capacity, and call/meeting service health. Incident response covers detection, logging, notification, investigation, recovery, and post-incident review, with records retained for operational analysis.

---

# 27. Security Architecture Summary

Security is enforced consistently across every module described above rather than as a separate bolt-on layer:

* Authentication and session integrity (Section 10), including second-factor and passkey/SSO design and their explicit interaction rules.
* Authorization via policies and named permissions, never role membership alone for sensitive actions (Section 11, Section 21.4).
* Server-managed message encryption with live, membership-checked key access (Section 14).
* Ownership validation and block-list enforcement on every contact/conversation/media operation (Section 13, Section 16).
* Step-up re-authentication (password re-entry) before any particularly consequential self-service or administrative action - deactivating an account, disabling 2FA, removing a passkey, or writing to the live environment configuration.
* Rate limiting and secure cookie configuration across authentication and messaging endpoints.
* Audit logging of administrative and privileged actions.
* TURN credential protection and secure signaling for real-time media (Section 17).
* Backup-before-write and config-cache invalidation around any change to the live environment configuration (Section 21.4, Section 25).

---

# 28. Performance Considerations

Across modules, performance work follows a consistent set of practices: paginated history and indexed queries for chat, contacts, and media; caching of frequently accessed configuration and permission data; asynchronous thumbnail/media processing off the request path; bounded-batch scheduled commands rather than unbounded background operations (Section 24); pre-provisioned WebRTC transceivers to avoid renegotiation cost during a live call (Section 17.4); and dashboard/administrative aggregation designed to never compete with user-facing request latency.

---

# 29. Final Technical Design Matrix

| Area | Primary Objective |
| --- | --- |
| Authentication & Sessions | Secure identity management across password, TOTP, passkey, SSO, and multi-account login |
| Contacts & Chat | Reliable relationships and real-time messaging |
| Message Encryption | Server-managed, membership-scoped confidentiality for message content |
| Offline-First Chat | Usable chat/contacts without an active connection |
| Media & One View | Secure media management with custom playback |
| Voice & Video Calling | Low-latency, renegotiation-free real-time communication |
| Call-to-Meeting Escalation | Reuse of the meeting engine instead of a second group-calling stack |
| Meetings & Calendar | Guest-accessible, synchronized scheduling and live sessions |
| Push Notifications | Reliable delivery via Firebase Cloud Messaging, even to a closed app |
| Administration | Operational management, including safe live environment editing |
| Background Processing | Cron-scheduled, bounded work with no persistent queue worker |
| Deployment | Stable, atomic, artifact-based releases on a constrained host |
| Database & Storage | Reliable, well-organized persistence |
| Testing & QA | Quality assurance including real-device/real-infrastructure verification |

Each technical area follows the engineering standards defined throughout this document.

---

# 30. Future Extensibility

The technical architecture is designed to accommodate, without requiring a foundational rewrite:

* Client-side encryption of media attachments, extending the current text-only encryption scope.
* An API/token-based equivalent for passkey, SSO, and remembered-device login, currently web-session-only.
* Distributed caching and a queue-worker-backed processing model, should the hosting platform ever change to support one.
* Cloud object storage as an alternative to local filesystem storage.
* Additional identity providers beyond Google SSO.
* AI-assisted meeting and messaging features.

Future capabilities are expected to integrate with the layered architecture (Section 4) and event-driven integration model (Section 22) without requiring redesign of those foundations.

---

# 31. TDS Summary

This Technical Design Specification defines the complete internal engineering design of the Meet platform: layered architecture and module standards; authentication design spanning password, TOTP, passkeys, SSO, and multi-account login; the contacts/chat/messaging engine and its server-managed encryption model; offline-first behavior; media handling and custom playback; the WebRTC calling design including renegotiation-free screen sharing and the call-to-meeting escalation bridge; meeting and calendar synchronization including guest access and participant liveness; push notification delivery; administration and safe live environment management; cross-module integration; database, storage, and caching design; the cron-scheduled background-processing model dictated by the hosting platform's constraints; deployment and release architecture; and the testing, security, and performance practices applied consistently across all of it.
