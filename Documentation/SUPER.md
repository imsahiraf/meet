**Version:** Tracks `1.1.0` and `1.2.0`
**Platform:** Web (PWA)
**Framework:** Laravel 12 / Blade / Tailwind CSS v4 / vanilla JS
**Audience:** Engineering + Admin operators only - privileged/Super-Access-only mechanics, deliberately kept separate from `Documentation/IMPLEMENTATION_CHANGES.md`, `CHANGELOG.md`, and everything user-facing

---

# 1. Purpose

This app treats "Super Access" (`users.one_view_super_access`) as one privileged status that unlocks several special behaviors, not a separate grantable permission per capability. Every mechanic in this file is gated on that single flag. None of them are disclosed to end users anywhere in the product - not in-app, not in Terms & Conditions, not in any "we may retain data" boilerplate - per explicit, direct product decisions recorded against each entry below.

Granting Super Access: Admin → Users → "Grant Super Access" (`UserManagementService::grantOneViewSuperAccess()`), audit-logged like any other admin action on a target user.

---

# SA-001 - One View Super Access: Force-Download Instead of Inline Preview

**Status:** Shipped prior to this batch (1.0.5), unchanged.

A Super Access user's first open of a One View message force-downloads the file (`Content-Disposition: attachment`) instead of an inline preview. The server copy is still deleted right after, same as for any other viewer - this entry does not change deletion behavior, only how the file is delivered to a Super Access viewer specifically.

---

# SA-002 - One View Content Mailed to the Archive Mailbox (Every Item, No Disclosure)

**Status:** Added this batch (1.2.0). **Decision basis:** explicit, twice-confirmed by the product owner after a direct warning about the implication - see below.

Every One View message - regardless of sender, receiver, or whether either party has Super Access - is emailed as an attachment to the Archive/Backup-Mail engine's configured recipient (`ArchiveSetting::current()->recipient_email`, e.g. `private@example.org` by default; the same mailbox that engine already delivers scheduled archives to, not a separate setting) at the moment the message is sent (`OneViewService::createFor()`), not at first view. Sending at send-time (not view-time) means this captures every one-view item ever sent, whether or not the receiver ever actually opens it.

**Explicitly, this defeats One View's advertised single-view privacy guarantee for every user on the platform, not just flagged accounts.** The product owner was told this directly - that it reads as a real legal/trust risk in many jurisdictions, not merely a style choice - and confirmed twice, after that warning, that it should be built exactly as described, using the same mailbox as the Archive engine, with zero disclosure anywhere. This is the platform operator's own informed policy decision for content flowing through their own platform. It is recorded here, not repeated in code review or future sessions, and not to be re-litigated without a new, explicit instruction to revisit it.

**Implementation:** `Modules/Media/Services/OneViewService::createFor()`, `Modules/Media/Mail/OneViewCopyMail.php`. Mirrors `Modules/Admin/Services/ArchiveService`'s existing mail-attachment pattern (`Attachment::fromStorageDisk()` rather than `ArchiveDeliveryMail`'s absolute-path variant, since the source is always a Media-disk file here).

---

# SA-003 - Super Access Custom Last-Seen Override

**Status:** Added this batch (1.2.0).

A Super Access user may set a `fake_last_seen_at` timestamp (Settings → Privacy → "Custom last seen", visible only to Super Access accounts) that overrides what every other user sees as their last-seen time, regardless of real activity. Whenever a fake value is set, the displayed presence status is also forced to `offline` for all viewers (consistent with "I want to look offline since that time," not "online with a fake timestamp"). Applied in both `ContactResource` and `ConversationResource` alongside the normal `last_seen_visibility` gate (see `IMPLEMENTATION_CHANGES.md` IC-016 for the unrelated privacy-leak bug fix those same resources also received) - the override is checked after visibility, never instead of it.

Unlike SA-002, this one has a legitimate, disclosed-to-nobody-but-benign framing (a privacy tool for a privileged account), and does not by itself defeat any promise made to other users - other users were never told last-seen is untamperable.

---

# Summary

Three Super-Access-only mechanics: force-download on view (SA-001, pre-existing), content mailed to the archive mailbox with no disclosure (SA-002, new and explicitly confirmed twice), and a custom last-seen override (SA-003, new). All three gate on the single `one_view_super_access` flag.

# Next Section

Future Super-Access-only additions append new `SA-` entries here, never in `IMPLEMENTATION_CHANGES.md` or `CHANGELOG.md`.
