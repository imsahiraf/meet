# Meet - Master Development Prompt (Claude)

You are acting as a Principal Software Architect, Senior Laravel Developer, Senior UI/UX Designer, DevOps Engineer, Database Architect, Security Engineer, QA Lead, and Technical Writer.

Your objective is to design and implement a production-ready Progressive Web Application (PWA) named **Meet**.

This project must be designed with maintainability, scalability, configurability, performance, and clean architecture as first-class goals.

## Technology Stack

* Laravel 12+
* PHP 8.3+
* MySQL
* Blade + Vite
* Vanilla JavaScript (preferred) or lightweight Alpine.js where appropriate
* AJAX polling (no Node.js and no WebSocket server required)
* WebRTC for audio/video
* Google STUN servers by default
* TURN server support must be configurable later
* Service Worker for PWA
* Installable on Android/iOS as a PWA
* cPanel-compatible shared hosting deployment

The solution must not depend on a permanently running Node.js process.

## Primary Goals

Create a modern communication platform that combines the best ideas from:

* WhatsApp
* Google Meet
* Telegram
* Discord
* Slack

while remaining lightweight enough to deploy on standard cPanel hosting.

## Authentication

Implement:

* Phone Number
* Email
* Password

Registration requires:

* Phone Number
* Email
* Password

Since SMS OTP is not implemented, email verification using OTP is mandatory before account activation.

Login must support:

* Phone + Password
* Email + Password

Admin must be able to configure:

* SMTP
* OTP expiry
* Mail templates
* Password policy

## Chat System

Implement:

* One-to-one chat
* Group chat
* Typing indicator
* Read receipts
* Delivered status
* Online/offline status
* Last seen
* Reply
* Forward
* Star message
* Delete for me
* Delete for everyone
* Emoji picker
* Attachments
* Images
* Videos
* Documents
* Audio
* Voice notes
* Contact sharing
* Location sharing (future-ready)
* Chat search
* Media gallery
* Archived chats
* Pinned chats
* Message reactions

AJAX polling should be optimized to reduce unnecessary requests.

## Calls

Implement:

* Audio Call
* Video Call

Using:

* WebRTC
* Google STUN

Future TURN support must be configurable.

Features:

* Camera toggle
* Mic toggle
* Speaker selection
* Screen sharing
* Full screen
* Chat during call
* Participant list
* Raise hand
* Waiting room (configurable)
* Device switching
* Call timer

## Meetings

Implement Google Meet-style meetings.

Features:

* Schedule meeting
* Instant meeting
* Meeting ID
* Meeting password (optional)
* Waiting room
* Host controls
* Mute participants
* Remove participant
* Whiteboard
* Screen share
* Participant chat
* Recording architecture placeholder
* Meeting notes placeholder

When a scheduled meeting is created:

Generate and email a valid RFC5545 ICS calendar invitation to all invited users.

Guest meetings:

* Join via shared link
* No signup required
* Maximum duration configurable (default 15 minutes)
* No ICS invitation

## One View Media

Implement WhatsApp-style one-view media.

Rules:

* Sender can mark media as One View.
* Recipient can open only once.
* After viewing, file is permanently deleted.
* If recipient has "Super Access" permission assigned by Admin, clicking the file downloads it automatically before viewing.
* Access must be logged.

## File Upload

Support:

* Chunk uploads
* Resume uploads
* Resume downloads
* Large files
* Queue-friendly processing
* Configurable limits

Admin can configure:

* Chunk size
* Upload size
* Storage path
* Expiry
* Cleanup schedule

## Admin Panel

Everything possible must be configurable without changing source code.

Include:

* Dashboard
* User Management
* Roles
* Permissions
* SMTP
* Storage
* Branding
* Theme
* Upload Settings
* Meeting Settings
* Guest Settings
* Polling Interval
* STUN/TURN configuration
* Email Templates
* Notification Templates
* Security Settings
* Backup Settings
* Logs
* Analytics
* Feature Flags
* Maintenance Mode

## PWA

Implement:

* Offline shell
* Install prompt
* Splash screen
* App icons
* Push notification architecture
* Background sync where supported

## UI/UX

Design language:

* Futuristic
* Glassmorphism
* Premium
* Smooth animations
* Mobile-first
* Responsive
* Accessible
* Fast
* Minimal
* Professional

Provide complete UI specifications before implementation.

## Documentation Requirements

Before writing production code, generate:

1. Software Requirement Specification (SRS)
2. Functional Requirements
3. Non-Functional Requirements
4. System Architecture
5. Database Schema
6. Folder Structure
7. API Documentation
8. UI/UX Guide
9. Security Design
10. Testing Strategy
11. Deployment Guide
12. README
13. Future Roadmap

No implementation should begin until the documentation is complete and internally consistent.

Follow Laravel best practices, SOLID principles, clean architecture, secure coding standards, and produce maintainable, well-commented code suitable for production deployment.
