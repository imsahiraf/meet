# Database Design Specification (DDS)

**Version:** 2.0

**Document Type:** Database Design Specification

**Priority:** Critical

---

# 1. Purpose

This document is the single, authoritative reference for the Meet platform's relational schema. It describes every table, column, relationship, and indexing decision as they actually exist in the running application - identity and authentication, contacts, chat and messaging, groups and communities, calls, meetings, calendar scheduling, the collaborative whiteboard, media handling, push notifications, administration, platform configuration, archiving, and auditing.

**Database:** MySQL 8+ (SQLite is used for the automated test suite only; a small number of migrations contain a driver-specific rebuild path to accommodate SQLite's lack of `ALTER COLUMN`/`DROP CONSTRAINT` support - the production schema they produce is identical).

**Framework:** Laravel 12. **ORM:** Eloquent. **Migrations:** one file per table or per schema change, applied in chronological order; every migration is reversible.

---

# 2. Database Conventions

These conventions apply uniformly across every table in this document unless a table's own section says otherwise.

## 2.1 Primary Keys

Every table has an auto-incrementing `BIGINT UNSIGNED` primary key named `id`. Most tables that are referenced externally (by API clients, join links, invite links, export files, etc.) also carry a `CHAR(36)` `uuid` column, generated at creation time and unique-indexed - the numeric `id` is used for internal joins and ordering, the `uuid` for anything exposed outside the database. Small pivot/log-style tables that are purely internal (e.g. `role_permissions`, `poll_votes`, `group_logs`'s own row identity is rarely referenced externally) may omit `uuid` where nothing ever needs to address that row from outside a join.

## 2.2 Naming

Tables are plural, snake_case (`users`, `meeting_participants`, `conversation_keys`). Columns are snake_case. Foreign key columns are named `<singular_entity>_id` (`user_id`, `meeting_id`, `conversation_id`), except where a table has more than one relationship to the same parent table, in which case the column is named for its role (`caller_id`/`receiver_id` on `calls`, `sender_id`/`receiver_id` on `signaling_messages`, `invited_by`/`invited_user_id` on `meeting_invitations`).

## 2.3 Timestamps

Transactional tables carry `created_at`/`updated_at`. Pure append-only log/history tables (`audit_logs`, `activity_logs`, `meeting_logs`, `group_logs`, `call_history`, signaling/ICE tables, etc.) carry `created_at` only, since a log row is never updated after it is written. A handful of tables use `DATETIME` instead of `TIMESTAMP` for a specific column where a value can legitimately fall outside `TIMESTAMP`'s 2038 ceiling - `chat_settings.muted_until` ("mute forever" stores `now()->addYears(100)`) is the one instance of this.

## 2.4 Soft Deletes

Soft deletes (`deleted_at`) are used where an entity's removal needs to be reversible or where historical rows must remain joinable for reporting after a user-facing "delete": `users`, `groups`, `communities`, and `messages`. Most other tables are hard-deleted (typically via `cascadeOnDelete()` from a parent) or are themselves ephemeral/log rows with no delete/restore concept.

## 2.5 Foreign Keys

Foreign keys reference primary keys only, are indexed, and use Laravel's migration-level constraints (`constrained()`/`foreignId()`). `cascadeOnDelete()` is used where a child row has no meaning once its parent is gone (e.g. `conversation_members` when a `conversation` is deleted); `nullOnDelete()` is used where the child row should survive as an orphaned/anonymized record (e.g. `audit_logs.user_id`, `calls.escalated_to_meeting_id`). A small number of columns are intentionally left as plain unconstrained `BIGINT UNSIGNED` rather than a hard FK, where the referenced table did not exist yet at the point the column was introduced and constraining it retroactively was judged unnecessary (`conversations.last_message_id`, `chat_settings.wallpaper_id`).

## 2.6 Enums

Fixed, small vocabularies (`status`, `role`, `type` columns) are implemented as native `ENUM` columns rather than a separate lookup table, since none of them are user-editable catalogs - widening an enum (adding a new allowed value) is done via a migration; two such widenings exist for `signaling_messages.signal_type` (see §9.3).

## 2.7 Indexing

Every foreign key is indexed. Columns used as lookup keys (`email`, `phone`, `uuid`, `meeting_code`, `invite_token`, `session_id`, `device_identifier`) are unique-indexed. Composite indexes are added where a query pattern is known in advance (e.g. `(caller_id, call_status)` on `calls`, `(meeting_id, status)` on several meeting sub-tables) rather than added speculatively.

---

# 3. Schema Overview

The schema is organized into the following domains, each covered in its own section below:

| # | Domain | Table count |
|---|--------|-------------|
| 4 | Authentication & Identity | 11 |
| 5 | User Profile & Preferences | 5 |
| 6 | Contacts | 4 |
| 7 | Chat & Messaging | 18 |
| 8 | Groups & Communities | 14 |
| 9 | Calls | 5 |
| 10 | Meetings | 11 |
| 11 | Calendar & Scheduling | 4 |
| 12 | Whiteboard | 5 |
| 13 | Media & Files | 2 |
| 14 | Push Notifications | 1 |
| 15 | Administration & Access Control | 5 |
| 16 | Platform Configuration | 5 |
| 17 | Archive & Backup | 2 |
| 18 | Auditing & Activity Logs | 2 |

Every module depends, directly or transitively, on Authentication & Identity - every other table in the schema ultimately traces a foreign key back to `users`.

---

# 4. Authentication & Identity

This domain owns the `users` table and everything needed to authenticate against it: one-time codes, password resets, sessions, trusted devices, login history, two-factor authentication, passkeys, and linked OAuth identities.

## 4.1 users

The root entity for the entire platform. Every other table in this document ultimately references `users` directly or transitively.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | Primary key |
| uuid | CHAR(36) | No | generated | Unique |
| full_name | VARCHAR(150) | No | - | Indexed |
| email | VARCHAR(255) | No | - | Unique |
| phone | VARCHAR(20) | Yes | NULL | Unique; nullable for password-less SSO signups (§4.11) |
| password | VARCHAR(255) | Yes | NULL | Hashed; nullable for SSO-only accounts |
| must_change_password | BOOLEAN | No | false | Set by an admin-triggered temporary password reset; a global middleware redirects to a forced change-password page until cleared |
| profile_photo | VARCHAR(255) | Yes | NULL | |
| status | ENUM | No | pending | `pending`, `active`, `inactive`, `suspended` |
| email_verified_at | TIMESTAMP | Yes | NULL | |
| last_login_at | TIMESTAMP | Yes | NULL | Indexed |
| remember_token | VARCHAR(100) | Yes | NULL | Also backs multi-account switching (§4 relationships below) |
| created_at / updated_at | TIMESTAMP | No | current | |
| deleted_at | TIMESTAMP | Yes | NULL | Soft delete; indexed |

**Business rules:** email and phone are each globally unique; a user cannot authenticate while `status` is `inactive` or `suspended`; `password` holds only a secure hash and may be null for an account created via Google SSO; deactivation soft-deletes the account rather than hard-deleting it.

**Relationships:** one-to-one with `user_profiles`, `user_preferences`, `user_privacy`, `user_presence`, `two_factor_secrets`; one-to-many with `email_otps`, `password_reset_tokens`, `email_verifications`, `user_sessions`, `trusted_devices`, `login_attempts`, `user_devices`, `passkeys`, `user_oauth_identities`, and effectively every other table in the schema that carries a `user_id`.

**Multi-account switching:** logging into a second account without ending the first session's login is built directly on `remember_token` and `Auth::loginUsingId()` rather than a separate session/token table - a held account is never more trustworthy than its own remember-me login, and a real password change or admin force-logout invalidates it exactly the way it always would have.

## 4.2 email_otps

One-time codes used for registration/email verification, password reset initiation, email changes, and future login flows.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id | BIGINT UNSIGNED | Yes | NULL | FK `users`, null on delete |
| email | VARCHAR(255) | No | - | Indexed |
| otp | VARCHAR(10) | No | - | |
| purpose | ENUM | No | registration | `registration`, `email_verification`, `password_reset`, `change_email`, `future_login` |
| attempts | INT | No | 0 | |
| max_attempts | INT | No | 5 | |
| expires_at | TIMESTAMP | No | - | Indexed |
| verified_at | TIMESTAMP | Yes | NULL | |
| status | ENUM | No | pending | `pending`, `verified`, `expired`, `blocked` |
| created_at / updated_at | TIMESTAMP | No | current | |

A code is valid for one verification cycle only; expired or already-verified codes are rejected; expired rows are removed by scheduled cleanup.

## 4.3 password_reset_tokens

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| email | VARCHAR(255) | No | - | Indexed |
| token | VARCHAR(255) | No | - | Unique |
| expires_at | TIMESTAMP | No | - | Indexed |
| used_at | TIMESTAMP | Yes | NULL | |
| status | ENUM | No | active | `active`, `used`, `expired` |
| created_at / updated_at | TIMESTAMP | No | current | |

## 4.4 email_verifications

An append-style history of every successful email verification (registration, email change, admin-initiated, or future manual verification) - kept distinct from `users.email_verified_at`, which only ever holds the most recent value.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| email | VARCHAR(255) | No | - | Indexed |
| verification_type | ENUM | No | registration | `registration`, `email_change`, `administrator`, `future_manual` |
| verified_at | TIMESTAMP | No | current | Indexed |
| verified_ip | VARCHAR(45) | Yes | NULL | |
| user_agent | TEXT | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | |

## 4.5 user_sessions

Active and historical login sessions, supporting forced logout and device visibility.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| session_id | VARCHAR(255) | No | - | Unique |
| ip_address | VARCHAR(45) | Yes | NULL | |
| device_name / browser / operating_system / platform | VARCHAR | Yes | NULL | Raw strings, no parsing library |
| login_at | TIMESTAMP | No | current | Indexed |
| last_activity_at | TIMESTAMP | Yes | NULL | Indexed |
| logout_at | TIMESTAMP | Yes | NULL | |
| status | ENUM | No | active | `active`, `expired`, `logged_out`, `terminated` |
| created_at / updated_at | TIMESTAMP | No | current | |

## 4.6 trusted_devices

Devices a user (or the 2FA "remember this device" flow) has marked as trusted, so future sign-ins can skip repeated verification for a bounded window.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| device_identifier | VARCHAR(255) | No | - | Unique; a hash of the device's own random token - the raw token lives only in the browser's cookie |
| device_name / browser / operating_system / platform | VARCHAR | Yes | NULL | |
| last_used_at | TIMESTAMP | Yes | NULL | Indexed |
| trusted_at | TIMESTAMP | No | current | |
| expires_at | TIMESTAMP | Yes | NULL | Backs the 2FA "remember this device for 30 days" option - an explicit, queryable deadline rather than a background job computing one |
| status | ENUM | No | active | `active`, `revoked`, `expired` |
| created_at / updated_at | TIMESTAMP | No | current | |

## 4.7 login_attempts

Every login attempt, successful or not, for rate limiting and security review.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id | BIGINT UNSIGNED | Yes | NULL | FK `users`, null on delete |
| email / phone | VARCHAR | Yes | NULL | Indexed |
| ip_address | VARCHAR(45) | Yes | NULL | Indexed |
| user_agent | TEXT | Yes | NULL | |
| login_result | ENUM | No | failed | `success`, `failed`, `blocked` |
| failure_reason | VARCHAR(255) | Yes | NULL | |
| attempted_at | TIMESTAMP | No | current | Indexed |
| created_at / updated_at | TIMESTAMP | No | current | |

## 4.8 two_factor_secrets

One row per user with TOTP-based two-factor authentication enabled.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade; unique (one per user) |
| secret | TEXT | No | - | TOTP secret |
| recovery_codes | TEXT | Yes | NULL | One-time recovery codes, shown to the user exactly once at setup |
| confirmed_at | TIMESTAMP | Yes | NULL | Null means "generated but not yet confirmed with a real code" - never treated as active 2FA until then |
| created_at / updated_at | TIMESTAMP | No | current | |

## 4.9 two_factor_challenges

A short-lived "password already verified, awaiting a TOTP code" marker for the login flow - mirrors the `email_otps`/`password_reset_tokens` pattern of a DB row with an expiry rather than a signed URL or cache entry.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| remember | BOOLEAN | No | false | Carries the login form's "Remember me" choice across the extra 2FA hop |
| expires_at | TIMESTAMP | No | - | |
| verified_at | TIMESTAMP | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | |

## 4.10 passkeys

WebAuthn/FIDO2 credentials, via the official Laravel Passkeys package.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade; indexed |
| name | VARCHAR | No | - | User-assigned label |
| credential_id | VARCHAR | No | - | Unique |
| credential | JSON | No | - | Full credential payload |
| last_used_at | TIMESTAMP | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | |

A passkey ceremony is itself possession plus biometric/PIN verification - already a second factor by construction - so a passkey login deliberately skips the separate two-factor challenge in §4.9.

## 4.11 user_oauth_identities

Linked third-party identity providers (currently Google Sign-In).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| provider | VARCHAR(30) | No | - | e.g. `google` |
| provider_user_id | VARCHAR | No | - | |
| created_at / updated_at | TIMESTAMP | No | current | Unique on `(provider, provider_user_id)` and on `(user_id, provider)` |

Signing in via a linked provider matches an existing account by email automatically, or creates a new, already-verified one. Unlike a passkey, this does not skip two-factor authentication - a linked account is a distinct identity from the platform account itself.

---

# 5. User Profile & Preferences

## 5.1 user_profiles

Personal profile information, kept separate from authentication data.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade; unique (one per user) |
| first_name / last_name | VARCHAR(100) | Yes | NULL | Split automatically from `users.full_name` the first time this row is created |
| display_name | VARCHAR(150) | Yes | NULL | |
| bio | TEXT | Yes | NULL | |
| date_of_birth | DATE | Yes | NULL | |
| gender | ENUM | Yes | NULL | `male`, `female`, `other`, `prefer_not_to_say` |
| website | VARCHAR(255) | Yes | NULL | |
| avatar / cover_photo | VARCHAR(255) | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | |

## 5.2 user_preferences

Application-level preferences and per-type notification toggles.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade; unique |
| language | VARCHAR(10) | No | en | |
| timezone | VARCHAR(100) | No | UTC | |
| theme | ENUM | No | system | `light`, `dark`, `system` |
| notification_sound | BOOLEAN | No | true | |
| notify_messages | BOOLEAN | No | true | Gates the nav-wide unread badge and new-message notifications |
| notify_calls | BOOLEAN | No | true | |
| notify_meetings | BOOLEAN | No | true | Also gates meeting-reminder delivery |
| notify_contact_requests | BOOLEAN | No | true | |
| auto_download_photos | BOOLEAN | No | false | Per-type auto-download, replacing an earlier single all-or-nothing toggle |
| auto_download_videos | BOOLEAN | No | false | |
| auto_download_documents | BOOLEAN | No | false | |
| default_wallpaper_id | BIGINT UNSIGNED | Yes | NULL | References `chat_wallpapers`, null on delete; applied only when a conversation's own `chat_settings` row is first created |
| font_size | ENUM | No | medium | `small`, `medium`, `large` |
| created_at / updated_at | TIMESTAMP | No | current | |

## 5.3 user_privacy

Visibility and read-receipt controls, enforced by every module that displays presence, profile photo, about text, or read status.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade; unique |
| last_seen_visibility | ENUM | No | everyone | `everyone`, `contacts`, `nobody` |
| profile_photo_visibility | ENUM | No | everyone | `everyone`, `contacts`, `nobody` |
| about_visibility | ENUM | No | everyone | `everyone`, `contacts`, `nobody` |
| read_receipts_enabled | BOOLEAN | No | true | |
| created_at / updated_at | TIMESTAMP | No | current | |

These settings are enforced consistently by every resource that exposes presence or profile data, including the Chat and Contacts APIs alike.

## 5.4 user_devices

A catalog of every device associated with an account, distinct from the security-oriented `user_sessions`/`trusted_devices` tables.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| device_name / device_type / operating_system / browser / app_version | VARCHAR | Yes | NULL | |
| last_seen_at | TIMESTAMP | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | |

## 5.5 user_presence

The user's current presence state, refreshed by lightweight client polling.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade; unique |
| presence_status | ENUM | No | offline | `online`, `offline`, `away`, `busy`, `invisible` |
| last_seen_at | TIMESTAMP | Yes | NULL | Rendered everywhere as a relative "last seen X ago" rather than a bare status word, subject to `last_seen_visibility` |
| updated_at | TIMESTAMP | Yes | NULL | This table has no `created_at` - presence is upserted, not appended |

---

# 6. Contacts

## 6.1 contacts

Approved, in-platform contact relationships (not a device address book import).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id / contact_user_id | BIGINT UNSIGNED | No | - | Both FK `users`, cascade |
| nickname | VARCHAR(100) | Yes | NULL | |
| is_favorite | BOOLEAN | No | false | |
| muted_until | TIMESTAMP | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | Unique on `(user_id, contact_user_id)` |

A user cannot add themselves as a contact; duplicate pairs are rejected at the unique index.

## 6.2 contact_requests

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| sender_id / receiver_id | BIGINT UNSIGNED | No | - | Both FK `users`, cascade; each indexed together with `status` |
| status | ENUM | No | pending | `pending`, `accepted`, `rejected`, `cancelled` |
| message | VARCHAR(255) | Yes | NULL | |
| responded_at | TIMESTAMP | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | |

An accepted request results in a corresponding `contacts` row for both parties; a pending incoming request also surfaces as an inline banner on the chat list, not only on the dedicated requests page.

## 6.3 blocked_users

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id / blocked_user_id | BIGINT UNSIGNED | No | - | Both FK `users`, cascade; unique together |
| reason | VARCHAR | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | |

Blocking suppresses message delivery, voice/video calls, and auto-rejects contact requests between the two parties.

## 6.4 favorite_contacts

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id / contact_user_id | BIGINT UNSIGNED | No | - | Both FK `users`, cascade; unique together |
| sort_order | INT UNSIGNED | No | 0 | |
| created_at / updated_at | TIMESTAMP | No | current | |

---

# 7. Chat & Messaging

## 7.1 conversations

The parent entity for every 1:1, group, or meeting-linked thread.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| type | ENUM | No | private | `private`, `group`, `meeting`; indexed |
| title | VARCHAR | Yes | NULL | |
| last_message_id | BIGINT UNSIGNED | Yes | NULL | Not FK-constrained (see §2.5) |
| last_activity_at | TIMESTAMP | Yes | NULL | Indexed |
| is_archived | BOOLEAN | No | false | Per-conversation-wide flag; per-member archive state lives on `conversation_members` |
| disappearing_messages_seconds | INT UNSIGNED | Yes | NULL | Off/24h/7d/90d; conversation-wide (unlike `chat_settings`, which is per-member) |
| e2e_enabled | BOOLEAN | No | false | Cached flag, flipped whenever a `conversation_keys` epoch is issued or rotated, and off again once a group crosses the encryption member cap |
| created_by | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| created_at / updated_at | TIMESTAMP | No | current | |

A `type = meeting` conversation is created automatically alongside a meeting room and is excluded from the Chat list and Dashboard's recent-conversations feed. A message-privacy banner is shown at the top of every conversation, explicitly not claiming end-to-end encryption for conversations that fall outside the encryption scheme in §7.18.

## 7.2 conversation_members

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| conversation_id | BIGINT UNSIGNED | No | - | FK `conversations`, cascade |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade; indexed |
| joined_at | TIMESTAMP | No | current | |
| left_at | TIMESTAMP | Yes | NULL | A conversation with `left_at` set for the viewing member is excluded from their own conversation list |
| role | ENUM | No | member | `owner`, `admin`, `member` |
| is_muted | BOOLEAN | No | false | |
| is_archived | BOOLEAN | No | false | Per-member archive state |
| created_at / updated_at | TIMESTAMP | No | current | Unique on `(conversation_id, user_id)` |

## 7.3 messages

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| conversation_id | BIGINT UNSIGNED | No | - | FK `conversations`, cascade; indexed |
| sender_id | BIGINT UNSIGNED | No | - | FK `users`, cascade; indexed |
| reply_to_message_id | BIGINT UNSIGNED | Yes | NULL | FK `messages`, null on delete; indexed |
| message_type | ENUM | No | text | `text`, `image`, `video`, `audio`, `file`, `location`, `contact`, `system`, `one_view` |
| body | LONGTEXT | Yes | NULL | Plaintext, or ciphertext when `encryption_mode = e2e` |
| encryption_mode | ENUM | No | none | `none` (plaintext - large groups over the encryption cap, or not yet migrated), `e2e` (AES-GCM ciphertext under `key_version`, unreadable server-side), `server` (encrypted at rest with the app's own key - View Once media only) |
| key_version | INT UNSIGNED | Yes | NULL | The `conversation_keys.key_version` this body is encrypted under |
| iv | VARCHAR | Yes | NULL | |
| media_id | BIGINT UNSIGNED | Yes | NULL | FK `media`, null on delete |
| status | ENUM | No | sent | `sending`, `sent`, `delivered`, `read`, `deleted` |
| edited_at | TIMESTAMP | Yes | NULL | |
| expires_at | TIMESTAMP | Yes | NULL | Computed at send time from the conversation's `disappearing_messages_seconds`; indexed; a scheduled job soft-deletes expired rows the same way "delete for everyone" does |
| created_at / updated_at | TIMESTAMP | No | current | |
| deleted_at | TIMESTAMP | Yes | NULL | Soft delete |

**Encryption model:** text message bodies are encrypted, but deliberately not as a zero-knowledge scheme - the app itself generates and holds every conversation's key server-side (§7.18), so there is no per-user keypair and no password-derived unlock step; any current, authenticated member of a conversation can fetch its key material on demand. Group conversations above a configurable member-count cap stay plaintext, with a visible indicator. Regular (non-View-Once) media attachments are not covered by this scheme - only text bodies are.

## 7.4 message_status

Per-recipient delivery/read tracking, distinct from `messages.status` (the message's own lifecycle) and from `read_receipts` (the privacy-gated confirmation shown to the sender).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| message_id | BIGINT UNSIGNED | No | - | FK `messages`, cascade |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| status | ENUM | No | sent | `sent`, `delivered`, `read` |
| delivered_at / read_at | TIMESTAMP | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | Unique on `(message_id, user_id)` |

Delivery advances at concrete trigger points - opening a specific thread, merely syncing the chat list, or a push notification reaching a backgrounded/closed client - so "delivered" can genuinely precede "read". A message is only shown to the sender as fully read once every recipient's row reaches `read`; a dedicated per-message "Info" view exposes the exact per-recipient timestamps to the sender.

## 7.5 message_reactions

One reaction per user per message; changing it replaces the row.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| message_id | BIGINT UNSIGNED | No | - | FK `messages`, cascade |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| emoji | VARCHAR(10) | No | - | |
| created_at / updated_at | TIMESTAMP | No | current | Unique on `(message_id, user_id)` |

## 7.6 message_replies

A stable, quoted-preview snapshot of the message being replied to - distinct from `messages.reply_to_message_id` (the live FK), so the preview a replier sees survives later edits or deletion of the original.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| message_id | BIGINT UNSIGNED | No | - | FK `messages`, cascade; unique (one per message) |
| replied_to_message_id | BIGINT UNSIGNED | Yes | NULL | FK `messages`, null on delete |
| replied_to_sender_id | BIGINT UNSIGNED | Yes | NULL | FK `users`, null on delete |
| replied_to_type | VARCHAR(20) | Yes | NULL | |
| replied_to_preview | VARCHAR(255) | Yes | NULL | Ciphertext (re-encrypted client-side, with its own IV) when the quoted message is encrypted |
| replied_to_preview_iv | VARCHAR | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | |

## 7.7 forwarded_messages

Links a newly created message in the destination conversation back to the message it was forwarded from.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| message_id | BIGINT UNSIGNED | No | - | FK `messages`, cascade; unique |
| original_message_id | BIGINT UNSIGNED | Yes | NULL | FK `messages`, null on delete |
| forwarded_by | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| created_at / updated_at | TIMESTAMP | No | current | |

A forward reuses the original message's `media_id` directly rather than duplicating the underlying file.

## 7.8 pinned_messages

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| conversation_id | BIGINT UNSIGNED | No | - | FK `conversations`, cascade |
| message_id | BIGINT UNSIGNED | No | - | FK `messages`, cascade |
| pinned_by | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| pinned_at | TIMESTAMP | No | current | |
| created_at / updated_at | TIMESTAMP | No | current | Unique on `(conversation_id, message_id)` |

## 7.9 starred_messages

Private to the user who created them - starring is never visible to anyone else in the conversation.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| message_id | BIGINT UNSIGNED | No | - | FK `messages`, cascade |
| created_at / updated_at | TIMESTAMP | No | current | Unique on `(user_id, message_id)` |

## 7.10 deleted_messages

"Delete for me" - per-user hiding of a message that still exists for everyone else. "Delete for everyone" instead uses `messages.deleted_at`/`messages.status = deleted` and also removes the underlying media file once nothing else still references it.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| message_id | BIGINT UNSIGNED | No | - | FK `messages`, cascade |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| deleted_at | TIMESTAMP | No | current | Unique on `(message_id, user_id)` |

## 7.11 read_receipts

The privacy-gated "read" confirmation shown to the sender - respects the recipient's `read_receipts_enabled` setting, unlike `message_status`, which always records delivery/read internally regardless of that preference.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| message_id | BIGINT UNSIGNED | No | - | FK `messages`, cascade |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| read_at | TIMESTAMP | No | current | Unique on `(message_id, user_id)` |

## 7.12 typing_status

Ephemeral, upserted by the client's own polling; stale rows are removed by scheduled cleanup.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| conversation_id | BIGINT UNSIGNED | No | - | FK `conversations`, cascade |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| status | ENUM | No | typing | `typing`, `recording_audio`, `uploading_file` |
| updated_at | TIMESTAMP | No | current | Unique on `(conversation_id, user_id)`; no `created_at` |

## 7.13 chat_settings

Per-conversation, per-member preferences - richer than `conversation_members`' plain `is_muted`/`is_archived` booleans.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| conversation_id | BIGINT UNSIGNED | No | - | FK `conversations`, cascade |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| wallpaper_id | BIGINT UNSIGNED | Yes | NULL | Not FK-constrained (see §2.5) |
| muted_until | DATETIME | Yes | NULL | `DATETIME`, not `TIMESTAMP` - "mute forever" overflows `TIMESTAMP`'s 2038 ceiling |
| notifications_enabled | BOOLEAN | No | true | |
| created_at / updated_at | TIMESTAMP | No | current | Unique on `(conversation_id, user_id)` |

## 7.14 chat_wallpapers

A small catalog of system-default wallpapers plus any custom ones a user has configured.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id | BIGINT UNSIGNED | Yes | NULL | FK `users`, cascade; null for system defaults |
| name | VARCHAR | Yes | NULL | |
| type | ENUM | No | default | `color`, `image`, `default` |
| value | VARCHAR | Yes | NULL | |
| is_system_default | BOOLEAN | No | false | Six default color wallpapers are seeded at install |
| created_at / updated_at | TIMESTAMP | No | current | |

## 7.15 drafts

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| conversation_id | BIGINT UNSIGNED | No | - | FK `conversations`, cascade |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| body | LONGTEXT | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | Unique on `(conversation_id, user_id)` |

## 7.16 broadcast_lists

A pure distribution list, not a shared conversation - sending fans out to separate 1:1 conversations, so replies stay private per-recipient.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| owner_id | BIGINT UNSIGNED | No | - | FK `users`, cascade; indexed |
| name | VARCHAR(150) | No | - | |
| created_at / updated_at | TIMESTAMP | No | current | |

## 7.17 broadcast_list_recipients

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| broadcast_list_id | BIGINT UNSIGNED | No | - | FK `broadcast_lists`, cascade |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| created_at / updated_at | TIMESTAMP | No | current | Unique on `(broadcast_list_id, user_id)` |

## 7.18 conversation_keys

One row per "epoch" of a conversation's message-encryption key. A new epoch is issued whenever a conversation first turns on encryption, or whenever a group's membership changes - messages already sent stay readable under their original epoch, and a member removed from the group can never decrypt anything sent under a later one.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| conversation_id | BIGINT UNSIGNED | No | - | FK `conversations`, cascade |
| key_version | INT UNSIGNED | No | - | Unique together with `conversation_id` |
| key_material | TEXT | Yes | NULL | The conversation's AES-256-GCM key, generated server-side and stored encrypted at rest with the application's own key |
| issued_for_member_ids | JSON | Yes | NULL | The member set this epoch was issued for, used to detect when a later membership change requires a new epoch |
| created_at / updated_at | TIMESTAMP | No | current | |

Access is checked live at fetch time - any current, authenticated member of the conversation may retrieve a given epoch's key material - rather than being fixed at the moment the epoch was issued. Clients cache fetched key material locally and only re-fetch on a cache miss.

---

# 8. Groups & Communities

## 8.1 groups

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| conversation_id | BIGINT UNSIGNED | No | - | FK `conversations`, cascade; unique (one group per conversation) |
| owner_id | BIGINT UNSIGNED | No | - | FK `users` |
| community_id | BIGINT UNSIGNED | Yes | NULL | FK `communities`, null on delete - a group belongs to at most one community |
| name | VARCHAR(150) | No | - | |
| description | TEXT | Yes | NULL | |
| avatar | VARCHAR | Yes | NULL | |
| invite_code | VARCHAR(50) | Yes | NULL | Unique |
| member_limit | INT UNSIGNED | No | 512 | |
| approval_required | BOOLEAN | No | false | |
| admin_only_messaging | BOOLEAN | No | false | |
| admin_only_edit_info | BOOLEAN | No | true | |
| admin_only_invite | BOOLEAN | No | false | |
| admin_only_pin | BOOLEAN | No | true | |
| allow_member_reactions | BOOLEAN | No | true | |
| allow_member_file_sharing | BOOLEAN | No | true | |
| status | ENUM | No | active | `active`, `archived`, `deleted`; indexed |
| created_at / updated_at | TIMESTAMP | No | current | |
| deleted_at | TIMESTAMP | Yes | NULL | Soft delete |

Group chat messages use the same `conversations`/`messages` tables as 1:1 chat - a group is a thin ownership/permission layer on top of a `type = group` conversation, not a separate messaging engine.

## 8.2 group_roles

Default roles are Owner, Admin, Moderator, Member; custom roles may also be created per group.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| group_id | BIGINT UNSIGNED | No | - | FK `groups`, cascade |
| role_name | VARCHAR(50) | No | - | Unique with `group_id` |
| description | VARCHAR | Yes | NULL | |
| is_system | BOOLEAN | No | false | |
| created_at / updated_at | TIMESTAMP | No | current | |

## 8.3 group_members

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| group_id | BIGINT UNSIGNED | No | - | FK `groups`, cascade |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade; indexed |
| role_id | BIGINT UNSIGNED | No | - | FK `group_roles` |
| joined_at | TIMESTAMP | No | current | |
| left_at | TIMESTAMP | Yes | NULL | |
| is_muted / is_admin_muted | BOOLEAN | No | false | Self-muted vs. muted by an admin |
| created_at / updated_at | TIMESTAMP | No | current | Unique on `(group_id, user_id)` |

## 8.4 group_permissions

Per-role permission toggles within a group (distinct from the platform-wide `permissions`/`role_permissions` in §15).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| role_id | BIGINT UNSIGNED | No | - | FK `group_roles`, cascade |
| permission_key | VARCHAR(60) | No | - | Unique with `role_id` |
| is_allowed | BOOLEAN | No | false | |
| created_at / updated_at | TIMESTAMP | No | current | |

## 8.5 group_invites

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| group_id | BIGINT UNSIGNED | No | - | FK `groups`, cascade |
| invited_by | BIGINT UNSIGNED | No | - | FK `users` |
| invited_user_id | BIGINT UNSIGNED | Yes | NULL | FK `users`, null on delete |
| invite_token | VARCHAR(64) | No | - | Unique |
| expires_at | TIMESTAMP | Yes | NULL | |
| accepted_at | TIMESTAMP | Yes | NULL | |
| status | ENUM | No | pending | `pending`, `accepted`, `revoked`, `expired`; indexed |
| created_at / updated_at | TIMESTAMP | No | current | |

## 8.6 group_join_requests

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| group_id | BIGINT UNSIGNED | No | - | FK `groups`, cascade |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| message | VARCHAR | Yes | NULL | |
| status | ENUM | No | pending | `pending`, `approved`, `rejected`, `cancelled` |
| reviewed_by | BIGINT UNSIGNED | Yes | NULL | FK `users`, null on delete |
| reviewed_at | TIMESTAMP | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | Indexed on `(group_id, status)` |

## 8.7 group_announcements

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| group_id | BIGINT UNSIGNED | No | - | FK `groups`, cascade; indexed |
| created_by | BIGINT UNSIGNED | No | - | FK `users` |
| title | VARCHAR | No | - | |
| announcement | TEXT | No | - | |
| is_pinned | BOOLEAN | No | false | |
| expires_at | TIMESTAMP | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | |

## 8.8 group_polls

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| group_id | BIGINT UNSIGNED | No | - | FK `groups`, cascade; indexed |
| created_by | BIGINT UNSIGNED | No | - | FK `users` |
| question | VARCHAR | No | - | |
| allow_multiple_votes | BOOLEAN | No | false | |
| closes_at | TIMESTAMP | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | |

## 8.9 poll_options

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| poll_id | BIGINT UNSIGNED | No | - | FK `group_polls`, cascade; indexed |
| option_text | VARCHAR | No | - | |
| sort_order | INT UNSIGNED | No | 0 | |
| created_at / updated_at | TIMESTAMP | No | current | |

## 8.10 poll_votes

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| poll_id | BIGINT UNSIGNED | No | - | FK `group_polls`, cascade |
| option_id | BIGINT UNSIGNED | No | - | FK `poll_options`, cascade |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| created_at | TIMESTAMP | No | current | Unique on `(poll_id, option_id, user_id)` |

## 8.11 group_events

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| group_id | BIGINT UNSIGNED | No | - | FK `groups`, cascade |
| title | VARCHAR | No | - | |
| description | TEXT | Yes | NULL | |
| event_start | DATETIME | No | - | Indexed with `group_id` |
| event_end | DATETIME | Yes | NULL | |
| created_by | BIGINT UNSIGNED | No | - | FK `users` |
| created_at / updated_at | TIMESTAMP | No | current | |

## 8.12 group_media

An index of media shared within a group; the physical file is owned by the Media domain (§13).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| group_id | BIGINT UNSIGNED | No | - | FK `groups`, cascade; indexed |
| message_id | BIGINT UNSIGNED | Yes | NULL | FK `messages`, null on delete |
| media_id | BIGINT UNSIGNED | Yes | NULL | FK `media`, null on delete |
| uploaded_by | BIGINT UNSIGNED | No | - | FK `users` |
| created_at | TIMESTAMP | No | current | No `updated_at` |

## 8.13 group_logs

Append-only significant-activity log (group created, member joined/removed, role changed, poll created, announcement published, group deleted, and similar events).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| group_id | BIGINT UNSIGNED | No | - | FK `groups`, cascade |
| user_id | BIGINT UNSIGNED | Yes | NULL | FK `users`, null on delete |
| event | VARCHAR(60) | No | - | |
| metadata | JSON | Yes | NULL | |
| created_at | TIMESTAMP | No | current | Indexed on `(group_id, created_at)`; no `updated_at` |

## 8.14 communities

A group-of-groups container above regular Groups; creating one auto-creates a default "Announcements" group.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| owner_id | BIGINT UNSIGNED | No | - | FK `users` |
| name | VARCHAR(150) | No | - | |
| description | TEXT | Yes | NULL | |
| avatar | VARCHAR | Yes | NULL | |
| announcement_group_id | BIGINT UNSIGNED | Yes | NULL | FK `groups`, null on delete - the auto-created Announcements group |
| created_at / updated_at | TIMESTAMP | No | current | |
| deleted_at | TIMESTAMP | Yes | NULL | Soft delete - "delete community" is reversible, owner-only |

Any group owned by, or attached to, the community may have its announcement/attachment rights delegated to that group's own admins, reusing the group role/permission system in §8.2-8.4 rather than a parallel one. The one Announcements group belonging to a community cannot itself be detached.

---

# 9. Calls

Direct 1:1 voice/video calling. Media streams flow peer-to-peer over WebRTC; these tables store only signaling, metadata, and history - polling-based, with no WebSocket/Node.js dependency.

## 9.1 calls

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| caller_id / receiver_id | BIGINT UNSIGNED | No | - | Both FK `users`; each indexed together with `call_status` |
| conversation_id | BIGINT UNSIGNED | Yes | NULL | FK `conversations`, null on delete - links the call back to the 1:1 chat it was started from, so it can log an inline call-history row there |
| call_type | ENUM | No | - | `voice`, `video` |
| call_status | ENUM | No | ringing | `ringing`, `accepted`, `rejected`, `missed`, `cancelled`, `ended` |
| media_state | JSON | Yes | NULL | The other party's live mute/camera-off state, keyed by caller/receiver |
| started_at | TIMESTAMP | No | current | |
| answered_at | TIMESTAMP | Yes | NULL | |
| ended_at | TIMESTAMP | Yes | NULL | |
| duration_seconds | INT UNSIGNED | Yes | NULL | |
| escalated_to_meeting_id | BIGINT UNSIGNED | Yes | NULL | FK `meetings`, null on delete - set when "Add member" escalates this call into a full meeting |
| created_at / updated_at | TIMESTAMP | No | current | |

## 9.2 call_history

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| call_id | BIGINT UNSIGNED | No | - | FK `calls`, cascade; unique |
| caller_id / receiver_id | BIGINT UNSIGNED | No | - | Both FK `users`, each indexed |
| duration_seconds | INT UNSIGNED | No | 0 | |
| end_reason | VARCHAR(50) | No | - | |
| network_quality | VARCHAR(20) | Yes | NULL | |
| created_at | TIMESTAMP | No | current | No `updated_at` |

## 9.3 signaling_messages

WebRTC offer/answer exchange for call setup, plus in-call reaction and screen-share signals riding the same channel.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| call_id | BIGINT UNSIGNED | No | - | FK `calls`, cascade; indexed with `id` |
| sender_id / receiver_id | BIGINT UNSIGNED | No | - | Both FK `users` |
| signal_type | ENUM | No | - | `offer`, `answer`, `reaction`, `screen-share` - widened twice from an original `offer`/`answer`-only set |
| payload | LONGTEXT | No | - | |
| processed_at | TIMESTAMP | Yes | NULL | |
| expires_at | TIMESTAMP | No | - | |
| created_at | TIMESTAMP | No | current | No `updated_at` |

Clients poll for signals newer than the highest id they have already consumed, the same pattern proven for chat message polling.

## 9.4 ice_candidates

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| call_id | BIGINT UNSIGNED | No | - | FK `calls`, cascade; indexed with `id` |
| sender_id / receiver_id | BIGINT UNSIGNED | No | - | Both FK `users` |
| candidate | LONGTEXT | No | - | |
| processed_at | TIMESTAMP | Yes | NULL | |
| created_at | TIMESTAMP | No | current | No `updated_at` |

## 9.5 call_statistics

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| call_id | BIGINT UNSIGNED | No | - | FK `calls`, cascade; indexed |
| packet_loss_percent | DECIMAL(5,2) | Yes | NULL | |
| avg_latency_ms / jitter_ms / connection_time_ms | INT UNSIGNED | Yes | NULL | |
| disconnect_count | INT UNSIGNED | No | 0 | |
| created_at | TIMESTAMP | No | current | No `updated_at` |

---

# 10. Meetings

N-way meetings, including guest access, waiting rooms, permissions, in-meeting chat, and post-meeting reporting. Multi-party WebRTC uses a full mesh, negotiated via the same polling-based signaling pattern as 1:1 Calls, consistent with this platform's no-WebSocket architecture and small-group video design target.

## 10.1 meetings

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| organizer_id | BIGINT UNSIGNED | Yes | NULL | FK `users`, null on delete - nullable to support guest-created meetings, which have no authenticated organizer |
| meeting_code | VARCHAR(12) | No | - | Unique |
| title | VARCHAR | No | - | |
| description | TEXT | Yes | NULL | |
| meeting_type | ENUM | No | - | `instant`, `scheduled` |
| password | VARCHAR | Yes | NULL | Hashed, never stored or compared in plaintext |
| scheduled_start / scheduled_end | TIMESTAMP | Yes | NULL | |
| timezone | VARCHAR | Yes | NULL | |
| actual_start / actual_end | TIMESTAMP | Yes | NULL | |
| guest_enabled | BOOLEAN | No | true | |
| waiting_room_enabled | BOOLEAN | No | true | |
| status | ENUM | No | scheduled | `scheduled`, `waiting`, `active`, `locked`, `ended`, `cancelled`; indexed with `organizer_id` |
| created_at / updated_at | TIMESTAMP | No | current | `scheduled_start` indexed |

A guest with no account can create an instant meeting and becomes its own `host` participant; a scheduled cron job ends any active/locked meeting with no genuinely present participant (by heartbeat, not a raw departure flag) for a full five minutes, covering both an abandoned lobby and an emptied-out meeting.

## 10.2 meeting_guest_sessions

A guest never registers - identity is a display name plus a secure session token embedded in the join link.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| meeting_id | BIGINT UNSIGNED | No | - | FK `meetings`, cascade |
| guest_name | VARCHAR | No | - | |
| session_token | VARCHAR(64) | No | - | Unique |
| joined_at | TIMESTAMP | Yes | NULL | |
| expires_at | TIMESTAMP | No | - | Default 15-minute expiry, admin-configurable |
| status | ENUM | No | pending | `pending`, `active`, `expired`, `removed`; indexed with `meeting_id` |
| created_at / updated_at | TIMESTAMP | No | current | |

## 10.3 meeting_participants

Either `user_id` (registered) or `guest_session_id` (guest) is set, never both - enforced at the service layer.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| meeting_id | BIGINT UNSIGNED | No | - | FK `meetings`, cascade |
| user_id | BIGINT UNSIGNED | Yes | NULL | FK `users`, cascade; indexed with `meeting_id` |
| guest_session_id | BIGINT UNSIGNED | Yes | NULL | FK `meeting_guest_sessions`, cascade |
| role | ENUM | No | participant | `host`, `co_host`, `presenter`, `participant` |
| joined_at | TIMESTAMP | No | current | |
| left_at | TIMESTAMP | Yes | NULL | Indexed with `meeting_id` |
| last_seen_at | TIMESTAMP | Yes | NULL | Heartbeat refreshed by the client's own roster poll; the live roster filters out anyone stale for more than a configurable window, independent of whether `left_at` was ever explicitly set |
| microphone_enabled | BOOLEAN | No | true | |
| camera_enabled | BOOLEAN | No | false | |
| screen_sharing | BOOLEAN | No | false | |
| hand_raised | BOOLEAN | No | false | |
| created_at / updated_at | TIMESTAMP | No | current | |

This table is the shared "whoever is in this meeting, guest or not" abstraction reused by the Whiteboard domain (§12) for its own guest-compatible creator/actor columns.

## 10.4 meeting_invitations

One row per invited registered user, or per ad-hoc invited email address that doesn't (yet) match an account.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| meeting_id | BIGINT UNSIGNED | No | - | FK `meetings`, cascade |
| invited_by | BIGINT UNSIGNED | No | - | FK `users` |
| invited_user_id | BIGINT UNSIGNED | Yes | NULL | FK `users` - nullable to support ad-hoc email invites |
| invited_email | VARCHAR | Yes | NULL | Set instead of `invited_user_id` for an address not matching an existing account; receives the invite and calendar file by email with a guest join link |
| invitation_status | ENUM | No | pending | `pending`, `accepted`, `declined`, `tentative` |
| sent_at / accepted_at / declined_at | TIMESTAMP | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | Unique on `(meeting_id, invited_user_id)` |

Calendar invite files include a per-recipient RSVP attendee line and a sequence number, so calendar clients render a real actionable invite.

## 10.5 meeting_waiting_room

One row per join attempt while the waiting room is enabled; covers both registered users and guests without two separate nullable FK columns, since a guest requesting entry has no `meeting_guest_sessions` row until admitted.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| meeting_id | BIGINT UNSIGNED | No | - | FK `meetings`, cascade |
| participant_type | ENUM | No | - | `user`, `guest` |
| user_id | BIGINT UNSIGNED | Yes | NULL | FK `users`, cascade |
| guest_name | VARCHAR | Yes | NULL | |
| requested_at | TIMESTAMP | No | current | |
| admitted_at | TIMESTAMP | Yes | NULL | |
| status | ENUM | No | waiting | `waiting`, `admitted`, `rejected`; indexed with `meeting_id` |
| created_at / updated_at | TIMESTAMP | No | current | |

## 10.6 meeting_settings

One-to-one with `meetings`; per-meeting feature toggles and permission modes.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| meeting_id | BIGINT UNSIGNED | No | - | FK `meetings`, cascade; unique |
| chat_enabled / whiteboard_enabled / screen_share_enabled | BOOLEAN | No | true | |
| whiteboard_permission | ENUM | No | all_participants | `host_only`, `host_and_co_host`, `all_participants`, `view_only` |
| screen_share_permission | ENUM | No | host_only | `host_only`, `all_participants` |
| auto_mute_on_join | BOOLEAN | No | false | |
| created_at / updated_at | TIMESTAMP | No | current | |

Capabilities such as "mute others" or "remove participant" are enforced as plain role checks (host/co-host) in the service layer rather than a separate per-participant ACL table.

## 10.7 meeting_chat

A thin, one-to-one pointer from a meeting to its `type = meeting` conversation; the conversation/message engine itself is entirely reused from Chat (§7).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| meeting_id | BIGINT UNSIGNED | No | - | FK `meetings`, cascade; unique |
| conversation_id | BIGINT UNSIGNED | No | - | FK `conversations`, cascade |
| created_at | TIMESTAMP | No | current | No `updated_at` |

## 10.8 meeting_logs

Append-only meeting activity log (created, participant joined/left, screen share started, whiteboard opened, meeting ended, and similar events).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| meeting_id | BIGINT UNSIGNED | No | - | FK `meetings`, cascade |
| user_id | BIGINT UNSIGNED | Yes | NULL | FK `users`, null on delete |
| event | VARCHAR | No | - | |
| context | JSON | Yes | NULL | |
| created_at | TIMESTAMP | No | current | Indexed with `meeting_id`; no `updated_at` |

## 10.9 meeting_reports

One row, computed when a meeting ends.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| meeting_id | BIGINT UNSIGNED | No | - | FK `meetings`, cascade; unique |
| duration_seconds | INT UNSIGNED | No | 0 | |
| total_participants | INT UNSIGNED | No | 0 | |
| guest_count | INT UNSIGNED | No | 0 | |
| screen_share_seconds | INT UNSIGNED | No | 0 | |
| whiteboard_object_count | INT UNSIGNED | No | 0 | |
| chat_message_count | INT UNSIGNED | No | 0 | |
| created_at / updated_at | TIMESTAMP | No | current | |

## 10.10 meeting_signals

Multi-party WebRTC offer/answer exchange, mirroring `signaling_messages` (§9.3) but keyed by participant pair for full-mesh negotiation.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| meeting_id | BIGINT UNSIGNED | No | - | FK `meetings`, cascade |
| sender_participant_id / receiver_participant_id | BIGINT UNSIGNED | No | - | Both FK `meeting_participants`, cascade; `receiver_participant_id` indexed with `id` |
| signal_type | ENUM | No | - | `offer`, `answer` |
| payload | LONGTEXT | No | - | |
| created_at | TIMESTAMP | No | current | No `updated_at` |

## 10.11 meeting_ice_candidates

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| meeting_id | BIGINT UNSIGNED | No | - | FK `meetings`, cascade |
| sender_participant_id / receiver_participant_id | BIGINT UNSIGNED | No | - | Both FK `meeting_participants`, cascade; `receiver_participant_id` indexed with `id` |
| candidate | LONGTEXT | No | - | |
| created_at | TIMESTAMP | No | current | No `updated_at` |

---

# 11. Calendar & Scheduling

A user's own calendar is simply their `calendar_events` rows - there is no separate one-per-user calendar container table.

## 11.1 recurring_events

The recurrence rule shared by a series of `calendar_events`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| created_by | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| frequency | ENUM | No | - | `daily`, `weekly`, `monthly`, `yearly` |
| interval | INT UNSIGNED | No | 1 | |
| occurrences | INT UNSIGNED | Yes | NULL | |
| ends_on | DATE | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | |

## 11.2 calendar_events

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| created_by | BIGINT UNSIGNED | No | - | FK `users`, cascade; indexed with `start_at` |
| meeting_id | BIGINT UNSIGNED | Yes | NULL | FK `meetings`, cascade - null for non-meeting event types |
| event_type | ENUM | No | - | `meeting`, `reminder`, `personal`, `holiday`, `task` |
| title | VARCHAR | No | - | |
| description | TEXT | Yes | NULL | |
| start_at / end_at | TIMESTAMP | No | - | |
| timezone | VARCHAR | No | - | |
| recurring_event_id | BIGINT UNSIGNED | Yes | NULL | FK `recurring_events`, null on delete |
| status | ENUM | No | scheduled | `draft`, `scheduled`, `active`, `completed`, `cancelled`, `archived` |
| created_at / updated_at | TIMESTAMP | No | current | |

Dragging an event in the Week/Day/Month calendar view reschedules the underlying meeting, updating both this table and `meetings` atomically; a bottom-edge resize handle adjusts `end_at` alone.

## 11.3 calendar_rsvp

One row per invited attendee's RSVP for a calendar event.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| calendar_event_id | BIGINT UNSIGNED | No | - | FK `calendar_events`, cascade |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| status | ENUM | No | pending | `pending`, `accepted`, `declined`, `tentative` |
| created_at / updated_at | TIMESTAMP | No | current | Unique on `(calendar_event_id, user_id)` |

## 11.4 calendar_reminders

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| calendar_event_id | BIGINT UNSIGNED | No | - | FK `calendar_events`, cascade; indexed with `sent_at` |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| remind_before_minutes | INT UNSIGNED | No | - | Default lead time is 15 minutes |
| channel | ENUM | No | in_app | `email`, `in_app` |
| sent_at | TIMESTAMP | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | |

A due reminder delivers as a real push notification (gated on the recipient's meeting-notification preference) and, for a meeting-linked reminder specifically, also posts a system message into the organizer's 1:1 conversation with the invitee.

---

# 12. Whiteboard

A collaborative drawing surface scoped to a meeting, usable by both registered participants and guests.

## 12.1 whiteboards

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| meeting_id | BIGINT UNSIGNED | No | - | FK `meetings`, cascade |
| title | VARCHAR | No | Whiteboard | |
| created_by | BIGINT UNSIGNED | Yes | NULL | FK `users` - set when created by a registered participant |
| created_by_participant_id | BIGINT UNSIGNED | Yes | NULL | FK `meeting_participants`, null on delete - set instead when created by a guest; exactly one of this pair is populated per row |
| permission_mode | ENUM | No | all_participants | `host_only`, `host_and_co_host`, `all_participants`, `view_only` |
| status | ENUM | No | active | `active`, `closed`, `archived` |
| created_at / updated_at | TIMESTAMP | No | current | |

## 12.2 whiteboard_pages

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| whiteboard_id | BIGINT UNSIGNED | No | - | FK `whiteboards`, cascade |
| page_number | INT UNSIGNED | No | - | Unique with `whiteboard_id` |
| name | VARCHAR | Yes | NULL | |
| created_at | TIMESTAMP | No | current | No `updated_at` |

## 12.3 whiteboard_objects

One row per drawn/placed object; the same creator/participant pairing as `whiteboards` supports guest-drawn objects.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| whiteboard_page_id | BIGINT UNSIGNED | No | - | FK `whiteboard_pages`, cascade; indexed with `is_deleted, id` |
| tool | ENUM | No | - | `pen`, `line`, `rectangle`, `circle`, `arrow`, `text`, `sticky_note`, `image` |
| data | JSON | No | - | Tool-specific geometry/content payload |
| color | VARCHAR(16) | Yes | NULL | |
| thickness | INT UNSIGNED | Yes | NULL | |
| z_index | INT UNSIGNED | No | 0 | |
| created_by | BIGINT UNSIGNED | Yes | NULL | FK `users` |
| created_by_participant_id | BIGINT UNSIGNED | Yes | NULL | FK `meeting_participants`, null on delete |
| is_deleted | BOOLEAN | No | false | A soft flag distinct from Eloquent `SoftDeletes`, so undo can resurrect an object without disturbing timestamp semantics |
| created_at / updated_at | TIMESTAMP | No | current | |

Drawing uses Pointer Events with `setPointerCapture()`, so both mouse and touch input work identically in the registered and guest rooms.

## 12.4 whiteboard_history

A whiteboard-wide (not per-user) undo/redo operation log.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| whiteboard_id | BIGINT UNSIGNED | No | - | FK `whiteboards`, cascade; indexed with `is_undone, id` |
| whiteboard_object_id | BIGINT UNSIGNED | No | - | FK `whiteboard_objects`, cascade |
| action | ENUM | No | - | `create`, `update`, `delete` |
| previous_state / new_state | JSON | Yes | NULL | |
| actor_id | BIGINT UNSIGNED | Yes | NULL | FK `users` |
| actor_participant_id | BIGINT UNSIGNED | Yes | NULL | FK `meeting_participants`, null on delete |
| is_undone | BOOLEAN | No | false | Marks entries currently reversed, so redo can find the next one |
| created_at | TIMESTAMP | No | current | No `updated_at` |

## 12.5 whiteboard_exports

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| whiteboard_id | BIGINT UNSIGNED | No | - | FK `whiteboards`, cascade |
| media_id | BIGINT UNSIGNED | No | - | FK `media`, cascade - the browser renders the export client-side and uploads it through the normal Media pipeline |
| format | ENUM | No | - | `png`, `jpg`, `pdf` |
| exported_by | BIGINT UNSIGNED | Yes | NULL | FK `users` |
| exported_by_participant_id | BIGINT UNSIGNED | Yes | NULL | FK `meeting_participants`, null on delete |
| created_at | TIMESTAMP | No | current | No `updated_at` |

---

# 13. Media & Files

## 13.1 media

The single master table for every uploaded file. Physical bytes live on disk (or a future remote disk); this table stores metadata only.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| uploader_id | BIGINT UNSIGNED | No | - | FK `users`, cascade; indexed |
| disk | VARCHAR(50) | No | local | |
| path | VARCHAR | No | - | |
| original_filename | VARCHAR | No | - | |
| mime_type | VARCHAR(150) | No | - | |
| size_bytes | BIGINT UNSIGNED | No | - | |
| media_type | ENUM | No | - | `image`, `video`, `audio`, `document` |
| is_voice_note | BOOLEAN | No | false | |
| duration_seconds | INT UNSIGNED | Yes | NULL | |
| is_encrypted_at_rest | BOOLEAN | No | false | Set for View Once uploads, which are encrypted at rest with the application's own key from the moment they are created |
| created_at / updated_at | TIMESTAMP | No | current | |

A scheduled job deletes orphaned media: a View Once file left over past its grace window, or any file referenced by nothing at all (no message, group-media entry, or whiteboard export) - covering both abandoned uploads and residue from before file cleanup on "delete for everyone" was added.

## 13.2 one_view_media

Metadata for a single-view ("View Once") attachment.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| message_id | BIGINT UNSIGNED | No | - | FK `messages`, cascade; unique |
| viewed_by | BIGINT UNSIGNED | Yes | NULL | FK `users`, null on delete |
| viewed_at | TIMESTAMP | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | |

A standard viewer may open the file once, after which both sender and receiver see it as opened; the server copy is deleted immediately once consumed (normally inline in the same request that streams it, with the scheduled orphan sweep in §13.1 as a safety net for an interrupted stream). The sender may never preview their own unconsumed upload.

---

# 14. Push Notifications

## 14.1 push_subscriptions

One row per subscribed device/browser, used to deliver real OS-level push notifications (new message, incoming call, missed call, contact request) via Firebase Cloud Messaging even when the app is fully closed.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade; indexed |
| fcm_token | TEXT | No | - | The Firebase Cloud Messaging registration token for this device |
| user_agent | VARCHAR(150) | Yes | NULL | Raw truncated string, identifying the subscription in a "manage your devices" list |
| created_at / updated_at | TIMESTAMP | No | current | |

Every notification is sent data-only (no FCM-rendered notification payload), so the client's own service worker stays in full control of what is displayed and how a reply/call-back action behaves.

---

# 15. Administration & Access Control

Role-based access control for the Admin panel, layered independently of the per-group roles in §8.2-8.4.

## 15.1 roles

Default roles are Super Admin, Admin, Moderator, User, Guest.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| name | VARCHAR | No | - | Unique |
| description | VARCHAR | Yes | NULL | |
| is_system | BOOLEAN | No | false | |
| created_at / updated_at | TIMESTAMP | No | current | |

## 15.2 permissions

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| permission_key | VARCHAR | No | - | Unique |
| permission_name | VARCHAR | No | - | |
| module | VARCHAR(100) | No | - | Indexed |
| created_at / updated_at | TIMESTAMP | No | current | |

Sensitive, narrowly-scoped permissions (e.g. `manage_backups`, `manage_environment` for the Admin environment-variable editor) are granted to the Super Admin role only, never the plain Admin role, by direct migration rather than relying solely on a re-run seeder - a real migration is what actually rolls a new permission out to an already-deployed installation.

## 15.3 role_permissions

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| role_id | BIGINT UNSIGNED | No | - | FK `roles`, cascade |
| permission_id | BIGINT UNSIGNED | No | - | FK `permissions`, cascade |
| created_at | TIMESTAMP | No | current | Unique on `(role_id, permission_id)`; no `updated_at` |

## 15.4 user_roles

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade |
| role_id | BIGINT UNSIGNED | No | - | FK `roles`, cascade |
| assigned_by | BIGINT UNSIGNED | Yes | NULL | FK `users`, null on delete |
| assigned_at | TIMESTAMP | No | current | Unique on `(user_id, role_id)` |

## 15.5 admins

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id | BIGINT UNSIGNED | No | - | FK `users`, cascade; unique |
| admin_level | VARCHAR(50) | No | admin | |
| is_super_admin | BOOLEAN | No | false | |
| created_at / updated_at | TIMESTAMP | No | current | |

---

# 16. Platform Configuration

Central, admin-editable configuration, so operational settings can change without a code deployment.

## 16.1 settings

The general key/value registry backing every admin-configurable value that doesn't warrant its own dedicated table (retention windows, session timeouts, login-attempt limits, polling intervals, meeting/call defaults, and similar values).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| setting_key | VARCHAR | No | - | Unique |
| setting_value | TEXT | Yes | NULL | |
| category | VARCHAR(100) | No | - | Indexed |
| data_type | ENUM | No | string | `string`, `integer`, `boolean`, `json` |
| is_editable | BOOLEAN | No | true | |
| created_at / updated_at | TIMESTAMP | No | current | |

## 16.2 branding_settings

Single-row table (enforced by convention, not a schema constraint) covering both branding and Progressive Web App presentation.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| app_name | VARCHAR | No | Meet | |
| pwa_short_name | VARCHAR(20) | No | Meet | |
| company_name | VARCHAR | Yes | NULL | |
| logo_path / favicon_path | VARCHAR | Yes | NULL | |
| theme_color | VARCHAR(16) | No | #4f46e5 | |
| pwa_background_color | VARCHAR(16) | No | #ffffff | |
| pwa_display_mode | ENUM | No | standalone | `standalone`, `fullscreen`, `minimal-ui`, `browser` |
| login_background_path | VARCHAR | Yes | NULL | |
| footer_text | TEXT | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | |

Progressive Web App configuration (short name, background color, display mode) is folded directly into this table rather than kept as a separate one, since it is a close sibling of the branding fields the table already stores.

## 16.3 smtp_settings

Single-row outbound mail configuration.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| host | VARCHAR | Yes | NULL | |
| port | INT UNSIGNED | Yes | NULL | |
| encryption | ENUM | No | tls | `none`, `tls`, `ssl` |
| username | VARCHAR | Yes | NULL | |
| password | TEXT | Yes | NULL | Encrypted at the model layer; never plaintext at rest |
| from_name / from_email | VARCHAR | Yes | NULL | |
| last_tested_at | TIMESTAMP | Yes | NULL | |
| last_test_succeeded | BOOLEAN | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | |

## 16.4 feature_flags

Simple key/enabled toggles for enabling or disabling a platform feature without a code change.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| feature_key | VARCHAR | No | - | Unique |
| label | VARCHAR | No | - | |
| is_enabled | BOOLEAN | No | true | |
| created_at / updated_at | TIMESTAMP | No | current | |

## 16.5 retention_policies

One row per data category, controlling how long that category's rows are retained before scheduled cleanup.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| category | VARCHAR(100) | No | - | Unique |
| retention_value | INT UNSIGNED | Yes | NULL | Null means "never" |
| retention_unit | ENUM | No | days | `hours`, `days` |
| created_at / updated_at | TIMESTAMP | No | current | |

---

# 17. Archive & Backup

Scheduled and on-demand system archiving, delivered by email to a configured recipient.

## 17.1 archive_settings

Single-row configuration for the archive engine.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| recipient_email | VARCHAR | No | private@example.org | |
| archive_password | TEXT | No | - | Encrypted at the model layer |
| password_protection_enabled | BOOLEAN | No | true | |
| schedule_frequency | ENUM | No | weekly | `daily`, `weekly`, `monthly`, `manual_only` |
| created_at / updated_at | TIMESTAMP | No | current | |

## 17.2 archives

One row per archive run, scheduled or manually triggered.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| scope | ENUM | No | entire_system | `entire_system`, `database_only`, `files_only` |
| trigger | ENUM | No | manual | `scheduled`, `manual` |
| requested_by | BIGINT UNSIGNED | Yes | NULL | FK `users`, null on delete |
| status | ENUM | No | queued | `queued`, `processing`, `completed`, `delivered`, `failed` |
| disk_path | VARCHAR | Yes | NULL | |
| size_bytes | BIGINT UNSIGNED | Yes | NULL | |
| checksum | VARCHAR(64) | Yes | NULL | |
| failure_reason | TEXT | Yes | NULL | |
| delivered_at | TIMESTAMP | Yes | NULL | |
| created_at / updated_at | TIMESTAMP | No | current | |

---

# 18. Auditing & Activity Logs

## 18.1 audit_logs

Permanent, append-only records of administrative and security-sensitive operations - never edited or deleted through the UI.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| uuid | CHAR(36) | No | generated | Unique |
| user_id | BIGINT UNSIGNED | Yes | NULL | FK `users`, null on delete |
| module | VARCHAR(100) | No | - | Indexed with `action` |
| action | VARCHAR(100) | No | - | |
| record_type | VARCHAR | Yes | NULL | Indexed with `record_id` |
| record_id | BIGINT UNSIGNED | Yes | NULL | |
| old_values / new_values | JSON | Yes | NULL | Before/after diff |
| ip_address | VARCHAR(45) | Yes | NULL | |
| user_agent | VARCHAR | Yes | NULL | |
| created_at | TIMESTAMP | No | current | No `updated_at` |

## 18.2 activity_logs

General user-activity feed (login, logout, message sent, meeting joined, file uploaded, and similar events) - distinct from `audit_logs`, which is specifically administrative create/update/delete actions with before/after diffs.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | No | auto | |
| user_id | BIGINT UNSIGNED | Yes | NULL | FK `users`, null on delete; indexed with `created_at` |
| event | VARCHAR(100) | No | - | Indexed |
| context | JSON | Yes | NULL | |
| ip_address | VARCHAR(45) | Yes | NULL | |
| created_at | TIMESTAMP | No | current | No `updated_at` |

---

# 19. Entity Relationship Summary

* `users` is one-to-one with `user_profiles`, `user_preferences`, `user_privacy`, `user_presence`, `two_factor_secrets`, `admins`; one-to-many with essentially every other table that carries a `user_id`.
* `conversations` is one-to-many with `messages` and `conversation_members`; `messages` is one-to-many with `message_status`, `message_reactions`, and one-to-one with `message_replies`, `forwarded_messages`, `one_view_media`.
* `conversations` is one-to-one with `groups` (where `type = group`) and with `meeting_chat` (where `type = meeting`); `groups` is one-to-many with `group_members`, `group_roles`, `group_invites`, `group_join_requests`, `group_announcements`, `group_polls`, `group_events`, `group_media`, `group_logs`, and many-to-one with `communities`.
* `calls` is one-to-one with `call_history`, one-to-many with `signaling_messages`, `ice_candidates`, `call_statistics`; a call may resolve into a `meetings` row via `escalated_to_meeting_id`.
* `meetings` is one-to-many with `meeting_participants`, `meeting_invitations`, `meeting_guest_sessions`, `meeting_waiting_room`, `meeting_logs`, `meeting_signals`, `meeting_ice_candidates`, `whiteboards`; one-to-one with `meeting_settings`, `meeting_chat`, `meeting_reports`.
* `whiteboards` is one-to-many with `whiteboard_pages` (which are one-to-many with `whiteboard_objects`), `whiteboard_history`, `whiteboard_exports`.
* `calendar_events` is many-to-one with `meetings` (optional) and `recurring_events` (optional), one-to-many with `calendar_rsvp` and `calendar_reminders`.
* `roles` is many-to-many with `permissions` via `role_permissions`, and with `users` via `user_roles`; `group_roles` is many-to-many with capability keys via `group_permissions` on a strictly per-group basis, independent of the platform-wide RBAC tables.

---

# 20. Migration Order

Migrations run in this dependency order (each is independently reversible):

1. Authentication & Identity core (`users`, then its dependent tables)
2. User Profile & Preferences
3. Contacts
4. Chat core (`conversations`, `conversation_members`, `messages`, then dependent chat tables)
5. Groups & Communities
6. Media (`media`, then `one_view_media`, then the deferred FK wiring onto `messages`/`group_media`)
7. Calls
8. Meetings (including Calendar, which depends on `meetings`)
9. Whiteboard
10. Push Notifications
11. Administration & Access Control
12. Platform Configuration
13. Archive & Backup
14. Auditing & Activity Logs

Schema-evolution migrations (adding a column, widening an enum, dropping a superseded table) are ordered strictly after the table they modify and are applied in the order shown by their own timestamp prefix.

---

# 21. Indexing & Performance Strategy

Every foreign key is indexed; unique-lookup columns (`email`, `phone`, `uuid`, `meeting_code`, `invite_token`, `session_id`, `device_identifier`, `fcm_token`'s owning `user_id`) are unique-indexed. Composite indexes exist wherever a hot query pattern is known - `(caller_id, call_status)`/`(receiver_id, call_status)` on `calls`, `(meeting_id, status)` across the meeting sub-tables, `(group_id, created_at)` on `group_logs`, `(whiteboard_page_id, is_deleted, id)` on `whiteboard_objects`. List endpoints that historically returned an entire unpaginated result set (Admin → Users, the chat list, the contacts list) now use real pagination with either page controls or infinite scroll. Eager loading is used to avoid N+1 queries on every relationship listed in §19.

---

# 22. Development & Documentation Rules

Developers interact with the schema exclusively through Eloquent models, Form Requests for validation, and Services for business logic - direct query building from controllers is avoided. Every migration is written to be reversible. Schema changes ship as new migrations, never as edits to an already-applied one; a column or table that must be removed is dropped by a new migration, not by deleting the file that created it. No table in this schema uses Laravel's `ShouldQueue` job dispatch - the platform's hosting environment has no persistent queue worker, so anything that looks like background processing is either a scheduled cron job or synchronous inline work performed during the request that triggers it.

---

# 23. Summary

The Meet platform's schema spans authentication and identity (including two-factor authentication, passkeys, and linked OAuth identities), user profile and privacy controls, contacts, a full chat and messaging engine with server-managed message encryption and disappearing messages, groups and communities, 1:1 calling with meeting escalation, N-way meetings with guest access and a collaborative whiteboard, calendar scheduling, media handling, push notifications, role-based administration, platform-wide configuration, archiving, and auditing - roughly ninety tables in total. Every table follows the same primary-key, naming, timestamp, soft-delete, and indexing conventions defined in §2, giving the platform one consistent, predictable relational foundation across every module.
