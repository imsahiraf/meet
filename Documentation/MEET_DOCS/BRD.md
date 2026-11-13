# Brand Guidelines (BRD)

**Version:** 2.0

**Document Type:** Brand Guidelines

**Priority:** High

---

# 1. Purpose

This document defines the official brand identity, design principles, visual standards, communication style, and branding rules for the Meet platform.

Its objective is to ensure that every user-facing experience maintains a consistent, recognizable, and professional brand identity across web, mobile, documentation, and marketing materials.

---

# 2. Brand Vision

Meet is designed to provide secure, fast, reliable, and intuitive communication.

The brand shall represent:

* Trust
* Simplicity
* Privacy
* Innovation
* Professionalism
* Reliability
* Human Connection

All branding decisions shall reinforce these values. Trust and Privacy are expressed directly in shipped product behavior, not just aspiration - see Section 12 (Brand Voice) and Section 23 (Communication Standards) on the chat-privacy banner.

---

# 3. Brand Personality

The Meet brand shall be perceived as:

* Modern
* Friendly
* Professional
* Secure
* Minimal
* Accessible
* Global

Visual and written communication shall remain consistent with this personality.

**Design-language note:** several major surfaces - chat, navigation, settings, the calendar, and the meeting room - deliberately adopt interaction patterns familiar from mainstream communication apps (WhatsApp-style chat/navigation/settings, Google Meet-style call room, Google Calendar-style scheduling). This is a considered choice about *interaction and layout conventions* users already know, not a departure from Meet's own logo, color system, or brand voice, all of which stay consistent. The "Modern," "Friendly," and "Minimal" personality traits are what motivate borrowing well-understood conventions rather than inventing unfamiliar ones.

---

# 4. Brand Mission

The mission of Meet is:

> To enable secure, high-quality, and effortless digital communication while protecting user privacy and delivering a reliable collaboration experience.

---

# 5. Core Brand Values

The platform shall consistently emphasize:

* Security by Design
* User Privacy
* Simplicity
* Reliability
* Performance
* Accessibility
* Continuous Innovation

These values shall influence product, design, and communication decisions.

---

# 6. Logo Guidelines

The official Meet logo shall:

* Remain clear and legible.
* Maintain consistent proportions.
* Preserve required clear space.
* Not be stretched or distorted.
* Not be recolored outside approved palettes.
* Be used in approved file formats.

Only approved logo assets shall be distributed. The real Meet Meet logo is used platform-wide:

* Every PWA manifest icon size, the Apple touch icon, and `favicon.ico` use the real logo (`public/icons/favicon-32.png`, `public/icons/apple-touch-icon.png`, `public/favicon.ico`).
* A maskable variant (`public/icons/icon-maskable-512.png`) scales the logo into the icon's safe zone on a white background, so OS-level circle/squircle cropping on Android home screens never clips it.
* The service worker's cache is versioned so already-installed PWA users receive updated icon assets on their next launch rather than staying on a stale cached copy.

---

# 7. Color System

The design system defines:

* Primary Brand Color
* Secondary Brand Color
* Accent Colors
* Success Color
* Warning Color
* Error Color
* Neutral Palette

Color usage complies with accessibility requirements.

The primary brand palette is implemented as a single source of truth in `resources/css/app.css`'s Tailwind v4 `@theme` block - an indigo-family scale (`--color-brand-50` through `--color-brand-900`, e.g. `--color-brand-600: #4f46e5` as the primary actionable/accent color used for buttons, links, and active states). Success/Warning/Error colors follow Tailwind's semantic defaults rather than a separate bespoke palette. Any future rebrand or palette change should be made in this one CSS block, not scattered per-component hex values.

One deliberate exception to color-by-convention: message read-receipt ticks (Section 15) use an amber/gold color rather than the blue commonly associated with other chat apps, or the app's own brand-600 indigo - a conscious choice to keep the read-state indicator visually distinct from both.

---

# 8. Typography

Typography defines:

* Primary Typeface
* Secondary Typeface
* Heading Scale
* Body Text Scale
* Button Typography
* Caption Typography

Typography remains consistent across all interfaces, using the Tailwind v4 default type scale in `resources/css/app.css`.

---

# 9. Iconography

Icons shall:

* Maintain a consistent visual style.
* Be simple and recognizable.
* Scale cleanly across supported devices.
* Support accessibility requirements.

Only approved icon sets are used. A consistent, icon-first visual language is applied to the Settings hub, the meeting room's in-call toolbar (Raise hand / Share screen / Whiteboard / Lock / Mute / Camera / End / Leave - icon-only below the `sm` breakpoint, with mic/camera icons swapping between on/off states), the Chat list header (Starred / Archived / Broadcast lists / Start a new chat), and Contacts' favorite/remove actions.

The Admin panel is a deliberate, documented exception to "icon-only": per-row actions there (Suspend/Activate, Force logout, Reset password, Grant/Revoke Super Access, Delete, Restore, Roles delete, Archives download) carry an icon **and** a text label together, since Admin is a lower-traffic, higher-stakes surface where an unlabeled icon is more likely to be misclicked. This is the one place brand iconography intentionally trades minimalism for reduced ambiguity.

---

# 10. Imagery

Images shall communicate:

* Collaboration
* Communication
* Diversity
* Professionalism
* Simplicity

Visual assets avoid unnecessary complexity.

---

# 11. Illustration Style

Illustrations shall be:

* Modern
* Minimal
* Friendly
* Consistent
* Scalable

Illustrations support the overall product identity. This remains a forward-looking section - see Section 25, Future Brand Evolution.

---

# 12. Brand Voice

Written communication shall be:

* Clear
* Helpful
* Respectful
* Professional
* Concise

Technical language is appropriate for the intended audience.

The brand voice principle of "Clear" and "Respectful" is expressed concretely in the chat product: every conversation displays a short banner reinforcing that messages are private to the conversation's participants. The wording is deliberately honest rather than over-promising - it does not claim end-to-end encryption in a way that would overstate the app's actual model. This stays consistent with the app's real encryption architecture (server-managed per-conversation keys - see Section 15), so the brand never claims more privacy than the system actually provides - a direct expression of the "Trust" value in Section 2.

---

# 13. Accessibility

Brand implementation shall support:

* Readable Typography
* Color Contrast
* Keyboard Navigation
* Screen Reader Compatibility
* Inclusive Design

Accessibility remains a core brand principle, consistent with the Iconography standards in Section 9 and the Component Branding standards in Section 15 (e.g. floating action menus with clear tap targets, icon+label pairing on high-stakes Admin actions).

---

# 14. UI Branding Standards

Every user interface shall maintain:

* Consistent Color Palette
* Standard Typography
* Uniform Iconography
* Consistent Spacing
* Standard Button Styles
* Unified Form Design
* Consistent Navigation

The UI follows the approved Design System (UDS).

"Consistent Navigation" is delivered through a single unified pattern: a ~72px icon-only sidebar rail on desktop (`lg:` and up, WhatsApp-Web style, with hover tooltips) and a fixed bottom bar on mobile, both surfacing the same four primary items (Chat, Contacts, Calls, Meetings), with Dashboard/Admin grouped separately. This single navigation pattern is the one standard referenced by every other component below.

---

# 15. Component Branding

The following components shall follow standardized branding:

* Login Screens
* Registration Screens
* Chat Interface
* Voice Call Interface
* Video Call Interface
* Notifications
* Dialogs
* Error Pages
* Settings Pages
* Administrative Dashboard
* Calendar

Brand consistency is maintained throughout the application:

* **Login / Registration Screens:** consistent core layout, with entry points added in the same visual language - "Continue with Google" (SSO), "Sign in with a passkey", and account-switcher tiles for a held-but-inactive account. Logout is icon-only rather than a text link, and "Remember me" defaults checked.
* **Chat Interface:** the most extensively developed component. Navigation chrome (header/bottom nav) is hidden while a conversation is open on mobile, full-bleed edge-to-edge, matching the reference apps' own back-arrow-only in-thread pattern. Message status is shown via ticks (sent/delivered/read, amber/gold for read - see Section 7), a floating action card carries message actions with an emoji-reaction strip across its top. Attachments use a Camera/Gallery/Document picker with real thumbnail previews for images and video, plus an in-app camera (shutter for photo, press-and-hold for video). The voice recorder is a dedicated bar with a live waveform, pause/resume, and one-tap send. All media (image/audio/video) uses custom-built in-app viewers/players rather than native browser controls, kept visually consistent with the rest of the chat surface.
* **Voice Call Interface / Video Call Interface (Meeting Room and direct calls):** full-screen, Google-Meet-style layout across every call surface - a full-width video grid (or, for a voice-only call, an uncropped full-screen self-view), a two-row icon+label control grid (Speaker/Camera/Mute, then More/Share/End), and People/Chat as a full-screen slide-up overlay rather than a persistent side panel. This layout is used consistently from the moment a call is initiated through to when it connects and ends - there is no separate, differently-styled "ringing" screen.
* **Notifications:** in-app toast/badge conventions plus real OS-level push (Firebase Cloud Messaging) alongside in-tab notifications, and nav-icon red-dot badges on Chat/Contacts.
* **Dialogs / Error Pages:** every user-facing confirmation, alert, and prompt uses the app's own themed modal component, styled consistently with the rest of the product - none rely on the browser's native `alert`/`confirm`/`prompt` dialogs.
* **Settings Pages:** a WhatsApp-style hub of category pages (Profile, Preferences, Notifications, Chat defaults, Privacy, Account & Security), with Account & Security itself further split into its own sub-hub (Change password, 2FA, Passkeys, Connected accounts, Active devices, Switch/Add account, Deactivate).
* **Administrative Dashboard:** action buttons carry an icon **and** a label (Section 9) as a deliberate exception to the icon-only convention used elsewhere.
* **Calendar:** styled closely to Google Calendar - thin grid lines, colored event chips with time labels, week/day hour-by-hour grids, drag-to-reschedule and drag-to-resize, and a dedicated nav icon of its own.

---

# 16. Marketing Materials

Marketing assets shall include:

* Website
* Product Brochures
* Presentations
* Social Media Graphics
* Email Campaigns
* Product Videos
* Advertisements

All materials shall use approved brand assets.

---

# 17. Documentation Branding

Project documentation shall maintain:

* Standard Cover Page
* Version Information
* Document Headers
* Typography Standards
* Consistent Formatting
* Revision History

Documentation reflects the official Meet identity. The `**Version:** / **Document Type:** / **Priority:**` header-block convention is retained across all documentation.

---

# 18. Social Media Guidelines

Official social media communication shall be:

* Professional
* Informative
* Friendly
* Consistent
* Brand Aligned

Visual identity shall remain consistent across platforms.

---

# 19. Brand Asset Management

Approved assets shall include:

* Logos
* Icons
* Illustrations
* Color Palette
* Typography Files
* Templates

Only approved asset repositories shall be used. The authoritative locations are `public/icons/` and `public/favicon.ico` for logo/icon assets (Section 6) and `resources/css/app.css`'s `@theme` block for the color palette (Section 7).

---

# 20. Brand Governance

Brand governance shall require:

* Design Review
* Marketing Review
* Product Review
* Documentation Review
* Final Approval

Brand changes shall follow documented approval processes.

---

# 21. Brand Approval Workflow

```text id="brandapprovalworkflow"
Design Created
      ↓
Brand Review
      ↓
Revision (if required)
      ↓
Approval
      ↓
Publication
```

Only approved materials shall be published.

---

# 22. Brand Compliance

Compliance reviews shall verify:

* Logo Usage
* Typography
* Color Accuracy
* Accessibility
* Messaging
* Asset Consistency

Non-compliant materials shall be updated before publication.

---

# 23. Communication Standards

External communication shall maintain:

* Clear Language
* Professional Tone
* Accurate Information
* Respectful Messaging
* Inclusive Language

Brand communication reinforces user trust. The in-product chat-privacy banner (Section 12) is the clearest current example of this principle in practice - accurate, non-overstated language about what the product actually does, rather than a generic trust claim.

---

# 24. Asset Versioning

Brand assets shall include:

* Version Identifier
* Release Date
* Usage Notes
* Approval Status

Obsolete assets are archived, not left alongside their replacements.

---

# 25. Future Brand Evolution

The branding framework supports:

* Design System Expansion
* Motion Branding
* Interactive Marketing Assets
* Brand Localization
* Product Family Branding

Future evolution shall preserve brand recognition. Section 11 (Illustration Style) and the Motion Design aspect of Section 3 remain aspirational.

---

# 26. Brand Review Process

Periodic reviews shall evaluate:

* Product Consistency
* Marketing Consistency
* Documentation Consistency
* Accessibility Compliance
* User Feedback

Review outcomes shall guide future brand improvements.

---

# 27. Summary

The Brand Guidelines define the complete visual identity, communication standards, branding governance, and asset management framework for the Meet platform:

* Real logo/icon assets are used across every installable surface.
* A consistent, icon-first visual language is applied across Settings, the meeting room, Chat, and Contacts, with the Admin panel's icon+label pairing kept as a deliberate, documented exception.
* Chat, navigation, settings, the calendar, and the meeting room each converge on well-understood mainstream interaction conventions (WhatsApp / Google Meet / Google Calendar) as a considered design-language choice - not a change to Meet's own logo, palette, or brand voice.
* Brand voice's "Clear" and "Respectful" principles are expressed concretely in the honest chat-privacy banner, kept consistent with the app's actual, disclosed encryption model.
* Every user-facing confirmation/alert/prompt uses the app's own themed modal component rather than the browser's native dialogs.
