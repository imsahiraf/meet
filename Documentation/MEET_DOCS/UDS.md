# UI/UX Design Specification (UDS)

**Version:** 2.0

**Document Type:** UI/UX Design Specification

**Priority:** High

---

# 1. Purpose

This document defines the complete, current user interface and interaction design of the Meet platform - every screen, component, layout rule, animation, and responsive behavior a user or administrator actually encounters. It is the single design reference for designers, frontend developers, backend developers, and QA engineers, superseding the original per-part UI/UX drafts as a unified, standalone specification.

Every screen, component, page, animation, spacing rule, and interaction described here reflects the shipped product. Where mainstream reference conventions (WhatsApp, Google Meet, Google Calendar) are named, that is a deliberate interaction-pattern choice described in full in `Documentation/MEET_DOCS/BRD.md` - this document describes the resulting screens in detail.

---

# 2. Platform & Scope

**Platform:** Responsive Web Application, installable as a Progressive Web App (PWA).

**Framework:** Laravel 12 + Blade + Tailwind CSS v4 + vanilla JavaScript.

**Target devices:** Mobile phones, tablets, laptops, desktops, and large displays, across modern Chromium-based browsers, Firefox, and Safari/WebKit.

The interface is designed mobile-first, then progressively enhanced for larger viewports, while remaining fully keyboard- and screen-reader-accessible on every device class.

---

# 3. Design Principles

The Meet platform follows these principles across every module:

* Simple - communication over decoration.
* Modern and professional.
* Fast - minimal clicks, minimal cognitive load.
* Accessible by default, not as an afterthought.
* Responsive across mobile, tablet, laptop, and desktop.
* Consistent - the same action looks and behaves the same everywhere it appears.
* Honest - the interface never claims more than the system actually does (see Section 41, Encryption & Privacy Indicators).

---

# 4. Design Goals

* Fast navigation with a low learning curve.
* Clear information hierarchy.
* Consistent interaction patterns across chat, calls, meetings, calendar, and admin.
* High accessibility across keyboard, screen reader, and touch input.
* A single navigation model used everywhere, rather than per-module navigation styles (Section 11).

---

# 5. Design Tokens

## 5.1 Spacing Scale

Base unit: 4px. Tokens: 4, 8, 12, 16, 20, 24, 32, 40, 48, 64px, used consistently for margins, padding, and component gaps. No component hardcodes spacing values outside this scale.

## 5.2 Border Radius Tokens

| Token | Value |
|---|---|
| Small | 4px |
| Medium | 8px |
| Large | 12px |
| Extra Large | 16px |
| Pill | 9999px |
| Circular | 50% |

## 5.3 Elevation System

| Level | Usage |
|---|---|
| 0 | No shadow |
| 1 | Cards |
| 2 | Dropdowns |
| 3 | Dialogs |
| 4 | Modals |
| 5 | Critical alerts |

Higher elevation visually signals higher interaction priority.

## 5.4 Responsive Breakpoints

| Device | Width |
|---|---|
| Mobile Small | 320px-575px |
| Mobile Large | 576px-767px |
| Tablet | 768px-991px |
| Laptop | 992px-1199px (Tailwind `lg:`) |
| Desktop | 1200px-1399px |
| Large Desktop | 1400px+ |

The Tailwind `lg:` breakpoint (992px) is the single, load-bearing threshold that separates the mobile interaction model (bottom navigation, full-bleed conversation view, floating full-screen meeting controls) from the desktop interaction model (persistent icon sidebar, split-view chat, multi-panel meeting room) everywhere in this document.

## 5.5 Motion Principles

Animations are smooth, short, and purposeful, at 150ms, 200ms, or 300ms, and never block user interaction.

## 5.6 Component States

Every interactive component supports: Default, Hover, Focus, Active, Disabled, Loading, Success, Warning, Error. State transitions are visually distinct and consistent.

---

# 6. Color System

The design system's single source of truth is a Tailwind v4 `@theme` block, defining an indigo-family brand scale (`--color-brand-50` through `--color-brand-900`, with `--color-brand-600` as the primary actionable color for buttons, links, and active states). Success/Warning/Error follow Tailwind's semantic defaults.

**Deliberate exception:** message read-receipt ticks (Section 15.3) render in amber/gold rather than the blue commonly associated with other chat apps, or the app's own brand-600 indigo - a conscious choice to keep the read-state indicator visually distinct from both link/action color and competitor conventions.

Status colors (Online, Offline, Busy, Away, Recording, Screen Sharing, Muted) remain visually consistent across chat, calls, meetings, and profiles, and never rely on color alone - each carries a label or icon as well.

---

# 7. Typography

A single sans-serif type family (the Tailwind v4 default stack) is used throughout, with a defined heading/body/caption scale. Global input styling normalizes borders, padding, and focus rings across every text-like `input` type - including `url` and `datetime-local` fields - so no field ever falls back to raw browser-default styling next to otherwise-identical siblings.

Mobile-specific rule: every `input`/`textarea`/`select` renders at 16px (`text-base`) below the `sm:` breakpoint and 14px (`sm:text-sm`) at `sm:` and up. This is a functional requirement, not a stylistic preference - iOS Safari auto-zooms the page on focus whenever the focused element's computed font size is under 16px, and the smaller size is only safe once that breakpoint's layout leaves no risk of the zoom trigger.

---

# 8. Iconography

A single icon family is used throughout. Icons are simple, recognizable, and scale cleanly.

A consistent, icon-first visual language is applied to: the Settings hub, the meeting room's in-call toolbar (Raise hand / Share screen / Whiteboard / Lock / Mute / Camera / End / Leave - icon-only below the `sm` breakpoint, with the mic and camera icons swapping between their on/off glyphs), the Chat list header (Starred / Archived / Broadcast lists / Start a new chat), and Contacts' favorite/remove actions.

**The Admin panel is a deliberate, documented exception.** Every per-row action there (Suspend/Activate, Force logout, Reset password, Grant/Revoke elevated access, Delete, Restore, Roles delete, Archives download) carries an icon **and** a text label together. Admin is a lower-traffic, higher-stakes surface, where an unlabeled icon is more likely to be misclicked than a Chat or Contacts icon a user sees dozens of times a day.

---

# 9. Theming (Light / Dark / System)

The application supports Light, Dark, and Follow System Preference. Light is the default unless configured otherwise. Both themes preserve identical layouts, spacing, typography, and component behavior - only color values differ. Icons and illustrations remain clearly legible in Dark theme, and status is never communicated by color alone.

---

# 10. Logo & Branding

The real Meet Meet logo is used across every installable surface: every PWA manifest icon size, the Apple touch icon, and `favicon.ico`. A maskable variant scales the logo into the icon's safe zone on a white background so Android's OS-level circle/squircle home-screen cropping never clips it. The service worker's cache is versioned so an already-installed PWA picks up updated icon assets on its next launch rather than staying pinned to a stale cached copy - this matters in practice, since the icon set was replaced wholesale from placeholder art to the real logo after initial installs were already in the field.

---

# 11. Global Navigation

Navigation uses a single, unified pattern across the entire application - the one standard every module below builds on.

**Desktop (`lg:` and up):** a ~72px icon-only sidebar rail, styled after WhatsApp Web, with hover tooltips identifying each icon. Four primary items appear here: **Chat** (merged with Groups - see below), **Contacts**, **Calls**, and **Meetings**. Dashboard and Admin (the latter conditional on the signed-in user holding admin rights) render in a visually separate group at the bottom of the rail.

**Mobile:** a fixed bottom bar carrying the same four primary items. Dashboard and Admin are not part of the persistent bar on mobile; they are reached via a quick-link card on the user's own profile page instead.

**Chat and Groups share one navigation item.** The chat list page carries All / Chats / Groups pill tabs - a client-side filter over conversation rows the page has already loaded (the underlying list already interleaves 1:1 and group conversations for "All"), not a second data source per tab. The standalone Groups pages remain reachable as a secondary deep link and are unaffected. Visiting any chat or groups route highlights this same merged nav icon as active.

**Nav-wide unread badges:** a small red dot renders on the Chat and Contacts nav icons - on both the desktop rail and the mobile bar - the moment either has unread content, polled from the shared app layout so it updates no matter which page is currently open. This is backed by an aggregate unread-count endpoint (summed across every conversation) and the existing contact-requests count.

**Mobile safe-area handling:** the fixed bottom nav bar sits above extra bottom padding on the main content area, and focusable elements carry `scroll-margin-bottom` tuned to the bar's own height, so a field or button near the bottom of a long page can never land fully hidden behind the bar once the page is scrolled to its end - this was a real, confirmed defect on short-viewport phones and is now guarded against explicitly.

**Breadcrumbs** remain desktop-only and do not appear on mobile.

**Logout** is not part of the persistent header or nav at all. See Section 34 (Account & Security Sub-Hub) - logout lives inside Settings, not as a floating top-level control.

---

# 12. Core Component Library

## 12.1 Buttons

Primary, Secondary, Outline, Ghost, Text, Icon, Floating Action Button, Danger, Success, and Warning variants, each supporting Default/Hover/Focus/Active/Disabled/Loading states, with optional icons, full-width, compact, and rounded variants.

## 12.2 Inputs, Selects, Checkboxes, Toggles

Standard text/password/email/number/tel/url/search inputs with floating labels, prefixes/suffixes, validation messaging, and password-visibility toggles; single/multi/searchable selects; standard and indeterminate checkboxes; radio groups; toggle switches for settings, notifications, and privacy controls; date/time/date-range pickers; drag-and-drop file upload with chunked, resumable, retry-capable progress.

## 12.3 Cards, Tables, Lists

Standard, profile, chat, meeting, statistics, and media cards. Tables support pagination, sorting, filtering, search, sticky headers, and bulk selection - every admin-facing table additionally wraps in its own inner horizontally-scrolling container on narrow viewports, so a wide table degrades to a horizontal scroll rather than being visually clipped by its own rounded-corner container. Lists (chat, contact, notification, file) each define loading, empty, and error states.

## 12.4 Modals, Dialogs, Confirmations - Themed Only, Never Native

**Every user-facing confirmation, alert, and prompt in the application uses the app's own themed modal component.** No surface - chat, calls, meetings, settings, or admin - ever falls back to the browser's native `alert()`, `confirm()`, or `prompt()` dialogs. This is a firm, product-wide standard, not a per-page choice: a destructive action (delete a message, leave a group, end a meeting, remove a participant, deactivate an account, restore a backup) always shows the same styled modal, with a clear title, consequence description, and an explicitly labeled confirm action distinguished visually from cancel. Modals support small/medium/large/extra-large/full-screen sizing, scrollable bodies, focus trapping, and keyboard close (`Escape`).

## 12.5 Tabs, Pill Tabs, Accordions

Horizontal, vertical, and scrollable tab variants exist, but the product standard for a same-page filtered view (Chat's All/Chats/Groups, Calendar's Month/Week/Day, Admin's section switcher) is a **pill-tab strip** - rounded, contained tab buttons rather than a plain underline row. This was standardized across Chat, Calendar, and Admin specifically so all three read as the same control.

## 12.6 Toasts, Alerts, Badges, Avatars, Tooltips, Skeleton Loaders

Toast positions (top-right desktop, bottom-center mobile) and alert types (success/info/warning/error) are dismissible with optional actions and configurable auto-close. Badges communicate status, notification counts, and labels; avatars support user/group/guest/admin types with initials fallback and online/offline/busy/away status rings. Tooltips trigger on hover, focus, or long-press (mobile). Skeleton loaders (avatar, text, card, table, chat bubble, meeting tile) are preferred over blank placeholders for perceived performance.

## 12.7 Empty, Loading, and Error States

Every module defines empty states with an illustration, title, description, and primary action (e.g. "No chats yet" vs. "No groups yet" - see Section 14.4, which are now distinct per active tab rather than a single generic message covering both). Error states (network, permission, validation, server, not-found) always offer a recovery action where one exists.

---

# 13. Authentication & Account Access Screens

## 13.1 Flow Overview

```text
Splash → Welcome → Login/Register → (Email Verification) → (2FA, if enabled) → Chat
```

Alternative entry points from Login: Register, Forgot Password, Reset Password, "Continue with Google" (SSO), "Sign in with a passkey", and an account-switcher tile for any other account already held on this device.

## 13.2 Splash & Welcome

Unchanged from the original design intent - a brief branded splash while the app initializes, redirecting automatically by auth state, and a first-run Welcome screen introducing Secure Chat, HD Meetings, Voice & Video Calls, Whiteboard, File Sharing, and Calendar.

## 13.3 Login Screen

Fields: Email or phone, Password. Features: password visibility toggle, "Remember me" (defaults **checked**), loading-state submit button, inline validation, autofocus on the first field.

Below the primary form, in the same visual language as the rest of the screen:

* **Continue with Google** - a single-tap SSO entry point.
* **Sign in with a passkey** - a WebAuthn-based passwordless entry point.
* **Account-switcher tiles** - one per additional account already held (but not currently active) on this device/browser, letting the user jump to it without re-entering a password (see Section 34.5).
* **Start a meeting** - an unauthenticated visitor can create an instant meeting straight from this screen without ever registering (see Section 27.2).

A newly-registered account's automatic post-verification login is always a *remembered* session, matching the checked-by-default "Remember me" behavior elsewhere.

## 13.4 Registration

Fields: Full name, Email, Phone number, Password, Confirm password, with a password-strength indicator, visibility toggle, and terms acceptance. Registration collects a single `full_name` field; on account creation, this is split automatically into first/last name so that Settings → Profile (Section 33.2) shows a pre-filled name immediately rather than a blank field, even though the user never entered first/last name separately.

## 13.5 Email Verification & OTP

Unchanged in shape: an email-verification screen (with resend/change-email) and a six-digit OTP screen (auto-advancing focus, paste support, countdown timer, resend).

## 13.6 Two-Factor Authentication (TOTP)

Enabled from Settings → Account & Security (Section 34.2): a QR code is scanned into an authenticator app, and 2FA only actually turns on once the user confirms it with one real generated code - it is never armed from merely displaying the QR code. Eight one-time recovery codes are shown exactly once at setup.

Once enabled, logging in (by password) stops at a dedicated "Enter your code" screen after the password check succeeds and before any session or token is issued. This screen offers a **"Remember this device for 30 days"** checkbox - checking it means that same browser skips the code screen entirely for the next 30 days. Settings → Account & Security → "Manage devices that skip the code" lists every remembered device and lets the user forget any of them early.

Signing in with a passkey (13.7) or Google SSO (13.8) interacts differently with this screen:

* A **passkey** login skips the 2FA challenge outright - a passkey ceremony is itself possession-plus-biometric/PIN verification, already a second factor by construction.
* A **Google SSO** login does **not** skip 2FA - a linked Google identity is treated as a separate identity from the app's own account, so the code screen still applies exactly as it would for a password login.

## 13.7 Passkeys (WebAuthn)

Managed from Settings → Account & Security: register a new passkey (platform authenticator or security key) and remove existing ones. The login page's own "Sign in with a passkey" button drives the same WebAuthn ceremony. This is a web-login-only capability in this pass.

## 13.8 Continue with Google (SSO)

A single "Continue with Google" button on the login screen signs a user in or registers them in one step: it matches an existing account by email automatically the first time it's used, or creates a new, already-verified account. Registering this way requires no phone number or password at all - the account exists purely against the linked Google identity, still fully subject to 2FA as described above.

## 13.9 Multi-Account Login and Switching

A user can hold more than one logged-in account on the same browser at once, without the second login disturbing the first session. This is reachable from two places:

* The login screen's account-switcher tiles (13.3), for jumping straight into a held-but-inactive account with no password.
* Settings → Account & Security → **Switch account** / **Add account** (Section 34.5).

Explicitly logging out of an account forgets it from the switcher entirely - it will not reappear as a tile until logged into again from scratch.

## 13.10 Forgot Password / Reset Password / Session Expired / Account Suspended

Unchanged in shape from the original baseline: email-based reset link flow with a confirmation state; a reset screen with a strength meter and visibility toggle; a session-expired screen offering re-login or return-home; an account-suspended screen showing the suspension notice, reason (if available), support contact, and a logout action.

**Admin-triggered forced password change:** if an administrator resets a user's password and marks it temporary, the emailed temporary password logs the user into a dedicated, unavoidable "Change your password" screen - the user cannot reach any other page in the app until they set a new password there. This is enforced by a global check on every request, not just a redirect from the login page.

## 13.11 Error Screens

Network Error, Server Error, Maintenance Mode, Access Denied, and Not Found each carry an illustration, a friendly message, and a retry or navigation action. A blank page is never an acceptable failure mode.

## 13.12 Layout Rules

Mobile: full-width form, large touch targets, safe-area-aware bottom spacing. Tablet: centered authentication card with balanced whitespace. Desktop: two-column layout - branding/illustration/feature highlights on the left, the authentication form on the right.

---

# 14. Home Dashboard

**Chat, not the Dashboard, is the default landing experience.** Successful login, successful post-verification registration, and visiting the application root all redirect to the Chat list rather than to the Dashboard. The Dashboard page itself is unchanged in content - recent chats, upcoming meetings, recent files, favorite contacts, calendar agenda - and remains fully reachable (via the desktop sidebar's separate bottom group, or via the profile-page quick-link card on mobile); it is simply no longer where a session starts.

The Dashboard's own recent-conversations widget, and the Chat list itself, both deliberately exclude a meeting's own auto-created room conversation from rendering as a phantom entry - a meeting nobody but the organizer ever joined previously surfaced there as an unlabeled "Unknown" conversation with no messages; this class of conversation is filtered out of both surfaces.

Widgets remain personalizable (visibility, order) where practical, and every widget defines loading, empty, and error states independently.

---

# 15. Chat List & Conversation Discovery

## 15.1 Chat List Layout

Each conversation row uses a **two-row layout**: the contact/group name and timestamp on the top row, and the last-message preview alongside the unread-count badge on the bottom row. This replaced an earlier single-row layout that squeezed the unread badge awkwardly next to the name with nothing to visually anchor it.

Each row displays avatar, name, last-message preview, timestamp, unread count, typing status, online indicator, pin indicator, and mute indicator, and supports infinite scroll (backed by real server-side pagination, not a single unpaginated collection) and pull-to-refresh.

## 15.2 List Header & Filter Tabs

The header carries icon-only actions for Starred, Archived, Broadcast lists, and Start a new chat (Section 8). Below it, a pill-tab strip filters the same loaded list into **All / Chats / Groups** - a client-side filter, not a separate fetch per tab. Each tab defines its own empty state ("No chats yet" vs. "No groups yet") distinct from the all-conversations empty state, so a user with only groups (or only 1:1 chats) never sees a misleadingly generic "nothing here" message while switching tabs.

The "+" (new conversation) action offers **New chat**, **New group**, and entry points into Broadcast lists and Communities.

## 15.3 Incoming Contact Requests, Inline

A pending incoming contact request renders as a dismissible-but-persistent banner directly on top of the chat list, with inline Accept/Decline, in addition to (not instead of) the standalone contact-requests page. A compact badge in the list header mirrors the same pending count.

## 15.4 Chat List Multi-Select

Tapping **Select** on the chat list reveals a checkbox on every row and swaps the header for a bulk-action bar: **Archive/Unarchive**, **Mute**, and **Delete**. Each bulk action is applied per selected conversation. "Delete" here removes the conversation from the list for the current user (leave-style) without deleting it for the other participant; starting a new conversation with someone whose chat was previously "deleted" this way correctly un-hides it rather than silently failing to reappear.

---

# 16. Conversation / Message Thread UI

## 16.1 Desktop Layout

```text
Sidebar → Chat List → Conversation Area → Contact/Group Information Panel
```

The left panel carries search, recent, pinned, and archived chats. The center panel carries the conversation header, message timeline, and composer. The right panel - contact profile, shared media/files/links, chat settings - collapses into a drawer on tablet.

## 16.2 Mobile: Full-Screen, No App Chrome

**An open 1:1 or group conversation on mobile hides the shared header and the fixed bottom navigation entirely**, matching how WhatsApp, Instagram, and Telegram's own mobile apps never show outer navigation chrome while a thread is open. The conversation renders truly edge-to-edge (its container's padding collapses to zero), with its own in-thread header - back arrow, contact/group name, call buttons - as the sole navigation surface. Desktop's split view is completely unaffected at any width; this is a mobile-only exception to the standard navigation chrome described in Section 11.

**On-screen keyboard tracking:** the conversation container tracks the browser's *visual* viewport height (not the layout viewport, which does not shrink when a mobile keyboard opens) via a `visualViewport` resize/scroll listener. This keeps the message list, composer, and in-thread header correctly sized and visible above the keyboard at all times, with no jump or hidden content when the keyboard opens or closes.

**Scroll containment:** the conversation's own message pane owns its scroll; the outer page container does not also scroll independently, preventing the header/composer stack from sliding out of place during an aggressive scroll or overscroll gesture. On iOS Safari specifically, document-level rubber-band bounce is stopped at the root (`overscroll-behavior-y: none` on `html`/`body`), while the message pane and main content area retain their own natural bounce contained to themselves (`overscroll-behavior-y: contain`) - this combination was needed because a physics-accurate rubber-band bounce is not something browser automation reproduces, so it required a real-device check to catch.

## 16.3 Conversation Header

Avatar (consistently non-shrinking at every viewport width), contact/group name, presence status, and "last seen" (privacy-permitting - rendered as a relative "last seen X ago" rather than the bare word "Offline"). Quick actions: Voice call, Video call, Search, and a **kebab ("⋯") menu**.

The kebab menu consolidates what used to be separate header buttons and adds several settings-level actions in one place: Mute, Archive/Unarchive (correctly reflecting the conversation's actual current state, not a hardcoded label), wallpaper picker, disappearing-messages setting, chat export, and "Select messages" (multi-select mode).

## 16.4 Message Timeline

Infinite scroll with date separators, an unread marker, delivery/read status, edited and deleted indicators. Messages load lazily and render virtualized for long conversations.

**Message status ticks** show sent → delivered → read progression. "Delivered" advances the moment the message reaches the recipient's client in any way - opening the specific thread, merely syncing the chat list, or a push notification arriving to a closed/backgrounded app - not only when the recipient opens that exact conversation. A group message's tick reflects "read" only once every recipient has read it. The read tick renders in the deliberate amber/gold color described in Section 6. A sender can open a dedicated "Info" view on their own sent message to see exact per-recipient delivered/read timestamps.

## 16.5 Call Log Rows, Inline

A completed or missed 1:1 call logs its own centered, system-style row directly into the conversation it was placed from - "Voice call · 12:34" for one that connected, "Missed voice/video call" for one that didn't - the same way WhatsApp shows call history inline in the thread itself.

## 16.6 Chat-Privacy Banner

Every conversation shows a short banner at the top of the message list. It is deliberately, carefully worded: it states that messages are private to the conversation's participants, without describing the app's protections in end-to-end-encryption terms that would overstate the actual model (see Section 41).

## 16.7 Empty Conversation State

Illustration, welcome message, and suggested actions ("Send your first message," "Share a file," "Start a voice call") for a thread with no messages yet.

---

# 17. Message Composer & Attachments

## 17.1 Composer

The text field is an auto-growing `<textarea>` (not a single-line `<input>`), expanding with content up to a 128px cap before it starts scrolling internally. Enter sends; Shift+Enter inserts a newline. The send control is a paper-plane icon button. Pasting an image directly into the composer (from the system clipboard) attaches it exactly as if it had been picked from the file browser.

## 17.2 Attachment Picker

Tapping the attachment button opens a **Camera / Gallery / Document** menu rather than a single generic file input:

* **Camera** opens the in-app camera (17.4).
* **Gallery** accepts images and video, with multi-select, and shows a real thumbnail preview for both - video previously fell back to a generic file icon, images did not.
* **Document** accepts any file type, matching a real OS "any file" picker, also with multi-select.

## 17.3 Reply / Forward

The composer shows the original sender and a message preview with a cancel action while a reply is pending. Forwarding supports selecting multiple destination conversations, an optional comment, and a preview before sending; a forwarded message always carries a "Forwarded" indicator. Forward also accepts multiple *source* messages at once (from message multi-select, Section 19.2) - every selected message forwards to the chosen destination in ascending original-send order, regardless of the order they were tapped in.

## 17.4 In-App Camera

A dedicated camera modal drives the device camera directly inside the app - a shutter tap captures a photo; a press-and-hold (roughly a third of a second) starts video recording; front/back camera flips when more than one is available. A review screen (retake / use) follows before the capture joins the normal attachment-preview flow. This is deliberately never the OS's own native camera capture sheet.

## 17.5 Voice Message Recorder

Recording replaces the entire composer row with a dedicated recording bar: a live waveform sampled from the actual microphone stream (not a canned animation), an elapsed-time counter, Pause/Resume, a trash button to discard outright, and a green send button that stops and sends immediately. Unlike every other attachment type, a voice note has no separate preview/confirm step before sending.

## 17.6 Upload/Download States

Drag-and-drop, multi-file, chunked, resumable upload with a visible queue, progress, and retry for failed uploads; large downloads stream without blocking the interface.

---

# 18. Media Viewers & One View

## 18.1 Custom Media Players

Every video, audio, and image a message can contain uses the app's own playback UI - **never** the browser's native `<video controls>` / `<audio controls>` or a native image viewer. A play/pause button, seek bar, and time label are built around a controls-less `<audio>`/`<video>` element: the audio player matches a compact voice-note bar with no volume control; the video player adds mute and fullscreen controls. Tapping any image bubble opens a full lightbox viewer for zoom/pan.

## 18.2 One View Media

Before opening, a one-view photo or video shows a small placeholder with a circular "1" badge rather than plain "Tap to view once" text. Tapping it opens the same custom, full-screen viewer described above. **Both** sender and receiver see "Opened" once it has been viewed, and this now updates live on an already-open conversation page - a sender who never navigates away or refreshes still sees the status flip the moment the recipient actually views it, without needing a reload.

The sender can never re-preview their own unconsumed One View upload - attempting to do so is blocked outright, and the sender's own bubble only ever shows a plain, non-clickable "One View" label rather than a tappable placeholder.

The One View full-screen viewer has its own **Reply** and **React** buttons, working directly from the viewer without needing the underlying message's own actions card.

## 18.3 Reactions & Emoji Picker

The reaction quick-strip (both on the message actions card and inside the One View viewer) offers a short row of common reactions plus a "more" button opening a full, categorized emoji picker (eight categories, several hundred emoji). The 24 most-recently-used emoji are remembered locally and shown first. Tapping an emoji you've already reacted with removes it (a toggle), matching the reference convention.

---

# 19. Message Actions, Search & Multi-Select

## 19.1 Message Actions Card

Message actions render as a floating card positioned near the tapped bubble - flipping above it, or clamped within the viewport, whenever there isn't room below - closed by tapping outside, choosing an action, or scrolling. This replaced an earlier inline row of underlined text links pushed into the page's own document flow beneath each bubble.

**Trigger:** a single tap on a bubble does **not** open this card. It requires a long-press (touch, roughly half a second, canceled if the finger moves more than a few pixels) or a double-click (desktop mouse). This deliberately frees the single tap for bubble-content-specific behavior - a One View placeholder's tap-to-view, and an image bubble's tap-to-expand lightbox - which previously competed with the actions card opening at the same moment.

Available actions: Reply, Forward, Copy (copies the message's real, already-legible text via the clipboard), Edit, Delete, Star, Pin, React. The reaction strip sits across the top of the card.

## 19.2 Multi-Select in a Thread

"Select messages," from the conversation kebab menu, reveals a checkbox alongside each bubble and swaps in a bulk-action bar: **Star**, **Forward**, **Delete**. Bulk Star only stars messages not already starred, so a mixed selection ends up fully starred rather than toggling some back off. Bulk Delete removes the selection for the current user only ("delete for me").

## 19.3 Search Within a Conversation

Supports text, files, links, dates, and media, with matching content highlighted in the results.

## 19.4 Starred Messages

Chat → Starred is a dedicated page listing everything the user has starred across every conversation, with inline unstar.

## 19.5 Chat Export

The conversation/group kebab menu offers "Export chat," downloading a zip containing a full CSV transcript (sender, timestamp, message) alongside every attached photo, video, and document still present on the server. A One View item already consumed is skipped; a message the exporting user deleted "for me" is left out of their own export. Because message bodies may be encrypted at rest (Section 41), the exported transcript shows a placeholder rather than raw ciphertext for any message it cannot legibly render.

---

# 20. Chat Settings, Wallpaper & Disappearing Messages

Reached from the conversation kebab menu (Section 16.3):

* **Wallpaper** - a per-conversation picker among a small set of default colors.
* **Disappearing messages** - Off / 24 hours / 7 days / 90 days. Once set, new messages in that conversation are removed for everyone automatically after the chosen interval, the same way an explicit "Delete for everyone" already removes a message.
* **Mute**, **Archive/Unarchive** (both correctly reflecting live state).
* **Notifications:** a new message triggers an in-app sound/notification even while the app is on a different page (as long as a tab is open), suppressed only while the user is actively viewing that exact conversation; gated on the relevant Notifications settings toggle (Section 33.3). A message pushed while the app is fully closed or backgrounded arrives as a genuine OS-level push notification (Section 31).

---

# 21. Broadcast Lists

Chat → Broadcast lists sends one authored message to a named list of contacts at once. Each recipient receives it as an ordinary 1:1 direct message in their own chat - not a group - so replies stay private between the sender and that one recipient rather than being visible to the whole list.

---

# 22. Contacts UI

Favorite and remove actions use the icon-first language described in Section 8. Presence ("last seen X ago") respects the same privacy visibility control used in the chat header. The contacts list uses the same real, server-paginated infinite scroll as the chat list.

---

# 23. Groups Module UI

The Groups module shares the Chat module's navigation entry (Section 11) and much of its visual language, while its own standalone list/detail pages remain reachable directly.

**Group list:** avatar, name, last message, timestamp, unread and mention counts, mute/pin indicators, search, filters, infinite scroll.

**Group header:** avatar, name, member count, online-member count where available; quick actions for voice call, video meeting, message search, group info, and more.

**Group information screen:** description, created date, owner, administrators, members, invite link (with copy/share/revoke and a clear active/expired indicator), shared media/files/links - administrative controls shown only to authorized roles (Owner/Admin/Moderator).

**Member management, join requests, announcements, polls, and group events** each follow the card-based patterns described in the component library (Section 12), with administrative actions visually distinguished from ordinary member actions per Section 3's design principle. Moderation actions (remove message, remove member) require the appropriate permission and always confirm through the app's themed modal (Section 12.4), never a native dialog.

---

# 24. Communities

Groups → Communities is a group-of-groups container that sits above ordinary Groups. Creating a community automatically creates a default "Announcements" group for it. The community owner attaches any other group they own (a group belongs to at most one community at a time); once attached, every member of every attached group can see announcements posted to the community.

An admin of the community's own Announcements group - delegated the same way any other Group admin already is, reusing the existing group role/permission system rather than a parallel one - can also attach groups and post announcements, not only the owner. Any attached group (except the Announcements group itself, which cannot be detached) can be removed by the community owner/admin or by that group's own current owner. Deleting a community is a soft delete, owner-only, confirmed through the themed modal pattern.

---

# 25. Calls UI (1:1 Voice & Video)

## 25.1 Unified Full-Screen Layout

Every call surface - ringing, connecting, and connected - uses one consistent, full-screen, Google-Meet-style layout, matching the meeting room's own visual language (Section 27). There is no separate, differently-styled "ringing" screen distinct from the connected call screen; the layout is continuous from the moment a call is initiated through to when it ends.

An incoming call still appears as a modal overlay preserving the current app state behind it, showing caller avatar, name, and call type, with Accept/Reject actions. An outgoing call shows the recipient's avatar/name and connection progress with a Cancel action.

## 25.2 In-Call Controls

Controls render as a two-row icon+label grid: **Speaker / Camera / Mute** on the first row, **More / Share / End** on the second - replacing an earlier flat row of plain, unlabeled buttons. A video call renders full-screen with the self-view picture-in-picture shown uncropped (`object-contain`), not cropped to fill its frame. A voice-only call shows an uncropped, full-screen self-view in the same layout family.

**Speaker/earpiece output routing** is available wherever the browser supports device output switching.

## 25.3 Screen Sharing During a 1:1 Call

Screen sharing works during either a voice or a video call. The underlying video connection is provisioned for a video track from the moment the call is set up (present with no active track during a voice call) specifically so that starting or stopping a share never requires renegotiating the connection mid-call. Starting a screen share during a voice call never also turns on the local camera - the two remain independent.

## 25.4 Add Member: Escalating a Call to a Meeting

An "Add member" action during an active 1:1 call creates a full instant Meeting and moves both original call parties directly into its room - bypassing the meeting lobby, since two people already mutually engaged in a live call have no real "waiting to be let in" moment to model. The original call ends automatically as part of this transition, and the party who didn't tap "Add member" is moved into the meeting automatically once their own client notices the call has escalated.

---

# 26. Call History

Call history rows are interactive, not plain static list items. Tapping a row opens a **detail popup** - name, direction, duration, timestamp - with a **Call Back** action (voice or video) that reuses the exact same call-start entry point available from an open conversation's own call buttons.

A missed call also generates its own dedicated push notification (distinct from the original "incoming call" ring, which is long gone by the time a call is actually marked missed), offering **Call back** and, when the missed call was tied to a conversation, a **Message** quick-reply action - identical in kind to a chat message's own push actions.

---

# 27. Meetings & Meeting Room UI

## 27.1 Meeting Flow

```text
Meetings List → Lobby → Meeting Room → Meeting Summary
```

## 27.2 Guest-Created Meetings (No Login Required)

A visitor with no account can create an instant meeting directly from the login screen ("Start a meeting"), not only join one via an existing link. They receive a shareable join link immediately, shown inside the meeting room itself, optionally password-protected, and become the meeting's host participant. Because this host is unauthenticated, they do not have access to the authenticated-only host-control endpoints (mute/remove/lock/transfer via the standard controls); the meeting instead winds down through normal inactivity handling (Section 27.7) once nobody genuinely remains.

This capability is admin-configurable and defaults on.

## 27.3 Meeting Lobby - Applies to Everyone, Including the Organizer

The lobby shows meeting title, organizer, scheduled time, and a camera/microphone device preview with toggles before joining. **Every participant passes through this lobby, including the meeting's own organizer** - creating an instant meeting no longer drops its creator straight into the room. This matters for the very first page load after creating a meeting: without going through the lobby, the organizer would see no camera/mic permission prompt or device preview at all, unlike everyone else joining that same meeting. A participant who refreshes mid-call and rejoins is still fast-tracked automatically, since that is a materially different situation from a first join.

## 27.4 Waiting Room

The schedule-meeting form presents an explicit "Who can join" choice - direct entry vs. host-approval - rather than an unlabeled checkbox, with an admin-configurable default. A guest joining via a password-protected link can submit that password directly on the guest join form.

## 27.5 Meeting Room - Desktop

Multi-panel layout: video grid (Grid / Speaker / Sidebar / Presentation, switchable mid-meeting), participant tiles showing video, name, speaking indicator, mute state, hand-raise, and connection quality, plus a persistent control bar and optional side panels for participants/chat.

## 27.6 Meeting Room - Mobile: Full-Bleed, Google-Meet Style

Below the `lg` breakpoint, both the registered-user and guest meeting rooms render full-bleed: the video grid takes the entire content width with no permanent side panel eating into it, the control toolbar becomes a floating, semi-transparent, icon-only bar pinned to the bottom of the screen, and People/Chat opens as a full-screen slide-up overlay via a dedicated toggle button rather than an always-visible sidebar. The desktop layout (`lg:` and up) is visually unaffected by this - only the mobile presentation changes.

The toolbar wraps rather than overflows on a narrow phone width; combined with the icon-first pass (Section 8), the great majority of the toolbar's buttons fit on a single row even at the narrowest common phone width, with the remainder wrapping cleanly to a second row instead of visually overlapping the meeting title.

## 27.7 Meeting Controls, Participants & Recording

Primary controls: Microphone, Camera, Screen Share, Whiteboard, Chat, Participants, Reactions, More, Leave. Host controls: End Meeting, Mute All, Lock Meeting, Recording, Waiting Room. The participant panel exposes name, role, audio/video state, with host actions (Mute, Remove, Promote to Co-Host, Admit from Waiting Room). A visible recording indicator (status, duration, host) appears to every participant whenever recording is active.

A meeting's join link is shown, with a Copy button, everywhere a meeting appears - the host's own lobby/room view, each row in the Meetings list, and the calendar event-detail popup - not only inside the room itself.

A guest who is the meeting's host sees the same Lock and Share Screen controls a registered host does, functioning identically.

A meeting with nobody genuinely present for a sustained period ends automatically rather than sitting active indefinitely - this covers both a lobby nobody ever actually entered and a room everyone has since left, with a brief grace window after the last person leaves so a meeting about to be rejoined isn't prematurely killed. A participant whose tab silently disappears (closed, crashed, lost network) stops rendering as a live video tile for everyone else within a short, bounded window, rather than leaving a stale "ghost" tile in the grid indefinitely; closing a tab normally also proactively signals departure at the moment it happens, so the common case resolves instantly rather than waiting out that window.

## 27.8 Meeting Summary

Duration, participants, shared files, whiteboard exports, chat summary, recording availability, with Download Report and Return to Dashboard actions.

---

# 28. Whiteboard

Tools: pen, highlighter, eraser, shapes, text, sticky notes, image upload, laser pointer, with undo/redo/clear/export. Drawing input uses pointer events (not mouse-only handlers), so the whiteboard is fully usable via touch as well as a mouse, in both the registered-user and guest rooms.

**Guests can use the whiteboard.** A guest joining with no account has the same whiteboard button, overlay, and drawing surface as a registered participant, attributed correctly in the object list/roster ("Alice" vs. "Bob (Guest)").

---

# 29. Calendar UI

## 29.1 Dedicated Nav Icon

Calendar is a first-class navigation item with its own dedicated icon - not a tab living inside the Meetings page.

## 29.2 Month / Week / Day Views

Styled closely to Google Calendar: thin grid lines, colored event chips carrying a time label (not just a title), and pill-tab view switching between Month, Week, and Day. Week and Day render a real hour-by-hour grid, not a flat list. Browsing works for past dates as well as upcoming ones. The month grid measures the actual remaining viewport height (accounting for the mobile bottom nav and other chrome) and compresses its own row height to fit - a real Google Calendar month view never forces the page itself to scroll to reveal a sixth week, and neither does this one.

## 29.3 Creating, Rescheduling & Resizing Events

A new "+ Add event" action creates standalone `reminder` / `personal` / `holiday` / `task` entries with no linked meeting, each color-coded by type, alongside ordinary scheduled meetings.

Dragging an event to a new day (Month view) or a new day/time (Week/Day view) reschedules the underlying meeting directly on the grid. Week/Day event blocks additionally carry a bottom-edge resize handle for adjusting duration by drag, holding the start time fixed while the end time changes.

## 29.4 Day Popover & Event Detail

Clicking any day cell that has events - not only the "+N more" overflow link - opens a day-detail popover listing every event on that date. Clicking an individual event (a month chip, a week/day block, or a popover row) opens an event-detail popup first - title, time range, description, and Delete or Join-meeting actions - rather than navigating or joining immediately on click.

## 29.5 Event Creation Form

Fields: title, description, date, start/end time, participants, reminder. Validation prevents invalid date/time combinations. Scheduling invites can include raw email addresses that don't match an existing account - they receive a real invitation and calendar-file email with a guest join link rather than being silently dropped, and this same matching behavior is consistent whether the meeting was scheduled from the web form or via the API.

## 29.6 Reminders

A due meeting reminder actually reaches the recipient - a push notification (gated on their Notifications preference) and, for a meeting tied to a real conversation between organizer and invitee, a system-style message posted into that conversation, matching how a call-log entry appears (Section 16.5). A standalone personal reminder with no organizer/invitee pair only produces the push, since it has nowhere else to post into.

---

# 30. Notifications & Push

## 30.1 Notification Center

Categories: Chat, Groups, Meetings, Calls, Files, Calendar, Security, System - with search, filters, mark-read, mark-all-read, and delete. Unread notifications are visually distinguished; each card shows an icon, title, description, time, and status.

## 30.2 Real OS-Level Push

Notifications reach a subscribed device as a genuine operating-system-level notification even with every browser tab and the app itself fully closed - not merely an in-tab toast. This is delivered through Firebase Cloud Messaging: the client obtains a device token via the Firebase Web SDK, explicitly reusing the app's own existing service worker registration (rather than a separate Firebase-managed one), since a page can only have one active service worker and the app's own worker already owns push handling.

Settings → Notifications → "Manage push notification devices" lists every device subscribed under the current account and lets the user revoke any of them individually. Turning off every notification toggle at once also unsubscribes every device for real, at the browser level, not merely in the database; turning off a single notification type alone leaves the shared device subscription in place, since one subscription serves every notification type.

**Reply directly from a notification:** on browsers that support inline-reply notification actions, a typed reply can be sent directly from the notification itself with no page open. Every other browser instead shows a plain "Reply"/"Open" action that opens the relevant conversation, per that browser's own graceful-degradation behavior.

Because message content may be encrypted at rest, a push preview for an encrypted message falls back to a generic "New message" rather than a real content preview - the server itself cannot decrypt it to build one.

## 30.3 Broadcast/System Messages

System announcements, maintenance notices, and emergency alerts are shown as pinned broadcasts, remaining visible until acknowledged or expired.

---

# 31. Files (Baseline)

The file manager (grid/list views, file cards with thumbnail/name/size/type/date/owner, drag-and-drop upload, shared/recent/favorites sections, and a storage dashboard) follows the same component patterns described in Section 12, unchanged in shape from the platform's original scope.

---

# 32. Settings Hub

Settings is a WhatsApp-style hub of category pages rather than one long stacked page:

* **Profile** - display name, date of birth, gender, and other profile fields.
* **Preferences** - timezone, and separate auto-download toggles for photos, videos, and documents (rather than one combined toggle).
* **Notifications** - per-type toggles for messages, calls, meetings, and contact requests; these are the exact toggles that gate the nav badges (Section 11), the incoming-call ringtone, and push delivery (Section 30.2), not merely stored preferences with no effect.
* **Chat defaults** - a default wallpaper and font size, applied the first time a *new* conversation's own settings are created; an existing conversation keeps whatever it already has.
* **Privacy** - last-seen visibility (everyone/contacts/nobody), enforced consistently everywhere presence is shown (chat header, contacts list, and the initial page render alike).
* **Account & Security** - its own dedicated sub-hub, described next.

Each hub entry uses the same icon + label + chevron list pattern.

---

# 33. Account & Security Sub-Hub

Account & Security is itself a sub-hub, in the same visual pattern as the top-level Settings hub, with each item as its own dedicated page:

* **Change password** - a real, dedicated password-change page.
* **Two-factor authentication** - setup, recovery codes, and the remembered-devices list (Section 13.6).
* **Passkeys** - register/remove WebAuthn credentials.
* **Connected accounts** - manage the linked Google SSO identity.
* **Active devices** - sessions, with force-logout.
* **Switch account** - jump to another held-but-inactive account.
* **Add account** - a direct, one-click entry point on the hub itself (rather than a small link buried inside Switch account).
* **Deactivate account** - re-confirms the current password, signs out everywhere, and soft-deletes the account in the same restorable way an administrator's own account-deletion action already works.
* **Logout / Logout of all accounts** - its own card, visually and functionally distinct from Deactivate account. This is where logout lives; there is no persistent header logout button anywhere in the app. "Logout of all accounts" only appears when more than one account is currently linked on the device, and clears every linked account's session in one action.

Every step-up-sensitive action in this sub-hub (disabling 2FA, removing a passkey, deactivating the account) re-confirms the user's current password before proceeding, and every destructive action here confirms through the app's themed modal component (Section 12.4).

---

# 34. Admin Panel UI

## 34.1 Layout

```text
Top Administration Bar
Admin Sidebar → Workspace → Context Panel (optional)
Status Bar
```

Sidebar sections cover Dashboard, Users, Roles, Permissions, Groups, Meetings, Files, Calendar, Notifications, Branding, Settings, SMTP, Queue, Scheduler, Analytics, Reports, Security, Backups, Archives, Logs, and Maintenance, each gated by permission. The section-switcher itself uses the pill-tab strip described in Section 12.5, matching Chat's and Calendar's own tab styling. On mobile, tables that would otherwise be clipped by their own rounded-corner container instead scroll horizontally inside an inner wrapper, so wide admin tables (Users, Archives, Logs) remain usable on a phone rather than visually cut off.

## 34.2 Icon + Label - the One Deliberate Exception to Icon-Only

Every per-row admin action (Suspend/Activate, Force logout, Reset password, Grant/Revoke elevated access, Delete, Restore, Roles delete, Archives download) shows an icon **and** a text label together, as described in Section 8 - the one place in the product that intentionally trades minimalism for reduced ambiguity, because Admin is lower-traffic and higher-stakes than Chat or Contacts.

## 34.3 User Management

List, search, filters, bulk actions, and status indicators, plus **All / Real / Load-test** pill tabs segmenting genuine accounts from load-test-seeded ones (identified by their own reserved email pattern) - a small badge also marks load-test rows even within the "All users" view. Page-by-page controls ("Page X of Y, N total") are real, matching the pagination already used by the underlying data.

An administrator can set a user's password directly from this screen, optionally marking it temporary - a temporary reset forces the recipient through the dedicated password-change screen described in Section 13.10 before they can reach anywhere else in the app, and force-logs-out their other sessions the same way any other admin account-access action does.

## 34.4 Environment Variable Management

A dedicated **Environment** tab (restricted to the highest administrative tier, never a plain Admin role) offers two ways to edit the live application environment configuration: a field-by-field form - one input per real key, with sensitive-looking keys (anything resembling a secret, password, token, or key) masked behind a Show/Hide toggle - and a raw full-file textarea for direct editing. Every save re-confirms the acting administrator's own account password first, the same step-up pattern used for deactivating an account or disabling 2FA, and takes a dated backup of the current configuration file before writing. Keys that exist in the reference configuration template but are missing or blank in the live configuration are highlighted directly in this UI, matching what the deploy pipeline itself would flag.

## 34.5 Roles, Branding, Settings, SMTP, Queue, Scheduler, Analytics, Reports, Security, Backups, Archives, Logs, Maintenance

Each follows its documented pattern: a roles/permission matrix with assigned-user visibility; branding fields (app name, logo, favicon, theme colors, login background, footer) with a preview before applying; grouped/searchable application settings; SMTP configuration with masked sensitive fields and a test-email action; queue/scheduler status with retry/execute/enable/disable controls; analytics charts (user growth, meeting usage, chat activity, storage, device distribution, daily active users) with export; generate/preview/download reports; a security center (failed logins, blocked IPs, suspicious activity, active sessions) with block/unblock/force-logout actions; backup/restore/verify with explicit confirmation before anything destructive; archive create/restore/download/delete; a searchable, filterable, exportable log viewer across application/audit/activity/security/API/queue/scheduler categories; and a maintenance-mode toggle that warns before enabling.

Every confirmation anywhere in this panel - as everywhere else in the product - uses the themed modal component, never a native browser dialog.

---

# 35. Responsive Design Rules

Mobile: bottom navigation, full-width forms, large touch targets, gesture support, one-handed-friendly menus. Tablet: collapsible sidebar, multi-column content where it helps, drawer navigation. Desktop: persistent sidebar, multi-panel workspace, keyboard shortcuts, hover states, resizable panels where applicable. No layout relies on a single screen size, and both portrait and landscape orientation remain usable for critical workflows.

---

# 36. Progressive Web App Behavior

The app installs as a standalone PWA with its own splash screen, app icon, and theme color. Installation follows each browser's own supported PWA flow.

**iOS-specific install guidance:** Safari/WebKit has no install-prompt API at all, so the install action detects iOS specifically and shows a step-by-step "Add to Home Screen" walkthrough modal instead of silently doing nothing.

**Safe forced update:** an installed PWA that's rarely fully closed can otherwise sit on stale app code indefinitely, since a browser only activates a newly fetched service worker once every tab for that origin has closed. On every page load, the app checks for a waiting update and activates it immediately - unless a call or meeting is actively in progress on that page, in which case it retries roughly every 30 seconds until it's safe to do so. It never force-activates unconditionally, preserving the guarantee that an update can never interrupt an active call or meeting.

---

# 37. Offline Experience

Available offline: the navigation shell, cached static assets, and - deliberately, for chat and contacts specifically - a real cached view of the conversation list, the most recent messages per conversation (already decrypted where applicable), and the contacts list, rendered from local cache the moment a live fetch fails while offline.

**Composing while offline:** sending a plain text message (or reply) while offline shows an optimistic "Waiting to send…" bubble immediately and queues the action rather than failing outright; the queue drains automatically once connectivity returns, for any conversation whose page is open at that moment. A queued action for a conversation not currently open stays queued until that conversation is next opened while online.

**Genuinely unavailable offline, by nature rather than by choice:** attachments, calls, meetings, and file uploads all require an active connection - queuing an arbitrary binary upload indefinitely, or holding a real-time call/meeting connection with no network, isn't a scope decision, it isn't possible.

---

# 38. Accessibility Standards

Every module supports full keyboard navigation, screen-reader labels and ARIA roles/states, visible focus indicators, high-contrast compatibility, and logical tab order. Status is never communicated by color alone. Forms carry associated labels, required-field indicators, and validation feedback that assistive technology can announce. Text remains usable under browser zoom and responsive scaling without introducing horizontal scrolling.

---

# 39. Performance Guidelines

Lazy-load dashboard widgets, analytics, and non-visible panels; paginate large user/log/file datasets; virtualize long message timelines; cache frequently-accessed summaries; generate heavy reports asynchronously; avoid blocking rendering on non-critical data.

---

# 40. Encryption & Privacy Indicators (UI-Facing)

The chat-privacy banner (Section 16.6) is the user-visible face of a server-managed, per-conversation encryption model for message text: the app itself generates and holds each conversation's key material, rather than a zero-knowledge design where only end-user devices ever hold key material. There is no per-user keypair, no password-derived unlock step, and - deliberately - no "unlock your encrypted messages" prompt anywhere in the product; encryption of message text is fully automatic from the user's perspective.

A conversation whose membership grows past a defined size cap continues to work exactly as before, with a plainly visible indicator - **"Not end-to-end encrypted (too many members)"** - rather than silently degrading with no explanation. This is a disclosed limit, matching how mainstream messengers cap group-level encryption rather than attempting to encrypt separately for every recipient in a very large group.

Any page or export that renders message content but cannot decrypt it (the starred-messages page, a chat export's CSV transcript) shows a placeholder for that message's body rather than raw ciphertext.

Regular (non-One-View) media attachments are not covered by this per-conversation text encryption; only message text bodies are. This is disclosed plainly rather than left as a silent gap.

---

# 41. Design Consistency & Governance Rules

* Similar actions look and behave the same everywhere they appear (Delete, Archive, Mute, Leave, all read the same regardless of which module they're invoked from).
* Destructive actions are always visually distinguished and always confirmed via the app's own themed modal - never a native browser dialog.
* Primary actions occupy a consistent position within each screen family.
* Empty states always provide the user a next action, not just an explanation.
* Components are theme-aware, keyboard-accessible, and reused rather than duplicated per page; business logic does not live inside a UI component.
* A new screen introduced after this document is authored documents its purpose, layout, component list, user actions, error states, accessibility notes, and responsive behavior before implementation is considered final.

---

# 42. Complete Navigation & User Journey Flows

```text
Authentication:
Splash → Welcome → Login/Register → (Email Verification) → (2FA) → Chat

Chat:
Chat List (All/Chats/Groups) → Conversation → Media / Calls / Files / Calendar links

Calls:
Chat (or Contacts) → Voice/Video Call → (optional) Add member → Meeting Room

Meetings:
Meetings List → Lobby → Meeting Room → Meeting Summary

Groups:
Chat (Groups tab) → Conversation → Group Information → Members / Polls / Events
                                                       → Communities (group-of-groups)

Calendar:
Calendar (own nav icon) → Month/Week/Day → Day popover / Event detail → Join / Edit / Delete

Settings:
Settings Hub → Profile / Preferences / Notifications / Chat defaults / Privacy
             → Account & Security Sub-Hub → Password / 2FA / Passkeys / Connected accounts
                                           → Active devices / Switch / Add account
                                           → Deactivate / Logout / Logout of all accounts

Administration:
Admin Login → Dashboard → Users → Roles → Settings → Environment → Reports → Security
```

Every screen integrates with related modules without duplicating functionality - a meeting joinable from Chat, Calendar, or the Meetings list is the exact same room regardless of entry point; a call escalated to a meeting is the exact same meeting room a scheduled meeting would open into.

---

# 43. Future Enhancements

The design system is architected to accommodate, without requiring a structural redesign:

* Breakout rooms, live captions, and real-time translation in meetings.
* Group-level channels and richer moderation tooling for Communities.
* An AI assistant surface integrated into the existing navigation model.
* Cloud storage integrations and offline file synchronization beyond chat/contacts.
* Custom, user-configurable dashboard widgets.

Future modules are expected to follow the same navigation model (Section 11), the same themed-modal confirmation standard (Section 12.4), and the same icon language (Section 8) established here, rather than introducing a parallel design language.

---

# 44. Summary

This specification defines the complete, current UI/UX of the Meet platform: a single icon-sidebar/bottom-bar navigation model with merged Chat+Groups; a chat experience with WhatsApp-parity message status, floating actions, full-screen mobile threads, custom media viewers, disappearing messages, broadcast lists, and Communities; a Google-Meet-styled call and meeting-room experience spanning 1:1 calls, call-to-meeting escalation, and full guest support down to the whiteboard; a Google-Calendar-styled scheduling experience with drag-to-reschedule and drag-to-resize; a WhatsApp-style Settings hub with a dedicated Account & Security sub-hub covering 2FA, passkeys, SSO, and multi-account switching; an Admin panel that deliberately keeps icon-and-label actions and adds direct environment-variable management; and a firm, product-wide rule that every confirmation, alert, and prompt uses the app's own themed modal - never the browser's native dialogs. Together these define the implementation reference for every designer, frontend developer, backend developer, and QA engineer working on the platform going forward.
