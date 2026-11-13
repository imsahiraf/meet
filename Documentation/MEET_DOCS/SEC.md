# Security (SEC)

**Version:** 2.0

**Document Type:** Security Architecture

**Priority:** Critical

---

# 1. Purpose

This document defines the security architecture, engineering principles, trust boundaries, and protection strategies for the Meet platform.

Its purpose is to establish a defense-in-depth security model that protects users, data, infrastructure, APIs, media, and administrative functions throughout the application lifecycle, and to describe - as a single, self-contained reference - how authentication, authorization, encryption, and privileged-configuration controls actually behave.

This document complements the Non-Functional Requirements (NFR) and Technical Design Specification (TDS) by defining the security architecture in detail.

---

# 2. Security Objectives

The platform provides:

* Confidentiality
* Integrity
* Availability
* Authentication
* Authorization
* Accountability
* Non-Repudiation
* Privacy Protection

Security considerations are incorporated into every module.

---

# 3. Security Principles

The platform follows:

* Defense in Depth
* Least Privilege
* Secure by Default
* Zero Trust Principles
* Separation of Duties
* Fail Securely
* Minimize Attack Surface
* Principle of Explicit Authorization

Every security decision favors protection over convenience. The step-up re-authentication pattern described in Section 10 is a direct expression of this principle: an authenticated session alone is not treated as sufficient proof of intent for the platform's most consequential actions.

---

# 4. Trust Boundaries

The application recognizes the following trust zones:

```text id="trustboundaries"
Internet Users
        ↓
HTTPS Gateway
        ↓
Laravel Application
        ↓
Business Services
        ↓
Database
        ↓
Storage
        ↓
Backup Systems
```

Every boundary crossing requires appropriate validation and authorization.

---

# 5. Security Domains

The platform security model consists of:

* User Security
* Authentication Security
* Authorization Security
* API Security
* Media Security
* Session Security
* Infrastructure Security
* Administrative Security
* Operational Security

Each domain is independently protected while contributing to the overall security posture.

---

# 6. Authentication Architecture

Authentication supports:

* Registration
* Login
* Logout
* Email Verification
* Password Reset
* Session Management
* Device Sessions
* Multi-Factor Authentication (TOTP)
* Passkeys (WebAuthn)
* Single Sign-On (Google)
* Multi-Account Login and Switching

```text id="authsecurityflow"
Login Request
        ↓
Input Validation
        ↓
Credential Verification
        ↓
Account Status Validation
        ↓
Two-Factor Challenge (if enabled and device not trusted)
        ↓
Session Creation
        ↓
Audit Log Entry
        ↓
Authenticated Session
```

Failed authentication attempts are handled securely and consistently. Passwords are hashed using Laravel's supported password hashing, never stored or logged in plaintext, reset only through verified workflows, and never returned by any API response.

---

# 7. Two-Factor Authentication, Passkeys, and Single Sign-On

**Two-factor authentication (TOTP).** Enabled from Settings → Account & Security via a QR code, and only actually activated once the user confirms it with one real generated code - generating a secret alone never turns 2FA on. Eight one-time recovery codes are shown exactly once at confirmation time and can be regenerated later (itself step-up gated, Section 10). Once enabled, both web and API login stop at a code-entry screen after the password check succeeds and before any session or token is issued. A "Remember this device" option on that screen lets the same browser skip the challenge for 30 days; only a hash of the device's own random token is stored, with the raw token held solely in the browser's cookie. Settings → Account & Security lists every device currently skipping the code and allows forgetting any of them early. Disabling 2FA and regenerating recovery codes are both step-up gated (Section 10).

**Passkeys (WebAuthn).** Registered and removed from Settings → Account & Security; a "Sign in with a passkey" entry point also sits on the login page. A passkey login deliberately skips the separate two-factor challenge - a passkey ceremony is itself possession plus biometric/PIN verification, already a second factor by construction, matching how major identity providers treat it. Removing a passkey is step-up gated (Section 10); registering a new one is not, since registration only ever adds a credential and cannot be used to weaken an account the way removal or disablement can.

**Single Sign-On (Google).** "Continue with Google" signs in or registers in one step, matching an existing account by email automatically or creating a new, already-verified one. Unlike a passkey, SSO does **not** skip the two-factor challenge - a linked Google account is a separate identity from the platform's own account record, so 2FA continues to protect it regardless of how the sign-in happened. Accounts created this way may have no password and no phone number at the database level; application-level registration validation is unchanged for accounts created the conventional way.

**Multi-account login.** A user can hold more than one account signed in on the same device and switch between them without re-entering a password, built entirely on the existing "remember me" mechanism rather than a separate session or token layer - a held account is never more trustworthy than its own remembered login already was, and revoking that underlying token elsewhere (a genuine password change, an administrative force-logout) invalidates the held session here too, automatically. Logging out of an account explicitly forgets it from the switcher. "Logout of all accounts" clears every linked account's session in one action. Multi-account switching, passkeys, SSO, and 2FA's remember-device feature are all web-login features; API/bearer-token clients have no cookie jar to hold the underlying state and are unaffected.

---

# 8. Step-Up Password Re-Confirmation

Several account actions carry consequences a hijacked or unattended session should not be able to trigger on its own. These actions require the acting user to re-enter their **current account password** at the moment of the action, verified server-side against the stored hash - an active session is treated as necessary but not sufficient authorization for them:

* Deactivating the user's own account.
* Disabling two-factor authentication.
* Regenerating two-factor recovery codes.
* Removing a registered passkey.
* Saving a change from the Super-Admin-only Environment editor (Section 14), for both the field-by-field form and the raw-file editor.

The pattern is uniform: the request must include the current password; if it does not match the account's stored hash, the action is rejected with a field-level error and no state changes; if it matches, the action proceeds and (where applicable) is recorded in the audit log. Deactivating an account additionally signs the account out of every device as part of the same action.

By contrast, unlinking a connected SSO account and registering a new passkey are **not** step-up gated: unlinking only ever narrows an account's login surface (and the underlying service still refuses to leave an account with no way to sign back in at all), and adding a new credential cannot be used to weaken an account the way disabling a factor or removing one can. This asymmetry - gating actions that reduce security, not actions that add to it - is deliberate.

---

# 9. Authorization Architecture

Authorization enforces:

* Role-Based Access Control (RBAC)
* Policy-Based Authorization
* Resource Ownership Validation
* Administrative Privilege Separation

Every protected operation is authorized before execution. Administrative roles are separated into User, Admin, and Super Admin, with permissions granted per role rather than implied by seniority. Certain permissions are deliberately withheld from the plain Admin role and granted only to Super Admin - backup management and environment-variable management (Section 14) both follow this same precedent, since both surfaces can expose credentials or affect every user on the platform, not just the record an ordinary Admin action targets.

---

# 10. Session Protection

The session architecture supports:

* Secure cookies
* Configurable session lifetime
* Idle timeout
* Session revocation
* Multi-device sessions
* Logout from a specific device
* Logout from all devices, including every linked account (Section 7)

Users can view their active sessions and known devices, revoke an individual session, and terminate all sessions except the current one from Settings → Account & Security → Active devices. An administrator can force-logout a target user's other sessions directly, and this same force-logout is triggered automatically whenever an administrator resets a user's password (Section 21).

---

# 11. Secure Communication

All client-server communication:

* Uses HTTPS in production.
* Protects sensitive information during transmission.
* Validates authenticated requests.
* Rejects unauthorized requests.

Transport security follows current industry best practices. A restrictive Content-Security-Policy is enforced application-wide (`app/Http/Middleware/SecurityHeaders.php`), with `connect-src` limited to the specific external hosts the application genuinely needs (for example, the push-notification provider's token-issuance endpoints) rather than left open by default.

---

# 12. API Security

Every protected API enforces:

* Authentication
* Authorization
* Request validation
* Rate limiting
* Ownership verification
* Standardized error responses

API behavior complies with the REST API Specification (RAS).

---

# 13. Input Validation and Injection Prevention

All external input is validated for required fields, data types, length constraints, allowed values, file types, file size, and business rules, on the server regardless of any client-side validation. Database access uses the framework's parameterized query mechanisms exclusively.

Where a form field is used to construct a raw file write rather than a database write, the same discipline applies. The Environment editor's field-by-field save path (Section 14) only ever writes a key that matches a strict `^[A-Z][A-Z0-9_]*$` pattern; any submitted field name that does not already look like a legitimate environment-variable key is silently discarded rather than written, which prevents a crafted form field from injecting an arbitrary new line, key, or shell-meaningful content into the live `.env` file.

---

# 14. Environment Variable Management (Super-Admin)

The Admin panel includes a dedicated Environment tab, gated behind a `manage_environment` permission that is granted to the Super Admin role only and never to the plain Admin role - the same precedent already established for backup management, since `.env` can hold database, mail, and third-party API credentials. It is intentionally kept as its own surface rather than folded into the general key/value Admin Settings table, given how much more sensitive its contents are.

Two editing modes are offered:

* **Field-by-field form** - one input per real key already present in the file. Keys that look sensitive (`*_KEY`, `*_SECRET`, `*_PASSWORD`, `*_TOKEN`) are masked by default with a Show/Hide toggle per field.
* **Raw full-file textarea** - a direct escape hatch for editing comments, blank lines, or anything else the field-by-field view does not represent individually.

Both modes share the same controls:

* Every save requires re-entering the acting Super Admin's own current account password (Section 8) before anything is written.
* A dated backup copy of the file is taken immediately before every write, so a mistaken raw-mode save has a rollback path.
* `config:clear` runs automatically immediately after a successful write, so a production deployment running on cached configuration picks up the change without a separate manual step.
* Keys that exist in the shipped example environment file but are missing or blank in the live file are highlighted directly in the UI, using the same detection logic the deployment pipeline already applies when syncing newly introduced configuration keys to a live server.
* A successful field-by-field save is recorded in the audit log by which keys changed; the actual values - before or after - are never written to the log, since they may themselves be real secrets.

---

# 15. Message Encryption Architecture

Text message bodies are encrypted using a server-managed key model. Each conversation is protected by its own AES-256-GCM key, generated server-side and stored encrypted at rest under the application's own encryption key. A new key epoch is issued transparently whenever one is needed - none exists yet for the conversation, or its membership has changed since the last epoch - with no separate "issue a key" step a client ever has to request.

This is a deliberately simpler trust model than a zero-knowledge scheme: the application itself generates and holds every conversation's key material. There is no per-user keypair, and no password-derived unlock step gating access to message history on a new device or browser - encryption and decryption are both fully automatic from the user's perspective. Access to a given epoch's key material is checked live, at the moment it is requested, against the requester's current conversation membership - not fixed permanently at the point the key happened to be issued - so a membership change rotates to a fresh epoch and a removed member's client cannot decrypt anything sent afterward.

Key material is cached client-side (IndexedDB) once fetched, so it persists across reloads without being re-requested on every page view. Groups above a configured member-count cap remain plaintext at the server rather than attempting per-recipient key distribution at broadcast scale, with a visible indicator shown to users in that case. Existing plaintext history in a conversation is migrated to encrypted storage automatically, in bounded batches, the next time a current member opens it. Any server-rendered surface that displays message content - including a starred-messages listing and a full chat export - either uses the same client-side decryption path or shows a placeholder rather than ever rendering raw ciphertext. Push notification previews fall back to a generic "New message" for an encrypted conversation, since the server itself cannot decrypt the body to build a real preview.

This architecture is disclosed honestly to users: every conversation shows a short banner noting that messages are private to the conversation's participants, without claiming end-to-end encryption in a way that would overstate what the system actually provides. The one deliberate, disclosed exception is View Once media, which stays encrypted at rest under the application's own key rather than this per-conversation scheme, remaining server-decryptable by design. Regular (non-View-Once) media attachments are not covered by this encryption pass; only text message bodies are - a materially larger undertaking involving client-side file encryption, streaming, and thumbnail generation.

---

# 16. File and Media Security

Uploaded files enforce authentication, authorization, MIME type validation, file size validation, and ownership verification. Protected files are not directly exposed through public URLs.

"Delete for everyone" removes the underlying stored file, not only the message row, once nothing else still references that exact media record - a forwarded copy of the same attachment is checked for before the underlying file is removed, so a forward is never left pointing at a file that no longer exists. A View Once attachment is deleted synchronously as soon as it is consumed, as the primary mechanism, with a scheduled sweep as a safety net for the rare case where that immediate deletion is interrupted. A separate scheduled sweep also removes any media record older than a day that nothing references at all - covering an uploaded-but-never-sent attachment, since upload and message-send are distinct steps and an abandoned upload is a real, reachable orphan.

---

# 17. Secret Management

Sensitive configuration includes database credentials, mail credentials, storage credentials, API keys, TURN server credentials, and third-party integration secrets. Secrets are never committed to source control. The Environment editor (Section 14) is the one supported UI path for changing these values directly on a live deployment, and is gated exactly as described there.

---

# 18. Privacy Controls

Users can configure profile visibility, last-seen visibility, online-status visibility, read receipts, and profile-photo visibility. Privacy settings are enforced consistently across every surface that could otherwise leak the corresponding signal, including polled API responses and initial page renders alike - a privacy setting is not considered correctly enforced if it holds on one code path but leaks through another.

---

# 19. Data Retention and Deletion

The platform supports configurable retention for audit logs, uploaded media, backups, temporary files, and application logs. Deletion workflows preserve system integrity and auditability where required, and several run on an automatic schedule rather than depending on manual cleanup:

* Expired guest meeting sessions are cleaned up on a schedule, and a guest-created meeting (which has no authenticated organizer to end it directly) is ended automatically once none of its guest sessions remain active.
* Messages covered by a conversation's disappearing-messages setting are removed on a schedule once they expire, using the same "delete for everyone" mechanism described in Section 16.
* Orphaned media files are swept on a schedule, as described in Section 16.
* A meeting left inactive - nobody genuinely present for a sustained window - is ended automatically on a schedule, closing the gap left by a lobby nobody ever entered or a meeting everyone has since left.

None of this platform's background cleanup runs as a queued job requiring a persistent worker process; all of it is scheduled, cron-driven work, consistent with the deployment environment this application actually runs in.

---

# 20. Database Security

The platform protects database operations through the framework's supported query mechanisms, database transactions, referential integrity, access control, and audit logging. Database credentials are stored securely and never logged.

---

# 21. Administrative Security

Administrative operations require strong authentication, role-based authorization, and audit logging. Every per-row administrative action - suspend/activate, force logout, password reset, granting or revoking elevated access, delete, restore - is authorized before it executes and is traceable afterward. An administrator resetting a user's password can mark the new password as temporary, which forces that user through a dedicated change-password step on their next login before reaching anywhere else in the app, and force-logs-out that user's other sessions the same way any other administrative account-access action already does.

---

# 22. Audit Logging

Security-relevant events recorded include registration, login success, login failure, logout, password reset, password change, session revocation, permission changes, administrative actions, configuration changes (including environment-variable changes, by key name only - see Section 14), and security exceptions. Logs support auditing and incident investigation. Audit entries never include the plaintext value of a secret being changed, only which key changed and who changed it.

---

# 23. Infrastructure and Operational Security

```text id="infraarchitecture"
Internet
      ↓
Firewall
      ↓
Web Server
      ↓
Laravel Application
      ↓
Scheduled Tasks (cron)
      ↓
Database
      ↓
Storage
      ↓
Backup Repository
```

Production servers implement minimal installed services, regular security updates, restricted administrative access, secure SSH configuration, time synchronization, and secure file permissions. Only required services are exposed publicly. Backup operations support encrypted storage where configured, access control, verification, and retention policies, with backup access limited to authorized administrators (Section 9).

---

# 24. Security Monitoring

Operational monitoring includes application availability, API performance, scheduler health, database status, storage usage, error rates, and WebRTC service health. Critical operational events generate alerts. Security-specific monitoring covers API failures, authorization failures, validation errors, file access events, security exceptions, and configuration changes.

---

# 25. Security Testing and Validation

Security testing covers authentication, authorization, session management, API security, file upload security, input validation, and administrative security, occurring before every production release. Where a real external service is involved - for example, push-notification token issuance and delivery - verification is performed against that real infrastructure rather than mocks alone, since a mocked integration can hide failures (such as a browser-level subscription conflict) that only appear against the genuine service. Security validation is considered complete only when security tests pass, critical vulnerabilities are resolved, monitoring is operational, and documentation is synchronized with real behavior.

---

# 26. Compliance Considerations

The architecture supports compliance with applicable legal, contractual, and organizational security requirements, including secure configuration, auditability, configurable data retention, and privacy-by-design controls. Specific compliance obligations depend on the deployment environment and applicable regulations and are evaluated separately from this document.

---

# 27. Future Security Enhancements

The security architecture is designed to support, without requiring fundamental redesign:

* Hardware security keys
* Advanced threat detection
* Security Information and Event Management (SIEM)
* Client-side encryption of media attachments, extending the text-message encryption model in Section 15
* Enterprise identity federation
* Automated, continuous vulnerability scanning

---

# 28. Summary

The Security Architecture defines the complete authentication, authorization, encryption, and operational security model for the Meet platform:

* Authentication spans password login, two-factor authentication, passkeys, Google single sign-on, and multi-account switching, each composed on top of the platform's existing session and remember-me mechanisms rather than a parallel identity system.
* A uniform step-up password re-confirmation pattern gates every action that reduces an account's security posture - deactivation, disabling 2FA, regenerating recovery codes, removing a passkey, and Super-Admin environment edits - while actions that only add credentials or narrow login surface are deliberately left ungated.
* Chat messages are protected by a server-managed, per-conversation AES-256-GCM encryption scheme, a deliberate and disclosed alternative to a zero-knowledge design, with live membership-based access control and an honest, non-overstated privacy banner shown to users.
* A Super-Admin-only Environment editor lets `.env` be changed from the Admin panel safely - password-gated, backed up before every write, restricted to well-formed key names, and never logging secret values.
* Scheduled, non-queued cleanup jobs enforce retention for disappearing messages, orphaned media, expired guest sessions, and abandoned meetings, matching the platform's cron-only operational model.
* File deletion, database access, administrative actions, and audit logging are all designed so that accountability and integrity hold even as the rest of the platform evolves.
