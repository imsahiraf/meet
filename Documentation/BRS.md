# Meet - Business Requirements Specification (BRS)

**Version:** 1.0
**Project:** Meet
**Technology:** Laravel 12+, PHP 8.3+, MySQL, PWA, WebRTC

---

# 1. Business Objective

Meet is a communication and collaboration platform that combines instant messaging, voice calls, video calls, online meetings, scheduling, file sharing, and administration in a single Laravel-based Progressive Web Application.

The application is designed to run efficiently on standard cPanel hosting while providing a premium user experience comparable to modern communication platforms.

---

# 2. Business Goals

The application shall:

* Provide secure communication between users.
* Replace multiple communication tools with one platform.
* Be installable as a PWA.
* Be mobile-first.
* Support businesses, teams, and personal users.
* Minimize infrastructure costs.
* Allow complete administration through an Admin Panel.
* Remain extensible for future enterprise features.

---

# 3. Stakeholders

## End User

Can:

* Register
* Login
* Verify Email
* Chat
* Create Groups
* Send Files
* Make Calls
* Schedule Meetings
* Join Meetings
* Share Screen
* Draw on Whiteboard
* Manage Profile
* Manage Devices

---

## Guest User

Can:

* Join meeting using invite link
* Join without registration
* Use audio/video
* Share screen (host permission)
* Use chat inside guest meeting

Cannot:

* Send private messages
* Access chat history
* Schedule meetings
* Create groups
* Upload permanent files
* Access profile

Guest session expires automatically after the configured time (default 15 minutes).

---

## Administrator

Has complete platform control.

Can:

* Manage users
* Manage roles
* Configure SMTP
* Configure storage
* Configure application branding
* Configure upload limits
* Configure meeting settings
* Configure polling interval
* Configure STUN/TURN servers
* View reports
* View logs
* Suspend users
* Reset passwords
* Enable/Disable features
* Configure email templates
* Configure file expiry policies

---

## Super Administrator

Has unrestricted system access including:

* Database maintenance
* Backup
* Restore
* Cron settings
* Queue settings
* Cache management
* Security policies
* Feature Flags
* License Management (future)

---

# 4. User Roles

The system shall support role-based permissions.

Minimum roles:

* Guest
* User
* Moderator
* Admin
* Super Admin

Additional custom roles can be created through the Admin Panel.

---

# 5. Functional Modules

## Authentication

Business Requirements:

* Register using Phone + Email + Password
* Email OTP verification mandatory
* Phone verification optional (future)
* Login using Email or Phone
* Forgot Password
* Remember Me
* Device Tracking
* Login History

---

## Profile

Users shall be able to:

* Upload Avatar
* Update Profile
* Change Password
* Configure Privacy
* Configure Notification Preferences

---

## Contacts

Users shall be able to:

* Search users
* Send contact request (optional)
* View mutual contacts (future)
* Block users
* Report users

---

## Chat

The application shall support:

* One-to-one chat
* Group chat
* Reply
* Forward
* Delete
* Delete for Everyone
* Reactions
* Read Receipts
* Typing Indicator
* Last Seen
* Online Status
* Archived Chats
* Starred Messages
* Chat Search

---

## Media

Supported media:

* Images
* Videos
* Documents
* Audio
* Voice Notes

---

## One View Media

Business Rules:

Sender may mark media as "One View".

Recipient may view only once.

Media is permanently deleted after successful viewing according to retention policy.

If recipient possesses Super Access permission:

* File automatically downloads before viewing.
* Download event is logged.

---

## Audio Calling

Requirements:

* One-to-One
* Accept
* Reject
* Busy
* Missed Call
* Call Duration
* Call History

---

## Video Calling

Requirements:

* Camera
* Microphone
* Screen Share
* Device Switching
* Full Screen
* Call Chat

---

## Meetings

Meeting Types:

* Instant Meeting
* Scheduled Meeting

Features:

* Host Controls
* Waiting Room
* Participants
* Chat
* Whiteboard
* Screen Share
* Raise Hand
* Join via Link
* Password Protected Meeting (optional)

---

## Meeting Scheduling

Users shall be able to:

* Select Date
* Select Time
* Select Duration
* Invite Participants

System shall:

* Generate valid ICS calendar file
* Email invitations
* Allow RSVP support in future

---

## Calendar

The application shall include:

* Month View
* Week View
* Day View
* Upcoming Meetings
* Past Meetings

---

## File Sharing

Requirements:

* Chunk Upload
* Resume Upload
* Resume Download
* Preview
* Download
* Delete
* Expiry

---

## Notifications

Notification Channels:

* Email
* In-App

Future:

* Push Notification
* SMS

---

## Admin Dashboard

Dashboard shall include:

* User Statistics
* Active Calls
* Active Meetings
* Active Chats
* Storage Usage
* Daily Registrations
* Email Queue
* System Health

---

## Reports

Reports shall include:

* User Activity
* Meeting Activity
* Chat Activity
* Login Reports
* Storage Reports
* Downloads
* One View Reports

---

# 6. Non-Functional Requirements

The system shall:

* Be responsive.
* Support mobile-first usage.
* Load quickly.
* Use optimized database queries.
* Minimize server requests.
* Be scalable.
* Be maintainable.
* Follow Laravel best practices.
* Be secure against common web vulnerabilities.

---

# 7. Configuration Requirements

Everything possible shall be configurable.

Examples:

* SMTP
* Branding
* Theme
* Storage Driver
* Polling Interval
* Upload Limit
* Guest Duration
* Meeting Duration
* OTP Expiry
* Email Templates
* STUN Servers
* TURN Servers
* Maintenance Mode

---

# 8. Security Requirements

The application shall include:

* CSRF Protection
* XSS Protection
* SQL Injection Protection
* Password Hashing
* Login Rate Limiting
* Signed URLs
* Secure File Access
* Audit Logs
* Activity Logs

---

# 9. Scalability Requirements

Architecture shall support future integration of:

* SMS Gateway
* Push Notifications
* AI Features
* Cloud Storage
* Subscription Plans
* Native Mobile Applications
* Desktop Applications

without requiring major architectural changes.

---

# 10. Acceptance Criteria

The Business Requirements Specification shall be considered complete when:

* All stakeholders are identified.
* Functional modules are defined.
* Business rules are documented.
* User roles are established.
* Security expectations are documented.
* Future extensibility is ensured.
* Configuration-first architecture is maintained.

This document serves as the business foundation for all subsequent technical specifications including the Software Requirements Specification (SRS), System Architecture, Database Design, API Specifications, UI/UX Guide, and Implementation Plan.
