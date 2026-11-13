**Version:** Tracks `1.1.0` through `1.2.3`
**Platform:** Web (PWA)
**Framework:** Laravel 12 / Blade / Tailwind CSS v4 / vanilla JS
**Audience:** Engineering - living record of real behavior changes since the original SRS/BRD/UDS/RAS/DDS suite was written

---

# 1. Purpose

The original documentation suite (`SRS/`, `UDS/`, `RAS/`, `DDS/`, etc.) is frozen as the v1.0.0 baseline and is not edited in place as the app evolves - see `Documentation/Meet/00_MASTER_PROMPT.md`. This file is where real, shipped behavior changes since that baseline are recorded going forward, one `IC-` (Implementation Change) entry per change, each naming which legacy chapter it supersedes or extends. It does not restate anything unchanged from the original suite.

Privileged/admin-only mechanics (Super Access and related features) are documented separately in `Documentation/SUPER.md`, not here.

---

# IC-001 - Chat Is the Default Landing Page

**Supersedes:** UDS/5 (Dashboard as default view), SRS/16 (post-login navigation)

Login, registration (post-verification), and `/` now redirect to the Chat list (`chat.index`) instead of the Dashboard. The Dashboard page itself is unchanged and still reachable via the sidebar (desktop) or the profile page (mobile) - it is simply no longer the default.

---

# IC-002 - Navigation: Icon Sidebar (Desktop) + Bottom Bar (Mobile), Chat+Groups Merged

**Supersedes:** UDS/5 (Top Navigation Bar, Sidebar Navigation, Mobile Bottom Nav), SRS/16 FR-263 (Mobile Navigation)

The header-based navigation (a desktop link row plus a separate mobile tab row) is removed entirely.

- **Desktop** (`lg:` and up): a ~72px icon-only sidebar rail, WhatsApp-Web style, with hover tooltips. Four items - Chat (merged with Groups, see below), Contacts, Calls, Meetings - plus Dashboard and Admin (conditional on `auth()->user()->admin`) in a separate group at the bottom.
- **Mobile**: a fixed bottom bar with the same four merged items. Dashboard/Admin are reached via a quick-link card on the user's own profile page, not a persistent link.
- **Chat + Groups share one nav item.** The chat list page (`chat.index`) has All/Chats/Groups tabs - a client-side filter over the already-loaded conversation rows (the list already interleaves 1:1 and group conversations for "All"), not a separate data source per tab. The standalone `/groups` pages are unchanged, still reachable as a secondary deep link.

**Acceptance criteria:** exactly 4 primary nav items render on both surfaces; visiting any `groups.*` or `chat.*` route highlights the same merged nav item as active.

---

# IC-003 - Incoming Contact Requests Shown Inline on the Chat List

**Extends:** SRS Contacts chapter, RAS contact-request endpoints (unchanged)

Pending incoming contact requests now render as a dismissible-but-persistent banner directly on the chat list page, with inline Accept/Decline, in addition to the existing standalone `/contacts/requests` page (kept as a secondary deep link). A compact icon-badge variant of the same indicator also sits in the chat list's header (see IC-012).

---

# IC-004 - One View Display: Badge + Symmetric "Opened" State

**Extends:** SRS One View Engine chapter, UDS media/One View screens

Before opening, a one-view photo/video shows a small placeholder with a circular "1" badge (matching the reference WhatsApp convention) instead of plain "Tap to view once" text. After it's been opened, **both** the sender and the receiver see "Opened" - previously only the receiver's client reflected the consumed state; the sender kept showing stale "tap to view" text even after the underlying file was gone.

---

# IC-005 - Real Pagination: Admin Users, Chat List, Contacts List

**Extends:** RAS pagination envelope (already documented generically; these three endpoints now actually use it)

- **Admin → Users**: page-by-page controls (prev/next, "Page X of Y (N total)") - the backend already paginated; only the frontend was missing.
- **Chat list, Contacts list**: converted from an unpaginated `Collection` to real `paginate()`, with infinite-scroll-on-reaching-bottom on the frontend (scrolling the app shell's `#app-main`, which is what actually scrolls - not the browser window).

---

# IC-006 - Guest-Created Meetings (No Login)

**Extends:** SRS FR-316/FR-321 (Guest Mode - previously join-only)

A visitor with no account can now create an instant meeting from the login page ("Start a meeting"), not just join one via an existing link. They get a shareable `/j/{code}` link back immediately, shown in the meeting room itself, optionally password-protected. The creating guest becomes the meeting's `role=host` participant but - being unauthenticated - cannot use the authenticated-only host-control endpoints (mute/remove/lock/transfer); the meeting instead winds down naturally via guest-session expiry (see IC-018). `meetings.organizer_id` is now nullable to support this.

**Business rule:** admin-configurable via `settings.meetings.guest_creation_enabled` (default on).

---

# IC-007 - ICS Invite Fixes + Ad-Hoc Email Invitees

**Fixes/Extends:** SRS FR-167 (ICS generation), FR-123/168/169 (Meeting Invitations)

- Fixed a real bug: the ICS's embedded join URL, and the `CalendarController::ics()` on-demand download, both pointed at a route that never existed (`/meetings/{id}/join` as a browsable GET page) - every "Join meeting" click from an invite email 404'd. Now points at the real authenticated (`meetings.show`) or guest (`meetings.guest-landing`) route as appropriate.
- Added per-recipient `ATTENDEE;RSVP=TRUE:mailto:...` and a `SEQUENCE` line to the generated ICS, so calendar clients render a real actionable invite (Accept/Decline) instead of a bare event.
- Scheduling a meeting can now invite raw email addresses that don't match an existing account (`meeting_invitations.invited_email`, nullable alongside `invited_user_id`) - they receive a real invitation + ICS email with the guest join link, instead of being silently dropped.
- Meeting passwords are now hashed (`Hash::make`/`Hash::check`) instead of stored/compared in plaintext.

---

# IC-008 - Meeting Access Model Clarified: Direct Join vs. Waiting Room

**Extends:** SRS FR-125/FR-127 (Join Meeting, Waiting Room)

The schedule-meeting form's unlabeled `waiting_room_enabled` checkbox is now an explicit "Who can join" choice (direct vs. host-approval), with an admin-configurable default (`settings.meetings.default_waiting_room_enabled`). Guests can now submit a password on the guest-landing join form - previously the field didn't exist there at all, making a password-protected meeting effectively unjoinable via a guest link.

---

# IC-009 - Calendar Month-Grid View

**Extends:** SRS Calendar chapter (previously flat lists only)

Meetings → Calendar renders a real month grid (prev/next/today navigation) with each scheduled meeting placed on its date, consuming the existing `CalendarController::index` upcoming-events feed. Forward-looking only (that feed is deliberately upcoming-events-only by design), not a historical/past-month browser.

---

# IC-010 - Mobile Chat Header Layout Fix

**Bug fix, no spec impact.**

The 1:1 chat header's avatar was the one avatar in the app missing `shrink-0`, and Mute/Archive rendered as full-width text-pill buttons - together they could squeeze/overlap the contact's name on narrow viewports. Both header actions are now consolidated into a kebab ("⋯") menu (see IC-014), which incidentally also resolved the width problem; verified via real bounding-rect measurements at 390px width.

---

# IC-011 - Logout Icon, Remember-Me Default Checked

Logout is now an icon-only button, not a text link. The login form's "Remember me" checkbox defaults to checked; a newly-registered account's post-verification auto-login is now always a remembered session (previously always unremembered, since that flow has no checkbox of its own to read).

---

# IC-012 - Nav-Wide Notification Badges

**New capability.**

Red-dot badges on the Chat and Contacts nav icons (desktop sidebar and mobile bottom bar alike), polled from the shared app layout so they update on every page, not just while viewing those sections. Backed by a new aggregate `GET /api/v1/conversations/unread-count` endpoint (summed across every conversation) and the existing contact-requests endpoint. Real push notifications (OS-level, Service Worker Push API) remain out of scope - see `BACKLOG.md`.

---

# IC-013 - Honest Chat-Privacy Banner

**Clarifies:** Project Vision Document's explicit v1 non-goal ("no end-to-end encryption")

Every conversation now shows a short banner at the top of the message list. It deliberately does **not** claim encryption - this app has no E2E encryption and the server can read message content - wording instead notes that messages are private to the conversation's participants.

---

# IC-014 - Starred Messages View, Per-Conversation Wallpaper, Header Kebab Menu

**Extends:** existing star action/API (already shipped, no dedicated view existed); existing but previously-unused `chat_settings.wallpaper_id`/`chat_wallpapers` schema

- Chat → Starred: a real page listing everything starred, with inline unstar.
- Per-conversation wallpaper: 6 default colors seeded via data migration, picker added to the chat header's new "⋯" (kebab) menu, which also now holds Mute/Archive (previously separate header icon buttons).
- Status/Stories and Channels explicitly excluded per direct product decision. See `BACKLOG.md` for what else remains unbuilt (disappearing messages, broadcast lists, Communities, chat export).

---

# IC-015 - Native iOS PWA Install Instructions

**Extends:** SRS PWA chapter (installability)

Safari/WebKit has no `beforeinstallprompt` API at all. The install button now detects iOS specifically and shows a step-by-step "Add to Home Screen" modal instead of silently doing nothing there.

---

# IC-016 - Last-Seen Privacy Leak Fixed in Chat API

**Bug fix**, `last_seen_visibility` (SRS Privacy settings) was already enforced correctly in the Contacts API but not in the Chat conversation API at all - a user who set their last-seen to "nobody"/"contacts" still leaked live presence to anyone chatting with them, both via the polled API and (for the first several seconds of every page load) via an unguarded initial Blade render. Both are now gated.

---

# IC-017 - Admin Users: Real vs. Load-Test Account Segmentation

**Extends:** SRS/DDS Admin User Management

Admin → Users now has All/Real/Load-test tabs (`account_type` param on the existing list endpoint), identifying load-test-seeded accounts by their `@mail.meet` email marker (see `LOAD_TESTING.md`) - the same marker `load-test:cleanup-users` already matched on. A small badge also marks load-test rows in the "All users" view.

---

# IC-018 - Guest-Created Meetings Auto-End (DB Hygiene)

**Extends:** SRS FR-327 (guest session expiry cleanup)

A guest-created meeting (IC-006) has no authenticated organizer, so it could never reach `status=ended` via the normal host-only `end()` action - it would have sat at `active` in the `meetings` table indefinitely once its guest sessions expired. The existing expired-guest-session cleanup job now also ends a guest-created meeting once none of its guest sessions are active anymore; it never touches a meeting with a real `organizer_id`.

---

# IC-019 - Settings Page First/Last Name Prefill Fix

**Bug fix, no spec impact.**

Registration only ever collects a single `full_name` field, but Settings' Profile section reads separate `first_name`/`last_name` fields - every real user's Settings page showed those boxes blank right after signing up, even though they'd already given the app their name. Registration now splits `full_name` into first/last name automatically when the default `UserProfile` row is created.

---

# IC-020 - Settings Page Expansion: Profile/Preferences/Notifications/Chat Defaults/Account Security

**Extends:** SRS Settings chapter (fields the API already accepted but the page never rendered)

- **Profile:** display name, date of birth, and gender fields now rendered.
- **Preferences:** timezone picker added; the single `auto_download_media` toggle split into three (photos/videos/documents).
- **New Notifications section:** per-type toggles (`notify_messages`/`notify_calls`/`notify_meetings`/`notify_contact_requests`) - these now actually gate the nav badges (IC-012) and the incoming-call ringtone, not just stored preferences.
- **New Chat defaults section:** default wallpaper + font size, applied when a **new** conversation's `chat_settings` row is first created; existing conversations keep whatever they already have.
- **New Account & Security section:** a real web password-change form (previously API-only, no web route existed at all) and a self-service **Deactivate account** action (re-confirms the current password, signs out everywhere, soft-deletes the account the same restorable way an admin's own "Delete" action already does).

---

# IC-021 - Calendar Redesign Pass 2: Merged Meetings/History/Calendar Tabs, Visual Rework

**Supersedes:** IC-009 (month-grid view, first pass)

Meetings/History/Calendar unified onto one page with client-side All/History/Calendar-style tabs (same pattern IC-002 already established for Chat/Groups). The month grid itself was reworked visually closer to Google Calendar - thinner grid lines, colored event chips with a time label instead of just a title, and an overflowing day's "+N more" opening a popover listing every event that day instead of just truncating the text.

---

# IC-022 - Disappearing Messages + Cross-Page Message Notifications

**New capability.**

A per-conversation disappearing-messages setting (Off / 24 hours / 7 days / 90 days), set from the chat header's "⋯" kebab menu (IC-014). New `disappearing_messages_seconds` column on `conversations`; `expires_at` computed on `messages` at send time from the conversation's current setting. A new non-`ShouldQueue` cleanup job (matching this app's no-queue-worker convention - see project memory) runs every 5 minutes, soft-deleting expired messages for everyone the same way "Delete for everyone" already does.

Alongside this: a new message now triggers a real notification (sound + browser `Notification`) even when you're not on that chat page, as long as the tab is open - gated on the Notifications settings (IC-020) and suppressed while already viewing that exact conversation live.

---

# IC-023 - Calendar Redesign Pass 3: Dedicated Nav Icon, Week/Day Views, Drag-to-Reschedule

**Supersedes:** IC-021.

Calendar promoted to its own dedicated nav icon, no longer a tab on the Meetings page. Real Month/Week/Day view switching (Week and Day render an hour-by-hour grid, not just a list); browsing now works for past dates too, not just "today onward"; dragging an event to a new day (Month) or day/time (Week/Day) reschedules the underlying meeting (`CalendarService::reschedule()`, updating both `calendar_events` and `meetings` atomically - nothing kept those two tables in sync before this).

**Bug fix found and fixed in the same pass:** scheduling a meeting from the web form silently shifted the actual start/end time by the organizer's UTC offset - the `datetime-local` field submits a naive wall-clock string with no UTC offset, and it was being parsed as if that string were already UTC instead of the organizer's local time. Found via the Calendar day view (narrow enough to expose the drift; Month/Week were wide enough to mask it).

---

# IC-024 - Broadcast Lists

**New capability.**

Chat → Broadcast lists: send one message to a named list of contacts at once. It goes out as separate 1:1 messages - each recipient sees it as a normal DM in their own chat, and replies stay private between the two of you rather than going to the whole list.

---

# IC-025 - Communities

**New capability.**

Groups → Communities: a group-of-groups container above regular Groups. Creating one auto-creates a default "Announcements" group; the owner can then attach any other group they own (a group belongs to at most one community at a time) and post an announcement every member of every attached group can see.

---

# IC-026 - Chat Export

**New capability.**

A chat/group header's "⋯" menu now has an "Export chat" item that downloads a zip containing a full CSV transcript (sender, timestamp, message) plus every attached photo/video/document still on the server - the same no-new-dependency `fputcsv`/`ZipArchive` pattern the existing group-export/archive features already use. Already-consumed One View media is skipped, and a message the exporting user deleted "for me" is left out of their own export.

---

# IC-027 - Real Web Push Notifications

**New capability.**

A new message, an incoming call, or a contact request now reaches a subscribed device as a genuine OS-level notification even with every tab and the app itself fully closed - not just the foreground-only in-tab notification from IC-022. Uses `minishlink/web-push` + a real VAPID keypair (operator-configured via `.env`; silently does nothing, not an error, when unset). Sent synchronously inline (no queue worker exists on this host - see project memory) from three trigger points, each gated on the matching Notifications toggle (IC-020).

---

# IC-028 - Two-Factor Authentication (TOTP)

**New capability.**

Settings → Account & Security: enable via QR code (`pragmarx/google2fa-qrcode`) and confirm with one real code before it's active - never turned on from just generating a secret. 8 one-time recovery codes are shown exactly once. Once enabled, logging in (web or API) stops at a new "enter your code" screen after the password checks out, before a session/token is actually issued.

---

# IC-029 - Passkeys (WebAuthn)

**New capability.**

Settings → Account & Security, and a "Sign in with a passkey" button on the login page, via the official `laravel/passkeys` package. A passkey login deliberately skips the separate 2FA challenge (IC-028) - a passkey ceremony is itself possession + biometric/PIN verification, already a second factor by construction, matching how major providers treat it. Web login only for this pass (no Sanctum/API equivalent yet).

---

# IC-030 - SSO with Google

**New capability.**

"Continue with Google" on the login page (`laravel/socialite`) signs in or registers in one step. Matches an existing account by email automatically the first time, or creates a new, already-verified one. Unlike a passkey (IC-029), this does **not** skip 2FA - a linked Google account is a separate identity from this app's own account, so 2FA still protects it however the sign-in happened. `users.phone` and `users.password` were both made nullable to support these password-less, phone-less accounts (existing registration's own request validation still requires both as before; only the DB-level constraint relaxed).

---

# IC-031 - Mobile Bottom Nav Bar Overlap Fix

**Bug fix, no spec impact.**

The fixed mobile bottom nav bar (IC-002) could land on top of a button/field near the bottom of a page on real small-phone-height viewports, once the page was scrolled all the way down - confirmed via a real repro (a `scrollIntoView({block:'end'})` call landing an element fully behind the bar) before and after the fix. Fixed with `scroll-margin-bottom` on focusable elements below the nav bar's own breakpoint, plus extra bottom padding on `<main>`.

---

# IC-032 - Real App Icon/Favicon

**Extends:** SRS PWA chapter (installability assets, previously placeholders)

The placeholder icon set - and an empty `favicon.ico` - replaced with the real Meet Meet logo across every PWA manifest icon size, the apple-touch-icon, and a proper maskable variant (icon scaled into the safe zone on a white background, so OS-level circle/squircle cropping never clips it). The service worker's cache version was bumped so already-installed PWA users actually pick up the new icon instead of an indefinitely stale cached one.

---

# IC-033 - Calendar: Drag-to-Resize Duration, Standalone Personal/Reminder/Task/Holiday Entries

**Supersedes:** IC-023.

Week/Day event blocks gained a bottom-edge resize handle (reuses the same reschedule endpoint IC-023 added, just holding `start_at` fixed while `end_at` changes). A new "+ Add event" button creates `reminder`/`personal`/`holiday`/`task` calendar entries with no linked meeting - `event_type` values that already existed in the schema but that nothing anywhere created before this. Entries are color-coded by type. Also fixed in this pass: the Meetings and Calendar nav icons both lit up together on either page, since both route names share a `meetings.` prefix and each one's generic same-prefix active-nav fallback matched the other's route too.

---

# IC-034 - Communities: Remove Group, Delete Community, Admin Delegation

**Supersedes:** IC-025.

A community owner - or an admin of the community's own Announcements group, delegated the same way any other Group admin already is (IC-025's group role/permission system, reused rather than duplicated) - can now attach groups and post announcements, not just the owner. Any attached group (except the Announcements group itself, which can't be detached) can be removed by the community owner/admin or the group's own current owner. "Delete community" is a soft delete, owner-only.

---

# IC-035 - Push Notification Device Management

**Supersedes:** IC-027.

Settings → Notifications → "Manage push notification devices" lists and lets you revoke your own subscribed devices - previously there was no way to see or revoke one short of a direct DB edit. Turning off every notification toggle (IC-020) at once now also unsubscribes every device for real: both the DB row and, via a real `PushManager.unsubscribe()` call, the actual browser-level subscription. Turning off a single toggle alone still leaves the shared subscription in place, since one subscription serves every notification type indiscriminately.

---

# IC-036 - 2FA: Remember This Device for 30 Days

**Supersedes:** IC-028.

Checking "Remember this device" on the code-entry screen means that same browser skips the 2FA challenge for 30 days, wiring up the previously-unused `trusted_devices` table (added an `expires_at` column, since the original schema had no queryable deadline). Only a hash of the device's own random token is ever stored - the raw token lives solely in the browser's cookie, matching how this app already treats other bearer-style secrets. Settings → Account & Security → "Manage devices that skip the code" lists and lets you forget any of them early. Web login only, same reasoning as Passkeys (IC-029)/SSO (IC-030) - a bearer-token API client has no cookie jar the same way.

---

# IC-037 - Multi-Account Login and Switching

**New capability.**
A user can now log into a second account without disturbing the first one's session (Settings → Account & Security → Switch account, or the login page's own "Continue as..." tiles for an account not currently active), and jump between held accounts on the same device without re-entering a password. Built entirely on the existing "Remember me" mechanism (`users.remember_token` + `Auth::loginUsingId()`), not a new session/token layer - a held account is never more trustworthy than its own remember-me login already was, and revoking that token elsewhere (a real password change, an admin force-logout) invalidates it here too, automatically. Logging out of an account explicitly forgets it from the switcher, since Laravel's own logout already rotates that account's remember_token. Web login only, same reasoning as Passkeys (IC-029)/SSO (IC-030)/2FA remember-device (IC-036).

---

# IC-038 - Admin-Triggered Password Reset with Forced Change

**New capability.**
An admin can set a user's password directly from Admin → Users, optionally marking it temporary. A temporary reset emails the new password to the user and forces them to a dedicated change-password page before they can reach anywhere else in the app on their next login (a new `users.must_change_password` column, checked by a global middleware). The target's other sessions are force-logged-out the same way any other admin account-access action already does.

---

# IC-039 - Call Log Entries Shown Inline in Chat

**New capability.**
A completed or missed 1:1 call now logs its own centered, system-style row into the chat it was started from - "Voice call · 12:34" for one that connected, "Missed voice/video call" for one that didn't (rejected, cancelled before pickup, or timed out) - the same way WhatsApp shows call history inline in the conversation itself. This activated a previously dead code path: `calls.conversation_id` has existed in the schema since the calls table was created, but the frontend never actually sent it, so no call was ever linked to the chat it came from until now.

---

# IC-040 - Settings Restructured into a WhatsApp-Style Hub

**Supersedes:** IC-020.
Settings was one long page stacking Profile, Preferences, Notifications, Chat defaults, Privacy, and Account & Security together. Split into a hub listing each category, with each one now its own page - matching how WhatsApp's own Settings screen works. Every existing action route is unchanged; only which page renders each form moved.

---

# IC-041 - "Last Seen X Ago" Instead of the Bare Word "Offline"

**Extends:** SRS presence/last-seen chapter.
An offline contact or chat previously showed nothing beyond the literal word "Offline" - `last_seen_at` was already tracked (and even already returned by one of the two resources that needed it) but never rendered. Now shown as a relative "last seen X ago" everywhere presence displays. The existing per-user `last_seen_visibility` control (everyone/contacts/nobody) is untouched - this is a display fix, not a permissions change.

---

# IC-042 - Safe PWA Force-Update for Stale Installed Clients

**Extends:** SRS PWA chapter (FR-258's "updates shall not interrupt active meetings or calls").
An installed PWA that's rarely fully closed could sit on an old service worker indefinitely, since the browser only activates a newly fetched one once every tab/window for that origin closes - which could mean a device stuck on old app code (including missing a more recently added feature like Web Push) with no way to force an update. Now checks on every page load: if an update is waiting, it activates immediately unless a call or meeting is actively in progress on that page, in which case it retries every 30s until safe - never calling `self.skipWaiting()` unconditionally, preserving the original guarantee.

---

# IC-043 - Icon-First UI Pass

**Extends:** across several chapters (Settings, Meetings room UI, Chat, Contacts).
Applied a consistent icon set to the Settings hub, the meeting room's in-call toolbar (Raise hand/Share screen/Whiteboard/Lock/Mute/Camera/End/Leave - icon-only below the `sm` breakpoint, with mic/camera swapping their icon between on/off states), the Chat list header (Starred/Archived/Broadcast lists/Start a new chat), and Contacts' favorite/remove actions. Not exhaustive - see `BACKLOG.md` for what's deliberately deferred to a follow-up pass.

---

# IC-044 - Fix: Meeting Room Chats Leaking into Chat List/Dashboard as "Unknown"

**Bug fix, no spec impact.**
Every meeting creates its own room-chat conversation with only the organizer as a member until someone else joins. When nobody ever did, that single-member, zero-message conversation was indistinguishable from a real 1:1 chat to the query backing both the Chat list and the Dashboard's recent-conversations widget, showing up as "Unknown" / "?" / "No messages yet". Excluded `type = 'meeting'` conversations from that query, matching an exclusion the Admin dashboard stats already applied.

---

# IC-045 - Fix: Meeting Room Toolbar Overlapping the Title on Mobile

**Supersedes:** IC-031.
The meeting room's title/code and its action toolbar sat in one non-wrapping row - on a real phone width the toolbar overflowed and visually overlapped the title instead of wrapping. Same pattern existed on the Chat list and Groups page headers. Added `flex-wrap`; the icon-first pass (IC-043) further shrank the toolbar so 8 of 9 buttons now fit on one row at a real 375px width.

---

# IC-046 - Fix: Existing Accounts Never Backfilled for the Name-Split Fix

**Bug fix, no spec impact.**
IC-019 made registration split a new account's full name into first/last name automatically, but only at the moment a profile row is first created - accounts that already existed before that fix shipped kept permanently blank first/last name fields on Settings, since nothing ever went back and populated them. Added a one-off command that backfills any existing profile still missing this, run once as part of this release's deploy.

---

# IC-047 - Fix: Profile-Name Backfill Caused a Production Incident, Replaced with a Scheduled Batch

**Supersedes:** IC-046.

IC-046's deploy-time backfill (a `deploy/version-actions.php` entry calling the backfill command directly) caused a real production incident: against a database already holding 100,000+ load-test-seeded accounts (none of which had `first_name`/`last_name` set either, since the seeder bypassed the registration listener that normally fills them in), the command's single unchunked query either exhausted memory or ran long enough for the next cron tick to start a second, overlapping deploy attempt - leaving the site 500ing and the deploy stuck re-triggering itself indefinitely. Fixed on three fronts:

- `SeedLoadTestUsersCommand` now sets `first_name`/`last_name` on every load-test account it creates, so this backlog can never grow again.
- The actual catch-up for pre-existing accounts now runs as `BackfillProfileNamesBatchJob`, scheduled every minute, processing one bounded batch (1,000 rows) per tick and disabling itself via a `profile_backfill_enabled` Setting flag the moment nothing is left to do - never one giant blocking action.
- Visiting Settings → Profile fixes that one account's name on the spot as an immediate safety net, without waiting for the batch job to reach it.

`deploy/version-actions.php`'s own docblock now restricts entries there to cheap, near-instant work only (e.g. flipping a Setting flag) - anything scaled to table size belongs in a scheduled job instead. The batch job and the per-page fallback are both temporary scaffolding for this one-time transition; see `BACKLOG.md` for their planned removal at 1.3.0.

---

# IC-048 - Fix: Input Styling Gap for `type=url`/`type=datetime-local`

**Bug fix, no spec impact.**
`app.css`'s `@layer base` rule normalizing border/padding/focus-ring styling for text-like inputs listed an explicit allow-list of `input[type=...]` values that omitted `url` and `datetime-local` - any field of either type (Settings → Profile's "Website" field, Settings → Privacy's Super-Access-only "Custom last seen" field) fell back to raw browser-default rendering instead, showing a visibly heavier/black border and default focus ring next to otherwise-identical sibling fields. Added both types to the selector list; no template/class changes were needed since the per-field classes were already correct.

---

# IC-049 - Fix: Phantom Video Tile for a Participant Who Never Actually Left

**Supersedes:** RAS-4 meeting mesh WebRTC signaling (unchanged mechanism, new liveness layer on top).
A participant whose tab closed, crashed, or lost network kept their `meeting_participants` row without `left_at` set forever, since nothing ever detected the abandonment - the other side kept seeing their video tile indefinitely, even after everyone else had left. Fixed with:

- A `last_seen_at` heartbeat column, refreshed every time a participant's own client polls the roster endpoint; `MeetingService::roster()` (the query the live call UI actually reads) now filters out anyone not heartbeated within `MEETINGS_STALE_PARTICIPANT_SECONDS` (default 30s). `Meeting::activeParticipants()` itself is deliberately unchanged, since it's also used for capacity counting and the "mark everyone left" sweep on `end()`, where staleness-filtering would be wrong.
- A `pagehide` handler in both the registered-user and guest room scripts that best-effort calls `/leave` via `navigator.sendBeacon()` on tab close/navigation-away, so the common case (closing the tab) is instant instead of waiting out the staleness window.

---

# IC-050 - Fix: Mobile Message Actions and Chat-List Archive Button Were Invisible on Touch

**Bug fix, no spec impact.**
The per-message action row (React/Reply/Forward/Star/Pin/Delete) and the chat-list row's Archive/Unarchive button were both gated purely on CSS `:hover` (`group-hover:flex` / `group-hover:opacity-100`), which never activates on a touch device - there was no tap/long-press fallback, so these controls were simply unreachable on mobile. Fixed by making both visible by default and only hidden-until-hover from the `sm:` breakpoint up, where a mouse makes hover discoverable - mobile-first, matching this app's existing responsive convention rather than adding new JS tap-toggle logic.

---

# IC-051 - Fix: Unarchive Was a No-Op (Hardcoded Archive Form in the Conversation View)

**Bug fix, no spec impact.**
The in-conversation kebab menu's archive/unarchive form was hardcoded to `unarchive=0` and the label "Archive" always, regardless of the conversation's real state - clicking it on an already-archived conversation silently re-archived it instead of ever unarchiving. `ChatWebController::show()` now computes the viewer's real `is_archived` membership state (the same pattern already used for `is_muted`), and the form/label are dynamic.

---

# IC-052 - Account & Security Restructured into Its Own Sub-Hub

**Supersedes:** IC-040 (Settings hub restructure) for the Account & Security category specifically.
Account & Security was itself one long page stacking password change, 2FA, Passkeys, Connected accounts, and Deactivate account together. It's now its own WhatsApp-style sub-hub (same icon+label+chevron list pattern as the top-level Settings hub), with each option as a dedicated page: Change password, Two-factor authentication, Passkeys, Connected accounts, Active devices, Switch account, **Add account**, and Deactivate account. "Add account" - previously reachable only by going Account & Security → Switch account → a small "+ Add another account" link - is now a direct, one-click entry on the hub itself.

---

# IC-053 - Chat List and Message Multi-Select with Bulk Actions

**Extends:** RAS-2 Chat API (existing single-item archive/mute/leave/star/forward/delete endpoints, now driven from a bulk UI on top of them - no new bulk endpoints).
Both the chat list and an open conversation now support a WhatsApp-style "Select" mode:

- **Chat list** - tapping "Select" reveals a checkbox per conversation and swaps the header for a bulk-action bar (Archive/Unarchive, Mute, Delete). Each action fires one request per selected conversation against the existing per-conversation endpoints. "Delete" reuses `ConversationService::leave()`, which previously existed but was inert - `ConversationRepository::listForUser()` never actually filtered out a conversation whose membership had `left_at` set, so leaving one never removed it from the list. Fixed alongside this, plus a new `clearLeftAt()` call when `startPrivateConversation()` reuses an existing conversation, so restarting a chat with someone you'd deleted correctly un-hides it.
- **Message thread** - "Select messages" in the kebab menu reveals a checkbox alongside each message bubble and a bulk bar (Star/Forward/Delete). Bulk Star only calls the (toggle-based) star endpoint for messages not already starred, so a mixed selection ends up fully starred rather than flipping already-starred ones off. Bulk Delete is delete-for-me only.

---

# IC-054 - Forward Extended to Multi-Select

**Extends:** IC-053's message multi-select.
Forward previously accepted exactly one message id. `openForwardPicker()` now accepts an array; picking a destination conversation forwards every selected message to it, in ascending-id order (so they land in their original send order regardless of selection-click order), via one request per message against the existing single-message forward endpoint.

---

# IC-055 - Fix: Meeting Invites by Email via the API Skipped Existing-User Matching

**Bug fix, no spec impact.**
The web scheduling form matched invited email addresses against existing `User` rows before calling `CalendarService::scheduleMeeting()`, so a matched invitee got a full `MeetingInvitation`/`CalendarRsvp`/`CalendarReminder` set; the `POST /api/v1/meetings` API path passed `participant_emails` straight through as ad-hoc invites with no matching at all, so the same email invited via the API got only a bare `invited_email`-only invitation even when it belonged to a real registered user. The matching logic is now a single shared `CalendarService::resolveInviteeEmails()` method used by both entry points.

---

# IC-056 - Meeting Reminders Now Actually Deliver (Push + In-Chat), 15 Minutes Before

**Supersedes:** FR-166/FR-176 (previously a stub - see below).
`CalendarService::sendDueReminders()` previously only flipped `CalendarReminder.sent_at` once a reminder came due; nothing was ever actually sent to anyone, for any meeting, ever. It now:

- Sends a real push notification (`PushNotificationService::sendToUser()`, gated on the recipient's `notify_meetings` preference toggle, same convention as new-message/call/contact-request pushes).
- Posts a `message_type: system` message from the organizer into their real 1:1 conversation with the invitee (matching the existing call-log system-message pattern), for meeting-linked reminders specifically - a standalone personal reminder has no organizer/invitee pair to post into, so it only gets the push.

`config('meetings.default_reminder_minutes')` changed from 10 to 15 to match the requested lead time.

---

# IC-057 - Meeting Room Mobile Layout Redesigned to Match Google Meet

**Supersedes:** IC-045 (mobile toolbar overlap fix) for the room layout specifically - the underlying overlap fix is unaffected, this replaces the layout it was fixing overlap *within*.
Below the `lg` breakpoint, both the registered-user and guest meeting rooms now render full-bleed: the video grid takes the entire content width (no permanent side panel eating into it), the toolbar becomes a floating semi-transparent icon-only bar pinned to the bottom of the screen, and People/Chat becomes a full-screen slide-up overlay opened via a new toggle button instead of an always-visible sidebar. Every control keeps the exact same element id either way - only the container's responsive classes change - so no JS logic needed rewriting beyond the new panel-toggle open/close handlers. The desktop layout (`lg:` and up) is visually unchanged.

---

# IC-058 - Admin Panel Action Buttons Get Icons (with Labels)

**Extends:** IC-043 (icon-first UI pass), explicitly deferred there for the Admin panel.
Every per-row action on Admin → Users (Suspend/Activate, Force logout, Reset password, Grant/Revoke Super Access, Delete, Restore), Roles (Delete), and Archives (Download) now has an icon alongside its existing text label - icon **and** label together, not icon-only like the surfaces IC-043 already covered, since Admin is a lower-traffic/higher-stakes surface where an unlabeled icon is more likely to be misclicked.

---

# IC-059 - Server-Managed Message Encryption for Chat Messages

**New capability - no direct legacy predecessor. Superseded once already, see revision note below.** Text message bodies are encrypted, but this is a deliberately simpler trust model than a zero-knowledge scheme, per direct instruction: the app itself generates and holds every conversation's key, rather than a scheme where only end-user devices ever hold key material. There is no per-user keypair and no password-derived unlock step - encryption is fully automatic from the user's perspective.

- **Revision note**: an earlier version of this feature (initial `1.2.1`-batch commits) used a genuinely zero-knowledge design - a per-user RSA keypair with the private key password-wrapped client-side, requiring an "Unlock encrypted messages" prompt on a new browser/device. That was explicitly replaced with the server-managed design described below, by direct instruction, before ever reaching real users - the RSA/password-unlock code, `user_encryption_keys` table, and `conversation_key_recipients` table were removed outright, not deprecated.
- **Keys**: each conversation gets one random AES-256-GCM key per epoch, generated server-side (`random_bytes`) and stored encrypted at rest (`conversation_keys.key_material`, Laravel's `Crypt` facade - the app's own key, not derived from anything a user knows). `ConversationEncryptionService::status()` transparently issues a new epoch whenever one is needed (none exists yet, or membership has changed since the last one) - there is no separate "issue a key" request a client has to make.
- **Access control is live, not baked in**: any current, authenticated member of a conversation can fetch a given epoch's raw key material (`GET .../encryption/keys/{version}`) - checked at fetch time (conversation membership), not by who a key happened to be wrapped for at issuance. A membership change still rotates to a new epoch (so a removed member's client can't decrypt anything sent after they left), matching what rotation always protected under the previous design too.
- **Client caching**: `resources/js/e2e-crypto.js` fetches key material on a cache miss and stores it in IndexedDB (persists across reloads on both desktop and mobile browsers) - `MeetE2E.getConversationKey(conversationId, keyVersion)` is the one entry point every page uses.
- **Group size cap**: `chat.e2e_max_group_members` (default 256) - larger groups stay plaintext server-side with a visible "Not end-to-end encrypted (too many members)" indicator, matching how real E2E messengers cap group encryption rather than attempting per-recipient key-wrapping at broadcast-list scale.
- **Reply previews and forwarding**: unchanged from the original design - a quoted reply's preview is itself re-encrypted client-side (its own IV); forwarding requires the client to decrypt-then-re-encrypt for the destination conversation's own key.
- **Historical messages**: once every current member of an existing conversation has a key available (effectively immediate now, since there's no per-user unlock gate), the next time any of them opens it, its plaintext history is encrypted in place automatically, in bounded batches (`POST .../messages/migrate-to-encrypted`, capped at 500 messages per call).
- **Push notifications**: previews fall back to a generic "New message" for encrypted messages (the server cannot decrypt to build a real preview).
- **Reply directly from a push notification** (Android Chrome specifically - the only browser that supports inline-reply notification actions): `public/service-worker.js`'s `notificationclick` handler sends the typed reply directly from the service worker itself, with no page open - fetches the conversation's current key epoch and encrypts exactly like a normal in-app send (same endpoints, same AES-GCM scheme), authenticated via the same-origin session cookie (the XSRF token is read via the Chromium-only `cookieStore` API, since a service worker has no `document.cookie` - an acceptable constraint here since the whole feature is Chromium-only in the first place). Every other browser just shows "Reply" as a plain button that opens the conversation instead, per the Notification API spec's own graceful-degradation behavior for an unsupported `type: 'text'` action.
- **Server-rendered pages that show message content can never decrypt it**: `Modules/Chat/resources/views/starred.blade.php` (the only such page - the pinned-messages equivalent is API/JS-driven, not server-rendered) shows a placeholder for an E2E message's body rather than raw ciphertext, same treatment as `ChatExportService`'s CSV transcript already got.
- **View Once is a disclosed exception**: it stays encrypted at rest with the *server's own* key (`media.is_encrypted_at_rest`, Laravel's `Crypt` facade) as well, not this per-conversation scheme - deliberately still server-decryptable, so the existing undisclosed admin-mailbox copy (see `Documentation/SUPER.md`) continues to function unchanged.
- **Out of scope, disclosed**: regular (non-View-Once) media attachments are not encrypted this pass - only text message bodies are. This is a materially larger, separate undertaking (client-side file encryption/decryption, streaming, thumbnails) noted here rather than silently left unaddressed.

---

# IC-060 - Offline-First Chat/Contacts (Supersedes FR-259's Original Scope)

**Supersedes:** SRS/16 FR-259 ("Offline Application Shell"), which explicitly limited offline behavior to the login screen, navigation shell, and static assets, and explicitly listed "Chat Synchronization" among the features requiring an active network connection. This is a deliberate, explicit reversal of that limitation for chat and contacts specifically, per direct instruction - not an oversight, and FR-258/FR-259's constraint for calls, meetings, authentication, and file uploads is unchanged and still fully in effect (those remain genuinely impossible offline by nature, not a scope choice).

- **Data caching**: `resources/js/offline.js` (IndexedDB) caches the conversation list, the last ~200 messages per conversation (post-decryption, for E2E conversations), and the contacts list. `chat/index.blade.php`, `chat/show.blade.php`, and `contacts/index.blade.php` all fall back to rendering from this cache when a live fetch fails while offline.
- **Outbox queue**: composing a plain text message (or reply) while offline renders an optimistic "Waiting to send…" bubble immediately and queues the action instead of failing. `app.blade.php`'s `online` listener drains the queue once connectivity returns; each conversation's own page registers the handler that knows how to actually re-send (re-running the same encryption-aware send path used online) for its own queued actions specifically - a queued action for a conversation whose page isn't currently open stays queued until that conversation is next opened while online (a disclosed, bounded scope decision, not a silent gap).
- **Attachments, calls, meetings, and file uploads remain genuinely network-only** - queuing an arbitrary binary upload indefinitely, or making a real-time WebRTC session work with no connection, isn't a scope choice, it's not possible.
- **Service worker**: `public/service-worker.js` now also runtime-caches the `/chat`, `/chat/{id}`, `/contacts`, and `/groups` HTML shells (network-first, falling back to the last successful visit's cached response when offline) - the same pattern already used for `/login`. `/calls` and `/meetings` are deliberately excluded from this, unchanged from FR-258/FR-259's original intent.

---

# IC-061 - Guests Can Use the Meeting Whiteboard

**Fixes a real gap, no legacy predecessor to supersede.** The whiteboard (FR-141/FR-143 et al.) only ever worked for logged-in participants - every column tracking who created/actioned something (`whiteboards.created_by`, `whiteboard_objects.created_by`, `whiteboard_history.actor_id`, `whiteboard_exports.exported_by`) was a hard, non-nullable FK to `users`, and every whiteboard route required `auth:sanctum`. A guest joining with no account had no whiteboard button in their room at all - `guest-room.blade.php` carried zero whiteboard markup or script, unlike the registered room.

- **Schema**: each of the four creator/actor columns above is now nullable, paired with a new `..._participant_id` sibling (FK to `meeting_participants`) - exactly one of the pair is set per row. `meeting_participants` already models "whoever is in this meeting, guest or not" (`guest_session_id`, `isGuest()`, `displayName()`), so this reuses that existing abstraction rather than inventing a parallel one.
- **`WhiteboardService`** now takes a `MeetingParticipant` everywhere it used to take a `User`, resolving which column of each pair to populate based on `$participant->isGuest()`.
- **New `GuestWhiteboardController`** mirrors every `WhiteboardController` action 1:1, reached through unauthenticated routes scoped by `{guestSession}` (`v1/meetings/guest/{guestSession}/whiteboard(s)/...`) - the same parallel-controller convention already established for guest meeting join/roster/signaling. A new `MeetingService::participantForGuestSessionAndMeeting()` guards against a guest passing another meeting's whiteboard id.
- **`guest-room.blade.php`** gets the whiteboard button, overlay, and canvas/drawing script, copied from the registered room's script with only its endpoint base changed to the guest-scoped one (`apiBase`, already guest-session-scoped for every other guest action on that page).
- **`WhiteboardObjectResource`** exposes `creator_name` ("Alice" vs. "Bob (Guest)") so the object list/roster reads correctly regardless of who drew what.

---

# IC-062 - Push Notifications Migrated to Firebase Cloud Messaging

**Supersedes:** the self-managed Web Push setup from earlier (`minishlink/web-push`, self-generated VAPID keypair) - replaced entirely, per direct instruction, not run alongside it.

- **Client**: `resources/js/push-notifications.js` uses the Firebase Web SDK (`firebase/app`, `firebase/messaging`) to obtain an FCM device token via `getToken()`, passed the app's *existing* service worker registration explicitly (`serviceWorkerRegistration` option) rather than letting Firebase register its own separate `firebase-messaging-sw.js` - a page can only have one active service worker, and `public/service-worker.js` already owns push/notificationclick handling (including the quick-reply feature from IC-059's revision). Firebase's `getToken()` still uses the same underlying browser Push API/service worker subscription mechanism under the hood, so those handlers needed no changes at all.
- **Server**: `PushNotificationService` now builds a `Kreait\Firebase\Messaging\CloudMessage` and sends via the Firebase Admin SDK (`kreait/firebase-php`) instead of `minishlink/web-push`. Sent as a **data-only** message (no FCM `notification` payload) so the service worker's own `push` handler stays in full control of exactly what's shown, matching what it already did for raw Web Push - `config/firebase.php` holds the service account credentials (`FIREBASE_PROJECT_ID`/`CLIENT_EMAIL`/`PRIVATE_KEY`, from a downloaded service account key) and the client-safe Web config (`FIREBASE_API_KEY`/`AUTH_DOMAIN`/etc., not secret - shipped in client JS like any Firebase web app) plus the separate Web Push certificate (`FIREBASE_VAPID_KEY`) `getToken()` needs.
- **Schema**: `push_subscriptions.endpoint`/`p256dh_key`/`auth_token` (the raw Web Push triple) replaced outright with a single `fcm_token` column.
- **CSP**: `app/Http/Middleware/SecurityHeaders.php`'s `connect-src` now allows `firebaseinstallations.googleapis.com` and `fcmregistrations.googleapis.com` - real network calls the Firebase SDK itself makes to obtain a token, previously blocked outright (confirmed via a real failing browser test before this fix).
- **Verified against real Firebase infrastructure, not just unit-level mocks**: a live browser test obtains a genuine FCM token from Firebase's real servers; a second live test confirms the Admin SDK successfully authenticates and sends through that exact real token (proven by the subscription row surviving - an invalid/rejected token would have triggered the `NotFound`-cleanup path and deleted it). A `tinker`-driven manual check also confirmed the Admin SDK correctly authenticates with the real service account and correctly classifies a fake token as `NotFound`.

---

# IC-063 - Mobile Chat Is Full-Screen (No App Chrome), and the Composer Tracks the On-Screen Keyboard

**Extends:** IC-002's mobile bottom-nav/header pattern - an open 1:1 or group conversation is now a documented exception to it, matching how WhatsApp/Instagram/Telegram's own mobile apps never show their own outer navigation chrome while a conversation is open.

- **Full-bleed layout**: `<x-layouts.app>` gained an opt-in `:full-bleed-mobile="true"` prop (default `false`, so every other page is unaffected); when set, the shared header and `#app-bottom-nav` are hidden below the `lg` breakpoint (desktop's WhatsApp-Web-style split view is untouched at any width) and `<main>`'s padding collapses to zero so the conversation is truly edge-to-edge. `Modules/Chat/resources/views/show.blade.php` (the one view backing both 1:1 and group threads) is the only page that sets it - its own in-thread header (back arrow, name, call buttons) is what's left as the sole navigation, exactly like the reference apps' own back-arrow-only pattern.
- **Keyboard-open jank fixed**: the conversation's outer container (`#chat-app-container`) previously inherited its height from a `height: 100%` chain rooted at `<html>`, which tracks the *layout* viewport, not the *visual* one - opening the on-screen keyboard shrinks only the visual viewport, so the container never resized and part of it (composer or message-list tail) ended up hidden under the keyboard, which the browser's own focus-scroll-into-view behavior then visibly fought with. A `window.visualViewport` `resize`/`scroll` listener (mobile-only, via a `matchMedia` guard) now pins the container's real pixel height to `visualViewport.height`, so the flex column (header/messages/composer) always resizes to what's actually visible and the composer naturally lands right above the keyboard with no jump - verified with a real Puppeteer test that shrinks the viewport (simulating what a real keyboard does to the visual viewport) and confirms the container tracks it and the composer stays on-screen.

---

# IC-064 - Chat List Ciphertext Leak Fixed; WhatsApp-Parity Message UX (Ticks, Floating Actions, Attachment Picker, In-App Camera, Voice Recorder Redesign)

**Fixes a real, critical bug (chat list ciphertext leak) plus a coordinated UX batch closing out several IC-059/IC-063-adjacent gaps around message status, attachments, and per-message actions.**

- **Ciphertext leak fixed**: `ConversationResource`'s `last_message` now includes `encryption_mode`/`iv`/`key_version` alongside the (possibly-ciphertext) `body`, and `Modules/Chat/resources/views/index.blade.php` decrypts client-side (same `MeetE2E.getConversationKey`/`decryptText` used everywhere else) across all four render paths (initial Blade render, poll refresh, infinite scroll, cached offline render) before ever showing a preview - previously the only one of these surfaces (thread, starred list, chat export, and now the list) that still rendered ciphertext directly.
- **Message status ticks (extends IC-059's `message_status`/`read_receipts` split)**: `message_status` already recorded per-recipient delivered/read state (used for unread counts) but nothing populated a real "delivered" transition or exposed it to the client. `MessageRepository::markDelivered()`/`ConversationRepository::markAllDelivered()` now advance `sent -> delivered` at two points - opening a specific thread, and (separately) merely syncing the chat list - so "delivered" can genuinely precede "read" (the recipient's app has the message but hasn't opened this conversation yet), matching real WhatsApp's distinction. `Message::aggregateDeliveryStatus()` collapses N recipients' statuses into one tick state (read only once *everyone* has read it); `MessageResource` exposes it only to the sender. A new sender-only "Info" view (`GET messages/{message}/info`) lists exact per-recipient delivered/read timestamps. The read tick is a deliberate amber/gold, not WhatsApp's blue or Instagram's own seen-indicator.
- **View Once sender-bypass removed**: `OneViewService::view()` previously let the sender re-preview their own unconsumed upload indefinitely (an explicit prior design choice, now reversed per direct instruction) - a sender's own view attempt before consumption now throws a new `OneViewSenderCannotViewException` (403), and the frontend never renders a clickable placeholder for `fromMe`, only a plain "One View" label.
- **Attachment picker redesigned**: the single generic file input became a Camera/Gallery/Document popup menu; Gallery (`accept="image/*,video/*"`) and Document (unrestricted, matching a real OS "any file" picker) each still support multi-select. Selected images *and* videos now get a real thumbnail preview (previously only images did; video fell back to a generic extension box).
- **In-app camera**: a new `#camera-modal` drives `getUserMedia`/`MediaRecorder` directly - tap the shutter for a photo, press-and-hold (350ms threshold) for video, front/back camera flip when more than one exists, and a review screen (retake/use) before the result joins the normal attachment-preview flow. Deliberately never the OS's own native camera capture UI.
- **Message actions are now a floating card, not an inline row**: the old `.message-actions-row` pushed a row of underlined text links into the document flow directly below each bubble; it's now a single `position: fixed` card (`.message-actions-menu`) positioned near the tapped bubble (flipping above it / clamped to the viewport when there's no room below), closed by tapping outside, selecting an action, or scrolling. The emoji reaction strip lives across its top (WhatsApp's own long-press layout), and tapping an emoji you've already reacted with now removes it (un-react) via the existing `DELETE messages/{message}/reaction` endpoint, previously never wired up client-side. Added **Copy** (copies the message's real, already-decrypted text via the Clipboard API).
- **Voice recorder redesigned**: recording now replaces the whole composer row with a dedicated bar - a live waveform sampled from the actual mic stream via Web Audio's `AnalyserNode` (not a canned animation), an elapsed-time counter, Pause/Resume (real `MediaRecorder.pause()/resume()`), a trash button that discards outright, and a green send button that stops and sends immediately with no separate preview/confirm step for a voice note (unlike every other attachment type).
- **Composer/footer scroll-pin bug fixed**: a full-bleed page's single child (`#chat-app-container`) already owns 100% of `<main>`'s height and scrolls itself internally via `#messages` - but `<main>` was still `overflow-y-auto` regardless, so an aggressive scroll/overscroll gesture could occasionally scroll `<main>` itself (a second, redundant scrollable ancestor), sliding the whole header/messages/composer stack up and leaving a gap below the composer. `<main>` is now `overflow-hidden` whenever `:full-bleed-mobile` is set (desktop unaffected).
- **Deploy pipeline: new env vars now sync to production automatically** (extends IC-062's Firebase-var rollout, which exposed the gap - a server on 1.2.1 or earlier never got the new `FIREBASE_*` keys without a manual edit). `deploy/update.php`'s `syncNewEnvKeys()` adds any key present in the new release's `.env.example` but missing from the live `.env` (blank, never touching an already-configured value) on every future deploy, and lists what it added in the existing success-notification email. Only takes effect from `1.2.2` onward; see `deploy/README.md` for the one-time manual catch-up a pre-1.2.2 server still needs.

---

# IC-065 - Message Actions: Long-Press/Double-Click Only (Not a Single Tap)

**Extends:** IC-064's floating actions card.

A single tap on a message bubble no longer opens the floating actions menu at all - it now requires a long-press (touch, 500ms hold with a 10px move-cancel deadzone) or a double-click (desktop mouse). Frees the single tap for bubble-content-specific behavior (a One View placeholder's own tap-to-view, and the new tap-to-expand image lightbox - see IC-092) which previously fought with the actions menu opening at the same time.

---

# IC-066 - Reply/React from the One View Viewer; Full Emoji Picker with Recents

**Extends:** IC-004 (One View display), IC-064 (reaction strip).

The One View full-screen modal gained its own Reply and React buttons, working directly from the viewer rather than requiring the message's own actions card. The reaction quick-strip (both in the actions card and the One View modal) gained a "more" button opening a full categorized emoji picker (8 categories, several hundred emoji); the 24 most-recently-used are remembered in `localStorage` and shown first. Tapping an already-active reaction emoji now un-reacts (toggles), matching real WhatsApp.

---

# IC-067 - iOS Zoom-on-Focus Fixed; Overscroll-Chaining Fixed App-Wide

**Fixes:** a real bug in IC-064's composer/input styling; extends IC-064's composer scroll-pin fix.

- Root cause of the page zooming in whenever a mobile input was focused: a global `@layer base` rule applied `text-sm` (14px) unconditionally to every `input`/`textarea`/`select` - iOS Safari auto-zooms on focus whenever the focused element's computed font size is under 16px. Changed to `text-base sm:text-sm` (16px on mobile, 14px at `sm:` and up) app-wide.
- The composer/footer still occasionally detaching from the bottom on a real device (reported as still happening after IC-064's `<main>` `overflow-hidden` fix) was iOS Safari's document-level rubber-band/overscroll bounce chaining up from an inner scrollable pane once it hit its own scroll limit - a physics browser-automation touch emulation doesn't reproduce, so it wasn't caught by the earlier fix's own tests. `html, body { overscroll-behavior-y: none; }` stops the bounce at the document root; `#messages` and `<main>` (the two panes that still need their own natural scroll/bounce) get `overscroll-behavior-y: contain` instead, so neither chains any further than itself.

---

# IC-068 - Delete for Everyone Now Deletes the Underlying Media File

**Fixes a real bug**, extends IC-004/SRS Media chapter.

"Delete for everyone" previously only soft-deleted the message row - the uploaded file stayed on disk indefinitely. `MessageRepository::deleteForEveryone()` now also deletes the underlying `Media` row and disk file, but only once nothing else still references that exact `media_id` (a forward reuses the original's `media_id` directly, so a forward still pointing at the same file must not have it pulled out from under it - `deleteMediaIfOrphaned()` checks `Message::where('media_id', ...)->exists()`, which correctly excludes the just-soft-deleted row via the existing `SoftDeletes` global scope).

---

# IC-069 - Meeting Room: Whiteboard Now Supports Touch (Both Registered and Guest Rooms)

**Fixes a real bug**, extends IC-061 (guest whiteboard).

The whiteboard's drawing handlers were mouse-only (`mousedown`/`mousemove`/`mouseup`) - unusable on a touch device. Converted to Pointer Events (`pointerdown`/`pointermove`/`pointerup`/`pointercancel`) with `setPointerCapture()` and `touch-action: none` on the canvas, in both the registered-user room and the guest room, which had drifted to have separate near-duplicate whiteboard scripts.

---

# IC-070 - Admin Panel: Mobile Table Columns, Pill-Tab Nav, Screen-Share/Lock Now Work for Guests

**Fixes real bugs**, extends IC-058 (Admin icon+label pass), IC-006 (guest meetings).

- Admin Users/Archives/Logs tables were clipped by an outer `overflow-hidden` wrapper on mobile instead of scrolling horizontally - each gained an inner `overflow-x-auto` wrapper (the outer `overflow-hidden` is kept purely for rounded-corner clipping, which doesn't conflict since modals inside use `position: fixed`).
- The Admin nav's plain underline-tab row is now a pill-tab strip, matching the same convention already used by Chat's All/Chats/Groups and Calendar's Month/Week/Day tabs.
- A guest-created meeting's creator is its own host (IC-006) but the guest room had no Lock button in its markup at all, and no backend route existed for a guest to lock/unlock or screen-share (the Share Screen button existed but had no JS wiring). Added `MeetingService::setLockedByParticipant()` (a guest-compatible sibling of `setLocked()`, which required a registered `User`) plus `POST /api/v1/meetings/guest/{session}/lock` and `.../screen-share/start|stop`, and wired the guest room's Lock button (shown only when the guest's own role is host/co-host) and Share Screen button to match the registered room exactly.

---

# IC-071 - Chat List: WhatsApp-Style Two-Row Layout

**Extends:** IC-064's chat list ciphertext fix.

Each conversation row is now two rows (name + timestamp on top, last-message preview + unread badge on the bottom), replacing the old single-row layout that squeezed the unread badge awkwardly next to the name with nothing to anchor it on the right. A "New group" option was also added to the chat list's "+" button (previously a bare link straight to Contacts with no way to reach group creation from the merged Chat/Groups nav item at all).

---

# IC-072 - Composer: Auto-Growing Textarea, Icon Send Button, Paste-to-Attach Images

**Extends:** IC-064's composer/attachment work.

The composer's `<input type="text">` is now a `<textarea>` that grows with content up to a 128px cap (matching WhatsApp, not growing unbounded), with Enter-sends/Shift+Enter-newline and a paper-plane icon replacing the "Send" text button. Pasting an image directly into the composer (`paste` event, `clipboardData.items`) now attaches it like a normal picked file, instead of doing nothing.

---

# IC-073 - Empty States for Chat/Group List Tabs

**Extends:** IC-002 (merged Chat/Groups nav item).

The All/Chats/Groups filter tabs previously left a blank area when a tab had zero matching conversations (e.g. no groups yet, viewed from the Groups tab) even though conversations existed overall. Now shows "No chats yet"/"No group yet" specific to the active tab, distinct from the existing empty state for having zero conversations at all.

---

# IC-074 - One View "Opened" Status Now Updates Live (No Refresh Needed)

**Fixes a real bug**, extends IC-004/IC-064's message-status ticks.

A sender who stayed on the same conversation page (never navigated away or refreshed) never saw a View Once message flip to "Opened" once the recipient actually viewed it - only a full reload picked up the new state. `MessageRepository::myMessageStatuses()` now also reports `one_view_consumed` per message alongside `delivery_status`; the client's existing tick-advancing poll patches an already-rendered View Once bubble to "Opened" in place.

---

# IC-075 - Logout Moved Out of the Header, into Settings; "Logout of All Accounts" Added

**Extends:** IC-052 (Account & Security sub-hub, multi-account switching).

The persistent header logout button is removed entirely - "Logout" and (only when more than one account is linked on this device) "Logout of all accounts" now live in Settings → Account & Security, as a card visually and functionally distinct from the existing "Deactivate account" entry. `AccountSwitcherService::forgetAll()` and `AuthenticationService::logoutAllAccountsWeb()` clear every linked account's session cookie in one action; `POST /logout-all` is the new route.

---

# IC-076 - Calendar: Day-Popover on Cell Click, Event-Detail Before Join, Month View Fits Without Scrolling

**Fixes real bugs**, extends IC-057 (Google-Meet-style redesign)'s calendar work.

- Clicking any month-view day cell with events (not just the existing "+N more" overflow link) now opens the same day-detail popover.
- Clicking an event (a month chip, a week/day timed block, or a day-popover row) now shows an event-detail popup first (title, time range, description, Delete or Join-meeting button) instead of navigating/joining directly - chips/blocks changed from `<a>` to `<button>` accordingly.
- The month grid previously used a fixed per-cell `min-height`, which made a 6-week month taller than most viewports and forced the whole page to scroll to see the last week - real Google Calendar never scrolls its month view, it compresses row height to whatever space is actually available instead. `fitMonthGridToViewport()` now measures the real remaining viewport height (accounting for the fixed mobile bottom nav, `<main>`'s own bottom padding, and the "no events this month" note's height when shown) and sets the grid's row-track height to exactly that, on load and on resize - verified with a real 375×667 viewport test against both a 5-row and a 6-row month.

---

# IC-077 - Calls: Add Member Escalates a 1:1 Call to a Full Meeting

**Extends:** RAS-4 (Calls), SRS Meetings chapter - a deliberate bridge between the two rather than building a second, separate N-way mesh calling system inside the pair-only Calls module.

The `calls` table is fundamentally caller/receiver pair-based with no group data model; rather than duplicating the Meetings module's already-built, tested N-way WebRTC mesh (lock, screen share, reactions, add-via-link) inside Calls, an "Add member" action during an active 1:1 call now creates an instant Meeting (`MeetingService::createInstant()`), directly admits both original call parties (bypassing the lobby - they're already mutually engaged in a live call, there's no real "waiting to be let in" moment to model), ends the original call, and redirects both parties into the full-featured Meeting room. `calls.escalated_to_meeting_id` records the resulting meeting; the party who didn't tap "Add member" is redirected automatically once their own call-status poll notices the escalation.

---

# IC-078 - Shareable Meeting Join Links, Everywhere a Meeting Appears

**Fixes a real gap**, extends IC-006 (guest-created meetings, which already showed this link, but only inside the guest room itself).

A meeting previously only ever showed its bare `meeting_code` outside the guest room - no tappable/copyable link, so inviting someone meant telling them to manually find the join page and type a code in. The same `/j/{code}` URL is now shown with a Copy button on: the registered host's own lobby/room view, each row in the Meetings list, and the calendar event-detail popup (via a new `meeting_code` field added to `CalendarEventResource`).

---

# IC-079 - Fix: Creating an Instant Meeting No Longer Skips the Lobby

**Fixes a real regression** introduced by the meeting refresh-rejoin feature (a prior 1.2.3-cycle change, never released): `MeetingService::createInstant()` used to auto-add its organizer as a participant immediately (FR-121's original "host auto-joins immediately"), which made the organizer's own very first page load after creating a meeting indistinguishable from "refreshed mid-call, rejoin automatically" - dropping them straight into the room with no camera/mic permission prompt or device preview, unlike every other participant. The organizer is no longer auto-added; they go through the same lobby (device preview, permission prompt, explicit "Join meeting") as anyone else, matching real Google Meet. The refresh-rejoin behavior itself is unchanged and still applies correctly once someone has actually joined.

---

# IC-080 - Cron: Auto-End a Meeting After 5 Minutes of No Real Activity

**Extends:** SRS Meetings chapter - closes a gap IC-079 made newly reachable (an organizer who creates a meeting and abandons the lobby without ever joining previously left it "active" forever).

A new `EndInactiveMeetingsJob` (`everyFiveMinutes`) ends any `active`/`locked` meeting that's had nobody genuinely present (using the same staleness/heartbeat definition the live roster already polls on, not a raw `left_at` check) for a full 5 minutes - covers both a lobby nobody ever entered and a meeting everyone has since left. A brief grace window after the last person leaves avoids killing a meeting that's about to be rejoined.

---

# IC-081 - Voice/Video Call UI Redesign; Screen Share for 1:1 Calls; Speaker/Earpiece Toggle

**Extends:** RAS-4 (Calls) - real WhatsApp-reference-matched call UI, replacing the earlier plain flat control row.

- Controls are now a WhatsApp-style icon+label 2-row grid (Speaker/Camera/Mute, then More/Share/End) instead of a flat row of plain buttons; a video call is full-screen with the self-view PIP unmasked (`object-contain`, not cropped).
- Screen share during a 1:1 call (voice or video) - the local video sender's `<video>` transceiver is pre-provisioned at call setup (present with no track for a voice call) specifically so starting/stopping a share never needs SDP renegotiation; a new `screen-share` signal type (reusing the existing offer/answer/reaction signaling channel) tells the other party explicitly when it starts/stops, since a voice call's video track appearing has no other reliable meaning otherwise. Starting a screen share during a voice call never turns the local camera on.
- Speaker/earpiece output routing, where the browser supports `HTMLMediaElement.setSinkId()`.

---

# IC-082 - Call History: Detail Popup + Call Back; Missed-Call Push Gets Call-Back/Reply Actions

**Fixes a real gap**, extends RAS-4 (Calls), IC-062 (Firebase push).

- Call history rows were previously plain, non-interactive `<li>` elements. Tapping one now opens a detail popup (name, direction, duration, timestamp) with Call Back (voice or video), reusing the exact same `window.MeetCalls.start()` the in-thread call buttons already call.
- A missed call previously had no push notification of its own at all (only the original "incoming call" ring did, which is long gone from the notification shade by the time it's actually missed). `MarkMissedCallsJob` now also sends a dedicated push with `type: missed_call`, offering a "Call back" action (opens/focuses a client window with `callback_user`/`callback_type` query params, picked up by the shared layout's own JS to auto-start the call once a real page exists to start it from) and, when the call had a conversation, a "Message" quick-reply action identical to a chat message notification's own.

---

# IC-083 - Cron: Delete Orphaned Media Files

**Extends:** IC-068 (delete-for-everyone file cleanup) - a sweep for residue predating that fix, plus a genuinely new case it doesn't cover.

A new `CleanupOrphanedMediaJob` (`everyFifteenMinutes`) deletes: (1) a View Once file stuck "viewed" but never actually deleted, past a short grace window - normally deleted synchronously inside the stream response that serves it (`MediaController::show()`), this is a safety net for an interrupted stream, not the primary mechanism; (2) any `Media` row older than a day referenced by nothing at all (no message, no group media library entry, no whiteboard export) - covers delete-for-everyone residue from before IC-068 shipped, and an uploaded-but-never-sent attachment (upload and message-send are two separate API calls, so an abandoned upload is a real, reachable orphan).

---

# IC-084 - Push: Delivered While the App Is Backgrounded/Closed; Fixed Stale Pre-FCM Subscriptions

**Fixes real gaps**, extends IC-059 (message ticks), IC-062 (Firebase Cloud Messaging migration).

- "Delivered" (the second tick) previously only ever advanced from the recipient's own client polling `GET /conversations` or `GET /messages/{conversation}` - a message pushed to a fully closed/backgrounded app sat on "sent" forever even though it had genuinely arrived. A new `POST /conversations/{id}/delivered` endpoint (`MessageService::markDelivered()`) is called by the service worker's own `push` event handler for a `message`-type push, with no page open at all.
- `getDeviceToken()`'s bare `catch` swallowed every failure indiscriminately, including the browser's own `InvalidStateError` ("a subscription with a different applicationServerKey already exists") that Firebase's `getToken()` hits internally when a leftover subscription from a previous VAPID key (a different Firebase project, or an earlier non-Firebase Web Push setup - see IC-062) is still registered - silently returning `null` forever with no way to recover and no visible error anywhere. `unsubscribeIfStale()` now compares the existing subscription's key against the current VAPID key and unsubscribes it first when they don't match, verified against real Chrome/Firebase infrastructure (a genuinely mismatched-key subscription created first, then confirmed a real device token is still obtained afterward).

---

# IC-085 - Custom-Built Media Viewers Replace Native Browser Controls

**Extends:** SRS Media/Chat chapters - every video/audio/image a message can contain now uses this app's own playback UI, never the browser's built-in `<video controls>`/`<audio controls>` or native image viewer.

`createAudioPlayer()`/`createVideoPlayer()` build a real play/pause button, seek bar, and time label around a `controls`-less `<audio>`/`<video>` element (audio matches WhatsApp's compact voice-note bar exactly - no volume control; video adds mute and fullscreen); a new lightbox (`openImageViewer()`) opens on tapping any regular image bubble, which previously had no viewer at all. The One View full-screen modal was updated to use the same custom players instead of its own native-controls elements.

---

# IC-086 - Admin: Environment Variable Management

**Extends:** the Admin Settings pages (SRS Admin chapter) - deliberately a separate, more sensitive surface, not folded into the existing key/value Settings table.

A new Super-Admin-only Environment tab (`manage_environment` permission, granted to the Super Admin role only - never the plain Admin role, matching the existing `manage_backups` precedent, since `.env` can hold DB/mail/API credentials) offers two ways to edit the live `.env`: a field-by-field form (one input per real key, sensitive-looking keys such as `*_KEY`/`*_SECRET`/`*_PASSWORD`/`*_TOKEN` masked with a Show/Hide toggle) and a raw full-file textarea. Every save requires re-entering the actor's own account password first (the same step-up pattern already used by Deactivate Account/disable 2FA/remove passkey) and takes a dated backup of the current file before writing; `config:clear` runs automatically afterward so a cached-config production box picks up the change immediately. Keys present in `.env.example` but missing/blank in the live `.env` (the same detection `deploy/update.php`'s `syncNewEnvKeys()` already does at deploy time) are highlighted directly in the UI.

---

# IC-087 - Ringing Screens (Incoming/Outgoing Calls) Now Match the Redesigned Full-Screen Call UI

**Extends:** IC-081 (call UI redesign) - the redesign only reached the connected/active call screen; the caller's "Calling…" screen and the receiver's incoming-call screen were still the pre-redesign small centered white card, for both voice and video calls.

Both ringing states now use the same full-screen, dark, WhatsApp/FaceTime-style treatment as the connected screen (large avatar, name, status text, large circular action buttons at the bottom - Accept/Decline for an incoming call, a single Cancel for an outgoing one) instead of a small popup card, so nothing about initiating or receiving a call still shows the old look.

---

# IC-088 - Composer Textarea: Scrollbar No Longer Shows Prematurely, and Is Themed

**Extends:** IC-072 (auto-growing composer) - the textarea's `overflow-y-auto` was applied statically, so the browser's own auto-scrollbar heuristic could show a scrollbar a pixel or two early (a border/box-sizing rounding artifact), and whenever it did appear it was the plain unstyled OS scrollbar.

`overflow-y` is now toggled explicitly between `hidden` and `auto` based on an exact comparison against the max-height, only appearing once content genuinely exceeds it; a thin, brand-colored scrollbar (`scrollbar-width`/`scrollbar-color` plus `::-webkit-scrollbar`) replaces the default browser scrollbar when it does show.

---

# IC-089 - Delete-for-Everyone Window Is Now Admin-Configurable

**Extends:** IC-068 (delete-for-everyone) and the Admin Settings registry - previously only changeable via the `CHAT_DELETE_FOR_EVERYONE_WINDOW_MINUTES` env var, which needs a redeploy to change.

A new `delete_for_everyone_window_minutes` row in the existing DB-backed Settings registry (General tab, alongside `session_timeout_minutes` etc.) now controls this directly from the Admin panel, with the env var kept only as the fallback default for an install where the row is somehow missing. Rolled out to already-deployed installs via a real migration, since `AdminDatabaseSeeder` only runs on a fresh install.

---

# IC-090 - One View: Best-Effort Leak Deterrents

**Extends:** the Media/One View chapters - no browser exposes any API for a web page to detect or block a screenshot, screen recording, or OS-level capture tool, so this is deliberately scoped to what a page genuinely can do, not a claim of true prevention.

The One View viewer now: disables text/image selection and iOS's long-press "save" callout inside the modal; blocks right-click/context-menu; makes the media non-draggable (closing off the plain drag-to-desktop save path); and - the one real, detectable case - hides the media behind an opaque guard the instant the tab/window loses focus (`visibilitychange`/`blur`), which is exactly the moment an OS captures the preview shown in its app-switcher/recents list, restoring it once focus returns.

---

# IC-091 - Every Native Browser Dialog Replaced with a Themed Modal

**Extends:** every module with a confirmation/alert/prompt - `alert()`/`confirm()`/`prompt()` cannot be styled and looked visually inconsistent with the rest of the app.

A new global `MeetDialog` (`resources/views/partials/meet-dialog.blade.php`, included once in the shared layout) provides Promise-based `alert()`/`confirm()`/`prompt()` equivalents styled to match the app, with a red "OK" for destructive confirmations. Every real call site across Chat, Admin, User profile/security, Groups/Communities, Meetings, and Contacts (19 files) was converted from the native, synchronous dialogs to this async, themed one.

---

# Summary

91 implementation changes since the v1.0.0 baseline. IC-087-091 are follow-on fixes within the `1.2.3` batch: ringing-screen UI parity with the call redesign (IC-087), a composer scrollbar fix (IC-088), an admin-configurable delete-for-everyone window (IC-089), One View best-effort leak deterrents (IC-090), and app-wide replacement of native browser dialogs with a themed modal (IC-091). IC-065-086 make up the rest of the `1.2.3` batch: message-action long-press-only trigger (IC-065), One View reply/react + full emoji picker (IC-066), iOS zoom-on-focus and app-wide overscroll-chaining fixes (IC-067), delete-for-everyone now deletes the real file (IC-068), touch support for the meeting whiteboard (IC-069), Admin mobile-table/nav fixes plus guest lock/screen-share (IC-070), WhatsApp-style two-row chat list plus a "New group" entry point (IC-071), auto-growing composer with paste-to-attach (IC-072), per-tab empty states (IC-073), live-updating One View "Opened" status (IC-074), logout relocated into Settings (IC-075), calendar day-popover/event-detail/no-scroll-month-view (IC-076), call-to-meeting escalation via "Add member" (IC-077), shareable meeting join links everywhere (IC-078), the instant-meeting lobby-skip regression fix (IC-079), a cron to auto-end inactive meetings (IC-080), the call UI redesign plus 1:1 screen share and speaker/earpiece routing (IC-081), call history detail/call-back plus missed-call push actions (IC-082), a cron to delete orphaned media (IC-083), background-push delivery marking and the stale-subscription fix (IC-084), fully custom-built media viewers replacing native browser controls (IC-085), and Admin environment-variable management (IC-086). IC-064 (chat list ciphertext leak fix + message ticks/floating actions/attachment picker/in-app camera/voice recorder redesign + composer scroll-pin fix + deploy env-sync) is the `1.2.2` batch. IC-059 (server-managed message encryption, revised mid-batch to drop the original zero-knowledge design - see that entry's own revision note), IC-060 (offline-first chat/contacts, a deliberate reversal of FR-259's original chat-sync exclusion), IC-061 (guest whiteboard support), IC-062 (Firebase Cloud Messaging migration), and IC-063 (full-screen mobile chat + keyboard-aware composer) make up the `1.2.1` batch. IC-001-018 predate the 1.1.0 batch; IC-019-036 make up that batch; IC-037-047 make up the 1.1.1/1.1.2 batches (see their own summary above). IC-048-058 make up the 1.2.0 batch: three bug fixes carried over from a mobile-UI/production-readiness pass (IC-048 input styling, IC-049 phantom meeting tile, IC-050 mobile message-action visibility, IC-051 unarchive no-op), the Account & Security sub-hub with a prominent Add-account entry (IC-052), chat/message multi-select with bulk actions (IC-053) and multi-select Forward (IC-054), two meeting-invite fixes (IC-055 API email matching, IC-056 real reminder delivery), the Google-Meet-style mobile room redesign (IC-057), and the Admin panel icon+label pass (IC-058). Privileged/Super-Access-only mechanics are tracked separately in `Documentation/SUPER.md`, not repeated here.

# Next Section

Future changes append new `IC-` entries here, in ascending order, each naming the legacy chapter(s) it supersedes or extends. Do not edit or renumber existing entries.
