# Meet v1.0 - Entity Relationship Design (ERD) & Data Dictionary

## Document Information

**Project Name:** Meet

**Version:** 1.0

**Database:** MySQL 8+

**ORM:** Laravel Eloquent

**Migration Engine:** Laravel Migrations

---

# 1. Purpose

This document defines the complete database schema for the Meet platform.

It specifies:

* Every database table
* Every column
* Data types
* Default values
* Primary Keys
* Foreign Keys
* Indexes
* Relationships
* Constraints
* Soft Delete Policy

This document is the single source of truth for all database migrations.

---

# 2. Database Design Principles

## Naming Convention

Tables

```text
users
meetings
messages
conversation_participants
```

Columns

```text
user_id
meeting_id
created_at
updated_at
```

---

## Primary Keys

Every table shall contain:

```sql
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
```

---

## Foreign Keys

Format

```text
{table_singular}_id
```

Example

```text
user_id

meeting_id

conversation_id
```

---

## Timestamp Columns

Every transactional table shall contain

```text
created_at

updated_at
```

Optional

```text
deleted_at
```

---

# 3. Database Overview

Estimated Tables

| Module         | Approx Tables |
| -------------- | ------------- |
| Authentication | 8             |
| Users          | 7             |
| Contacts       | 5             |
| Chat           | 18            |
| Groups         | 12            |
| Calls          | 8             |
| Meetings       | 15            |
| Whiteboard     | 8             |
| Calendar       | 8             |
| Files          | 10            |
| Notifications  | 8             |
| Analytics      | 8             |
| Archive        | 8             |
| Admin          | 15            |
| Security       | 10            |
| Configuration  | 10            |

**Estimated Total:** 150-170 Tables

---

# 4. Entity Groups

## Authentication

* users
* email_otps
* password_reset_tokens
* login_attempts
* user_sessions
* trusted_devices
* user_tokens
* email_verifications

---

## User Module

* user_profiles
* user_preferences
* user_privacy
* user_status
* user_devices
* profile_photos
* user_activity

---

## Contacts

* contacts
* blocked_users
* favorite_contacts
* contact_requests
* emergency_contacts

---

## Chat

* conversations
* conversation_members
* messages
* message_status
* message_reactions
* message_edits
* message_mentions
* pinned_messages
* starred_messages
* deleted_messages
* chat_wallpapers
* chat_settings
* typing_status
* online_status
* read_receipts
* forwarded_messages
* quoted_messages
* drafts

---

## Groups

* groups
* group_members
* group_roles
* group_permissions
* group_invites
* group_join_requests
* group_announcements
* group_events
* group_polls
* poll_votes
* group_media
* group_logs

---

## Voice & Video

* calls
* call_history
* signaling_messages
* ice_candidates
* call_devices
* call_quality_logs
* call_records_future
* call_statistics

---

## Meetings

* meetings
* meeting_participants
* meeting_invitations
* meeting_chat
* meeting_history
* meeting_waiting_room
* meeting_roles
* meeting_recordings_future
* meeting_chat_files
* meeting_permissions
* meeting_settings
* meeting_logs
* meeting_guest_sessions
* meeting_notes_future
* meeting_reports

---

## Whiteboard

* whiteboards
* whiteboard_pages
* whiteboard_objects
* whiteboard_history
* whiteboard_permissions
* whiteboard_exports
* whiteboard_templates
* whiteboard_logs

---

## Calendar

* calendars
* calendar_events
* calendar_reminders
* meeting_reminders
* calendar_rsvp
* recurring_events
* holidays
* availability

---

## File System

* uploads
* upload_chunks
* media_files
* media_versions
* one_view_files
* file_permissions
* file_downloads
* file_thumbnails
* storage_locations
* file_logs

---

## Notifications

* notifications
* notification_preferences
* notification_templates
* notification_logs
* email_queue
* push_queue_future
* reminder_queue
* broadcasts

---

## Security

* audit_logs
* security_logs
* blocked_ips
* security_events
* api_logs
* rate_limits
* login_devices
* session_logs
* csrf_logs
* suspicious_activity

---

## Analytics

* analytics_events
* analytics_daily
* analytics_monthly
* user_statistics
* meeting_statistics
* storage_statistics
* reports
* report_exports

---

## Archive

* archives
* archive_jobs
* archive_history
* retention_policies
* archive_logs
* backup_jobs
* restore_jobs
* cleanup_jobs

---

## Administration

* admins
* roles
* permissions
* role_permissions
* user_roles
* settings
* feature_flags
* smtp_settings
* storage_settings
* branding_settings
* scheduler_settings
* queue_settings
* system_health
* maintenance_logs
* admin_activity

---

## Configuration

* application_settings
* ui_settings
* meeting_settings_global
* guest_settings
* upload_settings
* notification_settings
* security_settings
* polling_settings
* api_settings
* pwa_settings

---

# 5. Relationship Standards

Examples

User

↓

hasOne

↓

UserProfile

---

User

↓

hasMany

↓

Messages

---

Conversation

↓

hasMany

↓

Messages

---

Meeting

↓

hasMany

↓

MeetingParticipants

---

Meeting

↓

hasMany

↓

MeetingInvitations

---

Group

↓

hasMany

↓

GroupMembers

---

Archive

↓

hasMany

↓

ArchiveLogs

---

# 6. Indexing Policy

Indexes shall exist on:

* Email
* Phone
* Username
* Meeting ID
* Conversation ID
* Message ID
* User ID
* Foreign Keys
* Created At
* Updated At
* Status

Composite indexes shall be created for high-frequency lookup tables.

---

# 7. Data Integrity Rules

* Foreign keys enforced where appropriate.
* Transactions used for multi-step operations.
* Soft deletes applied according to business requirements.
* Historical data preserved unless retention policies permit removal.

---

# 8. Migration Policy

Every migration shall include:

* Up Method
* Down Method
* Indexes
* Constraints
* Foreign Keys
* Comments where necessary

No schema change shall bypass Laravel Migrations.

---

# 9. Next Phase

The next section of this document will define every table individually.

For each table we will specify:

* Purpose
* Columns
* Data Types
* Default Values
* Constraints
* Primary Key
* Foreign Keys
* Indexes
* Relationships
* Eloquent Model
* Migration Example
* Validation Notes

This section will serve as the implementation blueprint for all database migrations.

---

# Document Summary

This Entity Relationship Design & Data Dictionary establishes the complete logical database structure for the Meet platform. It organizes approximately 150-170 tables into functional modules, defines naming and relationship standards, indexing policies, migration rules, and data integrity principles. The following sections of this document will describe each table in detail, making it the authoritative reference for database implementation.
