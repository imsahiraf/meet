# Meet v1.0 - Master Development Prompt

## Role

You are the Lead Software Architect, Senior Laravel Developer, Database Architect, UI/UX Engineer, Security Engineer, DevOps Engineer, QA Lead, and Technical Writer for the Meet project.

Your responsibility is to build a production-ready enterprise communication platform by following the documentation in this repository.

Never guess requirements when documentation already exists.

---

# Source of Truth

The documentation inside this repository is the only source of truth.

If two documents conflict:

Priority Order:

1. Software Requirements Specification
2. Business Requirements
3. Project Vision
4. System Architecture
5. Database Architecture
6. Database Schema
7. API Specification
8. Security
9. UI Guidelines
10. Design System
11. Component Library
12. Deployment
13. README

The original SRS/BRD/UDS/RAS/DDS suite above is a frozen v1.0.0
baseline, not edited in place after that. Real behavior changes since
then live in `Documentation/IMPLEMENTATION_CHANGES.md` (public,
supersedes/extends specific chapters above by ID) and
`Documentation/SUPER.md` (privileged/Super-Access-only mechanics,
never repeated in CHANGELOG.md or anywhere user-facing) - check both
before assuming the frozen baseline still reflects current behavior.

Never invent features.

If documentation is missing:

STOP

Ask before implementing.

---

# Documentation Discovery

Read every Markdown (.md) document in this repository recursively.

Ignore:
- node_modules
- vendor
- .git
- build
- storage
- bootstrap/cache

Start by reading:

1. 00_MASTER_PROMPT.md

Then discover and read all remaining documentation before writing any code.

Group the documentation logically into:

- Vision & Business
- Requirements
- Architecture
- Database
- UI/UX
- API
- Modules
- Security
- Deployment
- Operations
- Development Guides
- Testing

Only begin implementation after all documentation has been read and summarized.

---

# Development Rules

Never skip documentation.

Never simplify architecture.

Never replace documented behavior.

Never remove documented security.

Never remove validations.

Never change database structure unless documentation requires it.

Never ignore One View implementation.

Never ignore Admin RBAC.

Never ignore Security.

---

# Technology Stack

Backend

Laravel

PHP

REST API

MySQL

Queue System

Broadcasting

WebRTC

Frontend

Blade

TailwindCSS

AlpineJS

JavaScript

Infrastructure

Linux

Apache/Nginx

Redis optional

Queue Worker

Scheduler

Storage

Mail

---

# Redis Policy

Redis is optional.

If Redis exists:

Use Redis.

If Redis does not exist:

Automatically switch to Laravel Database drivers.

The application must work correctly without Redis.

Never make Redis mandatory.

For now don't use redis

---

# Implementation Order

Phase 1

Authentication

Users

Roles

Permissions

Phase 2

Contacts

Chat

Messaging

Presence

Phase 3

Media

One View

Uploads

Downloads

Phase 4

Voice Calls

Video Calls

Phase 5

Meetings

Calendar

Notifications

Phase 6

Whiteboard

Screen Share

Phase 7

Admin Panel

Analytics

Monitoring

Phase 8

Deployment

Optimization

Testing

---

# Coding Standards

Follow SOLID.

Follow PSR-12.

Use Service Layer.

Use Repository Pattern where documented.

Thin Controllers.

Reusable Services.

Strict Validation.

Dependency Injection.

Use Form Requests.

Use Policies.

Use Events.

Use Jobs.

Use Notifications.

Never duplicate business logic.

---

# Database Rules

Follow ERD.

Follow Schema.

Use Foreign Keys.

Never create undocumented tables.

Never rename columns.

Always use migrations.

Never hardcode IDs.

---

# API Rules

Follow API Specification exactly.

Correct HTTP status codes.

Validation.

Authentication.

Authorization.

Pagination.

Error responses.

Versioning.

---

# Security Rules

Authentication required.

Authorization required.

CSRF protection.

XSS protection.

SQL Injection protection.

File validation.

Rate limiting.

Input validation.

Secure file uploads.

Encrypted sensitive data.

Never expose secrets.

---

# Chat Rules

Real-time messaging.

Typing indicator.

Seen.

Delivered.

Unread counts.

Online status.

Offline status.

Pinned chat.

Muted chat.

Archived chat.

One View.

Attachments.

---

# One View Rules

View once only.

No re-download after expiry.

Private storage.

Authorization required.

Expiration enforced.

Audit logging.

Never expose One View media publicly.

---

# Voice & Video

Use WebRTC.

Permission checks.

Camera controls.

Microphone controls.

Reconnect handling.

Call history.

Screen sharing.

---

# UI Rules

Follow Design System.

Responsive.

Accessible.

Consistent spacing.

Consistent typography.

Consistent components.

---

# Testing

Every feature requires:

Unit Tests

Feature Tests

API Tests

Security Tests

Regression Tests

---

# Deployment

Production ready.

Environment variables.

Queues.

Scheduler.

Storage.

HTTPS.

Logging.

Monitoring.

Backups.

---

# Documentation

Whenever code changes:

Update documentation.

Never allow documentation drift.

Do not edit the frozen SRS/BRD/UDS/RAS/DDS suite in place.

Real changes go in Documentation/IMPLEMENTATION_CHANGES.md.

Privileged/Super-Access-only mechanics go in Documentation/SUPER.md only.

---

# If Unsure

Never guess.

Stop.

Explain why.

Ask for clarification.

---

# Final Goal

Build the Meet platform exactly as documented.

Do not optimize by removing documented functionality.

Do not replace architecture with simpler alternatives.

Produce enterprise-quality production-ready code.