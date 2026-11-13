# REST API Specification (RAS)

**Version:** 2.0

**Document Type:** REST API Specification

**Priority:** High

---

# 1. Purpose

This document defines every REST API endpoint and every session-authenticated web action route used by the Meet platform. It serves as the single implementation reference for backend development, frontend integration, the installable PWA, and any future native or third-party client.

Every endpoint follows the request/response standards defined in this document unless explicitly noted otherwise.

---

# 2. API Standards

## Content Type

JSON endpoints exchange `application/json`. File-upload endpoints accept `multipart/form-data`.

## Authentication Models

The platform uses four distinct authentication models, each suited to a different surface:

* **Laravel Sanctum - SPA cookie session.** The Blade-rendered web application authenticates its own JSON API calls (`/api/v1/...`) using Sanctum's stateful, cookie-based session - the same login session that renders the pages themselves. This is the primary mode for every `auth:sanctum`-protected endpoint below.
* **Laravel Sanctum - bearer token.** The same `/api/v1/...` endpoints also accept `Authorization: Bearer {token}` for a non-browser client (mobile app, third-party integration).
* **Web session (`auth` middleware).** Classic cookie-session authentication guards the server-rendered pages and a handful of account-security actions that have no bearer-token equivalent yet - Two-Factor Authentication, Passkeys, SSO, and multi-account switching. These are documented in this specification because they are real, addressable HTTP endpoints, even though they are reached from a Blade form rather than a JSON client.
* **Unauthenticated, session-token-scoped.** Meeting guests and whiteboard guests never hold a Sanctum session at all. Every guest action is instead authorized by an unguessable `session_token` embedded in the URL itself (`/api/v1/meetings/guest/{guestSession}/...`), generated when the guest joins or creates a meeting.

## HTTP Methods

| Method | Purpose            |
| ------ | ------------------ |
| GET    | Retrieve a resource |
| POST   | Create a resource, or perform an action |
| PUT    | Replace a resource |
| PATCH  | Partially update a resource |
| DELETE | Remove a resource, or reverse a toggle |

---

# 3. Standard Success Response

```json
{
    "success": true,
    "message": "Operation completed successfully.",
    "data": {},
    "errors": null
}
```

---

# 4. Standard Error Response

```json
{
    "success": false,
    "message": "Validation failed.",
    "data": null,
    "errors": {
        "email": [
            "The email field is required."
        ]
    }
}
```

---

# 5. HTTP Status Codes

| Code | Meaning                |
| ---- | ---------------------- |
| 200  | OK                     |
| 201  | Created                |
| 204  | No Content             |
| 400  | Bad Request            |
| 401  | Unauthorized           |
| 403  | Forbidden              |
| 404  | Not Found              |
| 409  | Conflict               |
| 422  | Validation Error       |
| 429  | Too Many Requests      |
| 500  | Internal Server Error  |

---

# 6. Base URL & Versioning

```text
https://your-domain.com/api/v1
```

Every JSON endpoint in this document lives under this one prefix; there is a single current version. Server-rendered web/session routes (login, chat pages, the settings hub, the admin console, guest landing pages) live at the site root, outside this prefix, and are called out explicitly wherever they appear below.

---

# 7. Rate Limiting Groups

Every route group in this specification is throttled by a named limiter, sized for the traffic pattern that group actually produces rather than a single blanket number:

| Limiter name | Limit | Applies to |
| --- | --- | --- |
| `auth-api` | 30/min | `/api/v1/auth/*` |
| `auth-web` | 30/min | Web login, register, password reset, 2FA challenge, passkey login, account switching |
| `user-profile` | 60/min | Profile, preferences, privacy, device list |
| `presence` | 600/min | The presence heartbeat, which fires automatically from every open tab roughly every 20 seconds |
| `contacts` | 60/min | Contact, block, and user-search endpoints |
| `chat` | 600/min | Conversations and messages - sized for ~1s message polling plus periodic conversation-meta polling per open tab |
| `groups` | 60/min | Group management |
| `calls` | 600/min | Calls and call signaling |
| `meetings` | 600/min | Authenticated meeting endpoints and signaling |
| `meetings-guest` | 120/min | Guest meeting status/roster/signaling polling |
| `meetings-guest-join` | 60/min | The initial unauthenticated guest-join request |
| `meetings-guest-create` | 20/min | Guest-created (no-login) instant meetings |
| `calendar` | 120/min | Calendar events |
| `whiteboard` / `whiteboard-guest` | 600/min | Whiteboard drawing operations, authenticated and guest |
| `media-upload` | 60/min | Media upload |
| `media-download` | 600/min | Media retrieval - sized for a media-heavy conversation issuing one GET per attachment on every page load |
| `push` | 60/min | Push-subscription registration |
| `admin` | 300/min | Every Admin API endpoint |

---

# 8. Authentication APIs

**Base:** `/api/v1/auth` unless noted. Rows marked **Web** are session-authenticated Blade routes at the site root instead.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/auth/register` | Create a new account and send an email verification OTP |
| POST | `/auth/verify-email` | Verify the email OTP and activate the account |
| POST | `/auth/resend-otp` | Resend a fresh verification OTP, replacing any pending one |
| POST | `/auth/login` | Authenticate by email or phone; returns a Sanctum token, or halts at a 2FA challenge if enabled |
| POST | `/auth/two-factor/verify` | Complete login by submitting a TOTP code (or recovery code) after password verification |
| POST | `/auth/forgot-password` | Send a password-reset email |
| POST | `/auth/reset-password` | Reset the password using a valid reset token |
| POST | `/auth/logout` | Revoke the current session/token |
| GET | `/auth/me` | Return the authenticated user's profile, preferences, roles, and account status |
| POST | `/auth/refresh` | Refresh the authenticated session and extend its validity |
| POST | `/auth/change-password` | Change password after verifying the current one |
| GET | `/login` **(Web)** | Render the login form |
| POST | `/login` **(Web)** | Authenticate a web session; "Remember me" defaults checked |
| GET | `/register` **(Web)** | Render the registration form |
| POST | `/register` **(Web)** | Register a new account from the web form |
| GET | `/verify-email` **(Web)** | Render the email-verification form |
| POST | `/verify-email` **(Web)** | Submit the verification OTP |
| POST | `/resend-otp` **(Web)** | Resend the verification OTP |
| GET | `/forgot-password` **(Web)** | Render the forgot-password form |
| POST | `/forgot-password` **(Web)** | Submit a password-reset request |
| GET | `/reset-password` **(Web)** | Render the reset-password form |
| POST | `/reset-password` **(Web)** | Submit the new password |
| GET | `/two-factor-challenge` **(Web)** | Render the 2FA code-entry screen shown after a correct password |
| POST | `/two-factor-challenge` **(Web)** | Submit the TOTP or recovery code, optionally trusting the device for 30 days |
| GET | `/passkey-login/options` **(Web)** | Fetch WebAuthn assertion options for passkey login |
| POST | `/passkey-login` **(Web)** | Complete login via a WebAuthn passkey ceremony; skips the separate 2FA challenge |
| GET | `/auth/{provider}` **(Web)** | Redirect to the SSO provider (Google) |
| GET | `/auth/{provider}/callback` **(Web)** | Complete SSO sign-in/registration on return from the provider |
| POST | `/logout` **(Web)** | Log out of the current web session |
| POST | `/logout-all` **(Web)** | Log out of every linked account on this device at once |
| GET | `/force-password-change` **(Web)** | Render the forced password-change screen shown to a user with a temporary admin-issued password |
| POST | `/force-password-change` **(Web)** | Set a new password and clear the forced-change flag |

---

# 9. Account Switching APIs (Web)

Built on the existing "Remember me" mechanism rather than a new session/token layer - a held account is never more trustworthy than its own remembered login. Revoking that login elsewhere (a real password change, an admin force-logout) invalidates it here too.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/accounts/{userId}/switch` | Switch the active session to another account already held on this device, without a password |
| POST | `/accounts/{userId}/forget` | Remove a held account from the switcher on this device |
| GET | `/accounts/add` | Render the "add another account" login form |
| POST | `/accounts/add` | Log into a second account alongside the current one |

---

# 10. User Profile & Settings APIs

## Profile, Preferences & Privacy (JSON API)

**Base:** `/api/v1/users`, `auth:sanctum` required.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/users/profile` | Return the authenticated user's full profile, preferences, privacy settings, and presence |
| PUT | `/users/profile` | Update name, display name, bio, date of birth, gender, website, avatar, cover photo |
| PATCH | `/users/preferences` | Update theme, language, timezone, notification sound, per-media-type auto-download |
| PATCH | `/users/privacy` | Update last-seen, profile-photo, about, and read-receipt visibility |
| PATCH | `/users/presence` | Heartbeat call that refreshes online/last-seen presence; fired automatically roughly every 20 seconds from every open tab |

## Settings Hub (Web)

**Base:** `/profile`, web session required. Rendered as a WhatsApp-style hub of category pages rather than one long form.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/profile` | Settings hub landing page |
| GET | `/profile/info` | Render the Profile category page |
| PUT | `/profile` | Save profile fields from the web form |
| GET | `/profile/preferences` | Render the Preferences category page |
| PATCH | `/profile/preferences` | Save preferences from the web form |
| GET | `/profile/notifications` | Render per-type notification toggles (messages/calls/meetings/contact requests) |
| GET | `/profile/chat-defaults` | Render default wallpaper and font-size settings for new conversations |
| GET | `/profile/privacy` | Render the Privacy category page |
| PATCH | `/profile/privacy` | Save privacy settings from the web form |
| GET | `/profile/security` | Render the Account & Security sub-hub |
| GET | `/profile/password` | Render the change-password form |
| PUT | `/profile/password` | Change the account password |
| GET | `/profile/deactivate` | Render the deactivate-account confirmation |
| DELETE | `/profile` | Deactivate (soft-delete) the account after re-confirming the current password |

## Two-Factor Authentication (Web)

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/profile/two-factor` | Render the 2FA setup/status page with QR code |
| POST | `/profile/two-factor` | Generate a new TOTP secret and QR code (not yet active) |
| POST | `/profile/two-factor/confirm` | Confirm a code against the pending secret and activate 2FA; issues 8 one-time recovery codes |
| DELETE | `/profile/two-factor` | Disable 2FA |
| POST | `/profile/two-factor/recovery-codes` | Regenerate the set of recovery codes |
| GET | `/profile/trusted-devices` | List devices that currently skip the 2FA challenge |
| DELETE | `/profile/trusted-devices/{device}` | Revoke a trusted device early |

## Passkeys (Web)

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/profile/passkeys` | List registered passkeys |
| GET | `/profile/passkeys/options` | Fetch WebAuthn registration options |
| POST | `/profile/passkeys` | Register a new passkey |
| DELETE | `/profile/passkeys/{passkey}` | Remove a passkey |

## Connected Accounts / SSO (Web)

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/profile/connected-accounts` | List linked SSO identities |
| DELETE | `/profile/oauth/{identity}` | Unlink a connected SSO identity |

## Multi-Account Switching Entry Point (Web)

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/profile/switch-accounts` | List accounts held on this device, with entry points to switch or add another |

---

# 11. Device, Session & Push Subscription APIs

## Sessions (JSON API)

**Base:** `/api/v1/users`, `auth:sanctum` required.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/users/devices` | List active devices/sessions - name, platform, browser, last active, current-device flag |
| DELETE | `/users/devices/{deviceId}` | Terminate all sessions for the selected device |

## Devices & Push Subscriptions (Web)

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/profile/devices` | Render the active-devices page |
| DELETE | `/profile/devices/{deviceId}` | Revoke a device from the web settings page |
| GET | `/profile/push-subscriptions` | List this account's subscribed push-notification devices |
| DELETE | `/profile/push-subscriptions/{subscriptionId}` | Revoke a push subscription, unsubscribing that browser at the OS level |

## Push Subscription Registration (JSON API)

**Base:** `/api/v1/push-subscriptions`, `auth:sanctum` required. Registers the Firebase Cloud Messaging device token used for real OS-level push notifications (new message, incoming call, missed call, contact request, meeting reminder).

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/push-subscriptions` | Register this device's FCM token for push delivery |
| DELETE | `/push-subscriptions` | Remove this device's push subscription |

---

# 12. Contact APIs

## JSON API

**Base:** `/api/v1`, `auth:sanctum` required.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/users/search` | Search users by name, email, or phone, filtered by their privacy settings |
| POST | `/users/{userId}/block` | Block a user - stops chat, calls, and contact requests between the two |
| DELETE | `/users/{userId}/block` | Unblock a user |
| GET | `/users/blocked` | List blocked users |
| POST | `/contacts/request` | Send a contact request |
| GET | `/contacts/requests` | List incoming/outgoing contact requests |
| POST | `/contacts/request/{contactRequest}/accept` | Accept a contact request |
| POST | `/contacts/request/{contactRequest}/reject` | Reject a contact request |
| DELETE | `/contacts/request/{contactRequest}` | Cancel a contact request sent by the current user |
| GET | `/contacts` | List contacts with last-seen (subject to privacy), online status, and favorite flag |
| DELETE | `/contacts/{contactId}` | Remove a contact |
| POST | `/contacts/{contactId}/favorite` | Mark a contact as favorite |
| DELETE | `/contacts/{contactId}/favorite` | Remove favorite status |

## Web

**Base:** `/contacts`, web session required. Mirrors the JSON API for the Blade Contacts page.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/contacts` | Contacts list page |
| POST | `/contacts/request` | Send a contact request from the web page |
| GET | `/contacts/requests` | Incoming/outgoing requests page |
| POST | `/contacts/request/{contactRequest}/accept` | Accept a request |
| POST | `/contacts/request/{contactRequest}/reject` | Reject a request |
| DELETE | `/contacts/request/{contactRequest}` | Cancel a sent request |
| GET | `/contacts/blocked` | Blocked-users page |
| DELETE | `/contacts/{contactId}` | Remove a contact |
| POST | `/contacts/{contactId}/favorite` | Favorite a contact |
| DELETE | `/contacts/{contactId}/favorite` | Unfavorite a contact |
| POST | `/users/{userId}/block` | Block a user |
| DELETE | `/users/{userId}/block` | Unblock a user |

---

# 13. Conversation APIs

**Base:** `/api/v1`, `auth:sanctum` required.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/conversations` | Start a conversation, reusing an existing private conversation where one already exists |
| GET | `/conversations` | List conversations - last message, unread count, typing/online status; supports pagination, search, and an archive filter |
| GET | `/conversations/unread-count` | Return one aggregate unread count summed across every conversation, for nav badges |
| GET | `/conversations/{conversation}` | Conversation details |
| POST | `/conversations/{conversation}/archive` | Archive a conversation |
| DELETE | `/conversations/{conversation}` | Leave/remove a conversation from the current user's list |
| POST | `/conversations/{conversation}/read` | Mark a conversation as read |
| POST | `/conversations/{conversation}/delivered` | Advance message delivery state to "delivered," including from a background push handler with no page open |
| POST | `/conversations/{conversation}/typing` | Report typing status for the typing indicator |
| POST | `/conversations/{conversation}/draft` | Save a draft message |
| DELETE | `/conversations/{conversation}/draft` | Delete a draft message |
| PATCH | `/conversations/{conversation}/settings` | Update mute/wallpaper/notification settings |
| PATCH | `/conversations/{conversation}/disappearing-messages` | Set the disappearing-messages timer (off / 24h / 7d / 90d) |
| GET | `/conversations/{conversation}/wallpaper` | List available wallpaper options |
| GET | `/conversations/{conversation}/export` | Download a zip export of the conversation (CSV transcript plus attachments) |
| GET | `/conversations/{conversation}/pinned` | List pinned messages |
| GET | `/conversations/{conversation}/message-statuses` | Return per-message delivery/read status and One-View-consumed state for the sender's own poll |
| GET | `/conversations/{conversation}/encryption` | Return the conversation's current encryption epoch/status |
| GET | `/conversations/{conversation}/encryption/keys/{keyVersion}` | Fetch the raw key material for a given encryption epoch, checked against current membership at fetch time |
| POST | `/conversations/{conversation}/messages/migrate-to-encrypted` | Encrypt a bounded batch (up to 500) of a conversation's existing plaintext history in place |

Business rules worth noting: text message bodies are encrypted at rest with a per-conversation, per-epoch AES-256-GCM key that the server itself generates and holds - this is a deliberately simpler trust model than zero-knowledge end-to-end encryption, not a password-derived or per-user-keypair scheme. A membership change rotates to a new epoch. Groups above a configurable member-count cap stay plaintext server-side with a visible indicator, matching how encrypted messengers generally cap group encryption. Regular media attachments (photos, videos, documents) are not covered by this scheme; only text bodies are.

---

# 14. Message APIs

**Base:** `/api/v1`, `auth:sanctum` required.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/messages` | Send a message - text, image, video, audio, file, contact, location, or One View |
| GET | `/messages/starred` | List starred messages across all conversations |
| GET | `/messages/{conversation}` | List messages in a conversation, with pagination |
| GET | `/messages/{message}/info` | Sender-only view of exact per-recipient delivered/read timestamps |
| PATCH | `/messages/{message}` | Edit a message, subject to the platform's edit time limit |
| DELETE | `/messages/{message}` | Delete a message - for me, or for everyone (which also removes the underlying media file once nothing else references it) |
| POST | `/messages/{message}/reaction` | React to a message with an emoji |
| DELETE | `/messages/{message}/reaction` | Remove (un-react) the current user's reaction |
| POST | `/messages/{message}/reply` | Reply to a message |
| POST | `/messages/{message}/forward` | Forward a message to another conversation |
| POST | `/messages/{message}/star` | Star a message |
| POST | `/messages/{message}/pin` | Pin a message |

---

# 15. Group APIs

**Base:** `/api/v1`, `auth:sanctum` required.

## Group Management, Members & Invitations

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/groups/join` | Join a group via an invite token |
| POST | `/groups` | Create a group; the creator becomes owner and a conversation is created automatically |
| GET | `/groups` | List groups - name, avatar, member count, last activity/message |
| GET | `/groups/{group}` | Group details, members, settings, statistics |
| PUT | `/groups/{group}` | Update group name/description/avatar |
| DELETE | `/groups/{group}` | Soft-delete a group |
| POST | `/groups/{group}/invite` | Generate an invite link |
| DELETE | `/groups/{group}/invite` | Revoke the invite link |
| GET | `/groups/{group}/join-requests` | List pending join requests |
| POST | `/groups/{group}/join-request` | Submit a join request |
| POST | `/groups/{group}/join-request/{joinRequest}/approve` | Approve a join request |
| POST | `/groups/{group}/join-request/{joinRequest}/reject` | Reject a join request |
| GET | `/groups/{group}/members` | List members |
| POST | `/groups/{group}/members` | Add members |
| DELETE | `/groups/{group}/members/{userId}` | Remove a member |
| POST | `/groups/{group}/leave` | Leave the group; ownership transfer is required if the owner leaves |
| POST | `/groups/{group}/bulk-remove` | Remove multiple members at once |
| POST | `/groups/{group}/transfer-ownership` | Transfer group ownership |

## Roles & Permissions

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/groups/{group}/roles` | Assign a role to a member |
| PATCH | `/groups/{group}/roles/{groupRole}` | Update a role |
| DELETE | `/groups/{group}/roles/{groupRole}` | Remove a role |
| GET | `/groups/{group}/permissions` | Get group permissions |
| PATCH | `/groups/{group}/permissions` | Update group permissions |

## Announcements, Polls & Events

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/groups/{group}/announcements` | List announcements |
| POST | `/groups/{group}/announcements` | Create an announcement |
| PATCH | `/groups/{group}/announcements/{announcement}` | Update an announcement |
| DELETE | `/groups/{group}/announcements/{announcement}` | Delete an announcement |
| POST | `/groups/{group}/polls` | Create a poll |
| POST | `/groups/{group}/polls/{poll}/vote` | Vote on a poll |
| GET | `/groups/{group}/polls/{poll}` | Get poll results |
| PATCH | `/groups/{group}/polls/{poll}/close` | Close a poll |
| GET | `/groups/{group}/events` | List group events |
| POST | `/groups/{group}/events` | Create a group event |
| PATCH | `/groups/{group}/events/{event}` | Update a group event |
| DELETE | `/groups/{group}/events/{event}` | Delete a group event |

## Media, Settings & Export

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/groups/{group}/media` | List shared images/videos/files/audio |
| GET | `/groups/{group}/links` | List shared links |
| GET | `/groups/{group}/documents` | List shared documents |
| PATCH | `/groups/{group}/settings` | Update approval requirement, member limit, message/invite permissions |
| POST | `/groups/{group}/mute` | Mute the group |
| POST | `/groups/{group}/archive` | Archive the group |
| GET | `/groups/{group}/export` | Export group information as CSV |

## Web (Blade Groups pages)

**Base:** `/groups`, web session required - mirrors the JSON API above for the server-rendered Groups pages, plus:

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/groups/join/{token}` | Render the join-via-invite landing page |
| POST | `/groups/join/{token}` | Join via invite from the web page |
| GET | `/groups` | Groups list page |
| GET | `/groups/create` | Group creation form |
| POST | `/groups` | Create a group from the web form |
| GET | `/groups/{group}` | Group detail page |
| DELETE | `/groups/{group}` | Delete a group |
| POST | `/groups/{group}/leave` | Leave a group |
| PATCH | `/groups/{group}/info` | Update group name/description |
| POST | `/groups/{group}/members` | Add members from the web page |
| DELETE | `/groups/{group}/members/{userId}` | Remove a member |
| POST | `/groups/{group}/transfer-ownership` | Transfer ownership |
| POST | `/groups/{group}/assign-role` | Assign a role |
| PATCH | `/groups/{group}/settings` | Update group settings |
| PATCH | `/groups/{group}/permissions` | Update permissions |
| POST | `/groups/{group}/mute` | Toggle mute |
| POST | `/groups/{group}/archive` | Toggle archive |
| POST | `/groups/{group}/invite` | Generate an invite link |
| DELETE | `/groups/{group}/invite` | Revoke the invite link |
| POST | `/groups/{group}/join-request` | Submit a join request |
| POST | `/groups/{group}/join-request/{joinRequest}/approve` | Approve a join request |
| POST | `/groups/{group}/join-request/{joinRequest}/reject` | Reject a join request |
| POST | `/groups/{group}/announcements` | Post an announcement |
| DELETE | `/groups/{group}/announcements/{announcement}` | Delete an announcement |
| POST | `/groups/{group}/polls` | Create a poll |
| POST | `/groups/{group}/polls/{poll}/vote` | Vote on a poll |
| POST | `/groups/{group}/polls/{poll}/close` | Close a poll |
| POST | `/groups/{group}/events` | Create an event |
| DELETE | `/groups/{group}/events/{event}` | Delete an event |

---

# 16. Community APIs (Web)

**Base:** `/communities`, web session required. A community is a group-of-groups container above regular Groups; creating one auto-creates a default "Announcements" group.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/communities` | List communities |
| GET | `/communities/create` | Community creation form |
| POST | `/communities` | Create a community and its default Announcements group |
| GET | `/communities/{community}` | Community detail page |
| DELETE | `/communities/{community}` | Soft-delete a community (owner only) |
| POST | `/communities/{community}/groups` | Attach a group the current user owns |
| DELETE | `/communities/{community}/groups/{group}` | Detach a group (not the Announcements group itself) |
| POST | `/communities/{community}/announcements` | Post an announcement visible to every member of every attached group |

---

# 17. Call APIs

**Base:** `/api/v1/calls`, `auth:sanctum` required, one-to-one calls only.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/calls/incoming` | Poll for an incoming call |
| GET | `/calls/history` | Call history - type, duration, status, participants, timestamp |
| POST | `/calls/voice/start` | Start a voice call |
| POST | `/calls/video/start` | Start a video call |
| GET | `/calls/{call}` | Call details |
| POST | `/calls/{call}/accept` | Accept a call and begin WebRTC negotiation |
| POST | `/calls/{call}/reject` | Reject a call |
| POST | `/calls/{call}/end` | End a call, recording duration and history |
| PATCH | `/calls/{call}/media` | Update local media state (mute/camera toggle) |
| POST | `/calls/{call}/add-member` | Escalate a 1:1 call into a full N-way Meeting, admitting both original parties directly |
| POST | `/calls/{call}/offer` | Send a WebRTC SDP offer |
| POST | `/calls/{call}/answer` | Send a WebRTC SDP answer |
| POST | `/calls/{call}/ice` | Exchange an ICE candidate |
| POST | `/calls/{call}/reaction` | Send an in-call reaction |
| POST | `/calls/{call}/screen-share` | Signal a screen-share start/stop during a 1:1 call |
| GET | `/calls/{call}/signals` | Poll for pending signaling messages |

## Web

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/calls/history` | Call history page |

A completed or missed 1:1 call also logs a centered system-style row directly into the conversation it was started from, matching how the call history itself renders it.

---

# 18. Meeting APIs

**Base:** `/api/v1/meetings`, `auth:sanctum` required unless noted.

## Lifecycle & Lobby

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/meetings/lookup` | Look up a meeting by its join code |
| GET | `/meetings/history` | List past meetings |
| GET | `/meetings/upcoming` | List upcoming meetings |
| POST | `/meetings/instant` | Create an instant meeting; the organizer goes through the same lobby/device-preview as any other participant |
| POST | `/meetings` | Schedule a meeting - title, description, time window, participants, password, guest access, waiting-room policy |
| GET | `/meetings/{meeting}` | Meeting details, participants, settings |
| DELETE | `/meetings/{meeting}` | Cancel a meeting, sending cancellation notices and updating calendar events |
| POST | `/meetings/{meeting}/join` | Join a meeting; returns a participant role and WebRTC configuration |
| POST | `/meetings/{meeting}/leave` | Leave a meeting |
| POST | `/meetings/{meeting}/end` | End a meeting (host/co-host only) |
| POST | `/meetings/{meeting}/lock` | Lock/unlock the meeting |
| POST | `/meetings/{meeting}/raise-hand` | Raise/lower a hand |
| PATCH | `/meetings/{meeting}/media` | Update local media state |

## Waiting Room & Participants

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/meetings/{meeting}/waiting-room` | List participants waiting for admission |
| GET | `/meetings/{meeting}/waiting-room/mine` | Check the current user's own waiting status |
| POST | `/meetings/{meeting}/waiting-room/{entry}/admit` | Admit a waiting participant |
| POST | `/meetings/{meeting}/waiting-room/{entry}/reject` | Reject a waiting participant |
| GET | `/meetings/{meeting}/participants` | List active participants (filtered by a recent-heartbeat liveness window) |
| POST | `/meetings/{meeting}/participants/{participant}/mute` | Mute a participant |
| POST | `/meetings/{meeting}/participants/{participant}/remove` | Remove a participant |
| POST | `/meetings/{meeting}/participants/{participant}/co-host` | Promote a participant to co-host |
| POST | `/meetings/{meeting}/participants/{participant}/revoke-co-host` | Revoke co-host status |
| POST | `/meetings/{meeting}/participants/{participant}/transfer-host` | Transfer host role to another participant |

## Screen Share & Signaling

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/meetings/{meeting}/screen-share/start` | Start screen sharing |
| POST | `/meetings/{meeting}/screen-share/stop` | Stop screen sharing |
| POST | `/meetings/{meeting}/offer` | Send an SDP offer |
| POST | `/meetings/{meeting}/answer` | Send an SDP answer |
| POST | `/meetings/{meeting}/ice` | Exchange an ICE candidate |
| GET | `/meetings/{meeting}/signals` | Poll for pending signaling messages |

## Guest Join (unauthenticated)

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/meetings/{meeting}/guest` | Submit the initial guest-join request (no account, no Sanctum session) |

## Meeting Web Pages

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/j/{code}` | Public guest-landing page for a shareable join link |
| GET | `/meetings/guest/new` | Guest instant-meeting creation form (no login) |
| POST | `/meetings/guest/new` | Create a guest meeting, returning a shareable `/j/{code}` link |
| GET | `/meetings/guest/{guestSession}` | Guest meeting room |
| GET | `/meetings` | Meetings list page |
| GET | `/meetings/history` | Meetings history tab |
| GET | `/meetings/calendar` | Calendar tab |
| GET | `/meetings/schedule` | Schedule-meeting form |
| POST | `/meetings/schedule` | Submit the schedule-meeting form |
| POST | `/meetings/instant` | Create an instant meeting from the web page |
| GET | `/meetings/{meeting}` | Meeting room / detail page |

---

# 19. Guest Meeting APIs (Unauthenticated)

**Base:** `/api/v1/meetings/guest/{guestSession}`. Every action is scoped by the guest's own unguessable session token rather than a Sanctum session.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/status` | Poll the guest's own current meeting/session status |
| GET | `/roster` | Poll the participant roster |
| PATCH | `/media` | Update the guest's local media state |
| POST | `/leave` | Leave the meeting; also called via `navigator.sendBeacon()` on tab close |
| POST | `/lock` | Lock/unlock the meeting, when the guest's own role is host/co-host |
| POST | `/screen-share/start` | Start screen sharing |
| POST | `/screen-share/stop` | Stop screen sharing |
| POST | `/offer` | Send an SDP offer |
| POST | `/answer` | Send an SDP answer |
| POST | `/ice` | Exchange an ICE candidate |
| GET | `/signals` | Poll for pending signaling messages |

---

# 20. Calendar APIs

**Base:** `/api/v1/calendar`, `auth:sanctum` required.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/calendar/events` | List upcoming calendar events (meetings and standalone personal/reminder/task/holiday entries) |
| POST | `/calendar/events` | Create a calendar event |
| DELETE | `/calendar/events/{event}` | Delete a calendar event |
| POST | `/calendar/events/{event}/rsvp` | RSVP to a meeting invitation (accepted/declined/tentative) |
| GET | `/calendar/events/{event}/ics` | Download an ICS file with a real actionable invite (per-recipient RSVP, sequence number) |
| PATCH | `/calendar/events/{event}/reschedule` | Reschedule an event by dragging it to a new day/time, keeping `calendar_events` and the underlying meeting in sync |

---

# 21. Whiteboard APIs

## Authenticated

**Base:** `/api/v1`, `auth:sanctum` required.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/meetings/{meeting}/whiteboard` | Open (or resume) the whiteboard for a meeting |
| GET | `/whiteboards/{whiteboard}` | Whiteboard details |
| PATCH | `/whiteboards/{whiteboard}/permission` | Set who may draw |
| POST | `/whiteboards/{whiteboard}/pages` | Add a page |
| DELETE | `/whiteboards/{whiteboard}/pages/{page}` | Delete a page |
| GET | `/whiteboards/{whiteboard}/pages/{page}/objects` | List objects on a page |
| POST | `/whiteboards/{whiteboard}/pages/{page}/objects` | Add an object - pen, text, rectangle, circle, arrow, image, sticky note |
| POST | `/whiteboards/{whiteboard}/pages/{page}/clear` | Clear a page |
| DELETE | `/whiteboards/{whiteboard}/objects/{object}` | Delete an object |
| POST | `/whiteboards/{whiteboard}/undo` | Undo the last action |
| POST | `/whiteboards/{whiteboard}/redo` | Redo the last undone action |
| POST | `/whiteboards/{whiteboard}/export` | Export the whiteboard |

## Guest (Unauthenticated)

**Base:** `/api/v1/meetings/guest/{guestSession}`. Mirrors every authenticated whiteboard action 1:1 so a guest participant can draw exactly like a registered one, resolved through the guest's own meeting-participant record rather than a `User`.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/whiteboard` | Open the whiteboard as a guest |
| GET | `/whiteboards/{whiteboard}` | Whiteboard details |
| PATCH | `/whiteboards/{whiteboard}/permission` | Set who may draw |
| POST | `/whiteboards/{whiteboard}/pages` | Add a page |
| DELETE | `/whiteboards/{whiteboard}/pages/{page}` | Delete a page |
| GET | `/whiteboards/{whiteboard}/pages/{page}/objects` | List objects |
| POST | `/whiteboards/{whiteboard}/pages/{page}/objects` | Add an object |
| POST | `/whiteboards/{whiteboard}/pages/{page}/clear` | Clear a page |
| DELETE | `/whiteboards/{whiteboard}/objects/{object}` | Delete an object |
| POST | `/whiteboards/{whiteboard}/undo` | Undo |
| POST | `/whiteboards/{whiteboard}/redo` | Redo |
| POST | `/whiteboards/{whiteboard}/export` | Export |

Both the registered and guest whiteboard surfaces accept Pointer Events, so drawing works identically on touch and mouse input.

---

# 22. Media APIs

**Base:** `/api/v1`, `auth:sanctum` required.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/media` | Upload a media file (image, video, audio, document) |
| GET | `/media/{media}` | Retrieve/stream a media file; consuming a One View file here also triggers its deletion workflow for a standard viewer |

One View media is sent as a message type (`POST /messages` with `message_type: one_view`) rather than through a dedicated upload flow, and is encrypted at rest with the server's own key regardless of the conversation's per-conversation encryption state. A scheduled cleanup job removes any orphaned upload (uploaded but never attached to a sent message) and any View Once file left over past its viewing grace window.

---

# 23. Admin - Dashboard & Analytics APIs

**Base:** `/api/v1/admin`, requires an authenticated administrator and the `admin` role gate.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/admin/dashboard` | Platform-wide dashboard statistics |
| GET | `/admin/analytics/users` | Registration, active-user, and device statistics |
| GET | `/admin/analytics/chat` | Messages sent, active conversations, media messages |
| GET | `/admin/analytics/meetings` | Meeting counts, average duration, guest/whiteboard/screen-share usage |
| GET | `/admin/analytics/storage` | Storage usage statistics |

---

# 24. Admin - User & Role Management APIs

**Base:** `/api/v1/admin`.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/admin/users` | List users with pagination, search, status/role/verification filters, and a real/load-test account-type segmentation |
| GET | `/admin/users/{user}` | User detail - profile, devices, sessions, meetings, chat stats, storage, login history |
| POST | `/admin/users/{user}/suspend` | Suspend a user |
| POST | `/admin/users/{user}/activate` | Reactivate a suspended user |
| DELETE | `/admin/users/{user}` | Soft-delete a user |
| POST | `/admin/users/{id}/restore` | Restore a soft-deleted user |
| POST | `/admin/users/{user}/force-logout` | Force-logout a user, invalidating every active session |
| POST | `/admin/users/{user}/reset-password` | Set a user's password directly, optionally marking it temporary so the user is forced to a change-password screen on next login |
| POST | `/admin/users/{user}/roles/{role}` | Assign a role to a user |
| DELETE | `/admin/users/{user}/roles/{role}` | Revoke a role from a user |
| GET | `/admin/roles` | List roles |
| POST | `/admin/roles` | Create a role |
| PATCH | `/admin/roles/{role}` | Update a role |
| DELETE | `/admin/roles/{role}` | Delete a role |
| POST | `/admin/roles/{role}/clone` | Clone a role |
| GET | `/admin/permissions` | List available permissions |

---

# 25. Admin - Settings, Branding & Feature Flag APIs

**Base:** `/api/v1/admin`.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/admin/settings/general` | General application settings |
| PATCH | `/admin/settings/general` | Update general settings - registration, upload limit, guest/session timeouts, meeting duration |
| GET | `/admin/settings/branding` | Branding settings |
| PATCH | `/admin/settings/branding` | Update app name, company name, logo, favicon, theme colors, login background, footer |
| GET | `/admin/settings/smtp` | SMTP settings |
| PATCH | `/admin/settings/smtp` | Update SMTP configuration |
| POST | `/admin/settings/smtp/test` | Send a test email |
| GET | `/admin/settings/features` | List feature flags |
| PATCH | `/admin/settings/features/{key}` | Toggle a feature flag |
| POST | `/admin/system/maintenance` | Enable/disable maintenance mode |

---

# 26. Admin - Environment Variable Management APIs (Web)

**Base:** `/admin/environment`, restricted to administrators granted the environment-management permission - deliberately a separate, more sensitive surface from the key/value Settings pages above, since the live `.env` can hold database, mail, and API credentials.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/admin/environment` | Render the Environment tab - a field-by-field form (one input per real key, with sensitive-looking keys masked behind a Show/Hide toggle) and a raw full-file editor |
| POST | `/admin/environment/fields` | Save one or more fields from the field-by-field form |
| POST | `/admin/environment/raw` | Overwrite the entire `.env` from the raw textarea |

Every save re-requires the acting administrator's own account password, takes a dated backup of the current file before writing, and runs `config:clear` afterward so a cached-config production box picks up the change immediately. Keys present in the shipped `.env.example` but missing or blank in the live file are highlighted directly in the form.

---

# 27. Admin - Logs & Archive APIs

**Base:** `/api/v1/admin`.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/admin/logs/audit` | List audit log entries, filterable by date range, user, and module |
| GET | `/admin/logs/audit/{auditLog}` | Audit log entry detail - previous/updated values, actor, IP, user agent, timestamp |
| GET | `/admin/logs/activity` | List activity log entries |
| GET | `/admin/archives` | List archives |
| POST | `/admin/archives` | Create an archive (chat, meeting, file, or complete) |
| GET | `/admin/archives/settings` | Archive retention/settings |
| PATCH | `/admin/archives/settings` | Update archive settings |
| GET | `/admin/archives/{archive}` | Archive detail |
| GET | `/admin/archives/{archive}/download` | Download an archive |

---

# 28. Admin Console Pages (Server-Rendered)

**Base:** `/admin`, requires an authenticated administrator and the `admin` role gate. These are the Blade pages backing the API sections above.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/admin` | Admin dashboard page |
| GET | `/admin/users` | Users management page |
| GET | `/admin/roles` | Roles & permissions page |
| GET | `/admin/settings` | Settings page |
| GET | `/admin/logs` | Logs page |
| GET | `/admin/archives` | Archives page |

---

# 29. API Naming, Pagination, Filtering & Sorting Standards

## Resource Naming

Resource names are plural (`/users`, `/groups`, `/messages`, `/meetings`, `/conversations`). Nested resources follow the parent (`/groups/{groupId}/members`, `/meetings/{meetingId}/participants`).

## Pagination

```text
?page=1&per_page=20
```

A paginated response includes `current_page`, `per_page`, `total`, `total_pages`, `next_page`, and `previous_page`.

## Filtering & Sorting

```text
?status=active
?account_type=real
?sort=created_at&order=desc
```

Supported values and combinations are documented per endpoint above where they are non-obvious (e.g. Admin Users' real/load-test segmentation).

## Search

```text
?search=john
```

---

# 30. Security & Validation Standards

* Every protected endpoint validates authentication before business logic executes; Admin endpoints additionally validate the `admin` role gate and log every action.
* Validation uses Laravel Form Requests and returns HTTP 422 with a per-field error map on failure.
* Sensitive configuration values (SMTP credentials, environment variables) are encrypted or masked before display and require step-up password confirmation to change.
* Text message bodies are encrypted at rest per conversation; conversation membership is re-checked at key-fetch time, not only at issuance.
* Meeting passwords are hashed, never stored or compared in plaintext.
* A guest session (meeting or whiteboard) is authorized purely by its own unguessable session token - there is no guest Sanctum session to steal or replay against another guest's session.
* HTTPS is required in production; input is validated server-side on every write; output is encoded where rendered back into HTML.

---

# 31. Events & Background Jobs

Representative domain events raised across the API surface above include `UserRegistered`, `ContactRequestSent`, `ContactAccepted`, `ConversationCreated`, `MessageSent`, `ReactionAdded`, `GroupCreated`, `MemberAdded`, `CallStarted`, `CallEnded`, `MeetingCreated`, `MeetingEnded`, `ParticipantAdmitted`, `WhiteboardOpened`, and `NotificationCreated`.

This application runs with no persistent queue worker, so nothing here is dispatched onto a queue - every action that would traditionally be an async job instead either runs synchronously inline (push notification delivery, thumbnail handling) or as a scheduled cron task. Representative scheduled tasks include: cleanup of expired guest sessions and guest-created meetings with no remaining active session; disappearing-message expiry; ending a meeting after a sustained period with no genuinely present participant; deletion of orphaned media files; missed-call detection; and due meeting-reminder delivery (push plus an in-chat system message, 15 minutes before start).

---

# 32. Summary

This specification defines the complete, current REST and web-session API surface of the Meet platform: authentication (including 2FA, passkeys, SSO, and multi-account switching), profile and settings, contacts, conversations and messaging with per-conversation encryption, groups and communities, one-to-one calls, N-way meetings with guest access and whiteboard collaboration, calendar scheduling, media handling, push notification registration, and the full administrative console - dashboard, analytics, user and role management, branding and SMTP settings, environment variable management, and logs/archives.

Together, these endpoints form the implementation contract for every Laravel controller, Form Request, Resource, service, policy, and background job backing the Meet platform's Blade frontend, installable PWA, and any future native or third-party integration.
