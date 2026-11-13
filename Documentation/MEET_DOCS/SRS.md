# Software Requirements Specification (SRS)

**Version:** 2.0

**Document Type:** Software Requirements Specification

**Priority:** Critical

---

# 1. Authentication Module

This chapter defines how an identity is created, verified, signed in, and signed out of the Meet platform. Beyond the original password/OTP baseline, authentication now includes two-factor authentication, passkeys, Google single sign-on, and the ability to hold more than one account on the same device at once.

## FR-001 - User Registration

A guest registers with a phone number, email address, and password, accepting Terms & Conditions. Laravel validates the request, creates the account, generates a 6-digit email OTP, and redirects the user to Email Verification; the account stays inactive until verification succeeds. Phone number is collected but phone verification remains reserved for a future version.

At the moment a user's profile row is first created, registration automatically splits the single `full_name` field the form collects into separate `first_name`/`last_name` values on `user_profiles`, so Settings → Profile is never blank right after sign-up. A one-off backfill command (and a batched, self-disabling scheduled job, `BackfillProfileNamesBatchJob`, gated by a `profile_backfill_enabled` Setting flag) populated this for every account that existed before the split was introduced, processing bounded batches rather than one blocking query; visiting Settings → Profile also fixes that one account's name immediately as a safety net. Load-test-seeded accounts are populated with first/last name at creation time as well, so this backlog cannot recur.

**Validation Rules:** phone 10-15 digits, unique; email required/unique/RFC-compliant/lowercased; password 8-64 characters requiring upper, lower, number, and special character, with confirmation.

**Business Rules:**
- Email verification is mandatory; the account remains inactive until it completes.
- Maximum OTP verification attempts: 5. Maximum OTP resend attempts: 5. OTP expires after 10 minutes (configurable).
- A newly-registered account's post-verification auto-login is always a remembered session (see FR-004), since that flow has no "Remember me" checkbox of its own to read.

**Database Tables:** `users`, `user_profiles`, `email_otps`, `user_devices`, `user_settings`, `activity_logs`.

## FR-002 - Email Verification

The user enters the received OTP; once validated the account is activated, the user is automatically logged in, and lands on the Chat list (see FR-004). Expired OTPs are rejected; generating a new OTP invalidates all previous ones; five failed attempts temporarily blocks further attempts.

**Database Tables:** `email_otps`, `users`, `activity_logs`.

## FR-003 - Resend OTP

Generates and sends a new OTP with a 60-second cooldown and a maximum of 5 resends per day, both administrator-configurable. The previous OTP becomes invalid immediately.

## FR-004 - Login

Supported methods remain phone number + password and email address + password, plus the newer methods defined in FR-376-FR-378 below. The login form's "Remember me" checkbox defaults to checked. On success the user's device information and login history are recorded and the session lands on the Chat list (`chat.index`) rather than the Dashboard - the Dashboard is unchanged and still reachable from the sidebar (desktop) or the profile page (mobile), it is simply no longer the default destination for login, registration (post-verification), or a bare `/` visit. If the account has Two-Factor Authentication enabled (FR-376), the password check is followed by a code-entry step before a session is actually issued.

**Events:** `UserLoggedIn`.

## FR-005 - Forgot Password

Allows a user to reset their password through email OTP verification, unchanged from the original design.

## FR-006 - Reset Password

Allows a user to securely set a new password using a valid reset token.

## FR-007 - Logout

Terminates the current session on the active device. Logout is an icon-only control (not a text link) and, since a later restructuring, lives inside Settings → Account & Security rather than as a persistent header button - see FR-011 and FR-379.

## FR-008 - Logout All Devices

Invalidates every active session across every device the account is logged into. Where more than one account is linked on the same browser via Multi-Account Login (FR-379), a separate "Logout of all accounts" action clears every linked account's session in one step.

## FR-009 - Session Management

Users can view active devices, log out a specific device, rename trusted devices, and mark a device as trusted.

## FR-010 - Delete Account

The user requests deletion; the account is soft-deleted and its data archived per retention policy, with permanent deletion after a configurable retention period. See FR-381 for the separate, non-destructive Deactivate Account action.

## FR-376 - Two-Factor Authentication (TOTP)

From Settings → Account & Security, a user enables TOTP-based two-factor authentication by scanning a QR code and confirming it with one valid code before it is ever turned on - generating a secret alone never activates it. Eight one-time recovery codes are shown exactly once at enrollment. Once enabled, both web and API login stop at a code-entry screen after the password check succeeds and before a session or token is issued. The code-entry screen offers "Remember this device," which lets that same browser skip the challenge for 30 days by issuing a random device token; only a hash of that token is stored server-side (`trusted_devices`, with an `expires_at` deadline), and the raw token lives solely in the browser's cookie. Settings → Account & Security → "Manage devices that skip the code" lists and can revoke any remembered device early. Remembered-device skipping is web-login only, since a bearer-token API client has no cookie jar to hold it in.

**Database Tables:** `two_factor_secrets` (or equivalent), `trusted_devices`.

## FR-377 - Passkey Authentication (WebAuthn)

Settings → Account & Security, and a "Sign in with a passkey" button on the login page, let a user register and sign in with a device passkey via the official `laravel/passkeys` package. A passkey sign-in deliberately skips the separate Two-Factor Authentication challenge (FR-376): the passkey ceremony itself is possession plus biometric/PIN verification, already a second factor by construction, matching how major providers treat passkeys. This is a web-login-only capability; there is no Sanctum/API equivalent.

## FR-378 - Single Sign-On with Google

A "Continue with Google" button on the login page (via `laravel/socialite`) signs a user in or registers them in one step: it matches an existing account by email automatically on first use, or creates a new, already-verified account. Unlike a passkey, Google sign-in does not skip Two-Factor Authentication - a linked Google account is a separate identity from the app's own account, so 2FA continues to protect it regardless of how the session started. `users.phone` and `users.password` are both nullable at the database level to support these password-less, phone-less accounts; the standard registration form's own validation still requires both as before - only the underlying column constraint was relaxed.

## FR-379 - Multi-Account Login and Switching

A user can log into a second account without disturbing the first one's session, via Settings → Account & Security → "Switch account," "Add account," or the login page's own tiles for an account already held but not currently active on that device. Switching between held accounts requires no re-entered password. This is built entirely on the existing "Remember me" mechanism (`users.remember_token` plus `Auth::loginUsingId()`) rather than a new session or token layer, so a held account is never more trusted than its own remember-me login already was - a real password change or an admin force-logout elsewhere invalidates it here too, automatically. Explicitly logging out of an account forgets it from the switcher (Laravel's own logout rotates that account's `remember_token`). This is a web-login-only capability, for the same reason passkeys and 2FA device-memory are.

## FR-380 - Administrator-Triggered Password Reset with Forced Change

An administrator can set a user's password directly from Admin → Users (see FR-197), optionally marking it temporary. A temporary reset emails the new password to the user and forces them to a dedicated change-password page before they can reach anywhere else in the app on their next login, enforced by a global middleware reading a `users.must_change_password` flag. The target account's other sessions are force-logged-out the same way any other admin account-access action already is.

---

# 2. User Profile & Account Management

This chapter covers the personal profile, privacy controls, password management, device/session visibility, notification and appearance preferences, localization, and the ability to block or report another user. The Settings area that surfaces most of this has grown from a single long page into a WhatsApp-style hub of category pages.

## FR-011 - User Profile

Authenticated users manage a profile picture, full name, optional username, phone number, email (verification required to change), date of birth, gender, bio, timezone, preferred language, country, and profile visibility. Settings' Profile page now actually renders display name, date-of-birth, and gender fields, alongside the previously-existing name/photo/bio controls - fields the API already accepted before but the page never surfaced.

Settings itself is organized as a WhatsApp-style hub: a top-level list of categories (Profile, Preferences, Notifications, Chat defaults, Privacy, Account & Security), each its own page, rather than one long scrolling form. Account & Security is further split into its own sub-hub - Change password, Two-factor authentication, Passkeys, Connected accounts, Active devices, Switch account, Add account, and Deactivate account - each a dedicated page reached from an icon+label+chevron list, with "Add account" reachable in one tap directly from the hub rather than nested inside "Switch account." Logout and "Logout of all accounts" (FR-379) live here too, as their own card, separate from Deactivate account.

**Business Rules:**
- Email change requires Email OTP verification; profile photos are optimized automatically; maximum image size and formats are administrator-configurable.

**Database Tables:** `users`, `user_profiles`, `activity_logs`.

## FR-012 - Profile Privacy

Users configure visibility (Everyone / Contacts Only / Nobody) for Last Seen, Online Status, Profile Photo, About/Bio, Phone Number, and Email. These settings are enforced consistently across Contacts and Chat - an earlier gap where `last_seen_visibility` was enforced in the Contacts API but not in the Chat conversation API (both the polled endpoint and the initial page render) has been closed; a user who sets their last-seen to "nobody" or "contacts" no longer leaks live presence through either surface.

**Database Tables:** `user_privacy_settings`.

## FR-013 - Change Password

A real, dedicated web form (Settings → Account & Security → Change password) - previously this action existed only as an API endpoint with no browsable page. Requires the current password, validates the new one against policy, and can invalidate other sessions.

## FR-014 - Device Management

Lists device name, browser, OS, IP, last activity, login time, and marks the current device; supports logout, rename, and trust actions, with confirmation required before removing the current device.

**Database Tables:** `user_devices`, `user_sessions`.

## FR-015 - Active Sessions

Displays current and other active sessions with start time, last activity, IP, browser, and device, with per-session or logout-all-sessions actions.

**Database Tables:** `user_sessions`.

## FR-016 - Notification Preferences

Settings → Notifications holds per-type toggles - `notify_messages`, `notify_calls`, `notify_meetings`, `notify_contact_requests` - that now do real work: they gate the nav-badge counts (FR-396), the incoming-call ringtone, and every push notification trigger (FR-397), not merely stored preferences with no effect. Turning off every toggle at once also unsubscribes the device's push registration for real, both the database row and (via a genuine `PushManager.unsubscribe()` call) the underlying browser subscription; turning off a single toggle alone leaves the shared subscription in place, since one device subscription serves every notification type. Settings → Notifications → "Manage push notification devices" (FR-398) lists and revokes a user's own subscribed devices.

**Database Tables:** `notification_preferences`.

## FR-017 - Theme & Appearance

Light/Dark/System theme, font size, chat bubble style, compact mode, animation level, and high-contrast mode, synchronized across a user's logged-in devices. Settings → Chat defaults now holds a default wallpaper and default font size, applied whenever a **new** conversation's `chat_settings` row is first created; an existing conversation is unaffected and keeps whatever it already had.

**Database Tables:** `user_preferences`.

## FR-018 - Language & Localization

Language selection, time format, date format, timezone, and number format; Settings → Preferences now renders a real timezone picker, and the single `auto_download_media` toggle was split into three independent toggles (photos, videos, documents).

**Database Tables:** `user_preferences`.

## FR-019 - Block User

Blocking removes a user's ability to message, voice call, video call, or invite the blocking user to meetings, and restricts presence visibility per privacy rules. Supports block, unblock, and viewing the block list.

**Database Tables:** `blocked_users`.

## FR-020 - Report User

Allows reporting a user for spam, harassment, fake profile, inappropriate content, scam, or another reason, with an optional description; the report lands in the Admin Moderation Dashboard for review, warning, suspension, or ban.

**Database Tables:** `user_reports`, `report_categories`, `moderation_actions`.

## FR-381 - Deactivate Account (Self-Service)

Settings → Account & Security offers a self-service "Deactivate account" action, distinct from the destructive Delete Account flow (FR-010): it re-confirms the current password, signs the account out everywhere, and soft-deletes it in the same restorable way an administrator's own "Delete" action on a user already does - an administrator can restore it later exactly as they would any other soft-deleted account.

---

# 3. Contacts & Social Graph

The Contacts module discovers users, manages relationships, tracks presence, and prepares the communication layer feeding Chat, Calls, and Meetings.

## FR-021 - Contact Discovery

Users discover other registered users by phone number, email, username, or (configurably) display name; QR code and device contact-sync remain reserved for a future version. Only active, non-suspended users appear, and results respect profile visibility.

Pending incoming contact requests are surfaced two ways beyond the standalone `/contacts/requests` page (kept as a secondary deep link): a dismissible-but-persistent banner directly on the chat list with inline Accept/Decline, and a compact icon badge in the chat list's own header (see FR-396).

**Database Tables:** `users`, `user_profiles`, `user_privacy_settings`.

## FR-022 - Global User Search

Search by name, username, phone (email is administrator-configurable), with instant search, suggestions, recent-search history, and history clearing. Company/organization search remain future-reserved.

## FR-023 - Contact Relationship

Maintains relationship state - Unknown, Contact, Favorite, Blocked, Muted, Archived - bidirectionally where applicable.

**Database Tables:** `user_relationships`.

## FR-024 - Favorite Contacts

Add/remove favorite, filter by favorite, and favorites sort first.

## FR-025 - Contact Profile

Displays another user's public profile - picture, name, about, last seen, online status, mutual groups, shared media/meeting counts - governed by that user's privacy settings.

## FR-026 - Contact Actions

Message, voice call, video call, schedule meeting, share contact, favorite, block, report, and mute, each launching the correct module.

## FR-027 - Contact Invitations

Invite a non-registered user by email or share link (SMS/QR remain future); the recipient receives a registration link.

**Database Tables:** `invitations`.

## FR-028 - Contact Synchronization (Future-Ready)

Remains reserved for future device/provider contact sync (Google, iCloud, Outlook, LDAP), requiring explicit user permission when built.

## FR-029 - Blocked Contacts

View, search, and unblock; a blocked contact cannot message, call, or invite to meetings, and presence is governed by privacy rules.

**Database Tables:** `blocked_users`.

## FR-030 - Muted Contacts

Mute for 8 hours, 1 week, or always; muting affects notifications only, messages still deliver.

## FR-031 - Archived Contacts

Archive/restore; archived contacts remain searchable.

## FR-032 - Presence Information

Presence states (Online, Offline, Away, Busy, Invisible, Typing, Recording, Uploading, Downloading, In Voice/Video Call, In Meeting, Screen Sharing) update through the application's polling engine and always respect the viewer's privacy permission for the subject. Where the raw state is Offline, the UI shows a relative "last seen X ago" rather than the bare word "Offline" wherever presence is displayed - `last_seen_at` was already tracked but previously left unrendered in most places; this is a display change only, not a change to the underlying `last_seen_visibility` permission model.

**Database Tables:** `user_presence`.

## FR-033 - User Availability

Incoming call behavior depends on availability state (Available, Busy, In Call, In Meeting, Do Not Disturb).

## FR-034 - Recently Contacted Users

Recently messaged, called, met, and file-shared contacts, sorted by most recent activity.

## FR-035 - Contact Activity Summary

Messages exchanged, voice/video calls, meetings, and shared files/photos/videos/documents per contact; AI relationship insights remain future-reserved.

---

# 4. Chat Engine

The Chat Engine is the core communication module. It began as a Laravel-polling-based 1:1/group messaging system and has since grown message-level encryption, offline support, disappearing messages, broadcast lists, export, and a full WhatsApp-parity messaging UX (message ticks, floating message actions, in-app camera, custom voice recorder).

## FR-036 - Start Conversation

Selecting a contact and clicking Message opens an existing conversation or creates a new one.

**Database Tables:** `conversations`, `conversation_participants`.

## FR-037 - Send Text Message

UTF-8 and emoji support, unlimited line breaks, URL detection; empty messages are rejected, maximum length is configurable, and HTML input is sanitized. Message bodies are transparently encrypted per FR-386.

**Database Tables:** `messages`, `message_status`.

## FR-038 - Message Status

States: Sending, Sent, Delivered, Read, Failed, Deleted, shown to the recipient as ticks on each bubble (grey single/double tick, amber/gold double tick for read - a deliberate color choice distinct from the blue commonly used elsewhere). `message_status` tracks per-recipient delivered/read state; `Message::aggregateDeliveryStatus()` collapses every recipient's state into one tick shown only to the sender - a group message shows "read" only once every recipient has read it. "Delivered" advances at several points: opening a specific thread, merely syncing the chat list, and - for a message pushed to a fully backgrounded or closed app - the service worker's own push handler calling a dedicated delivered-marking endpoint with no page open at all, so delivery no longer depends solely on the recipient's client actively polling. A sender-only "Info" view lists the exact per-recipient delivered/read timestamps for a message. Read receipts continue to respect the recipient's privacy setting.

## FR-039 - Typing Indicator

Typing, Recording Audio, and Uploading File states, disappearing automatically after a timeout, updated via lightweight polling.

## FR-040 - Read Receipts

Notifies the sender when a message is read, unless the recipient has disabled read receipts in Privacy Settings.

## FR-041 - Edit Message

Editing within a configurable time limit, marked with an "Edited" indicator; prior versions may be retained for audit purposes.

## FR-042 - Delete Message

Delete for Me and Delete for Everyone, the latter time-limited (configurable). "Delete for Everyone" now also deletes the underlying uploaded file from disk - not just the message row - once nothing else still references that exact media record (a forwarded copy of the same file is checked for and preserved). Both the chat list and an open conversation support a "Select" bulk mode: a checkbox per conversation/message plus a bulk action bar. In the chat list this drives Archive/Unarchive/Mute/Delete (Delete reuses the conversation-leave action, one request per selected conversation); in a thread it drives Star/Forward/Delete (delete-for-me only), with bulk Star only touching messages not already starred so a mixed selection ends up fully starred rather than toggling already-starred ones off.

## FR-043 - Reply to Message

Reply to any supported message type with a quoted preview; tapping the quote navigates to the original. A quoted reply's own preview is separately re-encrypted client-side under FR-386.

## FR-044 - Forward Message

Forward text, images, videos, documents, and voice notes, marked "Forwarded." Forward accepts a multi-selection: picking a destination conversation forwards every selected message to it in ascending send order, one request per message against the same single-message forward endpoint. Forwarding an encrypted message requires the client to decrypt then re-encrypt for the destination conversation's own key.

## FR-045 - Message Reactions

Emoji reactions with a visible total count; a user may change or remove their own reaction, including by tapping an already-active reaction to un-react (previously the un-react endpoint existed but nothing called it from the UI). A "more" button opens a full categorized emoji picker (eight categories, several hundred emoji); the 24 most-recently-used are remembered per browser and shown first.

## FR-046 - Pin Message

Pin/unpin and view pinned messages, up to a configurable maximum per conversation.

## FR-047 - Star Message

Star/unstar a message for private personal reference. Chat → Starred is a real dedicated page listing everything starred, with inline unstar - the underlying star action/API predates this view, but no page previously existed to browse it.

## FR-048 - Search Messages

Search within the current conversation or across all conversations, filtered by text, sender, date, media type, or file name.

## FR-049 - Archive Conversation

Archive/restore, including bulk archive; archived conversations remain searchable. Archiving and unarchiving are both real: the conversation list query correctly excludes a conversation whose membership has `left_at` set (an earlier version never filtered on it, so "leave" was inert), and the in-conversation kebab menu's own archive/unarchive control now reflects the viewer's actual archived state instead of a hardcoded "always archive" form. Restarting a chat with someone previously left/archived correctly un-hides it again.

## FR-050 - Mute Conversation

Mute for 8 hours, 1 week, or always; muting suppresses notifications only.

## FR-051 - Chat Background

Per-conversation wallpaper, chosen from six default colors, set from the chat header's "⋯" kebab menu (which also holds Mute and Archive, consolidated out of separate header buttons). Dark mode remains supported globally.

## FR-052 - Conversation Information

Shows participants, shared media/files/links counts, and creation date. Every conversation additionally displays a short banner (FR-382) disclosing the actual privacy model in plain language.

## FR-053 - Chat Polling Engine

Open conversation, conversation list, and notification polling intervals remain administrator-configurable (defaults ~700-1000ms, 5-10s, and 3s respectively), returning only incremental changes since the last sync.

## FR-054 - Chat Activity Log

Logs message sent/edited/deleted, file shared, One View opened, user blocked, and conversation archived events, with administrator-configurable retention.

## FR-382 - Chat Privacy Disclosure Banner

Every conversation shows a short banner at the top of its message list stating that messages are private to the conversation's participants. It deliberately does not claim end-to-end encryption in a way that would overstate the platform's actual model - see FR-386 for what encryption the platform genuinely provides.

## FR-383 - Disappearing Messages

A per-conversation setting (Off / 24 hours / 7 days / 90 days), set from the chat header's kebab menu. `conversations.disappearing_messages_seconds` drives an `expires_at` computed on each message at send time; a scheduled task (not a queued job - see Chapter 18) runs every five minutes and soft-deletes expired messages for everyone, the same way "Delete for everyone" already does.

## FR-384 - Broadcast Lists

Chat → Broadcast lists sends one authored message to a named list of contacts at once. Each recipient receives it as an ordinary 1:1 message in their own chat with the sender; replies stay private between sender and that one recipient and are never visible to the rest of the list.

## FR-385 - Chat Export

A chat or group header's kebab menu offers "Export chat," downloading a zip containing a full CSV transcript (sender, timestamp, message) plus every attachment still on the server, using the same `fputcsv`/`ZipArchive` approach the group export/archive features already use. Already-consumed One View media and messages the exporting user deleted "for me" are excluded from their own export. Where the conversation is encrypted (FR-386), the exported transcript shows the same placeholder text a server-rendered page would show for an undecryptable body, never raw ciphertext.

## FR-386 - Server-Managed Conversation Encryption

Text message bodies are encrypted at rest and in transit between client and server, using a deliberately server-managed trust model rather than a zero-knowledge one: the application itself generates and holds every conversation's key material, not a scheme where only end-user devices ever hold key material, and there is no per-user keypair or password-derived unlock step - encryption is fully automatic from the user's perspective.

Each conversation is assigned one random AES-256-GCM key per "epoch," generated server-side and stored encrypted at rest; a new epoch is issued transparently whenever one is needed - none exists yet, or conversation membership has changed since the last one. Any current, authenticated member of a conversation may fetch a given epoch's key material, checked at fetch time against live membership rather than against who the key happened to be issued for; a membership change rotates to a new epoch so a removed member's client cannot decrypt anything sent afterward. The client caches fetched key material in IndexedDB so it survives reloads. Once every current member of an existing plaintext conversation has key access, the next time any of them opens it, its history is encrypted in place automatically, in bounded batches.

Groups larger than a configurable member cap (default 256) remain plaintext server-side, shown with a visible "Not end-to-end encrypted (too many members)" indicator, matching how mainstream messengers cap group encryption rather than attempting per-recipient key-wrapping at broadcast scale. Regular media attachments (as opposed to message text) are not encrypted under this scheme; only text bodies are. Push notification previews for an encrypted conversation fall back to a generic "New message," since the server cannot decrypt to build a real preview - except on Android Chrome, the only browser supporting inline-reply notification actions, where the service worker itself can encrypt and send a typed reply directly from the notification with no page open, authenticated via the same-origin session cookie. Every server-rendered page that shows message content (for example, the Starred view) shows a placeholder for an encrypted body rather than raw ciphertext, matching Chat Export's treatment.

**Database Tables:** `conversation_keys`.

## FR-387 - Offline-First Chat & Contacts

Chat and Contacts remain usable, in a bounded way, without connectivity. The client caches the conversation list, roughly the last 200 messages per conversation (already decrypted for encrypted conversations), and the contacts list in IndexedDB; the Chat list, an open conversation, and Contacts all fall back to this cache when a live fetch fails offline. Composing a plain text message or reply while offline shows an optimistic "Waiting to send…" bubble immediately and queues the send; once connectivity returns, each conversation's own page drains and re-sends its own queued actions through the normal (encryption-aware) send path - a queued action for a conversation whose page is not currently open stays queued until that conversation is next opened while online, a disclosed, bounded scope rather than a silent gap. Attachments, calls, meetings, and file uploads remain genuinely network-only; queuing an arbitrary binary upload indefinitely, or running a live WebRTC session with no connection, is not possible by nature, not a scope choice. The service worker also runtime-caches the Chat, Contacts, and Groups HTML shells so they render from the last successful visit when offline, the same pattern already used for the login page; Calls and Meetings are deliberately excluded from this caching, since neither can function offline regardless. See also FR-259 in Chapter 16.

---

# 5. Media & File Sharing Engine

Shared file-transfer infrastructure used by Chat, Group Chat, and Meetings, supporting chunked uploads for large files while remaining compatible with Laravel on cPanel.

## FR-055 - Supported File Types

Images (JPG/PNG/WEBP/GIF), videos (MP4/MOV/AVI/MKV/WEBM), documents (PDF/DOC/DOCX/XLS/XLSX/PPT/PPTX/TXT/ZIP/RAR/7Z), audio (MP3/WAV/AAC/OGG/M4A); further types are administrator-configurable.

## FR-056 - Chunk Upload Engine

Chunked upload with resume, progress, retry, automatic merge, and verification; chunk size and maximum upload size are configurable, and temporary/corrupted chunks are cleaned automatically.

**Database Tables:** `uploads`, `upload_chunks`.

## FR-057 - Resume Upload

An interrupted upload resumes from its already-uploaded chunks and assembles the file once the final chunk arrives.

## FR-058 - Secure File Storage

Files are stored outside the public web root under `storage/{avatars,images,videos,audio,documents,voice-notes,one-view,meetings,temporary,chunks,archive,thumbnails}`; every download passes through an authorization check.

## FR-059 - File Preview

Images, PDF, audio, video, and text preview in place; unsupported types prompt for download. Every audio, video, and image a message can contain now uses the app's own custom playback UI - a real play/pause button, seek bar, and time label built around a controls-less `<audio>`/`<video>` element (audio matches a compact voice-note-bar layout with no volume control; video adds mute and fullscreen) - rather than the browser's native `<video controls>`/`<audio controls>` or native image viewer; see FR-388.

## FR-060 - File Download

Secure, resumable, progress-tracked, and logged; every download requires authorization.

## FR-061 - One View Media

See Chapter 20 for the complete One View specification (display, expiration, Super Access, and the current sender/recipient behavior).

**Database Tables:** `one_view_files`, `one_view_logs`.

## FR-062 - Media Gallery

Photos, videos, documents, audio, and links, with grid/list view, search, filter, and sort.

## FR-063 - File Search

Filter by file name, extension, sender, date, size, or conversation.

## FR-064 - Voice Notes

Record, pause, resume, cancel, and play back with a waveform and duration display. Recording now replaces the entire composer row with a dedicated bar: a live waveform sampled from the real microphone stream (not a canned animation), an elapsed-time counter, real pause/resume, a trash button that discards outright, and a send button that stops and sends immediately with no separate preview/confirm step - unlike every other attachment type. Playback speed control (1x/1.5x/2x) remains reserved for a future version.

## FR-065 - File Expiration

Administrator-configurable expiration (Never / 24h / 7d / 30d / 90d / Custom), enforced by the Laravel Scheduler. A dedicated scheduled task additionally deletes orphaned media on a recurring basis: any `Media` row older than a day referenced by nothing at all - no message, no group media library entry, no whiteboard export - covering both pre-existing delete-for-everyone residue (FR-042) and an uploaded-but-never-sent attachment (upload and message-send are separate API calls, so an abandoned upload is a real, reachable orphan). A View Once file stuck "viewed" but never actually removed past a short grace window is also swept here as a safety net; the primary removal mechanism remains the synchronous delete that happens inside the stream response serving it (Chapter 20).

## FR-066 - File Quotas

Per-user, per-group, and (future) per-organization storage quotas, administrator-configurable.

## FR-067 - Storage Providers

Local storage in Version 1; cloud providers (S3, R2, Spaces, Wasabi, GCS, Azure) remain future-ready and switchable from the Admin Panel without code changes.

## FR-068 - Upload Security

MIME and extension verification, file size validation, signed download URLs, authorization checks, rate limiting, and activity logging; duplicate detection and virus scanning remain future-reserved.

## FR-069 - Storage Analytics

Total files, total size, upload/download trends, One View usage, storage consumption, and cleanup statistics.

## FR-070 - Media Retention

Administrator-configurable retention policy, archive policy, cleanup schedule, One View retention, temporary file retention, and chunk cleanup interval.

## FR-388 - Custom In-App Media Viewers

Every video, audio, and image a message can contain uses the platform's own playback UI rather than a browser's native media controls or native image viewer. `createAudioPlayer()`/`createVideoPlayer()` build the play/pause, seek, and time-label controls described in FR-059; a lightbox (`openImageViewer()`) opens on tapping any regular image bubble, which previously had no dedicated viewer at all. The One View full-screen modal (Chapter 20) uses the same custom players rather than native-controls elements.

---

# 6. Group Chat Engine

Multi-user conversation spaces with role-based permissions, moderation, and (now) a group-of-groups container above them called Communities.

## FR-071 - Create Group

Select participants, name the group, optionally upload a group image; minimum 2 members, maximum administrator-configurable. A "New group" entry point is available directly from the chat list's "+" button, alongside the existing Contacts-first path.

**Database Tables:** `groups`, `group_members`.

## FR-072 - Group Roles

Owner, Administrator, optional Moderator, and Member, with the expected permission tiers (delete/transfer/promote for Owner; add/remove/pin/manage-requests for Administrator; send/share/call/join for Member).

## FR-073 - Group Information

Name, image, description, creation date, member count, and shared media/files/links.

## FR-074 - Edit Group

Authorized users update name, image, description, and rules; all changes are logged in group activity.

## FR-075 - Add Members

Search existing users or share an invite link; duplicate members are rejected and maximum size is enforced.

## FR-076 - Remove Members

A removed member loses access immediately; post-removal history visibility is configurable and the removal is logged.

## FR-077 - Leave Group

A member leaves voluntarily; if the owner leaves, ownership must be transferred first.

## FR-078 - Delete Group

Owner-only, with confirmation; data and files follow configured retention policy.

## FR-079 - Group Permissions

Administrator-configurable toggles: admin-only messaging, admin-only info edits, admin-only invites, admin-only pinning, member reactions, member file sharing.

## FR-080 - Group Announcements

Admin-only posting with member read access and optional reactions.

## FR-081 - Group Mentions

@User, @Admins, and (configurable) @Everyone, respecting recipient notification settings.

## FR-082 - Group Polls

Single- or multiple-choice polls with a closing time and visible results; anonymous polling remains future-reserved.

## FR-083 - Group Events

System messages for member joined/left/removed, group created/renamed/re-imaged, and ownership transferred.

## FR-084 - Group Media Gallery

Photos, videos, documents, audio, voice notes, and links, filterable by date, sender, or type.

## FR-085 - Group Activity Log

Records role changes, member additions/removals, setting changes, deletion, and ownership transfer, with administrator-configurable retention.

## FR-389 - Communities

Groups → Communities sits above regular Groups as a group-of-groups container. Creating a community auto-creates a default "Announcements" group; the owner attaches any other group they own (a group belongs to at most one community at a time) so every member of every attached group can see the community's announcements. A community owner, or an administrator of the community's own Announcements group - delegated the same way any Group administrator already is, reusing the existing group role/permission system rather than duplicating it - can attach groups and post announcements, not only the community's original owner. Any attached group except the Announcements group itself (which cannot be detached) may be removed by the community owner/administrator or by that group's own current owner. "Delete community" is a soft delete, owner-only.

---
# 7. Voice Call Engine

Secure one-to-one voice communication over WebRTC, signaled through polling-based Laravel APIs rather than a dedicated real-time server.

## FR-086 - Start Voice Call

A caller opens a conversation and clicks Voice Call; the browser requests microphone permission, a WebRTC peer connection is created, an SDP offer is generated and delivered to the recipient through the signaling API, and the recipient is notified.

**Database Tables:** `calls`, `call_participants`, `signaling_messages`, `activity_logs`.

## FR-087 - Incoming Call

Displays caller name/avatar with Accept/Reject and ringtone mute; unanswered calls time out after a configurable period (default 30 seconds).

## FR-088 - Accept Call

Accepting requests microphone access if needed, generates an SDP answer, completes ICE candidate exchange, and starts the call timer.

## FR-089 - Reject Call

The caller sees a "Rejected" status; the call is recorded in history.

## FR-090 - Missed Call

A call becomes Missed on timeout, non-answer, or caller cancellation before pickup, generating an in-app notification and (configurably) an email. A missed call also generates a dedicated push notification of its own (see FR-393) - the original incoming-call ring notification is long gone from the notification shade by the time a call is actually recorded as missed.

## FR-091 - Busy State

Prevents a second simultaneous call while already in a voice/video call, in a meeting, or in Do Not Disturb.

## FR-092 - Call Controls

Mute/unmute, end call, speaker toggle, and audio device selection; hold and merge calls remain future-reserved. See FR-391 for screen sharing and output routing added to the 1:1 call surface.

## FR-093 - Call Timer

Live elapsed timer plus recorded start/end time.

**Database Tables:** `call_history`.

## FR-094 - Call History

Incoming/outgoing/missed/rejected/cancelled/completed records, filterable by date, contact, duration, and status. Tapping a call history row opens a detail popup with name, direction, duration, and timestamp, and a Call Back action (see FR-393).

## FR-095 - Reconnect Call

Attempts reconnection on interruption, restoring the media stream if successful or ending the call after a configurable timeout.

## FR-096 - Signaling Engine

SDP offers/answers and ICE candidates are exchanged through Laravel APIs and polling, with no Node.js dependency; only authorized participants may exchange signaling data.

## FR-097 - STUN/TURN Configuration

Defaults to Google's public STUN servers; multiple STUN/TURN servers with credentials remain administrator-configurable without code changes.

## FR-098 - Call Quality Monitoring

Tracks latency, packet loss, jitter, connection type, and audio codec; user-visible quality indicators remain future-reserved.

## FR-099 - Call Security

HTTPS, secure WebRTC, permission validation, authorization checks, and activity logging; no media stream passes through the Laravel application server, only signaling data.

## FR-100 - Voice Call Lifecycle

States: Initiated, Ringing, Connecting, Connected, Muted, Reconnecting, Ended, Rejected, Missed, Failed - every transition is logged.

## FR-390 - Call-to-Meeting Escalation

An "Add member" action during an active 1:1 call escalates it into a full meeting rather than duplicating the Meeting Engine's already-built N-way WebRTC mesh (lock, screen share, reactions, add-via-link) inside the pair-only Calls module. It creates an instant meeting, directly admits both original call parties - bypassing the lobby, since they are already mutually engaged in a live call and there is no genuine "waiting to be let in" moment to model - ends the original call, and redirects both parties into the full meeting room. The call record's `escalated_to_meeting_id` links to the resulting meeting; the party who did not tap "Add member" is redirected automatically once their own call-status poll notices the escalation.

## FR-391 - Screen Sharing & Speaker/Earpiece Routing for 1:1 Calls

A voice or video 1:1 call supports screen sharing, mirroring the capability meetings already had. The local video sender's transceiver is pre-provisioned at call setup (present with no track for a voice call) specifically so starting or stopping a share never requires SDP renegotiation; a dedicated `screen-share` signal (reusing the existing offer/answer/reaction signaling channel) tells the other party explicitly when sharing starts or stops, since a voice call's video track appearing has no other reliable meaning. Starting a screen share during a voice call never turns the local camera on. Where the browser supports it, calls also offer speaker/earpiece output routing via `HTMLMediaElement.setSinkId()`. The call control layout itself is a WhatsApp-style icon+label two-row grid (Speaker/Camera/Mute, then More/Share/End); a video call is full-screen with the self-view picture-in-picture shown uncropped.

## FR-392 - Inline Call Log Entries in Chat

A completed or missed 1:1 call logs its own centered, system-style row into the chat conversation it was started from - "Voice call · 12:34" for one that connected, "Missed voice/video call" for one that did not - the same way a call history entry appears inline in a conversation in mainstream messaging apps. This relies on every call being linked to its originating conversation (`calls.conversation_id`).

## FR-393 - Call History Detail, Call Back & Missed-Call Push Actions

Tapping a call history row opens a detail popup (name, direction, duration, timestamp) with a Call Back action for voice or video, reusing the same call-start mechanism the in-thread call buttons use. A missed call's dedicated push notification (FR-090) offers a "Call back" action, which opens or focuses a client window and auto-starts the call once a page exists to start it from, and - when the missed call had an associated conversation - a "Message" quick-reply action identical to a chat message notification's own.

---

# 8. Video Call Engine

Extends the Voice Call Engine with real-time video over WebRTC, using the same polling-based signaling architecture.

## FR-101 - Start Video Call

Requests camera and microphone permission, creates a WebRTC peer connection, and delivers an SDP offer through the signaling API; the recipient sees an incoming video call notification.

**Database Tables:** `calls`, `call_participants`, `signaling_messages`, `activity_logs`.

## FR-102 - Incoming Video Call

Caller name/avatar, optional live camera preview, Accept/Reject, ringtone mute; timeout defaults to 30 seconds, administrator-configurable.

## FR-103 - Camera Management

Enable/disable, front/rear switch, and preview; permission is requested only when required.

## FR-104 - Microphone Management

Mute/unmute and input device selection/display, synchronized between participants.

## FR-105 - Video Layout

Full screen, split screen, picture-in-picture, and floating local preview, adapting automatically to device orientation. On mobile, the call surface is full-screen, Google-Meet-style, from the moment a call is initiated through connection to end - there is no separate, differently-styled "ringing" screen.

## FR-106 - Screen Sharing

Share entire screen, browser tab, or application window (subject to browser support); only one active share per 1:1 call. See FR-391 for the mechanism enabling this on voice calls as well as video.

## FR-107 - Video Quality

Auto/Low/Medium/High/HD, adjusting automatically to network conditions; default quality is administrator-configurable.

## FR-108 - Device Selection

Built-in/external/USB camera; built-in/external/Bluetooth microphone; speaker/headphone/Bluetooth output.

## FR-109 - Connection Monitoring

Latency, packet loss, jitter, connection type, resolution, FPS, and bitrate.

## FR-110 - Adaptive Video

Poor network conditions reduce resolution, then bitrate, while maintaining audio priority.

## FR-111 - Reconnection

On network loss, ICE candidates are re-exchanged and video is restored to continue the call, within a configurable maximum reconnect duration.

## FR-112 - Call Controls

Camera enable/disable, mute/unmute, screen share, fullscreen, end call, camera switch, and device selection.

## FR-113 - Picture in Picture

Enter/exit PiP for continued viewing while multitasking; OS-level PiP remains future-reserved where supported.

## FR-114 - Background Processing

Audio continues where the browser supports it; video behavior depends on browser restrictions, with the user notified when affected.

## FR-115 - Call History

Records caller, receiver, duration, start/end time, resolution, and status.

**Database Tables:** `call_history`.

## FR-116 - Network Recovery

States: Connected, Weak Network, Reconnecting, Connection Restored, Connection Failed, with clear user-facing status updates.

## FR-117 - Video Security

HTTPS mandatory, secure WebRTC, authentication, authorization before signaling, and activity logging; media streams never pass through the application server.

## FR-118 - Future Enhancements

Background blur, virtual backgrounds, beauty filters, noise suppression, live captions, and AI framing/eye-contact correction remain architecturally anticipated but unbuilt.

## FR-119 - Administration

Administrator-configurable maximum/default resolution, call timeout, STUN/TURN servers, maximum call duration, logging level, allowed devices, and screen-share permissions.

## FR-120 - Video Call Lifecycle

States: Initiated, Ringing, Connecting, Camera Initializing, Connected, Screen Sharing, Reconnecting, Ended, Rejected, Missed, Failed - every transition logged, retained per configured policy.

---

# 9. Meeting Engine

Real-time multi-party collaboration over WebRTC: instant and scheduled meetings, guest access, participant management, screen sharing, whiteboard, meeting chat, and host controls.

## FR-121 - Start Instant Meeting

Clicking "New Meeting" generates a meeting ID, creates the room, and produces an invite link. The organizer now goes through the same lobby as any other participant - device preview, permission prompt, and an explicit "Join meeting" - rather than being auto-added as a participant the instant the meeting is created; auto-joining the organizer immediately made their very first page load indistinguishable from "refreshed mid-call, rejoin automatically," dropping them straight into the room with no camera/mic prompt, unlike everyone else. The refresh-rejoin behavior itself is unchanged and still applies correctly once someone has actually joined.

## FR-122 - Schedule Meeting

Title, optional description, date, start time, duration, timezone, participants, and optional password; recurring meetings remain future-ready. Only verified users may schedule meetings. See Chapter 11 for the full scheduling/calendar experience.

## FR-123 - Meeting Invitation

Invites participants by email, internal notification, or share link, including meeting name, date/time/duration, organizer, join link, meeting ID, and password if enabled. A copyable `/j/{code}` join link is now shown everywhere a meeting appears - the host's own lobby/room view, each row in the Meetings list, and the calendar event-detail popup - not only inside a guest's own room as before.

## FR-124 - ICS Calendar Invitation

See Chapter 11 (FR-167) for the current, corrected ICS generation behavior.

**Database Tables:** `meetings`, `meeting_invitations`.

## FR-125 - Join Meeting

Join via link, meeting ID, calendar invitation, dashboard, or upcoming meetings list, validated against meeting existence, active status, authorization, and password if enabled. A guest joining by link can now submit a password on the guest-landing form itself - previously that field did not exist there, making a password-protected meeting effectively unjoinable via a guest link.

## FR-126 - Guest Mode

See Chapter 19 for the complete, current Guest Mode specification, including guest-created meetings (FR-402).

## FR-127 - Waiting Room

The schedule-meeting form presents "Who can join" as an explicit direct-vs-host-approval choice rather than an unlabeled checkbox, with an administrator-configurable default. The host can admit, reject, admit all, or remove later; waiting room can be toggled per meeting or globally.

## FR-128 - Participant Management

Host controls: admit, remove, mute, disable camera (where browser permissions allow), make/revoke co-host, lock meeting. A participant's tile is now reliably removed once they are genuinely gone: a heartbeat (`last_seen_at`, refreshed on every roster poll) lets the roster the live call UI reads filter out anyone not heartbeated within a configurable staleness window (default 30 seconds) - closing a tab, crashing, or losing network no longer leaves a phantom video tile indefinitely. A `pagehide` handler best-effort notifies the server via `navigator.sendBeacon()` on tab close so the common case is instant rather than waiting out the staleness window. The capacity count and the "mark everyone left" sweep on meeting end deliberately still use the raw participant record rather than the staleness filter, since those two purposes need the unfiltered picture.

## FR-129 - Meeting Chat

Public chat with emoji, file sharing, reply, reactions, and search; retention is configurable.

## FR-130 - Screen Sharing

Entire screen, browser tab, or application window; host-only or all-participants, administrator/host configurable.

## FR-131 - Whiteboard Integration

See Chapter 10 for the complete Whiteboard specification, including guest access parity.

## FR-132 - Raise Hand

Raise/lower hand with a visible queue.

## FR-133 - Meeting Controls

End for everyone, leave, transfer host, lock meeting, and enable/disable chat, screen share, and whiteboard. A guest who is the meeting's own host or co-host (as in a guest-created meeting, FR-402) now has the same Lock and Share Screen controls as a registered host, via guest-scoped equivalents of the registered-user endpoints.

## FR-134 - Meeting Lifecycle

States: Scheduled, Waiting, Starting, Active, Locked, Ending, Completed, Cancelled - every transition logged. See FR-394 for automatic transitions out of Active for an inactive or abandoned meeting.

## FR-135 - Meeting History

Records title, organizer, duration, participants, join/leave time, and status.

**Database Tables:** `meeting_history`, `meeting_participants`.

## FR-136 - Meeting Notifications

Created, reminder, participant joined, cancelled, and updated events, delivered by email and in-app, and now also by real push notification (Chapter 12).

## FR-137 - Meeting Permissions

Administrator-configurable maximum participants, guest access, waiting room, whiteboard, chat, screen share, and file sharing; recording remains future-reserved.

## FR-138 - Meeting Security

HTTPS, authenticated host, secure join tokens, authorization validation, activity logging, optional signed links, and password-protected meetings - passwords are hashed rather than stored or compared in plaintext.

## FR-139 - Future-Ready Features

Recording, breakout rooms, live captions, AI meeting summary/notes, live translation, polls, Q&A, and attendance reports remain architecturally anticipated, unbuilt.

## FR-140 - Administration

Administrator-configurable default/guest meeting duration, waiting room default, maximum participants, screen share/whiteboard availability, STUN/TURN servers, reminder timing, ICS/email templates, cleanup policies, retention, and logging level. `settings.meetings.guest_creation_enabled` and `settings.meetings.default_waiting_room_enabled` are among these.

## FR-394 - Automatic Cleanup of Inactive and Abandoned Meetings

A scheduled task ends any Active or Locked meeting that has had nobody genuinely present - using the same staleness/heartbeat definition the live roster polls on (FR-128), not a raw departure timestamp - for a full five minutes, with a brief grace window after the last person leaves so a meeting about to be rejoined is not killed prematurely. This covers both a lobby nobody ever entered (reachable now that the organizer goes through the lobby too, FR-121) and a meeting everyone has since left. A guest-created meeting specifically (FR-402) has no authenticated organizer and so could never previously reach "ended" through the normal host-only end action; the same cleanup also ends a guest-created meeting once none of its guest sessions remain active, without ever touching a meeting that has a real organizer.

---

# 10. Whiteboard Engine

Real-time collaborative drawing integrated with the Meeting Engine.

## FR-141 - Start Whiteboard

Host, co-host, or an authorized participant starts a session; participants are notified and the whiteboard opens for authorized users. A guest participant can now start and use the whiteboard on the same footing as a registered participant - see Chapter 19, FR-324.

## FR-142 - Whiteboard Permissions

Host Only, Host + Co-host, All Participants, or View Only, changeable mid-meeting without restarting the whiteboard.

## FR-143 - Drawing Tools

Pencil, brush, highlighter, eraser, straight line, arrow, rectangle, circle, triangle, and freehand drawing; custom shapes, templates, and sticky notes remain future-reserved. Drawing input is handled through Pointer Events (`pointerdown`/`pointermove`/`pointerup`/`pointercancel`, with `setPointerCapture()` and `touch-action: none` on the canvas) rather than mouse-only events, so the whiteboard is fully usable on a touch device in both the registered-user and guest rooms.

## FR-144 - Text Tool

Add/edit/move/delete text with font size, family, color, bold, italic, and underline.

## FR-145 - Color Management

Color picker, preset colors, stroke width, fill color, and opacity; recently used colors are remembered per user.

## FR-146 - Object Management

Select, move, resize, rotate, duplicate, delete, and reorder (bring forward/send backward).

## FR-147 - Undo / Redo

Multiple undo/redo levels with session history; maximum levels are configurable.

## FR-148 - Whiteboard Synchronization

Synchronizes coordinates, tool, color, thickness, text, object state, and timestamp; only incremental updates are exchanged after the initial sync. WebSocket-based synchronization remains architecturally anticipated but unbuilt.

## FR-149 - Multiple Pages

Add/delete/rename/duplicate/navigate pages, up to a configurable maximum per meeting.

## FR-150 - Import Images

PNG/JPG/JPEG/WEBP import with resize/rotate/move/delete.

## FR-151 - Export Whiteboard

PNG, JPG, or PDF export; SVG and editable JSON remain future-reserved. Export permission is host-configurable.

## FR-152 - Clear Whiteboard

Clear current page or the entire whiteboard, with confirmation required.

## FR-153 - Whiteboard Chat Integration

Share a whiteboard snapshot or export into meeting chat; a direct whiteboard link remains future-reserved.

## FR-154 - Whiteboard Activity Log

Logs started/closed, drawing created/deleted, export generated, and cleared events, retained per administrator policy.

## FR-155 - Whiteboard Security

Authentication, authorization, meeting-membership validation, activity logging, and secure export; only meeting participants with appropriate permission may access the whiteboard. Membership validation for a guest participant is enforced the same way as for a registered one - see Chapter 19, FR-324.

## FR-156 - Whiteboard Performance

Incremental synchronization, efficient object rendering, optimized payload size, and lazy loading for large boards.

## FR-157 - Whiteboard Administration

Administrator-configurable maximum whiteboards/pages, export formats, file size limits, sync interval, object limits, history retention, and auto-save interval.

## FR-158 - Auto Save

Periodic and manual save, with recovery after a browser refresh where applicable; interval is administrator-configurable.

## FR-159 - Future Enhancements

Sticky notes, laser pointer, collaborative cursors, drawing templates, infinite canvas, AI shape recognition/diagram generation, and formula support remain architecturally anticipated, unbuilt.

## FR-160 - Whiteboard Lifecycle

States: Created, Active, Editing, Read Only, Exporting, Saved, Closed, Archived - every transition logged.

---

# 11. Calendar & Scheduling Engine

Meeting planning: personal calendars, scheduling, RFC5545 ICS invitations, reminders, and now standalone (non-meeting) calendar entries.

## FR-161 - Personal Calendar

Every authenticated user has a personal calendar with Day, Week, Month, and Agenda views, previous/next/today navigation, jump-to-date, and search. Calendar has its own dedicated navigation icon (it was previously a tab folded into Meetings, then promoted to a standalone nav item). Month view renders a real grid with colored, time-labeled event chips; an overflowing day's "+N more" opens a popover listing every event for that day. Week and Day render a genuine hour-by-hour grid rather than a flat list, and browsing now works for past dates as well as upcoming ones. Dragging an event to a new day (Month) or a new day/time (Week/Day) reschedules the underlying meeting, keeping the calendar entry and the meeting record in sync atomically; a Week/Day event block also has a bottom-edge resize handle that changes duration by moving the end time while holding the start time fixed. Clicking any month-view day cell with events, or clicking an individual chip/block/popover row, opens an event-detail popup (title, time range, description, Delete or Join-meeting button) before navigating or joining. The month grid's row height is computed to fit the real available viewport space so the page itself never needs to scroll to see a six-week month, matching how mainstream calendar apps compress row height rather than scrolling.

## FR-162 - Schedule Meeting

Required: title, date, start time, duration, timezone, participants. Optional: description, password, waiting room, guest access, meeting color, reminder settings. Only verified users may schedule meetings. Scheduling from the web form correctly interprets the submitted date/time as the organizer's own local wall-clock time rather than misreading it as UTC - an earlier defect silently shifted the real start/end time by the organizer's UTC offset.

## FR-163 - Edit Scheduled Meeting

Title, description, date, time, duration, participants, password, reminders, waiting room, and guest access are all editable; participants are notified of updates to significant details.

## FR-164 - Cancel Meeting

Organizer cancellation with confirmation; participants are notified and the meeting remains visible in history per retention policy.

## FR-165 - Recurring Meetings

Daily/Weekly/Monthly/Yearly patterns (custom patterns remain future-reserved), ending after N occurrences, on a specific date, or never (administrator-configurable); instances inherit parent settings unless individually modified.

## FR-166 - Meeting Reminders

Reminder lead time options include 5/10/15/30 minutes, 1 hour, 1 day, or custom; the platform default lead time is 15 minutes. Reminders now genuinely deliver rather than merely marking themselves sent: a due reminder sends a real push notification (gated on the recipient's meeting-notification preference) and, for a meeting-linked reminder specifically, posts a system message from the organizer into their real 1:1 conversation with the invitee - matching the existing inline call-log system-message pattern. A standalone personal reminder with no organizer/invitee pair receives only the push.

## FR-167 - ICS Calendar Generation

Generates an RFC5545-compliant `.ics` file with organizer, title, description, date/time, timezone, join link, meeting ID, and optional password; guest-only meetings do not generate ICS invitations. The embedded join link (and the on-demand ICS download) points at the real authenticated or guest join route as appropriate - an earlier defect pointed both at a route that never existed, so every "Join meeting" click from an invite email 404'd. Each recipient additionally receives a proper `ATTENDEE;RSVP=TRUE` line and a `SEQUENCE` value, so calendar clients render a real, actionable invite (Accept/Decline) rather than a bare event. Scheduling a meeting can invite raw email addresses that do not match an existing account; they receive a real invitation and ICS email with a guest join link rather than being silently dropped, and the same existing-user matching logic (checking a raw invited email against a real registered account first) is applied consistently whether the invitation was scheduled from the web form or the API.

**Database Tables:** `meetings`, `meeting_invitations`.

## FR-168 - Email Invitations

Includes meeting details, a join button, ICS attachment, organizer information, and the meeting link; templates are administrator-configurable.

## FR-169 - RSVP Management

Accept/Tentative/Decline, with organizer visibility into accepted/pending/declined/tentative counts; automatic attendance analytics remain future-reserved.

## FR-170 - Participant Availability

Version 1 surfaces conflicts against meetings already scheduled inside the platform; external calendar integration remains future-reserved.

## FR-171 - Upcoming Meetings

Today, tomorrow, this week, and upcoming, in chronological order.

## FR-172 - Past Meetings

Date, duration, participants, status, and organizer, filterable by month, organizer, or status.

## FR-173 - Calendar Search

Filter by meeting title, organizer, participant, date, or status.

## FR-174 - Calendar Colors

Users assign colors to meetings for visual categorization; category-based calendars remain future-reserved.

## FR-175 - Time Zone Management

Automatic detection, manual selection, and organizer timezone display; invitations preserve the organizer's timezone while showing recipients their own local time.

## FR-176 - Calendar Notifications

Created/updated/cancelled/reminder-sent/starting-soon events, delivered by email and in-app. See FR-166 for reminder delivery specifically.

## FR-177 - Calendar Administration

Administrator-configurable default meeting duration, reminder defaults, ICS/invitation templates, working hours, default timezone, maximum future-scheduling window, and recurring meeting limits.

## FR-178 - Calendar Security

Authentication, authorization, secure invitation links, activity logging, and permission validation; users may only view calendars/meetings they are authorized to access.

## FR-179 - Future Integrations

Google Calendar, Outlook, Apple Calendar, CalDAV, and Exchange synchronization remain architecturally anticipated, unbuilt.

## FR-180 - Calendar Lifecycle

States: Draft, Scheduled, Updated, Reminder Sent, Active, Completed, Cancelled, Archived - every transition logged.

**Database Tables:** `calendars`, `meetings`, `meeting_participants`, `meeting_invitations`, `meeting_reminders`, `calendar_events`, `calendar_rsvp`, `activity_logs`.

## FR-395 - Standalone Calendar Entries (Personal / Reminder / Task / Holiday)

A "+ Add event" action on the Calendar creates `reminder`, `personal`, `holiday`, or `task` entries that have no linked meeting at all - `event_type` values that already existed in the schema but that nothing previously created. Each type is color-coded distinctly from meeting entries and supports the same drag-to-reschedule and drag-to-resize behavior described in FR-161.

---
# 12. Notification Engine

Delivers timely, reliable notifications across the platform through in-app, email, and (now genuinely implemented) push channels.

## FR-181 - In-App Notifications

New message, new group message, missed call, meeting invitation/reminder, participant joined, file shared, profile updated, password changed, new-device login, and system announcement notifications, with a badge, unread count, mark-all-read, delete, and search. See FR-396 for the nav-wide badge behavior specifically.

## FR-182 - Email Notifications

Registration OTP, password reset, email verification, meeting invitation/reminder/cancellation, new-device login, security alert, account suspension, and admin announcement emails. Email delivery is dispatched at request time rather than through a persistent queue worker - see Chapter 18 for the platform's background-processing model, which has no standing queue worker process.

## FR-183 - Notification Preferences

Per-category enable/disable for chat, groups, calls, meetings, calendar, files, and security alerts; critical security notifications cannot be disabled. See FR-016 for where these controls live in Settings and what they now actually gate.

## FR-184 - Notification Templates

Administrator-configurable subject/body, title/message, branding, logo, footer, and signature, with placeholder variables (user name, meeting title/time, verification code, join link).

## FR-185 - Notification Queue

Reliable delivery for email, reminder, system announcement, and retry processing. This runs as scheduled, synchronous work rather than a persistent background queue worker (see Chapter 18) - the hosting environment has no standing worker process, so time-sensitive sends either happen inline at the triggering request or are picked up by a scheduled task running every few minutes, with retry behavior built into that scheduled task rather than a queue's own retry mechanism.

## FR-186 - Notification History

Title, type, delivery time, read status, and delivery status, filterable by date, type, read status, and channel.

## FR-187 - Meeting Notifications

Scheduled/updated/cancelled/reminder/started, waiting-room approval, and participant joined/left, delivered by email, in-app, and push (FR-397).

## FR-188 - Chat Notifications

New message, mention, reply, reaction, group invitation, and group role updated; muted conversations do not generate user-facing notifications except where a critical system policy overrides that.

## FR-189 - Security Notifications

New device login, password changed, email changed, multiple failed logins, account locked, and administrator action, retained per configured audit policy.

## FR-190 - Notification Administration

Administrator-configurable notification-type toggles, email templates, retry attempts/delay, queue configuration, retention period, default user preferences, maintenance notifications, and broadcast announcements.

## FR-191 - Broadcast Notifications

Administrator-authored platform-wide announcements to all users, selected roles, selected users, or (optionally) guests, scheduled or immediate, with priority level and expiration.

## FR-192 - Reminder Engine

Meeting and calendar reminders, with timing configurable by both users and administrators; password-expiry and subscription reminders remain future-reserved.

## FR-193 - Notification Security

Authentication, authorization, queue integrity, delivery logging, and signed links for sensitive actions; sensitive content is never unnecessarily exposed in a notification body.

## FR-194 - Push and Third-Party Notification Channels

Firebase Cloud Messaging is implemented and in production use (FR-397); APNs, SMS gateway, WhatsApp Business API, Microsoft Teams, and Slack integrations remain architecturally anticipated, unbuilt.

## FR-195 - Notification Lifecycle

States: Created, Queued, Processing, Sent, Delivered, Read, Failed, Retrying, Expired, Archived - every transition logged.

**Database Tables:** `notifications`, `notification_templates`, `notification_preferences`, `notification_queue`, `notification_logs`, `notification_channels`, `notification_history`, `activity_logs`.

## FR-396 - Real-Time Notification Badges

Red-dot badges on the Chat and Contacts navigation icons, on both the desktop sidebar and the mobile bottom bar, are polled from the shared application layout so they update on every page rather than only while viewing those sections - backed by an aggregate unread-count endpoint (summed across every conversation) and the existing contact-requests endpoint.

## FR-397 - Push Notifications via Firebase Cloud Messaging

A new message, an incoming call, a missed call, or a contact request reaches a subscribed device as a genuine OS-level notification even with every tab and the application itself fully closed. Push is implemented on Firebase Cloud Messaging: the client obtains a device token through the Firebase Web SDK, passed the application's own existing service worker registration explicitly rather than letting Firebase register a separate one, since a page can only have one active service worker and the app's own already owns push/notification-click handling (including the encrypted quick-reply behavior described in FR-386). The server sends a data-only message (no FCM notification payload) so the service worker's own push handler stays in full control of what is actually shown. Each trigger point is gated on the recipient's matching notification-preference toggle (FR-016). "Delivered" status (FR-038) is marked directly from the service worker's own push handler for a message-type push, with no page open, rather than depending solely on the recipient's client polling. A subscription left over from an earlier VAPID key or an earlier non-Firebase push setup is detected and re-subscribed automatically rather than silently failing forever.

**Database Tables:** `push_subscriptions`.

## FR-398 - Push Notification Device Management

Settings → Notifications → "Manage push notification devices" lists a user's own subscribed devices and lets them revoke any one directly, rather than requiring a direct database edit to see or remove one.

---

# 13. Admin Panel & System Administration

The central management interface: dashboards, user administration, RBAC, application settings, storage, moderation, analytics, audit logging, backups, and (now) direct environment-variable management for the most sensitive configuration.

## FR-196 - Administrator Dashboard

Widgets: total/active/online users, today's registrations, active chats/calls/meetings, guest sessions, storage usage, queue status, failed jobs, system health, and recent activity, with refresh and export. A meeting's own dedicated room-chat conversation (created for every meeting, with only the organizer as a member until someone else joins) is excluded from any conversation count or "active chats" figure here, the same exclusion applied to the Chat list and Dashboard's own recent-conversations widget (Chapter 4) - otherwise a meeting nobody else ever joined would count as, and render as, an "Unknown" 1:1 chat.

## FR-197 - User Management

View, search, filter, create, edit, suspend, activate, delete, restore, reset password, verify email, force logout, and view devices. Users are segmented into All/Real/Load-test tabs, identifying load-test-seeded accounts by their reserved email marker; a small badge also marks a load-test row in the "All users" view. Reset Password supports the administrator-triggered forced-change flow described in Chapter 1, FR-380. Every per-row action here (Suspend/Activate, Force logout, Reset password, Grant/Revoke Super Access, Delete, Restore) carries both an icon and its existing text label - a deliberate exception to the icon-only convention used on lower-stakes surfaces elsewhere in the product, since an unlabeled icon here is more likely to be misclicked on a lower-traffic, higher-stakes screen. On mobile, this and the Archives/Logs tables scroll horizontally inside their own wrapper rather than clipping, and the panel's tab row is a pill-tab strip matching the same convention used elsewhere in the product (Chat's All/Chats/Groups, Calendar's Month/Week/Day).

**Filters:** name, email, phone, status, registration date, last login, role.

## FR-198 - Role Management

Default roles: Guest, User, Moderator, Admin, Super Admin. Create/edit/delete/clone role and assign permissions; the Super Admin role cannot be deleted.

## FR-199 - Permission Management

Role-based access control across Users, Chats, Groups, Meetings, Calls, Whiteboard, Files, Reports, Analytics, Settings, Notifications, Logs, Security, and Backups, with grant/revoke, bulk update, and permission groups. A dedicated `manage_environment` permission gates FR-399, granted to the Super Admin role only, matching how `manage_backups` is already restricted.

## FR-200 - Application Settings

General (name/logo/favicon/theme/language/timezone/maintenance), Authentication (OTP expiry/password policy/login attempts/session timeout), Communication (polling interval/default meeting duration/guest meeting duration/call timeout), and File Storage (upload limit/chunk size/retention/allowed types) sections, all administrator-configurable. See FR-399 for `.env`-level configuration specifically, which is deliberately kept separate from this key/value settings table.

## FR-201 - SMTP Configuration

Host, port, username, password, encryption, sender name, and sender email, with send-test-email, verify-SMTP, and mail-log viewing.

## FR-202 - Storage Management

View usage, storage analytics, cleanup temporary files/chunks/One View files, and archive old files.

## FR-203 - Meeting Administration

Default meeting settings, waiting room default, guest access, whiteboard availability, screen sharing, chat availability, maximum participants, and reminder defaults.

## FR-204 - Chat Administration

Edit/delete time limits, read receipts, typing indicator, message length, upload permissions, and One View availability.

## FR-205 - Call Administration

STUN/TURN servers, maximum call duration, call timeout, logging level, and default video quality.

## FR-206 - Notification Administration

Email templates, reminder templates, broadcast messages, retry attempts, queue configuration, and default notification preferences.

## FR-207 - Activity Logs

Logs login/logout/registration/profile update/message sent/meeting created/file uploaded/administrator actions/permission changes, with search, export, archive, and auto-cleanup.

## FR-208 - Audit Logs

Tamper-resistant records of user suspension/deletion, permission changes, settings changes, SMTP changes, security changes, and backup operations; audit logs cannot be modified through the interface.

## FR-209 - System Health

Database, queue, scheduler, storage, email, and polling status, plus application/PHP/Laravel version, with health check, diagnostics, and export.

## FR-210 - Analytics

Daily/monthly active users, chat/call/meeting statistics, storage growth, user growth, and device/browser distribution.

## FR-211 - Reports

User, meeting, chat, file usage, security, and login reports, exportable as PDF, Excel, or CSV.

## FR-212 - Backup Management

Manual/scheduled backup, database backup, file backup, download, and administrator-confirmed restore; retention policy is configurable.

## FR-213 - Feature Flags

Enable/disable guest meetings, whiteboard, One View media, screen sharing, voice/video calls, and future AI features without deploying new code.

## FR-214 - Maintenance Mode

Enable maintenance, custom message, administrator access during maintenance, and scheduled maintenance window.

## FR-215 - Archive Engine

See Chapter 21 for the complete archival specification, including its current, non-queue-worker background-processing model.

## FR-216 - Security Center

Failed login monitoring, suspicious activity detection, locked accounts, active sessions, device management, and rate-limit statistics; a dedicated 2FA dashboard, risk scoring, and security recommendations remain future-reserved (2FA itself is implemented - see Chapter 1, FR-376).

## FR-217 - System Configuration

Administrator-configurable queue settings, scheduler settings, cache settings, session settings, logging levels, environment flags, and polling configuration. See FR-399 for direct `.env` editing.

## FR-218 - Administrative Notifications

Notifies administrators of failed queue/scheduled jobs, failed email delivery, storage threshold reached, security incidents, backup completion, and system errors.

## FR-219 - Administrative Permissions

Every administrative action is validated by RBAC and logged in the audit log; Super Admin privileges are required for critical operations, including FR-399.

## FR-220 - Administration Lifecycle

States: Dashboard Loaded, Monitoring, Configuration Updated, Maintenance Enabled, Backup Running, Restore Running, Archived, Logged - every configuration change generates an activity log, audit log, and (where applicable) administrator notification, and a change requiring a cache refresh prompts the administrator accordingly.

**Database Tables:** `admins`, `roles`, `permissions`, `role_permissions`, `user_roles`, `settings`, `smtp_settings`, `storage_settings`, `audit_logs`, `activity_logs`, `reports`, `backups`, `feature_flags`, `maintenance_logs`, `analytics`, `system_health`.

## FR-399 - Administrator Environment Variable Management

A Super-Admin-only Environment tab, gated by the `manage_environment` permission (FR-199), offers two ways to edit the live `.env` file: a field-by-field form (one input per real key, with sensitive-looking keys such as `*_KEY`/`*_SECRET`/`*_PASSWORD`/`*_TOKEN` masked behind a Show/Hide toggle) and a raw full-file textarea. This is deliberately a separate, more sensitive surface from the key/value Application Settings table (FR-200), since `.env` can hold database, mail, and API credentials. Every save requires re-entering the acting administrator's own account password first - the same step-up-authentication pattern already used for Deactivate Account, disabling 2FA, and removing a passkey - and takes a dated backup of the current file before writing; `config:clear` runs automatically afterward so a cached-config production deployment picks up the change immediately. Keys present in the shipped `.env.example` but missing or blank in the live `.env` are highlighted directly in the interface.

---

# 14. Security & Compliance

The security baseline every module follows: authentication, authorization, session, transport, storage, and audit protections, following a defense-in-depth approach compatible with Laravel on cPanel hosting.

## FR-221 - Authentication Security

Password hashing via Laravel Hash, secure password reset, email OTP verification, session validation, login throttling, and configurable password policy - passwords are never stored in plain text. The platform's authentication surface now also includes Two-Factor Authentication (FR-376), Passkeys (FR-377), and Google Single Sign-On (FR-378), each with its own trust model as described in Chapter 1.

## FR-222 - Password Policy

Administrator-configurable minimum/maximum length, uppercase/lowercase/number/special-character requirements; expiry and history remain future-reserved. Weak passwords are rejected.

## FR-223 - Authorization

Every protected endpoint verifies authentication, user status, permissions, and resource ownership, implemented through Laravel Policies and Gates.

## FR-224 - Role-Based Access Control

Permissions are assigned through roles (Guest, User, Moderator, Admin, Super Admin); Super Admin permissions cannot be removed accidentally.

## FR-225 - Session Security

Secure/HTTP-only/SameSite cookies, session expiry, device tracking, and logout-all-devices; session timeout is administrator-configurable.

## FR-226 - CSRF Protection

All state-changing requests carry CSRF protection via Laravel's CSRF middleware, which remains enabled.

## FR-227 - XSS Protection

Input sanitization, output escaping, HTML filtering, and rich-text restrictions; user-generated content is escaped before rendering unless explicitly trusted and sanitized.

## FR-228 - SQL Injection Protection

Laravel Query Builder, Eloquent ORM, and parameter binding; raw SQL, where used, is parameterized.

## FR-229 - File Security

MIME/extension/size validation, authorization before download, secure storage, and optional signed download URLs; uploaded files are never executed as server-side code.

## FR-230 - Secure File Access

Permission validation, temporary access links, download logging, and access expiry; unauthorized users cannot reach restricted files.

## FR-231 - Rate Limiting

Applied to login, registration, OTP, password reset, API requests, and file uploads, with administrator-configurable limits.

## FR-232 - Account Lockout

Temporary lockout after repeated failed logins; failed-attempt limit and lock duration are administrator-configurable.

## FR-233 - Device Verification

New-device detection, login alerts, device history, and trusted devices (shared infrastructure with FR-376's "remember this device"); administrator approval for unknown devices remains future-reserved.

## FR-234 - Secure Communications

HTTPS mandatory, secure cookies, TLS-enabled mail transport, and secure WebRTC; the application warns administrators when HTTPS is not enabled.

## FR-235 - Secure API Access

Authentication, authorization, request validation, rate limiting, and activity logging; API keys and OAuth2 remain future-reserved.

## FR-236 - Audit Logging

Append-only logging of login/logout/registration/permission changes/settings changes/user suspension/meeting administration/file deletion/backup operations.

## FR-237 - Security Monitoring

Dashboard of failed logins, locked accounts, suspicious requests, queue/scheduled-job failures, storage alerts, and authentication errors.

## FR-238 - Incident Logging

Brute force, unauthorized access, suspicious file upload, permission violation, and API abuse incidents, with severity, timestamp, user, IP, and resolution status.

## FR-239 - Data Privacy

Users control profile visibility, last seen, online status, read receipts, and contact visibility, enforced across every module (Chapter 3, FR-032). Message bodies are additionally protected at rest and in transit under the server-managed encryption scheme described in Chapter 4, FR-386.

## FR-240 - Security Administration

Administrator-configurable password policy, session timeout, login attempts, lockout duration, rate limits, HTTPS warning, secure cookie settings, file restrictions, allowed MIME types, log retention, and audit retention.

**Future Compliance Readiness:** the architecture remains adaptable for GDPR, ISO 27001, SOC 2, and OWASP ASVS without significant redesign.

**Database Tables:** `security_logs`, `audit_logs`, `login_attempts`, `user_sessions`, `trusted_devices`, `blocked_ips`, `security_incidents`, `password_resets`, `activity_logs`.

---

# 15. REST API & Integration Layer

The communication contract between the Meet PWA, backend, and any future clients or integrations.

## FR-241 - API Architecture

RESTful, JSON request/response, HTTPS-only, UTF-8, versioned under `/api/v1/`; future versions avoid breaking backward compatibility where reasonably possible.

## FR-242 - API Authentication

Laravel Sanctum, with login/logout token, configurable token expiration, and device association; public endpoints remain limited to registration, login, password recovery, and guest meeting access.

## FR-243 - Standard API Response Format

Consistent `{success, message, data, meta}` success shape and `{success, message, errors}` error shape across every endpoint.

## FR-244 - HTTP Status Codes

200/201/202/204/400/401/403/404/409/422/429/500, accurately representing each request's outcome.

## FR-245 - API Validation

Every request validated through Laravel Form Requests - required fields, types, length limits, formats, uniqueness, and authorization; validation messages support localization.

## FR-246 - API Pagination

Configurable page size, total records, current/last/next/previous page. Real pagination is implemented (not merely documented as a generic envelope) on the Admin Users list, the Chat list, and the Contacts list - the Chat and Contacts lists were previously an unpaginated collection and now paginate with infinite-scroll-on-reaching-bottom on the client, scrolling the application shell's own main content region rather than the browser window.

## FR-247 - API Filtering & Sorting

Filter by date, status, user, type, or name; sort by created date, updated date, name, or (future) popularity.

## FR-248 - API Rate Limiting

Applied to login, registration, OTP, search, upload, and public APIs, with administrator-configurable limits.

## FR-249 - API Error Handling

Error code, human-readable message, validation details, and (future) correlation ID; sensitive implementation details are never exposed.

## FR-250 - API Documentation

Endpoint, method, parameters, validation rules, authentication, and example requests/responses/errors; OpenAPI/Swagger generation remains future-reserved.

## FR-251 - Webhooks

Outbound event notifications remain future-reserved (user registered, meeting created/completed, file uploaded, user suspended), with retry policy, delivery logs, and signature validation planned.

## FR-252 - Third-Party Integration

Architecture remains ready for Google/Outlook calendar, cloud storage, SMS, push, and (future) payment providers, all administrator-configurable.

## FR-253 - API Logging

Endpoint, method, response code, execution time, user, device, and IP; sensitive request data is not logged unless explicitly required and protected.

## FR-254 - API Security

HTTPS-only, authentication, authorization, rate limiting, input validation, output escaping, CSRF where applicable, and signed URLs for sensitive operations.

## FR-255 - API Version Lifecycle

States: Draft, Internal, Beta, Stable, Deprecated, Retired - a deprecated version continues functioning during its announced support period.

**Database Tables:** `personal_access_tokens`, `api_logs`, `api_rate_limits`, `activity_logs`.

---

# 16. Progressive Web App (PWA) & Client Application

The complete client-side experience: installability, offline resilience, navigation, and mobile-first design.

## FR-256 - Progressive Web Application

Installable, responsive, mobile-first, HTTPS-required, with a valid manifest and service worker; supported browsers show the install prompt.

## FR-257 - Web App Manifest

Application name, short name, description, theme/background color, icons, display mode, orientation, and start URL, configurable from the Admin Panel where appropriate. Every manifest icon size, the Apple touch icon, and `favicon.ico` use the real product logo rather than placeholder art, including a maskable variant scaled into the safe zone on a white background so OS-level circle/squircle cropping never clips it; the service worker's cache version is bumped whenever icon assets change so already-installed users pick up the update rather than staying on a stale cached copy.

## FR-258 - Service Worker

Caches static assets and the application shell, handles updates, provides an offline fallback, and (where supported) background tasks; updates never interrupt an active meeting or call. An installed PWA that is rarely fully closed can otherwise sit on an old service worker indefinitely, since a browser only activates a newly fetched one once every tab/window for that origin closes. Every page load now checks for a waiting update and activates it immediately unless a call or meeting is actively in progress on that page, in which case it retries every 30 seconds until safe - the original "never interrupt an active call or meeting" guarantee is preserved, but a device is no longer able to get stuck indefinitely on stale app code.

## FR-259 - Offline Application Shell

Offline behavior differs meaningfully by module rather than being uniformly limited to the login screen and static shell. Chat and Contacts are usable offline in a bounded way (see Chapter 4, FR-387): the conversation list, roughly the last 200 messages per conversation, and the contacts list are cached client-side and rendered from cache when a live fetch fails, and a plain text send while offline queues optimistically and is replayed once connectivity returns. Calls, Meetings, authentication, and file uploads remain genuinely network-only by nature - a real-time WebRTC session or an arbitrary binary upload cannot function without a live connection, regardless of caching strategy.

## FR-260 - Cache Management

Caches the application shell, CSS, JavaScript, icons, fonts, user avatars, and (configurably) recently viewed images, with configurable expiration.

## FR-261 - Application Updates

New version detected, assets downloaded, user notified, reload, new version activated - never interrupting an active call or meeting (FR-258).

## FR-262 - Install Experience

Install prompt, manual install instructions, and installation success notification, on Android, Windows, macOS, and Linux. Safari/WebKit exposes no `beforeinstallprompt` API at all; the install control detects iOS specifically and shows a step-by-step "Add to Home Screen" modal instead of doing nothing there.

## FR-263 - Navigation

Navigation is a single unified pattern across the whole application: a roughly 72px icon-only sidebar rail on desktop (`lg:` and up, with hover tooltips), and a fixed bottom bar on mobile, both surfacing the same four primary items - Chat, Contacts, Calls, Meetings - with Dashboard and Admin (where the user has admin access) grouped separately rather than mixed into the primary four. Chat and Groups share one nav item: the chat list's own All/Chats/Groups tabs filter the single already-loaded set of conversations client-side rather than querying a second data source, and the standalone Groups pages remain reachable as a secondary deep link. Calendar has its own dedicated nav icon (Chapter 11, FR-161), separate from Meetings, after briefly sharing a route-name prefix that made both nav icons light up together regardless of which page was open - now resolved. On mobile, a fixed bottom nav bar accounts for on-screen-keyboard and small-viewport edge cases (scroll-margin and extra bottom padding) so it can no longer land on top of a focused field or button once a page is scrolled to its end.

## FR-264 - Responsive Design

Adapts to mobile phones, tablets, laptops, and desktops without loss of functionality.

## FR-265 - Device Features

Camera, microphone, notification, and clipboard permissions, requested only when required; contacts access and the File System Access API remain future-reserved.

## FR-266 - App Shortcuts

New Chat, New Meeting, Join Meeting, Calendar, and Profile shortcuts from the installed application; administrator-configurable shortcuts remain future-reserved.

## FR-267 - Push Notification Readiness

Fully implemented via Firebase Cloud Messaging - see Chapter 12, FR-397 - rather than remaining a forward-looking architectural placeholder.

## FR-268 - Background Synchronization

Implemented for Chat specifically: a queued plain-text send while offline is replayed once connectivity returns, per conversation, as described in Chapter 4, FR-387. Synchronizing failed uploads and drafts more broadly remains future-reserved, and only occurs where browser support exists.

## FR-269 - Performance Requirements

Fast initial load, optimized assets, lazy loading, code splitting, image optimization, and efficient polling, prioritizing mobile users on average network conditions.

## FR-270 - PWA Administration

Administrator-configurable app name, icons, splash screen, theme colors, cache duration, offline message, update notification text, and manifest settings, without requiring source code changes for most branding updates.

## FR-271 - Accessibility

Keyboard navigation, screen reader compatibility, accessible form labels, color contrast compliance, and focus indicators across major interfaces.

## FR-272 - Client Security

Secure HTTPS, configurable Content Security Policy, secure cookies, safe local storage usage, XSS protection, and cache validation. The CSP's `connect-src` explicitly allows the real Firebase endpoints (`firebaseinstallations.googleapis.com`, `fcmregistrations.googleapis.com`) the push implementation (FR-397) genuinely calls.

## FR-273 - Client Lifecycle

States: First Visit, Installed, Online, Offline, Updating, Active, Background, Closed.

## FR-274 - Future Client Features

Native Android/iOS applications, a desktop application, offline draft sync, multi-window support, an AI assistant, and home-screen widgets remain architecturally anticipated, unbuilt.

## FR-275 - Mobile Experience Standards

Native-like navigation, smooth animations, fast transitions, a consistent design system, touch-friendly controls, and battery-/network-conscious operation. See FR-400 for the full-screen mobile chat layout specifically.

**Database Tables:** `user_preferences`, `app_settings`, `activity_logs`, `notification_preferences`, `device_sessions`.

## FR-400 - Full-Screen Mobile Chat Layout

An open 1:1 or group conversation is a documented, opt-in exception to the standard mobile navigation chrome (FR-263): the shared header and bottom navigation bar are hidden below the `lg` breakpoint specifically for the conversation view, and the page's own in-thread header (back arrow, name, call buttons) is left as the sole navigation - the desktop split-view layout is unaffected at any width. The conversation's outer container tracks the browser's visual viewport (not just the layout viewport) via a resize/scroll listener, so opening the on-screen keyboard resizes the header/messages/composer column correctly and the composer lands right above the keyboard rather than being partly hidden underneath it. Document-level rubber-band/overscroll bounce is stopped at the root (`overscroll-behavior-y: none` on `html`/`body`), while the message list and main content region retain their own natural scroll and bounce contained to themselves, so a scroll gesture inside the conversation can no longer visibly detach the composer from the bottom of the screen. Text-like inputs use a 16px font size on mobile specifically (14px from the `sm:` breakpoint up), since a focused input under 16px triggers an unwanted auto-zoom on iOS Safari.

---

# 17. Analytics, Reporting & Monitoring

Operational, business, and technical insight into platform usage, respecting user privacy settings throughout.

## FR-276 - Analytics Dashboard

Total/verified/active/online users, DAU/MAU, new registrations, active meetings/calls, messages sent today, storage usage, and queue/scheduler status, with refresh, auto-refresh, and export.

## FR-277 - User Analytics

New registrations, active users, returning users, login frequency, and device/browser/OS distribution, filterable by date range, role, and verification status.

## FR-278 - Chat Analytics

Total messages, messages per day, private vs. group chats, shared media, voice notes, One View usage, and average messages per user, charted daily/weekly/monthly/yearly. A meeting's own auto-created room-chat conversation is excluded from these figures the same way it is excluded from the Admin dashboard and the Chat list/Dashboard widgets (Chapter 13, FR-196), so it never inflates "active chats" or per-user message counts.

## FR-279 - Voice & Video Call Analytics

Total/completed/missed/rejected calls, average duration, failures, and network issues, reported daily/weekly/monthly.

## FR-280 - Meeting Analytics

Meetings created/completed, guest meetings, scheduled vs. instant, average participants/duration, and screen-share/whiteboard usage, filterable by organizer, date, and status.

## FR-281 - File & Storage Analytics

Total uploads/downloads, storage used/growth, One View files, expired files, and cleanup statistics, by category (images/videos/audio/documents/temporary).

## FR-282 - Notification Analytics

Emails sent, delivery success/failure, queue time, and reminder/broadcast statistics, across email, in-app, and (now) push channels.

## FR-283 - Security Analytics

Failed logins, account lockouts, security incidents, blocked requests, suspicious activity, and rate-limit events; a security health score remains future-reserved.

## FR-284 - Performance Monitoring

Average API response time, database query time, scheduled-task execution time, memory usage, and PHP/Laravel version; metrics dependent on hosting capability degrade gracefully where unavailable.

## FR-285 - Queue Monitoring

Pending/running/failed/retried jobs, with retry, delete, and export. See Chapter 18 for how background work is actually structured in production (scheduled tasks rather than a standing queue worker).

## FR-286 - Scheduler Monitoring

Last/next execution, failed scheduled tasks, cleanup jobs, and reminder jobs, with diagnostics and export.

## FR-287 - Report Generation

User, meeting, chat, call, file, notification, security, audit, and storage reports, exportable as PDF, Excel, or CSV.

## FR-288 - Audit Reporting

Filterable by administrator, action type, date, module, and severity; audit reports are read-only.

## FR-289 - System Health Monitoring

Database connectivity, storage availability, queue health, scheduler health, SMTP availability, application status, and polling status, at Healthy/Warning/Critical levels.

## FR-290 - Business Intelligence Readiness

Predictive analytics, AI reports, engagement analysis, meeting-trend analysis, capacity planning, and storage forecasting remain architecturally anticipated, unbuilt.

## FR-291 - Administrator Alerts

Low storage, queue failure, SMTP failure, backup failure, security incident, high error rate, and scheduler failure, delivered via dashboard and email.

## FR-292 - Data Retention

Administrator-configurable analytics/report/log retention and archive period.

## FR-293 - Privacy & Compliance

Respects user privacy settings, minimizes personally identifiable information in reports, secures report access, and enforces role-based visibility.

## FR-294 - Analytics Administration

Administrator-configurable dashboard refresh interval, default date range, export formats, chart preferences, data retention, and alert thresholds; report scheduling remains future-reserved.

## FR-295 - Analytics Lifecycle

States: Data Collected, Processed, Aggregated, Report Generated, Archived, Deleted - every process traceable for diagnostics and auditing.

**Database Tables:** `analytics`, `analytics_events`, `reports`, `report_exports`, `system_health`, `queue_logs`, `scheduler_logs`, `performance_metrics`, `storage_statistics`, `notification_statistics`, `activity_logs`, `audit_logs`.

---

# 18. Deployment, Operations & Disaster Recovery

How the platform is deployed, configured, maintained, monitored, backed up, upgraded, and recovered in production.

## FR-296 - Supported Deployment Platforms

cPanel shared hosting, cPanel VPS, and WHM servers in Version 1; Docker, Kubernetes, and major clouds remain a future migration path.

## FR-297 - Environment Configuration

Application, database, mail, cache, queue, session, storage, security, STUN/TURN, and branding configuration is centralized in environment configuration, never hardcoded; production secrets are never committed to version control. See FR-399 for direct, audited `.env` editing from the Admin Panel.

## FR-298 - Installation Wizard

Environment check, database configuration, SMTP configuration, administrator account creation, storage configuration, and application initialization, without manually editing source code.

## FR-299 - Database Migration

Migration execution, rollback, history, and validation, version-controlled through Laravel migrations. A large, one-time data migration against an existing table (for example, backfilling a column for every pre-existing account) runs as a scheduled, self-disabling batch job processing bounded chunks per tick rather than a single blocking command - a blocking, unchunked migration against a large production table risks exhausting memory or overlapping with the next deploy attempt. Deploy-time actions are reserved for cheap, near-instant work (such as flipping a configuration flag); anything whose cost scales with table size belongs in a scheduled batch job instead.

## FR-300 - Database Backup

Manual and scheduled backup, export, and compression; encryption remains future-reserved. Frequency, retention period, and location are administrator-configurable.

## FR-301 - File Backup

User uploads, avatars, documents, images, videos, meeting assets, and whiteboard exports; temporary files are excluded unless explicitly configured.

## FR-302 - Backup Restoration

Database-only, files-only, or complete-system restore, requiring Super Administrator confirmation and recorded in the audit log.

## FR-303 - Laravel Scheduler

Scheduled tasks cover cleanup of expired OTPs, temporary files, and chunks; sending meeting reminders; archiving logs; and executing retention policies - monitored through the Admin Panel. The Scheduler is also the platform's primary background-processing mechanism generally (see FR-304): scheduled tasks run cleanup of orphaned media (Chapter 5, FR-065), expired disappearing messages (Chapter 4, FR-383), inactive/abandoned meetings (Chapter 9, FR-394), and expired guest sessions, each on its own recurring interval (typically every few minutes to every fifteen minutes).

## FR-304 - Queue Management

The production deployment runs with no persistent queue worker process - a standard constraint of the supported cPanel hosting environment, which offers cron but not a long-running worker. Background-shaped work is therefore handled one of two ways: dispatched synchronously inline at the point of the triggering request (for example, sending a push notification or an email at the moment the triggering event happens), or picked up by a Laravel Scheduler task running on a short recurring interval (for example, every five or fifteen minutes) rather than by `ShouldQueue` jobs sitting in a `notification_queue`/`retry_queue` waiting for a worker that does not exist. Failed-job visibility and retry behavior (Chapter 17, FR-285) apply to this scheduled-task model rather than to a queue worker's own retry semantics.

## FR-305 - Application Optimization

Route cache, config cache, view cache, Composer optimization, and asset optimization; optimization commands are safe to run repeatedly.

## FR-306 - Logging

Application, authentication, queue, scheduler, security, API, and mail logs, with search, download, archive, and auto-cleanup.

## FR-307 - Disaster Recovery

Documented, testable recovery procedures for database, storage, SMTP, queue/scheduler, and application-corruption failure scenarios.

## FR-308 - Production Monitoring

Disk usage, storage growth, queue/scheduler health, database and SMTP connectivity, and application availability, at Informational/Warning/Critical levels.

## FR-309 - Application Updates

Database migration, cache refresh, version tracking, and rollback guidance, minimizing downtime and preserving user data. See FR-401 for how a production update is actually rolled out.

## FR-310 - Maintenance Operations

Enable maintenance mode, schedule a maintenance window, display a maintenance message, and allow administrator access during maintenance.

## FR-311 - Log Rotation & Retention

Administrator-configurable retention period, maximum log size, archive schedule, and automatic cleanup.

## FR-312 - Storage Maintenance

Orphan file detection, temporary file cleanup, and chunk cleanup (Chapter 5, FR-065); duplicate detection remains future-reserved. Maintenance operations never remove active user content.

## FR-313 - Operational Documentation

Installation, upgrade, backup, restore, scheduler setup, queue setup, SMTP setup, storage configuration, and troubleshooting guides, maintained alongside releases.

## FR-314 - Disaster Recovery Readiness

Defined recovery procedure, backup verification, restore verification, recovery testing, and audit logging; a multi-region backup strategy remains future-reserved.

## FR-315 - Deployment Lifecycle

States: Fresh Installation, Configured, Initialized, Operational, Maintenance, Updating, Backup Running, Restore Running, Archived, Decommissioned - every transition logged.

**Database Tables:** `backups`, `backup_logs`, `deployment_logs`, `maintenance_logs`, `scheduler_logs`, `queue_logs`, `system_health`, `application_versions`, `activity_logs`, `audit_logs`.

## FR-401 - Automated Deployment Pipeline

Production updates are rolled out through a scripted deployment pipeline that runs no arbitrary code execution on the server beyond what the pipeline itself performs, with the compiled frontend build and vendor dependencies committed alongside the application code rather than built on the production host. Any environment-variable key present in the release's shipped `.env.example` but missing from the live `.env` is added automatically on every deploy (left blank, never overwriting an already-configured value), with what was added listed in the deployment's own success notification - closing a gap where a server on an older release never picked up a newly introduced configuration key without a manual edit. A small, explicitly reviewed set of one-off deploy-time actions may run automatically as part of a release, restricted by convention to cheap, near-instant work (see FR-299 for why anything scaled to table size is excluded from this mechanism).

---
# 19. Guest Mode & Public Access

Allows a visitor to participate in meetings without creating an account, while preserving host control and platform security.

## FR-316 - Guest Meeting Access

A guest opens a meeting link, the system validates the meeting, the guest enters a display name, requests to join, and is admitted by the host if a waiting room is enabled; the guest then appears in the participant list with a Guest badge. See FR-402 for a guest creating a meeting outright, not merely joining one.

## FR-317 - Guest Session Duration

Default 15 minutes; administrator-configurable to 5/10/15/30/60 minutes or unlimited (disabled by default). Guests are notified before their session expires.

## FR-318 - Guest Permissions

A guest may join the meeting, toggle microphone/camera, view a shared screen, and (configurably) participate in meeting chat and view the whiteboard. A guest cannot schedule meetings, access private chats, create groups, view user profiles, change meeting settings, access admin features, view meeting history, or download restricted files. Where the guest is themselves the meeting's host or co-host - as in a guest-created meeting, FR-402 - they additionally have the Lock Meeting and Share Screen controls a registered host has (Chapter 9, FR-133), through guest-scoped equivalents of the registered-user endpoints; every other guest permission restriction above is unaffected.

## FR-319 - Guest Identity

Display name, guest badge, join time, device information (internal), and IP address (audit only); display names need not be unique.

## FR-320 - Waiting Room for Guests

Host may admit, reject, admit all, or remove a guest; the host is notified whenever a guest requests access.

## FR-321 - Guest Meeting Link

Each guest link carries a meeting identifier, secure token, expiration validation, and optional password requirement; expired or invalid links are rejected. Guests can now submit a meeting password directly on the guest-landing join form - previously that field did not exist there, making a password-protected meeting effectively unjoinable through a guest link. A copyable `/j/{code}` join link is shown consistently everywhere a meeting appears (Chapter 9, FR-123), not only inside the guest room itself.

## FR-322 - Guest Chat

Administrator options: Disabled, Read Only, or Full Chat Access; guest chat history is limited to the current meeting session.

## FR-323 - Guest Screen Sharing

Host options: Disabled, Allowed After Approval, or Always Allowed; the host may revoke screen-share permission at any time. Screen sharing for a guest who is host/co-host of their own guest-created meeting is fully wired end-to-end (Chapter 9, FR-133) - an earlier gap left the Share Screen button present with no backing route or JavaScript wiring in the guest room specifically.

## FR-324 - Guest Whiteboard Access

A guest has the same whiteboard access as a registered participant, governed by the same host-configured permission mode (No Access / View Only / Draw with Permission) changeable mid-meeting. This did not previously work in practice: every whiteboard creator/actor column was a hard foreign key to a registered `User` row, every whiteboard route required authenticated Sanctum access, and the guest room carried no whiteboard markup at all. Each creator/actor column is now nullable, paired with a sibling foreign key into the meeting-participant record (which already models "whoever is in this meeting, guest or not"), so exactly one of the pair is set per row depending on whether the actor is a guest. A parallel set of guest-scoped whiteboard routes, scoped by guest session, mirrors the registered-user whiteboard actions one-to-one, guarded against a guest reaching a whiteboard belonging to a different meeting. The object list exposes a creator label ("Alice" vs. "Bob (Guest)") so the roster reads correctly regardless of who drew what, and drawing input works via Pointer Events on touch devices in the guest room exactly as in the registered room (Chapter 10, FR-143).

## FR-325 - Guest File Sharing

Disabled by default; administrator-configurable to allow upload, download, and view of shared files. One View media is never available to guests.

## FR-326 - Guest Security

Secure join token, HTTPS, authorization validation, meeting-membership validation, activity logging, and rate limiting; a guest may only access the meeting for which they hold a valid invitation.

## FR-327 - Guest Session Expiry

On reaching the configured duration, a warning is displayed, the session expires, the guest is removed, and the host is optionally notified; rejoining requires a valid invitation and host approval if applicable. Expired guest sessions are cleaned up on a recurring schedule (Chapter 18, FR-303), and that same cleanup ends a guest-created meeting (FR-402) once none of its guest sessions remain active (Chapter 9, FR-394).

## FR-328 - Guest Analytics

Guest joins/leaves, average duration, devices, browsers, and meeting participation; guest countries by geography remain future-reserved.

## FR-329 - Guest Administration

Administrator-configurable: enable guest mode, default session duration, waiting room default, chat/whiteboard/screen-share/file-sharing permissions, guest name policy, and maximum guest participants. `settings.meetings.guest_creation_enabled` (FR-402) is among these, default on.

## FR-330 - Guest Lifecycle

States: Invitation Opened, Name Entered, Waiting Approval, Joined, Active, Session Warning, Session Expired, Removed, Left Meeting - every event logged.

**Database Tables:** `guest_sessions`, `guest_tokens`, `guest_activity_logs`, `meeting_guests`, `activity_logs`.

## FR-402 - Guest-Created Meetings

A visitor with no account can create an instant meeting directly from the login page's "Start a meeting" action, not only join one via an existing link. They receive a shareable `/j/{code}` link back immediately, shown in the meeting room itself, optionally password-protected. The creating guest becomes the meeting's host-role participant, but - being unauthenticated - cannot use the authenticated-only host-control endpoints that require a real `User` (mute/remove/lock/transfer); the meeting instead winds down through the same expired-guest-session cleanup described in FR-327 and Chapter 9, FR-394, once none of its guest sessions remain active, since it can never reach "ended" through the normal host-only end action. `meetings.organizer_id` is nullable to support an organizer-less, guest-created meeting. This capability is administrator-configurable via `settings.meetings.guest_creation_enabled` (default on, FR-329).

---

# 20. One View Media Engine

Secure, single-view media sharing, plus the platform's Super Access capability for specifically authorized users.

## FR-331 - One View Media

A sender selects an image or video and enables One View; once the recipient successfully opens it, the media becomes unavailable per the configured retention policy, with the view event recorded. Documents and audio remain future-reserved for One View. Before opening, a One View photo or video shows a small placeholder with a circular "1" badge rather than plain "Tap to view once" text. After it has been opened, both the sender and the recipient see "Opened" - previously only the recipient's own client reflected the consumed state, and the sender's client kept showing a stale, clickable "tap to view" placeholder even after the underlying file was gone; a sender's own attempt to view their unconsumed upload is now explicitly rejected (a dedicated exception, surfaced as a plain, non-clickable "One View" label rather than a preview) rather than the earlier behavior of letting a sender re-preview their own upload indefinitely. The "Opened" status now updates live on an already-open conversation page without requiring a refresh, patching an already-rendered bubble in place the next time the sender's own message-status poll runs.

## FR-332 - One View Status

States: Uploading, Processing, Ready, Delivered, Viewed, Expired, Deleted - every transition logged.

## FR-333 - View Validation

Verifies recipient identity, conversation membership, authorization, and media availability before allowing a view; unauthorized attempts are denied and logged.

## FR-334 - Automatic Expiration

Administrator-configurable immediate, delayed, or scheduled cleanup, performed through the Laravel Scheduler (Chapter 18, FR-303). A recurring cleanup task additionally catches a One View file stuck "viewed" but never actually removed, past a short grace window, as a safety net for an interrupted download stream - the primary removal mechanism remains the synchronous delete performed inside the stream response that serves the file.

## FR-335 - Super Access

Only users explicitly granted the Super Access permission by an administrator receive this handling: media downloads automatically before display, the download is recorded, and the view is recorded; the media remains governed by retention policy even after this automatic-download behavior applies.

## FR-336 - Super Access Management

Administrators grant/revoke Super Access, view authorized users, and view usage logs; permission changes take effect immediately.

## FR-337 - One View Security

Authentication, authorization, secure storage, signed download requests where implemented, and activity logging; media is never directly reachable through a public storage path.

## FR-338 - Download Restrictions

A standard user has no manual download option and no repeated viewing once policy is enforced. A Super Access user's media downloads automatically before viewing, with the download logged for auditing. A sender may never preview their own unconsumed One View upload - see FR-331.

## FR-339 - Storage Management

Temporary storage, secure storage, scheduled cleanup, and retention policies; orphaned One View files are detected and removed (Chapter 5, FR-065).

## FR-340 - One View Notifications

Media delivered, viewed, and expired events, delivered in-app; email remains optional/future.

## FR-341 - Activity Logging

Logs upload, delivery, view, automatic download, expiration, deletion, and permission-change events, retained per configured audit policy.

## FR-342 - One View Analytics

Total One View files, views, expirations, automatic downloads, Super Access usage, and storage consumption, reported daily/weekly/monthly.

## FR-343 - Administrator Configuration

Enable One View, supported media types, maximum file size, cleanup interval, retention period, Super Access availability, and logging level, all without source code changes.

## FR-344 - Future Enhancements

Screenshot/screen-recording detection, watermarking, device binding, and AI content classification remain architecturally anticipated, unbuilt.

## FR-345 - One View Lifecycle

States: Selected, Uploading, Processing, Ready, Delivered, Viewed, Automatically Downloaded (Super Access), Expired, Deleted, Archived - every transition logged.

**Database Tables:** `one_view_files`, `one_view_permissions`, `one_view_logs`, `one_view_downloads`, `activity_logs`, `audit_logs`.

## FR-403 - Reply, React, and Full Emoji Picker from One View

The One View full-screen viewer has its own Reply and React controls, working directly from the viewer rather than requiring the underlying message's own actions card. The reaction quick-strip, both in the ordinary message-actions card (Chapter 4, FR-045) and inside the One View viewer, opens a full categorized emoji picker on demand and remembers the 24 most recently used reactions per browser; tapping an already-active reaction un-reacts, matching ordinary message reactions.

---

# 21. Archive, Backup Mail & Data Retention

Automated archival, configurable retention, secure backup generation, and long-term data lifecycle management.

## FR-346 - Automatic Archive Engine

Archives chats, group chats, meetings, whiteboards, shared files, One View logs, notifications, activity logs, and audit logs per configured retention policy, without interrupting normal application usage. Archive generation, like other background-shaped work in this platform, runs as scheduled tasks rather than through a persistent queue worker (Chapter 18, FR-304) - there is no standing worker process on the supported hosting environment.

## FR-347 - Archive Schedule

Daily, weekly, monthly, or custom cron schedule, via the Laravel Scheduler.

## FR-348 - Archive Format

ZIP (TAR.GZ future-reserved), containing a database export, uploaded files, whiteboard exports, meeting assets, and an optional configuration snapshot. Chat Export (Chapter 4, FR-385) is a smaller-scope, self-service sibling of this same archival pattern - a single conversation's own CSV transcript and attachments, downloaded on demand by the conversation's own participant rather than a full-system administrator archive.

## FR-349 - Password Protected Archive

Archives support password protection before distribution; passwords are never stored in plain text. Administrator options: enable protection, custom password, or (future) auto-generated password.

## FR-350 - Archive Email Delivery

Delivers the completed archive by email to an administrator-configurable recipient, including the archive file (subject to size limits), summary, date, size, generator, checksum, and retention information; an oversized archive is delivered as a secure download link instead of an attachment.

## FR-351 - Archive Password Configuration

Administrator-configurable archive password and rotation policy; passwords are encrypted at rest and never appear in application logs.

## FR-352 - Archive Encryption

Password-protected archives in Version 1; AES-256, public-key encryption, and cloud key management integration remain future-reserved.

## FR-353 - Archive Verification

Verifies file size, checksum, completion status, and file count; failed verification prevents delivery and notifies administrators.

## FR-354 - Archive Retention

Administrator-configurable retention for archives, database backups, file backups, activity logs, and audit logs; expired archives are deleted only after successful retention validation.

## FR-355 - Manual Archive Generation

Administrator-triggered archive of the entire system, database only, files only, meetings, chats, whiteboards, or logs.

## FR-356 - Archive History

Name, creation time, size, status, generator, and delivery status, filterable by date, status, and type.

## FR-357 - Archive Notifications

Notifies administrators of archive started/completed/failed, delivery completed, and verification failed, via in-app and email.

## FR-358 - Cleanup Engine

Removes temporary files, upload chunks, expired One View media, expired guest sessions, old notifications, archived data, and log files, on the Laravel Scheduler, with detailed logs.

## FR-359 - Archive Security

Authentication, Super Administrator authorization, activity and audit logging, secure storage, and download authorization; only authorized administrators may create, download, or delete archives.

## FR-360 - Archive Lifecycle

States: Scheduled, Queued, Processing, Compressing, Verifying, Completed, Emailing, Delivered, Failed, Archived, Deleted - every transition logged.

**Database Tables:** `archives`, `archive_jobs`, `archive_history`, `archive_settings`, `archive_notifications`, `archive_logs`, `retention_policies`, `backup_history`, `activity_logs`, `audit_logs`.

---

# 22. Non-Functional Requirements (NFR)

Quality attributes every module must satisfy - how the system performs, scales, remains secure, and stays maintainable.

## NFR-001 - Performance

Fast page loading, optimized API responses and database queries, lazy loading, an efficient polling architecture, and optimized image loading, prioritizing mobile users on average network conditions.

## NFR-002 - Scalability

The architecture supports future migration from shared hosting through VPS, dedicated servers, Docker, Kubernetes, and cloud infrastructure without major redesign.

## NFR-003 - Availability

Designed for high availability today; load balancing and redundant infrastructure remain a future deployment option.

## NFR-004 - Reliability

Graceful error handling, automatic retry where appropriate, scheduler monitoring, and recovery after temporary failures. Background processing is scheduler-driven rather than backed by a persistent queue worker - the supported cPanel hosting environment provides cron but not a standing worker process, so reliability for time-sensitive work depends on synchronous inline dispatch at the triggering request plus short-interval scheduled tasks (Chapter 18, FR-304), rather than on queue-level retry semantics.

## NFR-005 - Maintainability

Modular architecture, service layer, repository pattern, dependency injection, configuration-driven features, and comprehensive documentation; business logic is not duplicated across modules.

## NFR-006 - Security

HTTPS, authentication, authorization, CSRF/XSS/SQL-injection protection, secure file storage, and activity logging (Chapter 14), now further strengthened by Two-Factor Authentication, Passkeys, and Google Single Sign-On (Chapter 1) and by server-managed message-body encryption at rest and in transit (Chapter 4, FR-386).

## NFR-007 - Privacy

User privacy settings are respected throughout the application; sensitive information is visible only to authorized users.

## NFR-008 - Accessibility

Keyboard navigation, screen reader support, color contrast compliance, accessible forms, and focus indicators.

## NFR-009 - Compatibility

Chrome, Edge, Firefox, and Safari (subject to WebRTC support), on Android, Windows, macOS, and Linux; native iOS/Android applications remain future-reserved.

## NFR-010 - Usability

Simple navigation, a consistent interface, mobile-first responsive design, clear error messages, and contextual help.

## NFR-011 - Localization

Multiple languages, timezones, and local date formats; RTL languages remain future-reserved.

## NFR-012 - Logging

Application, security, audit, queue, scheduler, and API logs, retained per configured policy.

## NFR-013 - Backup & Recovery

Scheduled and manual backups, restore procedures, and backup verification, with documented, testable recovery procedures.

## NFR-014 - Data Integrity

Database constraints, foreign keys, transactions, validation, and integrity checks.

## NFR-015 - Configuration

Application behavior is configurable through the Admin Panel wherever practical; hardcoded operational values are avoided. See Chapter 13, FR-399 for direct, audited environment-variable editing where the Admin Settings table itself is not the appropriate surface.

## NFR-016 - Extensibility

The architecture supports future modules - AI assistant, team workspace, CRM, helpdesk, payments, plugins - without significant redesign.

## NFR-017 - Monitoring

Administrators can monitor system health, queue status, scheduler status, storage usage, SMTP status, and application availability.

## NFR-018 - Compliance Readiness

Remains adaptable for future GDPR, ISO 27001, SOC 2, and OWASP ASVS compliance.

## NFR-019 - Documentation

The project maintains an SRS, architecture documentation, API documentation, database documentation, deployment documentation, administrator guide, user guide, and developer guide.

## NFR-020 - Quality Objectives

Stable, secure, maintainable, scalable, configurable, mobile-friendly, and production-ready.

**Acceptance Criteria:** the Meet platform satisfies the functional and non-functional requirements defined throughout this specification while remaining suitable for deployment on Laravel with cPanel hosting and ready for future infrastructure evolution.

---

# 23. Future Roadmap & AI Readiness

The long-term vision for the platform beyond the current baseline. A number of capabilities that were originally described here as forward-looking have since shipped and now live in their own chapters; this chapter reflects what genuinely remains ahead.

## FR-361 - AI Meeting Summary

Key discussion points, action items, decisions made, participant summary, and timeline summary remain unbuilt; the Meeting Engine exposes extension points intended to support this without structural change when it is built.

## FR-362 - AI Chat Assistant

Answering questions, summarizing conversations, drafting messages, explaining shared documents, and suggesting meeting agendas remain unbuilt; any future implementation would operate only on content the user is already authorized to access.

## FR-363 - Live Captions

Speaker identification, caption preferences, export, and search remain unbuilt; caption generation is intended to remain optional and configurable when built.

## FR-364 - Live Translation

Real-time translation, caption translation, chat translation, and interface translation assistance remain unbuilt, and would be configurable and may rely on external providers.

## FR-365 - Meeting Recording

Manual/automatic recording, audio-only, video, screen recording, download, and sharing remain unbuilt; availability would be configurable by administrators and meeting hosts.

## FR-366 - AI Search

Natural-language, semantic, conversation, meeting, and file search remain unbuilt; results would always respect authorization and privacy settings.

## FR-367 - Plugin Architecture

CRM, helpdesk, HR, project management, knowledge base, and workflow-automation plugin types remain unbuilt, intended to integrate through documented extension points and APIs.

## FR-368 - Enterprise Workspace

Organizations, departments, teams, workspace branding, organization policies, and team administration remain unbuilt; workspace isolation would prevent unauthorized cross-organization data access.

## FR-369 - Cloud Storage Integration

S3, Cloudflare R2, GCS, Azure Blob, Wasabi, and Backblaze B2 remain unbuilt beyond the existing local-storage-only implementation (Chapter 5, FR-067), intended to be administrator-configurable without code changes.

## FR-370 - Push Notification Platform

Already implemented - see Chapter 12, FR-397 (Firebase Cloud Messaging) - rather than remaining a future item. Rich notifications, action buttons, and silent notifications are partially present today (action buttons on message and missed-call notifications, Chapter 7 FR-393); broader rich-notification and badge-update capability remains an open area for further investment.

## FR-371 - Mobile Applications

Native Android and iOS applications remain unbuilt; a future native client would reuse the existing REST API and authentication model (Chapter 15).

## FR-372 - Desktop Applications

Windows, macOS, and Linux desktop clients remain unbuilt; a future desktop client would use the same backend APIs with desktop-specific enhancements.

## FR-373 - Enterprise Authentication

SAML, OAuth 2.0, OpenID Connect, Microsoft Entra ID, and LDAP remain unbuilt beyond the Google OAuth-based Single Sign-On already implemented (Chapter 1, FR-378); any future enterprise authentication method would coexist with the existing authentication system rather than replace it.

## FR-374 - AI Administration

AI provider selection, feature toggles, usage limits, cost controls, prompt templates, and AI audit logs remain unbuilt, pending any future AI feature that would need them; AI features would be configurable and disableable globally or per organization.

## FR-375 - Product Evolution Roadmap

**Delivered beyond the original Version 1 scope:** Two-Factor Authentication, Passkeys, and Google Single Sign-On (Chapter 1); server-managed message encryption, offline-first Chat/Contacts, disappearing messages, broadcast lists, Communities, and chat export (Chapter 4); custom in-app media viewers (Chapter 5); real Web Push via Firebase Cloud Messaging (Chapter 12); call-to-meeting escalation and 1:1 screen sharing (Chapter 7); guest-created meetings and full guest whiteboard parity (Chapters 9, 10, 19); and an Admin environment-variable management surface (Chapter 13).

**Still ahead:** meeting recording, cloud storage integration, enhanced predictive analytics, AI meeting summary and chat assistant, live captions and translation, enterprise workspaces, a plugin marketplace, and native mobile/desktop applications.

**Architectural Principles:** future enhancements preserve backward compatibility where practical, reuse existing APIs and service layers, respect privacy and authorization rules, avoid major database redesign whenever possible, and follow the modular architecture defined throughout this specification.
